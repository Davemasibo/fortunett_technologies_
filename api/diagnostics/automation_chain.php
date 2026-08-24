<?php
/**
 * End-to-end automation verification: money in → internet on.
 *
 * Walks every link in the chain that has to hold for "customer pays, customer
 * gets connected, nobody touches anything" to be true, and reports which link
 * is broken. Grouped so a failure is immediately attributable:
 *
 *   collection  — can money reach us and be recognised?
 *   schema      — will the handlers survive writing it down?
 *   activation  — does confirmed money turn into router access?
 *   evidence    — what actually happened over the last 7 days
 *
 * Everything is read-only. Counts come from the tenant's own rows only.
 *
 * POST (no params) — tenant is taken from the session.
 */
ob_start();
ini_set('display_errors', 0);
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/db_master.php';
require_once __DIR__ . '/../../includes/cron_heartbeat.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    ob_clean(); echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit;
}

$t = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$t->execute([$_SESSION['user_id']]);
$tenantId = (int)$t->fetchColumn();
if (!$tenantId) { ob_clean(); echo json_encode(['success' => false, 'error' => 'No tenant']); exit; }

$groups = [
    'collection' => ['title' => 'Collecting the money',   'checks' => []],
    'schema'     => ['title' => 'Recording it safely',    'checks' => []],
    'activation' => ['title' => 'Granting access',        'checks' => []],
    'evidence'   => ['title' => 'Last 7 days',            'checks' => []],
];

$add = function (string $group, string $label, string $level, string $detail, ?string $action = null) use (&$groups) {
    $groups[$group]['checks'][] = ['label' => $label, 'level' => $level, 'detail' => $detail, 'action' => $action];
};

// ═══ A. Collection ════════════════════════════════════════════════════════════

// 1. M-Pesa gateway
$gw = null;
try {
    $g = $pdo->prepare("SELECT * FROM payment_gateways WHERE tenant_id = ? AND gateway_type = 'mpesa_api' ORDER BY id DESC LIMIT 1");
    $g->execute([$tenantId]);
    $gw = $g->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $_e) {}

$creds = [];
if ($gw) {
    try {
        require_once __DIR__ . '/../../includes/credential_helper.php';
        $creds = decrypt_gateway_credentials($gw['credentials']) ?: [];
    } catch (Throwable $_e) { $creds = []; }
}

if (!$gw) {
    $add('collection', 'M-Pesa gateway', 'fail',
        'No M-Pesa API gateway saved for this tenant. Nothing can be collected automatically.',
        'Add your Daraja credentials under Settings → Payment Gateways.');
} else {
    $missing = [];
    foreach (['consumer_key' => 'Consumer Key', 'consumer_secret' => 'Consumer Secret',
              'passkey' => 'Passkey', 'shortcode' => 'Shortcode'] as $k => $label2) {
        if (empty($creds[$k])) $missing[] = $label2;
    }
    $env = strtolower((string)($creds['environment'] ?? 'sandbox'));
    if ($missing) {
        $add('collection', 'M-Pesa gateway', 'fail',
            'Missing: ' . implode(', ', $missing) . '.',
            'Complete the credentials — an incomplete set fails at token exchange, before any STK push is sent.');
    } elseif ($env !== 'live' && $env !== 'production') {
        $add('collection', 'M-Pesa gateway', 'warn',
            "Credentials complete but the environment is '{$env}'. Sandbox returns a success code and never pushes to a real phone, "
            . 'so every test looks like it worked and no customer is ever charged.',
            'Switch to live once Safaricom has approved the shortcode.');
    } else {
        $add('collection', 'M-Pesa gateway', 'ok', 'Live, shortcode ' . $creds['shortcode'] . '.');
    }
}

