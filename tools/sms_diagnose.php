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

// "a row exists" is not "a usable configuration", and the difference is the
// whole reason platform SMS looked configured while nothing sent: a tenant row
// with a blank key used to shadow the platform key permanently.
$ownUsable = smsConfigIsUsable($own);
echo "  own config     : " . ($own
    ? ($ownUsable ? 'YES' : 'row exists but has NO api_key — falls back to the platform key')
    : 'no — will fall back to the platform key') . "\n";
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
    if (smsApiUrlIsStale($own['api_url'] ?? null)) {
        echo "    !! stored api_url is the dead TalkSasa v1 endpoint. It is normalised\n";
        echo "       to " . SMS_API_URL_DEFAULT . " on read, so sending still works,\n";
        echo "       but save the SMS settings once to heal the stored value.\n";
    }
}
echo "  platform config: " . ($plat ? 'present — ' . mask($plat['api_key'] ?? '') : 'none') . "\n";
if ($plat && !smsConfigIsUsable($plat)) {
    echo "    !! the platform row exists but its api_key is blank. Every tenant with\n";
    echo "       no key of their own has NO working SMS. Set it under\n";
    echo "       super_admin/settings.php?tab=sms and use Send Test to prove it.\n";
}
if ($plat && smsApiUrlIsStale($plat['api_url'] ?? null)) {
    echo "    !! platform api_url is the dead TalkSasa v1 endpoint — this is the\n";
    echo "       column DEFAULT the table was created with, so it is stale on every\n";
    echo "       deployment that never edited it. Normalised on read; open the\n";
    echo "       platform SMS settings page once to heal it.\n";
}

$helper = new SMSHelper($pdo, $tenantId);
echo "  EFFECTIVE      : " . ($helper->isUsingPlatform() ? 'PLATFORM key' : ($helper->hasConfig() ? "this tenant's own key" : 'NONE — sending is disabled')) . "\n";

if (!$helper->hasConfig()) {
    exit("\nNo usable SMS configuration. Add one under Settings on sms.php.\n");
}

// Reach the endpoint without sending, to separate "wrong URL" from "wrong key".
// Resolved through the same function SMSHelper uses -- deriving it separately
// meant the diagnostic could probe one endpoint while the sender used another,
// which is the one thing a diagnostic must never do.
[$effectiveCfg, $effectivePlatform] = smsResolveConfig($pdo, $tenantId);
$url = smsNormalizeApiUrl($effectiveCfg['api_url'] ?? null);
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

// ── Is the TOKEN wrong, or the REQUEST wrong? ────────────────────────────────
// Sending is the worst way to test a credential: a failure could be the token,
// the payload shape, the sender ID, or the account balance, and the provider
// answers all of them with the same word. A plain authenticated GET isolates
// it — if these also say "Unauthenticated." the token itself is not accepted
// and nothing about the message matters yet.
// Same resolver again. `$own['api_key'] ?? $plat['api_key']` looks right but
// `??` only fires on a MISSING key, so a tenant row holding an empty string
// resolved to '' instead of falling through to the platform key -- the probes
// below would then all report "Unauthenticated." for a key that was never sent.
$effectiveKey = trim((string)($effectiveCfg['api_key'] ?? ''));

echo "\n  Key shape:\n";
$hasPipe = strpos($effectiveKey, '|') !== false;
$isHex   = (bool)preg_match('/^[0-9a-f]+$/i', $effectiveKey);
printf("    length %d, %s\n", strlen($effectiveKey),
    $hasPipe ? "contains '|' — looks like a Laravel Sanctum token (what v3 issues)"
             : ($isHex ? "pure hexadecimal, no '|' — looks like an older v1-style API key"
                       : "mixed characters, no '|'"));
if (!$hasPipe) {
    echo "    NOTE: TalkSasa v3 tokens are issued as <id>|<random> and contain a pipe.\n";
    echo "          A key without one is usually from the older API and will never\n";
    echo "          authenticate against /api/v3, however many times it is re-pasted.\n";
}

$base = preg_replace('~/sms/send/?$~', '', $url);
foreach (['/balance', '/profile', '/sms/balance'] as $path) {
    $probe = $base . $path;
    $c = curl_init($probe);
    curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($c, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Authorization: Bearer ' . $effectiveKey,
    ]);
    curl_setopt($c, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($c, CURLOPT_TIMEOUT, 12);
    $b = curl_exec($c);
    $hc = curl_getinfo($c, CURLINFO_HTTP_CODE);

    $snippet = stripos(ltrim((string)$b), '<') === 0 ? '(HTML page)' : substr((string)$b, 0, 120);
    printf("    GET %-28s HTTP %-3d %s\n", $path, $hc, $snippet);
}

