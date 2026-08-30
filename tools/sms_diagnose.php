<?php
/**
 * Show exactly which SMS credentials a tenant is using and what the provider
 * says about them.
 *
 *   php tools/sms_diagnose.php --tenant=5
 *   php tools/sms_diagnose.php --tenant=5 --send=254712345678
 *   php tools/sms_diagnose.php --fix-whitespace          # clean stored keys
 *
 * Why this exists
 * ---------------
 * "Unauthenticated." is all the provider returns, and from the admin UI there
 * is no way to tell WHICH key it rejected: a tenant with no SMS config of their
 * own silently falls back to the platform key, so the operator can be editing
 * a field that is not being used at all. This prints the effective config, the
 * URL actually contacted after redirects, and the raw response body.
 *
 * Keys are masked to first/last four characters — enough to compare against the
 * provider dashboard without pasting a live credential into a terminal log.
 * --send transmits a REAL message and costs real credit.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/../classes/SMSHelper.php';

$tenantId = 0;
$sendTo   = '';
foreach ($argv as $a) {
    if (strpos($a, '--tenant=') === 0) $tenantId = (int)substr($a, 9);
    if (strpos($a, '--send=')   === 0) $sendTo   = substr($a, 7);
}

// ── Clean whitespace out of every stored credential ──────────────────────────
// SMSHelper trims at send time and saveConfig() trims on save, so this is only
// for rows written before those existed. Worth doing anyway: the settings form
// shows the raw column, so an operator comparing it against the provider
// dashboard sees a value that looks identical to the correct one.
if (in_array('--fix-whitespace', $argv, true)) {
    $fixed = 0;
    foreach ([
        ['sms_configurations',  ['api_key', 'sender_id', 'api_url', 'provider']],
        ['platform_sms_config', ['api_key', 'sender_id', 'api_url', 'provider']],
    ] as [$table, $cols]) {
        foreach ($cols as $col) {
            try {
                $st = $pdo->prepare("UPDATE `$table` SET `$col` = TRIM(`$col`) WHERE `$col` <> TRIM(`$col`)");
                $st->execute();
                if ($st->rowCount()) {
                    printf("  %-22s %-10s cleaned %d row(s)\n", $table, $col, $st->rowCount());
                    $fixed += $st->rowCount();
                }
            } catch (Throwable $e) {
                printf("  %-22s %-10s skipped: %s\n", $table, $col, $e->getMessage());
            }
        }
    }
    echo $fixed ? "\nCleaned $fixed value(s).\n" : "\nNothing needed cleaning.\n";
    if (!$tenantId) exit;
    echo "\n";
}

if (!$tenantId) {
    echo "Usage: php tools/sms_diagnose.php --tenant=<id> [--send=2547XXXXXXXX]\n\n";
    echo "Tenants:\n";
    foreach ($pdo->query("SELECT id, company_name, subdomain FROM tenants ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $t) {
        printf("  %-4s %-32s %s\n", $t['id'], $t['company_name'], $t['subdomain']);
    }
    exit;
}

function mask(?string $v): string
{
    $v = trim((string)$v);
    if ($v === '') return '(empty)';
    if (strlen($v) <= 8) return str_repeat('*', strlen($v)) . ' — ' . strlen($v) . ' chars';
    return substr($v, 0, 4) . str_repeat('*', strlen($v) - 8) . substr($v, -4) . ' — ' . strlen($v) . ' chars';
}

echo str_repeat('=', 72) . "\n";
printf("Tenant %d\n", $tenantId);
echo str_repeat('-', 72) . "\n";

// Their own config
$own = null;
try {
    $st = $pdo->prepare("SELECT * FROM sms_configurations WHERE tenant_id = ? AND is_active = 1 LIMIT 1");
    $st->execute([$tenantId]);
    $own = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    echo "  (sms_configurations unreadable: " . $e->getMessage() . ")\n";
}

// The platform fallback
$plat = null;
try {
    $plat = $pdo->query("SELECT * FROM platform_sms_config WHERE id = 1 AND is_active = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) { /* table may not exist */ }

echo "  own config     : " . ($own ? 'YES' : 'no — will fall back to the platform key') . "\n";
if ($own) {
    printf("    provider     : %s\n", $own['provider']  ?? '');
    printf("    api_url      : %s\n", $own['api_url']   ?? '(not set)');
    printf("    api_key      : %s\n", mask($own['api_key'] ?? ''));
    printf("    sender_id    : %s\n", $own['sender_id'] ?? '');
    $raw = (string)($own['api_key'] ?? '');
    if ($raw !== trim($raw)) {
        echo "    !! the stored key has leading/trailing whitespace — a bearer header\n";
        echo "       with a stray newline is rejected as \"Unauthenticated.\"\n";
    }
}
echo "  platform config: " . ($plat ? 'present — ' . mask($plat['api_key'] ?? '') : 'none') . "\n";

$helper = new SMSHelper($pdo, $tenantId);
echo "  EFFECTIVE      : " . ($helper->isUsingPlatform() ? 'PLATFORM key' : ($helper->hasConfig() ? "this tenant's own key" : 'NONE — sending is disabled')) . "\n";

if (!$helper->hasConfig()) {
    exit("\nNo usable SMS configuration. Add one under Settings on sms.php.\n");
}

// Reach the endpoint without sending, to separate "wrong URL" from "wrong key"
$url = trim((string)($own['api_url'] ?? ($plat['api_url'] ?? 'https://bulksms.talksasa.com/api/v3/sms/send')));
echo "\n  Contacting " . $url . " ...\n";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['ping' => 1]));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_POSTREDIR, CURL_REDIR_POST_ALL);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
$body  = curl_exec($ch);
$code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$final = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
$err   = curl_error($ch);
// No curl_close(): handles are objects since PHP 8.0 and the call is a
// deprecated no-op. The handle is freed when $ch goes out of scope.

if ($err) {
    echo "  network error: $err\n";
} else {
    printf("  HTTP %d, final URL %s\n", $code, $final);
    if ($final !== $url) {
        echo "  NOTE: the URL redirected. Save the final URL above under Settings to\n";
        echo "        avoid a wasted round trip on every message.\n";
    }
    if (stripos(ltrim((string)$body), '<') === 0) {
        echo "  the response is HTML, not JSON — this URL is not the API endpoint.\n";
        echo "  TalkSasa v3: https://bulksms.talksasa.com/api/v3/sms/send\n";
    } else {
        echo "  unauthenticated probe replied: " . substr((string)$body, 0, 200) . "\n";
        echo "  (an auth complaint here is EXPECTED — it proves the endpoint is live.)\n";
    }
}

if ($sendTo === '') {
    echo "\nNo message sent. Add --send=2547XXXXXXXX to try a real one (costs credit).\n";
    exit;
}

echo "\n  Sending a real test message to $sendTo ...\n";
$res = $helper->send($sendTo, 'FortuNett test message — ' . date('H:i:s'), null);
echo "  success : " . (!empty($res['success']) ? 'YES' : 'no') . "\n";
echo "  message : " . ($res['message'] ?? '') . "\n";
if (!empty($res['response'])) {
    echo "  raw     : " . substr((string)$res['response'], 0, 300) . "\n";
}
