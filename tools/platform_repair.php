<?php
/**
 * Fix every platform-level break that can be fixed mechanically, in one run.
 *
 *   php tools/platform_repair.php                        # dry run — changes nothing
 *   php tools/platform_repair.php --apply
 *   php tools/platform_repair.php --apply --install-cron
 *   php tools/platform_repair.php --apply --base-url=https://fortunetttech.site
 *   php tools/platform_repair.php --apply --external-ip=41.90.x.x
 *
 * The companion to super_admin/diagnostics.php: that page says what is broken,
 * this fixes what a machine can fix and reports precisely what is left.
 *
 * The repairs themselves live in includes/platform_repair.php, shared with the
 * button on the diagnostics page — a fix that behaves one way from the browser
 * and another from the shell is worse than no fix.
 *
 * Two things are CLI-only, on purpose:
 *
 *   --install-cron   needs a shell the web user usually does not have, and is
 *                    the one repair that touches the machine rather than the
 *                    database.
 *   the credentials  a Daraja secret, a TalkSasa token, an SMTP password. Only
 *                    you hold them; inventing a default is worse than empty.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/../includes/platform_repair.php';

$APPLY        = in_array('--apply', $argv, true);
$INSTALL_CRON = in_array('--install-cron', $argv, true);
$opts = ['apply' => $APPLY];
foreach ($argv as $a) {
    if (strpos($a, '--base-url=')    === 0) $opts['base_url']    = trim(substr($a, 11));
    if (strpos($a, '--external-ip=') === 0) $opts['external_ip'] = trim(substr($a, 14));
}

$ROOT = dirname(__DIR__);

echo "FortuNett platform repair — " . ($APPLY ? 'APPLYING CHANGES' : 'DRY RUN (nothing is written; add --apply)') . "\n";
echo "Install root: {$ROOT}\n";

$LABEL = [
    'ok'     => '[ok]     ',
    'fixed'  => '[FIXED]  ',
    'would'  => '[would]  ',
    'manual' => '[YOU]    ',
    'error'  => '[ERROR]  ',
];

$result = platformRepairRun($pdo, $opts);

echo "\nDatabase, settings and registration\n";
echo str_repeat('=', 35) . "\n";
foreach ($result['steps'] as $s) {
    echo '  ' . $LABEL[$s['status']] . $s['title'] . "\n";
    echo '            ' . wordwrap($s['detail'], 96, "\n            ", false) . "\n";
    if (!empty($s['action'])) {
        echo '            -> ' . wordwrap($s['action'], 93, "\n               ", false) . "\n";
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Crontab — CLI only.
//
// A crontab line that was never added is invisible: nothing errors, the work
// simply never happens. stk_poll.php was absent for a long time and nothing in
// the system could say so, which is why every one of these stamps a heartbeat.
// ─────────────────────────────────────────────────────────────────────────────
echo "\nScheduled jobs\n" . str_repeat('=', 14) . "\n";

$php  = PHP_BINARY ?: 'php';
$jobs = [
    ['stk_poll',            '*/2 * * * *',  'fortunett_stk_poll'],
    ['retry_provisions',    '*/5 * * * *',  'fortunett_provision'],
    ['check_expiry',        '*/15 * * * *', 'fortunett_expiry'],
    ['check_router_status', '*/5 * * * *',  'fortunett_router_status'],
    ['sync_hotspot_pages',  '30 * * * *',   'fortunett_portal_sync'],
    ['check_suspensions',   '0 8 * * *',    'fortunett_suspensions'],
    ['monthly_billing',     '0 6 1 * *',    'fortunett_billing'],
];

$cronInstalled = 0;
$cronPending   = [];

if (stripos(PHP_OS_FAMILY, 'Windows') === 0) {
    echo "  [skip]    crontab is not available on Windows — run this on the server.\n";
} else {
    $current = @shell_exec('crontab -l 2>/dev/null') ?: '';
    foreach ($jobs as [$name, $sched, $log]) {
        if (strpos($current, "cron/{$name}.php") !== false) {
            echo "  [ok]      cron/{$name}.php already scheduled.\n";
            continue;
        }
        $cronPending[] = "{$sched} {$php} {$ROOT}/cron/{$name}.php >> /var/log/{$log}.log 2>&1";
    }

    // cron/disburse_payouts.php is deliberately NOT auto-installed. It sends
    // real money and M-Pesa has no chargeback; the documented practice is to
    // watch several dry runs before adding --live to crontab.
    if (!$cronPending) {
        echo "  [ok]      Every scheduled job is present.\n";
    } elseif (!$INSTALL_CRON) {
        echo "  " . count($cronPending) . " job(s) missing. Re-run with --apply --install-cron to add them,\n";
        echo "  or paste these into `crontab -e` yourself:\n\n";
        foreach ($cronPending as $m) echo "    {$m}\n";
    } elseif (!$APPLY) {
        foreach ($cronPending as $m) echo "  [would]   crontab += {$m}\n";
    } else {
        $backup = sys_get_temp_dir() . '/crontab-backup-' . date('Ymd-His') . '.txt';
        @file_put_contents($backup, $current);

        $new = rtrim($current, "\n") . "\n"
             . "\n# FortuNett — added by tools/platform_repair.php on " . date('Y-m-d H:i:s') . "\n"
             . implode("\n", $cronPending) . "\n";
        $tmp = tempnam(sys_get_temp_dir(), 'ftcron');
        file_put_contents($tmp, $new);
        $out = @shell_exec('crontab ' . escapeshellarg($tmp) . ' 2>&1');
        @unlink($tmp);

        // Verify by reading it back rather than trusting the exit of a shell
        // command — a crontab that silently did not take is exactly the failure
        // this whole section exists to catch.
        $after = @shell_exec('crontab -l 2>/dev/null') ?: '';
        $stillMissing = 0;
        foreach ($jobs as [$name, , ]) if (strpos($after, "cron/{$name}.php") === false) $stillMissing++;

        if ($stillMissing === 0) {
            $cronInstalled = count($cronPending);
            echo "  [FIXED]   {$cronInstalled} crontab line(s) installed.\n";
            echo "            Previous crontab backed up to {$backup}\n";
        } else {
            echo "  [ERROR]   crontab install did not take" . ($out ? ': ' . trim($out) : '.') . "\n";
            echo "            Paste the lines above into `crontab -e` manually.\n";
            echo "            Backup of the old crontab: {$backup}\n";
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSummary\n" . str_repeat('=', 7) . "\n";
$c = $result['counts'];
if ($APPLY) {
    echo "  Repaired automatically : " . ($c['fixed'] + $cronInstalled) . "\n";
} else {
    echo "  Would repair           : " . ($c['would'] + count($cronPending)) . "   (re-run with --apply"
       . ($cronPending ? ' --install-cron' : '') . ")\n";
}
echo "  Already correct        : {$c['ok']}\n";
echo "  Needs a value from you : {$c['manual']}\n";
if ($c['error']) echo "  Errored                : {$c['error']}\n";

if ($result['manual']) {
    echo "\n  Left for you — these are values only you hold:\n";
    foreach ($result['manual'] as $i => $s) {
        echo '   ' . ($i + 1) . ". {$s['title']} — " . wordwrap($s['detail'], 88, "\n      ", false) . "\n";
        if (!empty($s['action'])) echo "      -> " . wordwrap($s['action'], 88, "\n         ", false) . "\n";
    }
}

echo "\nRe-run super_admin/diagnostics.php to confirm. Anything still failing there is either\n";
echo "in the list above or genuinely per-tenant.\n";
