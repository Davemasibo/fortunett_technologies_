<?php
/**
 * Cron: pay the ISPs what the platform is holding for them.
 *
 *   php cron/disburse_payouts.php                 # DRY RUN — shows what it would send
 *   php cron/disburse_payouts.php --live          # actually sends
 *   php cron/disburse_payouts.php --tenant=7 --live
 *
 * Suggested schedule, once you have watched a few dry runs:
 *   0 9 * * *  php /var/www/html/cron/disburse_payouts.php --live >> /var/log/fortunett_payouts.log 2>&1
 *
 * Until this existed, "release" only stamped `released_at` and an email told the
 * ISP the money "will be remitted within 1 business day" — by hand. A released
 * payout and a paid one were the same row, so nothing could answer "what do we
 * still owe?".
 *
 * How a run is made safe
 * ----------------------
 *  - DRY RUN unless --live. There is no way to send money by accident.
 *  - Three gates before anything is sent (see disbursementPreflight and
 *    tenantPayoutGate): a platform switch that is off by default, a per-tenant
 *    opt-in, and a human-verified destination number.
 *  - One B2C call per tenant per run, over an aggregated batch. Fewer moving
 *    parts and one Safaricom fee instead of dozens.
 *  - The batch row is written and the queue marked 'processing' BEFORE the API
 *    call. If the process dies mid-send, the next run sees 'processing' and
 *    leaves it alone: a stuck payout a human must look at is a far better
 *    outcome than a duplicate payment, which M-Pesa cannot claw back.
 *  - A network timeout is NOT treated as a failure. Safaricom may have accepted
 *    the request; the batch is marked 'unknown' and left for a human.
 *  - max_daily per tenant, checked against what has already gone out today.
 *
 * The money is NOT settled here. Safaricom's asynchronous result callback
 * (api/payment/b2c_result.php) is what marks the queue 'paid' and stamps
 * payments.released_at. This script only ever gets as far as 'accepted'.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/../includes/payouts.php';
require_once __DIR__ . '/../includes/cron_heartbeat.php';
require_once __DIR__ . '/../classes/MpesaAPI.php';

$live       = in_array('--live', $argv, true);
$onlyTenant = 0;
foreach ($argv as $a) {
    if (strpos($a, '--tenant=') === 0) $onlyTenant = (int)substr($a, 9);
}

$logFile = __DIR__ . '/../logs/payouts.log';
if (!is_dir(dirname($logFile))) @mkdir(dirname($logFile), 0755, true);

function plog(string $msg): void {
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    echo $line;
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

try { cron_heartbeat($pdo, 'disburse_payouts'); } catch (Throwable $e) { /* non-fatal */ }

plog($live ? '=== LIVE RUN — money will be sent ===' : '=== DRY RUN — nothing will be sent (add --live) ===');

ensurePayoutTables($pdo);

// ── Platform gate ─────────────────────────────────────────────────────────────
$pre = disbursementPreflight($pdo);
if (!$pre['ok']) {
    plog('Payouts are not ready:');
    foreach ($pre['reasons'] as $r) plog('  - ' . $r);
    if ($live) {
        plog('Refusing to send. Fix the above, then re-run.');
        exit(1);
    }
    plog('(dry run continues so you can see what WOULD be sent)');
}