// ── Was the token truncated on the way into the column? ─────────────────────
// A Sanctum token is <id>|<random>. If the column is narrower than the token,
// MySQL outside strict mode stores a silent prefix — which still LOOKS like a
// valid token (right prefix, right pipe) and can never authenticate. A stored
// length sitting exactly on the column limit is that signature.
try {
    $ci = $pdo->prepare("
        SELECT TABLE_NAME, CHARACTER_MAXIMUM_LENGTH
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME IN ('sms_configurations','platform_sms_config')
          AND COLUMN_NAME = 'api_key'
    ");
    $ci->execute();
    echo "\n  Column widths:\n";
    foreach ($ci->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $max = (int)$c['CHARACTER_MAXIMUM_LENGTH'];
        printf("    %-22s api_key VARCHAR(%d), stored value is %d chars%s\n",
            $c['TABLE_NAME'], $max, strlen($effectiveKey),
            ($max > 0 && strlen($effectiveKey) >= $max) ? '   <== AT THE LIMIT, likely TRUNCATED' : '');
    }
} catch (Throwable $e) {
    echo "  (could not read column widths: " . $e->getMessage() . ")\n";
}

echo "\n  Reading the probes above:\n";
echo "    every one Unauthenticated  -> the TOKEN is not accepted. Generate a new\n";
echo "                                  one in the TalkSasa dashboard under API /\n";
echo "                                  Developers and paste it into Settings.\n";
echo "    one returns real data      -> the token is FINE and the problem is the\n";
echo "                                  send request itself. Send me this output.\n";

// ── Did the CUSTOMER path ever run? ─────────────────────────────────────────
// Everything above tests the credentials. It is entirely possible for the
// credentials to be perfect and for no customer to ever be texted, because the
// send sits at step 10 of process_payment_success() behind a phone number, a
// dedupe check and two tables that some deployments do not have. This section
// answers the question the credential probes cannot: was a message even tried.
echo "\n" . str_repeat('-', 72) . "\n";
echo "Customer delivery path\n";
echo str_repeat('-', 72) . "\n";

$tables = [];
foreach (['sms_outbox', 'sms_logs'] as $t) {
    try {
        $pdo->query("SELECT 1 FROM `$t` LIMIT 1");
        $tables[$t] = true;
    } catch (Throwable $e) {
        $tables[$t] = false;
    }
}
foreach ($tables as $t => $ok) {
    printf("  %-12s %s\n", $t, $ok ? 'present' : 'MISSING — run the platform repair, or open any page that sends SMS');
}
if (!$tables['sms_logs']) {
    echo "    sms_logs is written by process_payment_success() and read by the admin\n";
    echo "    dashboard. Without it there is no record that any payment SMS was ever\n";
    echo "    attempted, and Safaricom's callback retries can text a customer twice.\n";
}

if ($tables['sms_outbox']) {
    try {
        $st = $pdo->prepare("SELECT sent_at, recipient_phone, status, provider_response
                             FROM sms_outbox WHERE tenant_id = ? ORDER BY sent_at DESC LIMIT 8");
        $st->execute([$tenantId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            echo "\n  sms_outbox is EMPTY for this tenant — nothing has been handed to the\n";
            echo "  provider at all. The problem is upstream of the credentials: no send is\n";
            echo "  being reached. Check that payments are completing and that the clients\n";
            echo "  being credited have a phone number on file.\n";
        } else {
            echo "\n  Last " . count($rows) . " message(s):\n";
            foreach ($rows as $r) {
                printf("    %-19s %-14s %-7s %s\n",
                    $r['sent_at'], $r['recipient_phone'], $r['status'],
                    substr(preg_replace('/\s+/', ' ', (string)$r['provider_response']), 0, 90));
            }
        }
    } catch (Throwable $e) {
        echo "  (could not read sms_outbox: " . $e->getMessage() . ")\n";
    }
}

// Paid customers who were never texted. This is the number that matters: it is
// the customers who gave money and heard nothing back.
try {
    $st = $pdo->prepare("
        SELECT COUNT(*) FROM payments p
        JOIN clients c ON c.id = p.client_id AND c.tenant_id = p.tenant_id
        WHERE p.tenant_id = ? AND p.status = 'completed'
          AND p.payment_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $st->execute([$tenantId]);
    $paid = (int)$st->fetchColumn();

    $texted = 0;
    if ($tables['sms_outbox']) {
        $st2 = $pdo->prepare("SELECT COUNT(*) FROM sms_outbox
                              WHERE tenant_id = ? AND status = 'sent'
                                AND sent_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $st2->execute([$tenantId]);
        $texted = (int)$st2->fetchColumn();
    }
    printf("\n  Last 7 days: %d completed payment(s), %d SMS recorded as sent.\n", $paid, $texted);
    if ($paid > 0 && $texted === 0) {
        echo "  Every payment went untold. If sms_outbox has failed rows above, the\n";
        echo "  provider is refusing them — read the response. If it is empty, the send\n";
        echo "  is never being reached.\n";
    }
} catch (Throwable $e) {
    echo "  (could not compare payments to messages: " . $e->getMessage() . ")\n";
}

// A client with no phone number is skipped in silence at step 10.
try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM clients
                         WHERE tenant_id = ? AND (phone IS NULL OR TRIM(phone) = '')");
    $st->execute([$tenantId]);
    $noPhone = (int)$st->fetchColumn();
    if ($noPhone) {
        printf("  %d client(s) have no phone number and can never be texted.\n", $noPhone);
    }
} catch (Throwable $e) { /* optional */ }

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
