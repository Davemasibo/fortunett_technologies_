<?php
/**
 * Why didn't the customer get connected after paying?
 *
 * Walks the whole auto-activation chain and reports which link is broken:
 *
 *   Safaricom → C2B/STK callback URL → handler → payment_pipeline
 *   → clients.status='active' → autoProvisionClient() → router
 *
 * The most common failure is the first link: Safaricom rejects any callback URL
 * containing the word "mpesa", so a C2B registration made against the old
 * /api/mpesa/ paths silently never registered, and confirmations are never
 * delivered. Nothing downstream ever runs, so accounts stay unactivated.
 *
 * CLI:      php tools/diagnose_autoactivation.php [--tenant=ID] [--days=7]
 * Browser:  /tools/diagnose_autoactivation.php    (localhost only)
 */

$cli = (PHP_SAPI === 'cli');
if (!$cli) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($ip, ['127.0.0.1', '::1'], true)) {
        http_response_code(403);
        exit('Access denied. Run from localhost or the CLI.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/../includes/credential_helper.php';

$opts       = $cli ? getopt('', ['tenant::', 'days::']) : $_GET;
$onlyTenant = (int)($opts['tenant'] ?? 0);
$days       = max(1, (int)($opts['days'] ?? 7));

$logDir  = __DIR__ . '/../logs';
$issues  = [];

function h(string $t): void { echo "\n" . $t . "\n" . str_repeat('─', 74) . "\n"; }
function bad(string $m): void { global $issues; $issues[] = $m; echo "  [X] $m\n"; }
function ok(string $m): void { echo "  [OK] $m\n"; }
function info(string $m): void { echo "       $m\n"; }

echo "Auto-activation diagnostic — " . date('Y-m-d H:i:s') . "\n";
echo "Looking at the last $days day(s)\n";

// ── 1. Callback log files: did Safaricom ever reach us? ───────────────────────
h('1. Inbound callback logs (proof Safaricom is reaching this server)');
$logs = [
    'mpesa_c2b.log'      => 'Platform shared-paybill C2B confirmations',
    'tenant_c2b.log'     => 'Tenant-own-paybill C2B registrations',
    'mpesa_callbacks.log' => 'STK push callbacks',
    'mpesa_errors.log'   => 'Handler errors',
];
foreach ($logs as $file => $what) {
    $path = $logDir . '/' . $file;
    if (!file_exists($path)) {
        echo "  [ ] $file — missing ($what)\n";
        continue;
    }
    $size  = filesize($path);
    $mtime = date('Y-m-d H:i', filemtime($path));
    $age   = (time() - filemtime($path)) / 86400;
    printf("  [%s] %-22s %6d bytes, last write %s%s\n",
        $size > 0 ? 'OK' : ' ', $file, $size, $mtime,
        $age > $days ? '  (STALE)' : '');
    info("     $what");
}
if (!file_exists($logDir . '/mpesa_c2b.log') && !file_exists($logDir . '/tenant_c2b.log')) {
    bad('No C2B log at all — Safaricom has never delivered a C2B confirmation to this server.');
    info('This is the classic symptom of a C2B URL that was never accepted by Safaricom.');
}

// ── 2. Per-tenant M-Pesa wiring ───────────────────────────────────────────────
h('2. M-Pesa wiring per tenant');
$tSql = "SELECT id, subdomain, company_name FROM tenants" . ($onlyTenant ? " WHERE id = $onlyTenant" : "");
foreach ($pdo->query($tSql)->fetchAll(PDO::FETCH_ASSOC) as $t) {
    $tid = (int)$t['id'];
    echo "\n  Tenant #$tid — " . ($t['company_name'] ?: $t['subdomain']) . "\n";

    $gw = null;
    try {
        $g = $pdo->prepare("SELECT * FROM payment_gateways WHERE tenant_id = ? AND gateway_type='mpesa_api' AND is_active=1 ORDER BY is_default DESC LIMIT 1");
        $g->execute([$tid]);
        $gw = $g->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}

    if ($gw) {
        $c = [];
        try { $c = decrypt_gateway_credentials($gw['credentials']) ?: []; } catch (Throwable $e) {}
        $complete = !empty($c['consumer_key']) && !empty($c['consumer_secret'])
                 && !empty($c['passkey']) && !empty($c['shortcode']);
        echo "    mode: OWN PAYBILL (shortcode " . ($c['shortcode'] ?? '?') . ")\n";
        $complete ? ok('credentials complete') : bad("tenant #$tid own-paybill credentials incomplete — STK will fall back to platform");

        if (empty($c['c2b_registered'])) {
            bad("tenant #$tid has NOT registered C2B URLs — paybill payments will never auto-activate");
            info('Fix: Payments -> M-Pesa gateway -> Register C2B');
        } else {
            $conf = $c['c2b_confirmation_url'] ?? '';
            if (stripos($conf, '/api/mpesa/') !== false) {
                bad("tenant #$tid C2B confirmation URL still uses the retired /api/mpesa/ path: $conf");
                info('Safaricom rejects URLs containing "mpesa" — re-register C2B.');
            } else {
                ok('C2B registered ' . ($c['c2b_registered_at'] ?? '') . ' -> ' . $conf);
            }
        }
        if (!empty($c['callback_url']) && stripos($c['callback_url'], '/api/mpesa/') !== false) {
            bad("tenant #$tid STK callback_url still points at /api/mpesa/ — run tools/fix_mpesa_callback_urls.php");
        }
    } else {
        echo "    mode: PLATFORM SHARED PAYBILL\n";
        // Platform routing needs an account_prefix on the tenant's admin user
        $p = $pdo->prepare("SELECT account_prefix FROM users WHERE tenant_id = ? AND role='admin' AND account_prefix IS NOT NULL AND account_prefix <> '' LIMIT 1");
        $p->execute([$tid]);
        $prefix = $p->fetchColumn();
        if ($prefix) {
            ok("account_prefix '$prefix' set — BillRefNumber like {$prefix}0001 will route here");
        } else {
            bad("tenant #$tid has NO account_prefix — platform C2B cannot route payments to this tenant at all");
            info('Every payment to the shared paybill for this tenant logs as UNROUTABLE.');
        }
    }
}

// ── 3. Platform paybill config ────────────────────────────────────────────────
h('3. Platform shared-paybill config');
try {
    $cfg = $pdo->query("SELECT * FROM platform_mpesa_config LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$cfg) {
        info('No platform_mpesa_config row — platform paybill not in use.');
    } else {
        $cb = $cfg['callback_url'] ?? '';
        echo "  shortcode:    " . ($cfg['shortcode'] ?? '?') . "\n";
        echo "  callback_url: " . ($cb ?: '(auto-detected per request)') . "\n";
        if (stripos($cb, '/api/mpesa/') !== false) {
            bad('Platform callback_url uses the retired /api/mpesa/ path — run tools/fix_mpesa_callback_urls.php');
        } elseif ($cb) {
            ok('callback_url uses a current path');
        }
        info('The platform C2B confirmation URL must be registered MANUALLY in the Daraja portal as:');
        info('  https://<your-domain>/api/payment/c2b_confirmation.php');
        info('There is no in-app button for the platform shortcode — only for tenant-owned paybills.');
    }
} catch (Throwable $e) {
    info('platform_mpesa_config not present: ' . $e->getMessage());
}

// ── 4. The actual symptom: money in, account not active ───────────────────────
h("4. Paid but not activated (last $days days) — the symptom you are seeing");
try {
    $sql = "SELECT p.id, p.client_id, p.tenant_id, p.amount, p.payment_method,
                   p.transaction_id, p.payment_date, p.status AS pay_status,
                   c.full_name, c.status AS client_status, c.expiry_date, c.package_id
            FROM payments p
            JOIN clients c ON c.id = p.client_id
            WHERE p.status = 'completed'
              AND p.payment_date >= DATE_SUB(NOW(), INTERVAL $days DAY)
              AND (c.status <> 'active' OR c.expiry_date IS NULL OR c.expiry_date < NOW())"
         . ($onlyTenant ? " AND p.tenant_id = $onlyTenant" : "")
         . " ORDER BY p.payment_date DESC LIMIT 40";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        ok('No completed payment left an account inactive. The pipeline is doing its job.');
    } else {
        bad(count($rows) . ' completed payment(s) did NOT leave the client active');
        foreach ($rows as $r) {
            printf("       client#%-5s %-22s paid %7s via %-14s on %s -> status=%s expiry=%s%s\n",
                $r['client_id'], substr((string)$r['full_name'], 0, 22), $r['amount'],
                $r['payment_method'], $r['payment_date'], $r['client_status'],
                $r['expiry_date'] ?: 'NULL',
                $r['package_id'] ? '' : '  [NO PACKAGE -> no expiry can be computed]');
        }
        info('');
        info('If payment_method is mpesa_paybill, the C2B handler DID run and the pipeline failed.');
        info('If these payments were entered manually, the callback never arrived (link 1).');
    }
} catch (Throwable $e) {
    info('query failed: ' . $e->getMessage());
}

// ── 5. Provisioning retry queue ───────────────────────────────────────────────
h('5. Provisioning queue (activated in the DB but not pushed to a router)');
try {
    $rows = $pdo->query("SELECT client_id, tenant_id, attempts, last_error, created_at
                         FROM pending_provisions ORDER BY created_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        ok('Queue empty — nothing is stuck waiting for a router.');
    } else {
        bad(count($rows) . ' client(s) waiting to be provisioned on a router');
        foreach ($rows as $r) {
            printf("       client#%-5s tenant#%-3s attempts=%-2s %s — %s\n",
                $r['client_id'], $r['tenant_id'], $r['attempts'], $r['created_at'],
                substr((string)$r['last_error'], 0, 60));
        }
        info('cron/retry_provisions.php drains this queue — confirm it is in crontab.');
    }
} catch (Throwable $e) {
    info('pending_provisions not present: ' . $e->getMessage());
}

// ── 6. Clients that cannot activate even on a good payment ────────────────────
h('6. Clients with no package (a payment cannot compute an expiry for these)');
try {
    $sql = "SELECT id, full_name, phone, status FROM clients
            WHERE (package_id IS NULL OR package_id = 0)"
         . ($onlyTenant ? " AND tenant_id = $onlyTenant" : "")
         . " LIMIT 15";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        ok('Every client has a package assigned.');
    } else {
        bad(count($rows) . ' client(s) have no package — payment marks them active but sets no expiry');
        foreach ($rows as $r) {
            printf("       client#%-5s %-24s %s (%s)\n", $r['id'], $r['full_name'], $r['phone'], $r['status']);
        }
    }
} catch (Throwable $e) {
    info('query failed: ' . $e->getMessage());
}

// ── Summary ───────────────────────────────────────────────────────────────────
h('SUMMARY');
if (!$issues) {
    echo "  No problems found in the auto-activation chain.\n";
} else {
    echo "  " . count($issues) . " issue(s) to fix, most likely cause first:\n\n";
    foreach ($issues as $i => $m) {
        echo "   " . ($i + 1) . ". $m\n";
    }
}
echo "\n";
