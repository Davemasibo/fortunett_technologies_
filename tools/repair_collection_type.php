<?php
/**
 * Re-tag payments whose collection_type is wrong.
 *
 *   php tools/repair_collection_type.php            # dry run
 *   php tools/repair_collection_type.php --apply
 *
 * Why this exists
 * ---------------
 * `payments.collection_type` says whose bank the money is actually in:
 *
 *   'platform' — FortuNett's till/paybill took it; the ISP is owed a payout
 *   'direct'   — the ISP's own paybill took it; nothing to disburse
 *
 * Only api/payment/stk_push.php ever wrote the column. process_payment_success()
 * queued an ISP payout whenever $platformCollected was true but never tagged the
 * row, so it fell back to the column DEFAULT 'direct'. Money sitting in
 * FortuNett's account was therefore recorded as money the ISP already had, while
 * a payout for that same shilling sat in isp_payout_queue.
 *
 * Everything that reads the column was wrong in the same direction: the ISP's
 * own billing.php float, super_admin/collections.php, release_payments.php,
 * cron/auto_release_settlements.php and settings.php.
 *
 * The authoritative signal for a historic row is isp_payout_queue: the pipeline
 * only ever queued a payout for platform-collected money. Rows the platform C2B
 * handler wrote are matched as a second pass by their note.
 *
 * Those two signals miss the largest group. When
 * api/payment/hotspot_payment_status.php confirmed an STK push inline it passed
 * a hard-coded platformCollected=false, so the pipeline booked the row 'direct'
 * AND skipped step 7 — no payout was queued, leaving no trace to match on. The
 * decisive evidence for those is the tenant's own configuration: a tenant with
 * no complete M-Pesa API credentials of their own has no paybill that could
 * have received the money, so every M-Pesa payment they ever took was collected
 * by the platform till. That is the third rule below.
 *
 *   php tools/repair_collection_type.php --tenant=7    # limit to one tenant
 *
 * Idempotent — re-running changes nothing once the rows are correct.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/../includes/payment_routing.php';

$apply = in_array('--apply', $argv, true);

$onlyTenant = 0;
foreach ($argv as $a) {
    if (strpos($a, '--tenant=') === 0) $onlyTenant = (int)substr($a, 9);
}

// Tenants that have never had M-Pesa credentials of their own. Every shilling
// they collected over M-Pesa went into FortuNett's till, whatever the row says.
$platformOnlyTenants = [];
foreach ($pdo->query("SELECT id FROM tenants")->fetchAll(PDO::FETCH_COLUMN) as $tid) {
    if (!tenantHasOwnMpesaCredentials($pdo, (int)$tid)) $platformOnlyTenants[] = (int)$tid;
}
if ($onlyTenant) {
    $platformOnlyTenants = array_values(array_filter(
        $platformOnlyTenants,
        function ($t) use ($onlyTenant) { return $t === $onlyTenant; }
    ));
}
$platformOnlyIn = $platformOnlyTenants
    ? implode(',', array_map('intval', $platformOnlyTenants))
    : '0';

echo $apply ? "=== APPLYING ===\n\n" : "=== DRY RUN (add --apply to commit) ===\n\n";

/** Rows that must be 'platform', with the evidence that says so. */
$claims = [
    'a payout was queued for it (isp_payout_queue)' => "
        SELECT p.id, p.tenant_id, p.amount, p.transaction_id, p.payment_date, p.collection_type
        FROM payments p
        JOIN isp_payout_queue q ON q.payment_id = p.id
        WHERE p.collection_type <> 'platform'
    ",
    'recorded by the platform paybill/till C2B handler' => "
        SELECT p.id, p.tenant_id, p.amount, p.transaction_id, p.payment_date, p.collection_type
        FROM payments p
        WHERE p.collection_type <> 'platform'
          AND p.notes LIKE '%platform paybill%'
    ",
    // Scoped to platform-only tenants deliberately. Step 6 of the pipeline
    // charges a hotspot commission on EVERY hotspot payment, including ones the
    // ISP's own paybill received, so an unqualified commission join would
    // re-tag legitimately direct money as platform.
    'a platform commission was charged on it' => "
        SELECT p.id, p.tenant_id, p.amount, p.transaction_id, p.payment_date, p.collection_type
        FROM payments p
        JOIN platform_commissions pc ON pc.payment_id = p.id
        WHERE p.collection_type <> 'platform'
          AND p.tenant_id IN ($platformOnlyIn)
    ",
    // The catch-all for rows the inline stkQuery path booked 'direct' and left
    // no payout behind. Cash and tenant-own C2B are excluded: neither can be
    // platform-collected by definition.
    'tenant has no M-Pesa paybill of their own — platform took it' => "
        SELECT p.id, p.tenant_id, p.amount, p.transaction_id, p.payment_date, p.collection_type
        FROM payments p
        WHERE p.collection_type <> 'platform'
          AND p.status = 'completed'
          AND p.tenant_id IN ($platformOnlyIn)
          AND p.payment_method LIKE 'mpesa%'
          AND p.payment_method <> 'mpesa_c2b_tenant'
    ",
];