// 2. Callback URL
$callbackUrl = (string)($creds['callback_url'] ?? '');
if ($callbackUrl === '') {
    $add('collection', 'STK callback URL', 'warn',
        'Not set — it will be auto-derived from the request host at push time. That works from the browser but not from cron.',
        'Set it explicitly to https://<your subdomain>/api/payment/callback.php.');
} else {
    $host = parse_url($callbackUrl, PHP_URL_HOST) ?: '';
    $isLocal = preg_match('/^(localhost|127\.|192\.168\.|10\.|172\.(1[6-9]|2\d|3[01])\.)/i', $host);
    if ($isLocal) {
        $add('collection', 'STK callback URL', 'fail',
            $callbackUrl . ' points at a private address. Safaricom cannot reach it, so no callback will ever arrive.');
    } elseif (stripos($callbackUrl, 'mpesa') !== false) {
        $add('collection', 'STK callback URL', 'fail',
            'The URL contains the word "mpesa". Safaricom rejects callback URLs containing it — this is why the endpoints '
            . 'live under /api/payment/ rather than /api/mpesa/.',
            'Rename the path to /api/payment/callback.php.');
    } elseif (!str_starts_with($callbackUrl, 'https://')) {
        $add('collection', 'STK callback URL', 'warn', $callbackUrl . ' is not HTTPS.');
    } else {
        $add('collection', 'STK callback URL', 'ok', $callbackUrl);
    }
}

// 3. C2B registration — this is what captures a direct paybill payment
if (!empty($creds['c2b_registered'])) {
    $add('collection', 'Direct paybill capture (C2B)', 'ok',
        'Registered ' . ($creds['c2b_registered_at'] ?? '') . ' → ' . ($creds['c2b_confirmation_url'] ?? '?')
        . '. A customer who pays your paybill from their own M-Pesa menu is captured without any STK push.');
} else {
    $add('collection', 'Direct paybill capture (C2B)', 'fail',
        'C2B URLs are not registered with Safaricom. Payments made straight to your paybill — the ones where the customer '
        . 'never touches the portal — arrive nowhere. Only STK pushes started from your own pages are captured.',
        'Register C2B under Settings → Payment Gateways. This is the single change that makes direct-paybill PPPoE '
        . 'renewals auto-activate.');
}

// 4. STK reconciliation cron
$hb = cron_last_run($pdo, 'stk_poll');
if (!$hb['ran']) {
    $add('collection', 'STK reconciliation cron', 'fail',
        'cron/stk_poll.php has never run on this deployment. Any callback Safaricom fails to deliver leaves the payment '
        . 'stuck on pending forever and the customer never gets connected.',
        'Add: */2 * * * * php /var/www/html/cron/stk_poll.php');
} elseif ($hb['age'] > 900) {
    $add('collection', 'STK reconciliation cron', 'fail',
        'Last ran ' . cron_age_human($hb['age']) . '. It is supposed to run every 2 minutes — it has stopped.');
} elseif ($hb['age'] > 300) {
    $add('collection', 'STK reconciliation cron', 'warn', 'Last ran ' . cron_age_human($hb['age']) . ' (expected every 2 minutes).');
} else {
    $add('collection', 'STK reconciliation cron', 'ok', 'Last ran ' . cron_age_human($hb['age']) . '.');
}

// 5. Paybill payments that arrived but matched nobody
require_once __DIR__ . '/../../includes/unmatched_payments.php';
$orphans = count_unmatched_payments($pdo, $tenantId);
if ($orphans > 0) {
    $add('collection', 'Paybill references resolved', 'fail',
        $orphans . ' payment(s) arrived on your paybill and could not be matched to a customer. The money is in your '
        . 'account; nobody has been credited or connected.',
        'Open Unmatched Payments and assign each one — crediting runs the same activation pipeline a matched payment would.');
} else {
    $add('collection', 'Paybill references resolved', 'ok',
        'Every paybill payment resolved to a customer.');
}

// ═══ B. Schema ════════════════════════════════════════════════════════════════
// These two failures are why "paid but not connected" happens with no error
// anywhere the operator can see: production runs STRICT_TRANS_TABLES, so an
// out-of-range ENUM throws mid-handler and a missing column is a hard 1054 —
// both land before the activation step.