// ── Anything stuck from a previous run? ───────────────────────────────────────
// Reported loudly every run. A batch in 'sending' or 'unknown' means we do not
// know whether money left, and nothing further should be sent to that tenant
// until a human has checked the M-Pesa statement.
$stuckSt = $pdo->query("
    SELECT id, tenant_id, sent_amount, originator_conversation_id, status, created_at
    FROM isp_payout_batches
    WHERE status IN ('sending','unknown')
    ORDER BY created_at
");
$stuck = $stuckSt ? $stuckSt->fetchAll(PDO::FETCH_ASSOC) : [];
$blockedTenants = [];
foreach ($stuck as $b) {
    $blockedTenants[(int)$b['tenant_id']] = true;
    plog(sprintf('  !! STUCK batch #%d tenant %d KES %s (%s) from %s — outcome unknown, check the M-Pesa statement',
        $b['id'], $b['tenant_id'], number_format($b['sent_amount'], 2),
        $b['originator_conversation_id'], $b['created_at']));
}

// ── What is owed ──────────────────────────────────────────────────────────────
$sql = "
    SELECT q.tenant_id, t.company_name,
           COUNT(*) AS n,
           COALESCE(SUM(q.gross_amount), 0)      AS gross,
           COALESCE(SUM(q.commission_amount), 0) AS commission,
           COALESCE(SUM(q.net_amount), 0)        AS net,
           GROUP_CONCAT(q.id) AS queue_ids
    FROM isp_payout_queue q
    JOIN tenants t ON t.id = q.tenant_id
    WHERE q.status = 'pending'
";
$params = [];
if ($onlyTenant) { $sql .= " AND q.tenant_id = ? "; $params[] = $onlyTenant; }
$sql .= " GROUP BY q.tenant_id, t.company_name ORDER BY net DESC";

$st = $pdo->prepare($sql);
$st->execute($params);
$owed = $st->fetchAll(PDO::FETCH_ASSOC);

if (!$owed) {
    plog('Nothing pending. Done.');
    exit(0);
}

plog(count($owed) . ' tenant(s) with pending payouts.');

$sent = 0; $skipped = 0; $totalSent = 0.0;

foreach ($owed as $row) {
    $tid   = (int)$row['tenant_id'];
    $name  = $row['company_name'];
    $net   = round((float)$row['net'], 2);
    $label = "tenant $tid ($name)";

    if (isset($blockedTenants[$tid])) {
        plog("SKIP $label — an earlier batch has an unknown outcome; resolve it first");
        $skipped++;
        continue;
    }

    $gate = tenantPayoutGate($pdo, $tid);
    if (!$gate['ok']) {
        plog(sprintf('SKIP %s — %s (KES %s waiting)', $label, $gate['reason'], number_format($net, 2)));
        $skipped++;
        continue;
    }
    $settings = $gate['settings'];

    if ($net < (float)$settings['min_payout']) {
        plog(sprintf('HOLD %s — KES %s is below their minimum of %s',
            $label, number_format($net, 2), number_format((float)$settings['min_payout'], 2)));
        $skipped++;
        continue;
    }

    $already = payoutSentToday($pdo, $tid);
    $room    = (float)$settings['max_daily'] - $already;
    if ($room < $net) {
        plog(sprintf('HOLD %s — KES %s would exceed today\'s cap (%s already sent, cap %s)',
            $label, number_format($net, 2), number_format($already, 2),
            number_format((float)$settings['max_daily'], 2)));
        $skipped++;
        continue;
    }

    $queueIds = array_filter(array_map('intval', explode(',', (string)$row['queue_ids'])));

    if (!$live) {
        plog(sprintf('WOULD SEND KES %s to %s (%s) for %s — %d payment(s)',
            number_format($net, 2), $settings['payout_phone'], $settings['payout_name'] ?: $name,
            $label, count($queueIds)));
        $sent++;
        $totalSent += $net;
        continue;
    }

    // ── Reserve BEFORE sending ────────────────────────────────────────────────
    // Everything below this line assumes money may already be in flight.
    $originatorId = 'FTN-' . $tid . '-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 6);

    try {
        $pdo->beginTransaction();

        $pdo->prepare("
            INSERT INTO isp_payout_batches
                (tenant_id, originator_conversation_id, payout_phone, gross_amount,
                 commission_amount, net_amount, sent_amount, queue_ids, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'sending')
        ")->execute([
            $tid, $originatorId, $settings['payout_phone'],
            round((float)$row['gross'], 2), round((float)$row['commission'], 2),
            $net, floor($net), implode(',', $queueIds),
        ]);
        $batchId = (int)$pdo->lastInsertId();

        $in = implode(',', array_fill(0, count($queueIds), '?'));
        // The status guard makes this a claim, not just an update: if a
        // concurrent run got here first, rowCount comes back short and we bail.
        $claim = $pdo->prepare("
            UPDATE isp_payout_queue
            SET status = 'processing', batch_id = ?, attempts = attempts + 1
            WHERE id IN ($in) AND status = 'pending'
        ");
        $claim->execute(array_merge([$batchId], $queueIds));

        if ($claim->rowCount() !== count($queueIds)) {
            $pdo->rollBack();
            plog("SKIP $label — another run claimed these payouts first");
            $skipped++;
            continue;
        }

        $pdo->commit();
    } catch (Throwable $e) {
        try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable $re) {}
        plog("ERROR $label — could not reserve the batch: " . $e->getMessage());
        $skipped++;
        continue;
    }

    // ── Send ──────────────────────────────────────────────────────────────────
    $cfg   = $pre['config'];
    $mpesa = new MpesaAPI($pdo, null);
    $mpesa->loadFromArray($cfg);
    $mpesa->setInitiator(
        (string)($cfg['initiator_name'] ?? ''),
        (string)($cfg['security_credential'] ?? ''),
        (string)($cfg['b2c_shortcode'] ?? ($cfg['shortcode'] ?? ''))
    );

    // Guarded again here, not just in the preflight: this is the last point
    // before money leaves, and a relative path would sail past b2cPayment()'s
    // empty-string check and be sent to Safaricom as an unreachable ResultURL.
    // The payout would go out and its confirmation would never come back.
    $base = rtrim(payoutSetting($pdo, 'public_base_url', ''), '/');
    if (!preg_match('~^https://[^/\s]+~i', $base)) {
        $pdo->prepare("UPDATE isp_payout_batches SET status='failed', result_desc=?, completed_at=NOW() WHERE id=?")
            ->execute(['no valid https public_base_url — refused before sending', $batchId]);
        $in = implode(',', array_fill(0, count($queueIds), '?'));
        $pdo->prepare("UPDATE isp_payout_queue SET status='pending', batch_id=NULL, last_error=?
                       WHERE id IN ($in) AND status='processing'")
            ->execute(array_merge(['no valid https public_base_url'], $queueIds));
        plog("ABORT $label — public_base_url is not a valid https URL; nothing sent");
        plog('  Fix with: php tools/payout_config.php --base-url=https://your-domain');
        $skipped++;
        continue;
    }
    $resultUrl = $base . '/api/payment/b2c_result.php';
    $timeoutUrl= $base . '/api/payment/b2c_timeout.php';

    $res = $mpesa->b2cPayment(
        (string)$settings['payout_phone'],
        $net,
        'FortuNett settlement for ' . $name,
        $originatorId,
        $resultUrl,
        $timeoutUrl
    );

    if ($res['success']) {
        $pdo->prepare("UPDATE isp_payout_batches SET status='accepted', conversation_id=? WHERE id=?")
            ->execute([$res['conversation_id'], $batchId]);
        plog(sprintf('SENT KES %s to %s for %s — accepted as %s, awaiting result callback',
            number_format($net, 2), $settings['payout_phone'], $label, $res['conversation_id'] ?: $originatorId));
        $sent++;
        $totalSent += $net;
        continue;
    }

    // accepted === null means the network call itself was inconclusive. The
    // request may be live at Safaricom, so the queue stays 'processing' and
    // nothing is retried automatically.
    if ($res['accepted'] === null) {
        $pdo->prepare("UPDATE isp_payout_batches SET status='unknown', result_desc=? WHERE id=?")
            ->execute([substr((string)$res['error'], 0, 255), $batchId]);
        plog("UNKNOWN $label — " . $res['error']);
        plog("  The payouts stay 'processing'. Check the M-Pesa statement before re-running.");
        $skipped++;
        continue;
    }

    // A clean rejection: Safaricom never took it, so it is safe to put the money
    // back in the queue for the next run.
    $in = implode(',', array_fill(0, count($queueIds), '?'));
    $pdo->prepare("UPDATE isp_payout_batches SET status='failed', result_desc=?, completed_at=NOW() WHERE id=?")
        ->execute([substr((string)$res['error'], 0, 255), $batchId]);
    $pdo->prepare("
        UPDATE isp_payout_queue SET status='pending', batch_id=NULL, last_error=?
        WHERE id IN ($in) AND status='processing'
    ")->execute(array_merge([substr((string)$res['error'], 0, 255)], $queueIds));

    plog("FAILED $label — " . $res['error'] . ' (returned to the queue)');
    $skipped++;
}

plog(sprintf('Done. %d %s, %d skipped, KES %s %s.',
    $sent, $live ? 'sent' : 'would be sent', $skipped, number_format($totalSent, 2),
    $live ? 'in flight' : 'pending'));

if (!$live) {
    plog('Nothing was sent. Re-run with --live once the amounts above look right.');
}
exit(0);