if ($onlyTenant) {
    foreach ($claims as $k => $sql) {
        $claims[$k] = $sql . ' AND p.tenant_id = ' . $onlyTenant . ' ';
    }
}

echo "Tenants with no M-Pesa credentials of their own (platform collects for them): "
   . ($platformOnlyTenants ? implode(', ', $platformOnlyTenants) : 'none') . "\n\n";

$toFix = [];   // payment_id => reason
foreach ($claims as $reason => $sql) {
    try {
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        echo "  (skipping check \"$reason\": " . $e->getMessage() . ")\n";
        continue;
    }
    echo sprintf("  %-52s %d row(s)\n", $reason, count($rows));
    foreach ($rows as $r) {
        if (!isset($toFix[$r['id']])) {
            $toFix[$r['id']] = ['reason' => $reason, 'row' => $r];
        }
    }
}

if (!$toFix) {
    exit("\nNothing to re-tag — every payment's collection_type already matches its evidence.\n");
}

echo "\n" . count($toFix) . " payment(s) are tagged 'direct' but the money is held by the platform:\n\n";

$byTenant = [];
foreach ($toFix as $id => $info) {
    $r = $info['row'];
    $byTenant[$r['tenant_id']]['count'] = ($byTenant[$r['tenant_id']]['count'] ?? 0) + 1;
    $byTenant[$r['tenant_id']]['amount'] = ($byTenant[$r['tenant_id']]['amount'] ?? 0) + (float)$r['amount'];
}
foreach ($byTenant as $tid => $agg) {
    $name = '';
    try {
        $st = $pdo->prepare("SELECT company_name FROM tenants WHERE id = ?");
        $st->execute([$tid]);
        $name = (string)$st->fetchColumn();
    } catch (Throwable $e) { /* ignore */ }
    printf("  tenant %-4s %-30s %4d payment(s)  KSH %s\n",
        $tid, substr($name, 0, 30), $agg['count'], number_format($agg['amount'], 2));
}

if (!$apply) {
    echo "\nNothing was written. Re-run with --apply to commit.\n";
    exit;
}

$ids = array_keys($toFix);
$done = 0;
foreach (array_chunk($ids, 500) as $chunk) {
    $in = implode(',', array_fill(0, count($chunk), '?'));
    $st = $pdo->prepare("UPDATE payments SET collection_type = 'platform' WHERE id IN ($in)");
    $st->execute($chunk);
    $done += $st->rowCount();
}

echo "\nRe-tagged $done payment(s) as 'platform'.\n";

// Re-tagging alone is only half the repair. Step 7 of the pipeline is what
// actually queues the disbursement, and it never ran for a row booked
// 'direct' - so this money would read "Awaiting disbursement" to the ISP with
// nothing for FortuNett to release. Queue the ones that are missing.
$queued = 0;
$queueErrors = 0;
foreach (array_keys($toFix) as $pid) {
    try {
        $st = $pdo->prepare("
            SELECT p.id, p.tenant_id, p.amount, p.transaction_id, p.released_at,
                   COALESCE(pc.commission_amount, 0) AS commission_amount
            FROM payments p
            LEFT JOIN platform_commissions pc ON pc.payment_id = p.id
            WHERE p.id = ? LIMIT 1
        ");
        $st->execute([$pid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) continue;

        // Already disbursed - nothing to queue, the money has gone.
        if (!empty($row['released_at'])) continue;

        $exists = $pdo->prepare("SELECT id FROM isp_payout_queue WHERE payment_id = ? LIMIT 1");
        $exists->execute([$pid]);
        if ($exists->fetchColumn()) continue;

        $gross      = (float)$row['amount'];
        $commission = (float)$row['commission_amount'];
        $pdo->prepare("
            INSERT INTO isp_payout_queue
                (tenant_id, payment_id, gross_amount, commission_amount, net_amount,
                 receipt, scheduled_for, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            (int)$row['tenant_id'], $pid, $gross, $commission,
            round($gross - $commission, 2), $row['transaction_id'],
            date('Y-m-d', strtotime('+3 days')),
            'Backfilled by repair_collection_type.php - payment was mis-booked as direct',
        ]);
        $queued++;
    } catch (Throwable $e) {
        $queueErrors++;
        error_log("repair_collection_type queue payment $pid: " . $e->getMessage());
    }
}

echo "Queued $queued missing ISP payout(s)"
   . ($queueErrors ? " ($queueErrors could not be queued - see the error log)" : '') . ".\n";
echo "Their ISPs' float on billing.php and the super-admin Collections view now\n";
echo "reflect money FortuNett is actually holding. Rows already released\n";
echo "(released_at set) keep that release — only the tag changed.\n";
