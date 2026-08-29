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
 * Idempotent — re-running changes nothing once the rows are correct.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../includes/db_master.php';

$apply = in_array('--apply', $argv, true);
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
    'a platform commission was charged on it' => "
        SELECT p.id, p.tenant_id, p.amount, p.transaction_id, p.payment_date, p.collection_type
        FROM payments p
        JOIN platform_commissions pc ON pc.payment_id = p.id
        WHERE p.collection_type <> 'platform'
    ",
];

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
echo "Their ISPs' float on billing.php and the super-admin Collections view now\n";
echo "reflect money FortuNett is actually holding. Rows already released\n";
echo "(released_at set) keep that release — only the tag changed.\n";