try {
    $enumSt = $pdo->query("
        SELECT COLUMN_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clients' AND COLUMN_NAME = 'status'
    ");
    $colType = (string)($enumSt ? $enumSt->fetchColumn() : '');
    $needed  = ['active', 'inactive', 'suspended', 'grace', 'pending', 'expired'];
    $absent  = array_values(array_filter($needed, fn($v) => stripos($colType, "'" . $v . "'") === false));
    if ($colType === '') {
        $add('schema', 'clients.status accepts every state', 'warn', 'Could not read the column definition.');
    } elseif ($absent) {
        $add('schema', 'clients.status accepts every state', 'fail',
            'Missing ENUM member(s): ' . implode(', ', $absent) . '. Writing one of these throws 1265 Data truncated under '
            . 'strict mode, which surfaces to the customer as "Payment could not be initiated".',
            'Run: php tools/repair_status_enums.php');
    } else {
        $add('schema', 'clients.status accepts every state', 'ok', 'All six states present.');
    }
} catch (Throwable $e) {
    $add('schema', 'clients.status accepts every state', 'warn', $e->getMessage());
}

try {
    $cols = $pdo->query("
        SELECT COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mpesa_transactions'
    ")->fetchAll(PDO::FETCH_COLUMN);
    $want    = ['tenant_id', 'mpesa_receipt_number', 'raw_callback'];
    $missing = array_values(array_diff($want, $cols));
    if ($missing) {
        $add('schema', 'mpesa_transactions has its callback columns', 'fail',
            'Missing: ' . implode(', ', $missing) . '. That INSERT runs BEFORE activation in both C2B handlers, so the '
            . 'handler aborts on a 1054 and the customer is never activated even though the money arrived.',
            'Run: php tools/repair_status_enums.php (or the 2026-07-26 migration).');
    } else {
        $add('schema', 'mpesa_transactions has its callback columns', 'ok', 'tenant_id, mpesa_receipt_number, raw_callback all present.');
    }
} catch (Throwable $e) {
    $add('schema', 'mpesa_transactions has its callback columns', 'warn', $e->getMessage());
}

try {
    $pm = (string)$pdo->query("
        SELECT COLUMN_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'payment_method'
    ")->fetchColumn();
    if ($pm !== '' && stripos($pm, 'enum') === 0 && stripos($pm, "'mpesa_paybill'") === false) {
        $add('schema', 'payments.payment_method accepts paybill', 'fail',
            "The ENUM has no 'mpesa_paybill' member, so paybill payments are silently written with a blank method.",
            'Run: php tools/repair_status_enums.php');
    } else {
        $add('schema', 'payments.payment_method accepts paybill', 'ok',
            stripos($pm, 'enum') === 0 ? 'mpesa_paybill accepted.' : 'Free-text column — nothing to constrain.');
    }
} catch (Throwable $e) {
    $add('schema', 'payments.payment_method accepts paybill', 'warn', $e->getMessage());
}

// ═══ C. Activation ════════════════════════════════════════════════════════════

// Router availability — autoProvisionClient() takes the first ACTIVE router
try {
    $rs = $pdo->prepare("SELECT id, name, status, vpn_ip, ip_address, api_port FROM mikrotik_routers WHERE tenant_id = ? ORDER BY id");
    $rs->execute([$tenantId]);
    $routers = $rs->fetchAll(PDO::FETCH_ASSOC);
    $active  = array_values(array_filter($routers, fn($r) => $r['status'] === 'active'));

    if (!$routers) {
        $add('activation', 'Provisioning target', 'fail', 'No routers registered for this tenant.');
    } elseif (!$active) {
        $add('activation', 'Provisioning target', 'fail',
            count($routers) . ' router(s) registered but none has status "active". autoProvisionClient() selects the first '
            . 'ACTIVE router and gives up if there is none — every paid customer will queue in pending_provisions instead of '
            . 'being connected.',
            'Set the router status to active on the Routers page once its tunnel is up.');
    } else {
        $r    = $active[0];
        $ip   = $r['vpn_ip'] ?: $r['ip_address'];
        $port = (int)($r['api_port'] ?: 8728);
        $sock = @fsockopen($ip, $port, $errno, $errstr, 4);
        if ($sock) {
            fclose($sock);
            $add('activation', 'Provisioning target', 'ok',
                ($r['name'] ?: 'Router ' . $r['id']) . " reachable at {$ip}:{$port}"
                . (count($active) > 1 ? ' (first of ' . count($active) . ' active).' : '.'));
        } else {
            $add('activation', 'Provisioning target', 'fail',
                ($r['name'] ?: 'Router ' . $r['id']) . " is marked active but {$ip}:{$port} does not answer — "
                . ($errstr ?: 'no route') . (str_starts_with((string)$ip, '10.200.200.') ? ' (WireGuard tunnel down).' : '.')
                . ' Every payment will complete and then fail to grant access.',
                'Check the VPN tunnel on the Routers page.');
        }
    }
} catch (Throwable $e) {
    $add('activation', 'Provisioning target', 'warn', $e->getMessage());
}

// Packages without a speed provision uncapped
try {
    $ps = $pdo->prepare("SELECT COUNT(*) FROM packages WHERE tenant_id = ? AND COALESCE(download_speed,0) <= 0");
    $ps->execute([$tenantId]);
    $noSpeed = (int)$ps->fetchColumn();
    if ($noSpeed > 0) {
        $add('activation', 'Every package carries a speed', 'warn',
            $noSpeed . ' package(s) have no download_speed. Those provision UNCAPPED — the customer gets line speed '
            . 'regardless of what they paid.',
            'Set a download speed on each package.');
    } else {
        $add('activation', 'Every package carries a speed', 'ok', 'All packages have a download speed.');
    }
} catch (Throwable $e) {
    $add('activation', 'Every package carries a speed', 'warn', $e->getMessage());
}

// Provisioning retry queue + its cron
try {
    $qs = $pdo->prepare("SELECT COUNT(*) FROM pending_provisions WHERE tenant_id = ?");
    $qs->execute([$tenantId]);
    $queued = (int)$qs->fetchColumn();
} catch (Throwable $e) { $queued = 0; }

$hbR = cron_last_run($pdo, 'retry_provisions');
if (!$hbR['ran']) {
    $add('activation', 'Provisioning retry cron', $queued > 0 ? 'fail' : 'warn',
        'cron/retry_provisions.php has never run'
        . ($queued > 0 ? " and {$queued} customer(s) are waiting in the retry queue." : '.')
        . ' A payment that lands while the router is unreachable is queued here and never retried.',
        'Add: */5 * * * * php /var/www/html/cron/retry_provisions.php');
} else {
    $lvl = ($hbR['age'] > 1800) ? 'fail' : (($hbR['age'] > 600) ? 'warn' : 'ok');
    $add('activation', 'Provisioning retry cron', $queued > 0 && $lvl === 'ok' ? 'warn' : $lvl,
        'Last ran ' . cron_age_human($hbR['age'])
        . ($queued > 0 ? " · {$queued} customer(s) currently queued for retry." : ' · queue empty.'));
}

// Expiry enforcement cron
$hbE = cron_last_run($pdo, 'check_expiry');
if (!$hbE['ran']) {
    $add('activation', 'Expiry enforcement cron', 'fail',
        'cron/check_expiry.php has never run. Expired customers stay online indefinitely — the enforcement sweep that cuts '
        . 'live sessions for non-active clients lives in this script.',
        'Add: */15 * * * * php /var/www/html/cron/check_expiry.php');
} else {
    $add('activation', 'Expiry enforcement cron', $hbE['age'] > 3600 ? 'fail' : ($hbE['age'] > 1800 ? 'warn' : 'ok'),
        'Last ran ' . cron_age_human($hbE['age']) . ' (expected every 15 minutes).');
}

// ═══ D. Evidence — what the last 7 days actually did ══════════════════════════

try {
    $ev = $pdo->prepare("
        SELECT COUNT(*) AS total,
               SUM(CASE WHEN c.status = 'active' AND c.expiry_date > NOW() THEN 1 ELSE 0 END) AS activated
        FROM payments p
        JOIN clients  c ON c.id = p.client_id AND c.tenant_id = p.tenant_id
        WHERE p.tenant_id = ? AND p.status = 'completed'
          AND p.payment_date >= NOW() - INTERVAL 7 DAY
    ");
    $ev->execute([$tenantId]);
    $row  = $ev->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'activated' => 0];
    $tot  = (int)$row['total'];
    $act  = (int)$row['activated'];
    $gap  = $tot - $act;

    if ($tot === 0) {
        $add('evidence', 'Payments that produced access', 'warn',
            'No completed payments in the last 7 days — nothing to verify against. Make one live payment to prove the chain '
            . 'end to end, then re-run this check.');
    } elseif ($gap === 0) {
        $add('evidence', 'Payments that produced access', 'ok',
            "{$tot}/{$tot} completed payments left the customer active with a future expiry. Full automation is working.");
    } else {
        $add('evidence', 'Payments that produced access', 'fail',
            "{$act}/{$tot} completed payments left the customer active. {$gap} customer(s) paid and are not connected.",
            'Run: php tools/diagnose_autoactivation.php for the per-customer breakdown.');
    }
} catch (Throwable $e) {
    $add('evidence', 'Payments that produced access', 'warn', $e->getMessage());
}

try {
    $st = $pdo->prepare("
        SELECT COUNT(*) FROM mpesa_transactions
        WHERE tenant_id = ?
          AND (result_code IS NULL OR result_code = '')
          AND created_at BETWEEN NOW() - INTERVAL 7 DAY AND NOW() - INTERVAL 10 MINUTE
    ");
    $st->execute([$tenantId]);
    $stuck = (int)$st->fetchColumn();
    if ($stuck > 0) {
        $add('evidence', 'Stuck STK transactions', 'fail',
            $stuck . ' transaction(s) older than 10 minutes still have no result code. Their callback never arrived and the '
            . 'reconciliation poller has not resolved them either.',
            'Confirm cron/stk_poll.php is in crontab, then check whether a CDN or WAF is intercepting the callback URL.');
    } else {
        $add('evidence', 'Stuck STK transactions', 'ok', 'Every transaction older than 10 minutes has a final result code.');
    }
} catch (Throwable $e) {
    $add('evidence', 'Stuck STK transactions', 'warn', $e->getMessage());
}

try {
    $sp = $pdo->prepare("
        SELECT AVG(TIMESTAMPDIFF(SECOND, p.payment_date, rs.deployed_at)) AS avg_secs, COUNT(*) AS n
        FROM payments p
        JOIN router_services rs ON rs.client_id = p.client_id AND rs.tenant_id = p.tenant_id
        WHERE p.tenant_id = ? AND p.status = 'completed'
          AND p.payment_date >= NOW() - INTERVAL 7 DAY
          AND rs.deployed_at >= p.payment_date
    ");
    $sp->execute([$tenantId]);
    $lat = $sp->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!empty($lat['n'])) {
        $secs = (int)round((float)$lat['avg_secs']);
        $add('evidence', 'Payment → router access latency', $secs > 120 ? 'warn' : 'ok',
            'Average ' . ($secs < 60 ? $secs . 's' : intdiv($secs, 60) . 'm ' . ($secs % 60) . 's')
            . ' across ' . (int)$lat['n'] . ' provisioning event(s).'
            . ($secs > 120 ? ' Anything over ~2 minutes means the customer is waiting on the retry cron rather than the callback.' : ''));
    }
} catch (Throwable $e) { /* router_services may be absent */ }

// ── Roll up ───────────────────────────────────────────────────────────────────
$counts = ['ok' => 0, 'warn' => 0, 'fail' => 0];
foreach ($groups as $g) {
    foreach ($g['checks'] as $c) { $counts[$c['level']] = ($counts[$c['level']] ?? 0) + 1; }
}

if ($counts['fail'] > 0) {
    $verdict = ['level' => 'fail',
                'title' => $counts['fail'] . ' break' . ($counts['fail'] === 1 ? '' : 's') . ' in the automation chain',
                'message' => 'Payments will not reliably turn into internet access until these are cleared.'];
} elseif ($counts['warn'] > 0) {
    $verdict = ['level' => 'warn',
                'title' => 'Automation working, ' . $counts['warn'] . ' caveat' . ($counts['warn'] === 1 ? '' : 's'),
                'message' => 'Nothing is blocking activation, but the items below will cause trouble under load or over time.'];
} else {
    $verdict = ['level' => 'ok',
                'title' => 'Full automation verified',
                'message' => 'Money can arrive by STK push or direct paybill, is recorded without schema faults, and turns into '
                           . 'router access without anyone intervening.'];
}

ob_clean();
echo json_encode(['success' => true, 'verdict' => $verdict, 'counts' => $counts, 'groups' => $groups]);
