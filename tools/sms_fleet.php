<?php
/**
 * Verify every tenant's SMS setup in one pass.
 *
 *   php tools/sms_fleet.php            probe each tenant's effective credentials
 *   php tools/sms_fleet.php --no-probe configuration only, no network calls
 *
 * Shares includes/sms_verify.php with super_admin/sms_status.php, so the shell
 * and the browser can never report different verdicts for the same tenant.
 */
require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/../includes/sms_verify.php';

$probe = !in_array('--no-probe', $argv, true);

$rows = smsVerifyAllTenants($pdo, $probe);
if (!$rows) { echo "No tenants found.\n"; exit(1); }

$counts = [];
echo str_repeat('=', 100), "\n";
printf("%-4s %-28s %-9s %-26s %-9s %s\n", 'ID', 'Tenant', 'Sends as', 'Verdict', 'Last 30d', 'Sender');
echo str_repeat('-', 100), "\n";

foreach ($rows as $r) {
    $counts[$r['verdict']] = ($counts[$r['verdict']] ?? 0) + 1;
    printf(
        "%-4s %-28s %-9s %-26s %-9s %s\n",
        $r['tenant_id'],
        substr((string)$r['company_name'], 0, 28),
        $r['source'],
        smsVerdictLabel($r['verdict']),
        $r['sent_30d'] . '/' . ($r['sent_30d'] + $r['failed_30d']),
        $r['sender_id'] ?: '—'
    );
    if ($r['verdict'] !== 'ok') {
        if ($r['detail']) echo "       ", $r['detail'], "\n";
        if ($r['action']) echo "       -> ", $r['action'], "\n";
        if ($r['last_error']) echo "       last failure: ", substr($r['last_error'], 0, 90), "\n";
    }
}

echo str_repeat('-', 100), "\n";
foreach (SMS_VERDICT_ORDER as $v) {
    if (!empty($counts[$v])) printf("  %-26s %d tenant(s)\n", smsVerdictLabel($v), $counts[$v]);
}
echo "\n";
if (!$probe) echo "Configuration only — re-run without --no-probe to test the credentials against the provider.\n";
