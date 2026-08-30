<?php
/**
 * End-to-end automation verification for ONE tenant: money in → internet on.
 *
 * Two things had to change about this file, and they are the same thing:
 *
 *  1. It checked every link as though every tenant collected the same way.
 *     A tenant on the FortuNett shared paybill was told "No M-Pesa API gateway
 *     saved for this tenant — nothing can be collected automatically" and sent
 *     to add Daraja credentials they will never have. A tenant on a manual
 *     paybill was told to register C2B, which cannot be done without an API.
 *     Both are FAIL rows that describe a working system.
 *
 *  2. It reported PLATFORM faults as the tenant's to fix. The schema, the
 *     crons and the shared paybill are one installation serving everyone, so a
 *     single fault appeared identically on every tenant's page, each time
 *     phrased as an instruction the tenant could not carry out — a crontab line
 *     for cron/stk_poll.php, handed to someone with no shell. Those checks now
 *     live at super_admin/diagnostics.php, checked once for the installation,
 *     and appear here only as a single honest "FortuNett is aware" row.
 *
 * What remains is what the tenant can actually act on: how they collect, their
 * routers, their packages, and what their last 7 days actually did.
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
    'collection' => ['title' => 'Collecting the money', 'checks' => []],
    'activation' => ['title' => 'Granting access',      'checks' => []],
    'evidence'   => ['title' => 'Last 7 days',          'checks' => []],
    'platform'   => ['title' => 'Handled by FortuNett', 'checks' => []],
];

$add = function (string $group, string $label, string $level, string $detail, ?string $action = null) use (&$groups) {
    $groups[$group]['checks'][] = ['label' => $label, 'level' => $level, 'detail' => $detail, 'action' => $action];
};

// ═══ A. Collection ════════════════════════════════════════════════════════════
// Which links apply at all depends on HOW this tenant collects. tenantC2BStatus()
// is the one place that decides, and it is the same function that drives the
// banner on payments.php — so the two pages cannot contradict each other.

require_once __DIR__ . '/../../includes/c2b_registration.php';
$c2b  = tenantC2BStatus($pdo, $tenantId);
$mode = $c2b['mode'];   // 'direct' (own API) | 'manual_paybill' | 'platform'

if ($mode === 'direct') {
    $env = strtolower((string)($c2b['environment'] ?? 'sandbox'));
    if ($env !== 'live' && $env !== 'production') {
        $add('collection', 'Your M-Pesa credentials', 'fail',
            "Complete, but the environment is '{$env}'. Sandbox returns a success code and never pushes to a real phone, "
            . 'so every test looks like it worked and no customer is ever charged.',
            'Switch to live under Settings → Payments once Safaricom has approved shortcode ' . $c2b['shortcode'] . '.');
    } else {
        $add('collection', 'Your M-Pesa credentials', 'ok',
            'Live on shortcode ' . $c2b['shortcode'] . '. Payments go straight into your own account.');
    }

    // The callback URL is only the tenant's to get right when the push leaves
    // their own credentials. On the shared paybill it is a platform value, so
    // asking them about it was noise.
    try {
        require_once __DIR__ . '/../../includes/credential_helper.php';
        $gwSt = $pdo->prepare("
            SELECT credentials FROM payment_gateways
            WHERE tenant_id = ? AND gateway_type = 'mpesa_api' AND is_active = 1
            ORDER BY is_default DESC, id ASC LIMIT 1");
        $gwSt->execute([$tenantId]);
        $cb = (string)((decrypt_gateway_credentials((string)$gwSt->fetchColumn()) ?: [])['callback_url'] ?? '');

        if ($cb === '') {
            $add('collection', 'Your STK callback URL', 'warn',
                'Not set — it is derived from the request host at push time. That works from a browser but not from a '
                . 'scheduled job.',
                'Set it explicitly to https://<your subdomain>/api/payment/callback.php under Settings → Payments.');
        } elseif (preg_match('/^(localhost|127\.|192\.168\.|10\.|172\.(1[6-9]|2\d|3[01])\.)/i',
                             (string)(parse_url($cb, PHP_URL_HOST) ?: ''))) {
            $add('collection', 'Your STK callback URL', 'fail',
                $cb . ' points at a private address. Safaricom cannot reach it, so no confirmation will ever arrive and '
                . 'every payment sits pending until the reconciliation job catches it.',
                'Use your public https subdomain under Settings → Payments.');
        } elseif (stripos($cb, 'mpesa') !== false) {
            $add('collection', 'Your STK callback URL', 'fail',
                'The URL contains the word "mpesa". Safaricom rejects callback URLs containing it — that is why these '
                . 'endpoints live under /api/payment/ rather than /api/mpesa/.',
                'Change the path to /api/payment/callback.php under Settings → Payments.');
        } elseif (stripos($cb, 'https://') !== 0) {
            $add('collection', 'Your STK callback URL', 'warn', $cb . ' is not HTTPS.');
        } else {
            $add('collection', 'Your STK callback URL', 'ok', $cb);
        }
    } catch (Throwable $e) {
        $add('collection', 'Your STK callback URL', 'warn', $e->getMessage());
    }

    // C2B is what captures a customer who pays the paybill from their own
    // M-Pesa menu instead of through your portal. Only meaningful with an API.
    if (!empty($c2b['active'])) {
        $add('collection', 'Direct paybill capture (C2B)', 'ok',
            'Registered' . ($c2b['registered_at'] ? ' ' . $c2b['registered_at'] : '')
            . '. A customer who pays ' . $c2b['shortcode'] . ' from their own M-Pesa menu is captured and reconnected '
            . 'without any STK push.');
    } else {
        $add('collection', 'Direct paybill capture (C2B)', 'fail',
            'Your C2B URLs are not registered with Safaricom. Payments made straight to ' . $c2b['shortcode']
            . ' — the ones where the customer never opens your portal — arrive nowhere. Only STK pushes started from '
            . 'your own pages are captured.',
            'Open Settings → Payments and re-save the gateway; registration runs automatically.');
    }
} elseif ($mode === 'manual_paybill') {
    // Not a fault the tenant can clear with a button, and pretending otherwise
    // is what left operators hunting a "broken" C2B registration that a manual
    // paybill can never have.
    $add('collection', 'How you collect', 'warn',
        $c2b['reason']);
} else {
    // Shared paybill. Whether this works is a PLATFORM question, so it is
    // answered from the platform config rather than from anything the tenant
    // owns — and it is never phrased as their job.
    $plat = [];
    try { $plat = $pdo->query("SELECT * FROM platform_mpesa_config WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: []; } catch (Throwable $e) {}

    $complete = $plat
        && trim((string)($plat['consumer_key'] ?? '')) !== ''
        && trim((string)($plat['consumer_secret'] ?? '')) !== ''
        && trim((string)($plat['passkey'] ?? '')) !== ''
        && trim((string)($plat['shortcode'] ?? '')) !== '';
    $live = in_array(strtolower((string)($plat['environment'] ?? 'sandbox')), ['live', 'production'], true);

    if ($complete && $live) {
        $add('collection', 'How you collect', 'ok',
            'Through the FortuNett shared paybill ' . $plat['shortcode'] . '. Customer payments are captured and '
            . 'activated automatically, and settled to you on release — you do not need M-Pesa credentials of your own. '
            . 'Add your own under Settings → Payments only if you want the money to land in your account directly.');
    } else {
        $add('collection', 'How you collect', 'fail',
            'You collect through the FortuNett shared paybill, and it is not currently able to take payments'
            . ($complete ? ' — it is in sandbox, which never charges a real phone.' : ' — its credentials are incomplete.')
            . ' This is a FortuNett platform setting; nothing in your own settings affects it.',
            'Contact FortuNett support. Adding your own Daraja credentials under Settings → Payments also removes this '
            . 'dependency entirely.');
    }
}

// Paybill payments that arrived but matched nobody — the tenant's to resolve
// whichever way they collect.
require_once __DIR__ . '/../../includes/unmatched_payments.php';
$orphans = count_unmatched_payments($pdo, $tenantId);
if ($orphans > 0) {
    $add('collection', 'Payments matched to a customer', 'fail',
        $orphans . ' payment(s) arrived and could not be matched to a customer. The money is recorded; nobody has been '
        . 'credited or connected.',
        'Open Unclaimed Payments on the Payments page and assign each one — crediting runs the same activation '
        . 'pipeline a matched payment would.');
} else {
    $add('collection', 'Payments matched to a customer', 'ok', 'Every payment resolved to a customer.');
}

// ═══ B. Activation ════════════════════════════════════════════════════════════
// This half is genuinely the tenant's: their routers, their packages.

try {
    $rs = $pdo->prepare("SELECT id, name, status, vpn_ip, ip_address, api_port FROM mikrotik_routers WHERE tenant_id = ? ORDER BY id");
    $rs->execute([$tenantId]);
    $routers = $rs->fetchAll(PDO::FETCH_ASSOC);
    $active  = array_values(array_filter($routers, fn($r) => $r['status'] === 'active'));

    if (!$routers) {
        $add('activation', 'Provisioning target', 'fail', 'No routers registered.',
            'Add your router on the Routers page.');
    } elseif (!$active) {
        $add('activation', 'Provisioning target', 'fail',
            count($routers) . ' router(s) registered but none has status "active". Provisioning selects the first '
            . 'ACTIVE router and gives up if there is none — every paid customer queues for retry instead of being '
            . 'connected.',
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

try {
    $ps = $pdo->prepare("SELECT COUNT(*) FROM packages WHERE tenant_id = ? AND COALESCE(download_speed,0) <= 0");
    $ps->execute([$tenantId]);
    $noSpeed = (int)$ps->fetchColumn();
    if ($noSpeed > 0) {
        $add('activation', 'Every package carries a speed', 'warn',
            $noSpeed . ' package(s) have no download speed. Those provision UNCAPPED — the customer gets line speed '
            . 'regardless of what they paid.',
            'Set a download speed on each package.');
    } else {
        $add('activation', 'Every package carries a speed', 'ok', 'All packages have a download speed.');
    }
} catch (Throwable $e) {
    $add('activation', 'Every package carries a speed', 'warn', $e->getMessage());
}

// The retry queue is the tenant's problem only in that it tells them customers
// are waiting; whether it drains is a cron, i.e. platform. Report the backlog
// here and leave the cron to the platform row below.
try {
    $qs = $pdo->prepare("SELECT COUNT(*) FROM pending_provisions WHERE tenant_id = ?");
    $qs->execute([$tenantId]);
    $queued = (int)$qs->fetchColumn();
} catch (Throwable $e) { $queued = 0; }

if ($queued > 0) {
    $add('activation', 'Customers waiting to be connected', 'fail',
        $queued . ' customer(s) paid while their router was unreachable and are queued for retry. They are retried '
        . 'automatically every 5 minutes once the router answers.',
        'Bring the router back online — the queue drains on its own.');
} else {
    $add('activation', 'Customers waiting to be connected', 'ok', 'Nobody is queued — every payment provisioned.');
}

// ═══ C. Evidence — what the last 7 days actually did ══════════════════════════

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
            'No completed payments in the last 7 days — nothing to verify against. Make one live payment to prove the '
            . 'chain end to end, then re-run this check.');
    } elseif ($gap === 0) {
        $add('evidence', 'Payments that produced access', 'ok',
            "{$tot}/{$tot} completed payments left the customer active with a future expiry. Full automation is working.");
    } else {
        $add('evidence', 'Payments that produced access', 'fail',
            "{$act}/{$tot} completed payments left the customer active. {$gap} customer(s) paid and are not connected.",
            'Check the Provisioning target above first; if that is green, contact FortuNett support with this figure.');
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
        $add('evidence', 'Stuck M-Pesa transactions', 'fail',
            $stuck . ' transaction(s) older than 10 minutes still have no result code. Their callback never arrived and '
            . 'the reconciliation job has not resolved them either.',
            'This is a platform-side job. Report the figure to FortuNett support.');
    } else {
        $add('evidence', 'Stuck M-Pesa transactions', 'ok', 'Every transaction older than 10 minutes has a final result.');
    }
} catch (Throwable $e) {
    $add('evidence', 'Stuck M-Pesa transactions', 'warn', $e->getMessage());
}

// ═══ D. Handled by FortuNett ══════════════════════════════════════════════════
// The shared half, collapsed to one row per concern and never phrased as
// something the tenant should do. Detail lives on super_admin/diagnostics.php,
// where it is checked once for the whole installation.

$schemaFaults = [];
try {
    $colType = (string)$pdo->query("
        SELECT COLUMN_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clients' AND COLUMN_NAME = 'status'")->fetchColumn();
    foreach (['active', 'inactive', 'suspended', 'grace', 'pending', 'expired'] as $v) {
        if ($colType !== '' && stripos($colType, "'" . $v . "'") === false) { $schemaFaults[] = 'clients.status'; break; }
    }
} catch (Throwable $e) {}
try {
    $cols = $pdo->query("
        SELECT COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mpesa_transactions'")->fetchAll(PDO::FETCH_COLUMN);
    if (array_diff(['tenant_id', 'mpesa_receipt_number', 'raw_callback'], $cols)) $schemaFaults[] = 'mpesa_transactions';
} catch (Throwable $e) {}

if ($schemaFaults) {
    $add('platform', 'Database is up to date', 'fail',
        'This installation is missing a database migration (' . implode(', ', array_unique($schemaFaults)) . '). '
        . 'Payments are recorded and then the handler stops before connecting the customer, with no error anywhere you '
        . 'can see. Nothing in your own settings causes or fixes this.',
        'Report it to FortuNett support — it is a one-command repair on the server.');
} else {
    $add('platform', 'Database is up to date', 'ok', 'No missing migrations on this installation.');
}

// The three jobs whose absence a tenant would otherwise experience as an
// unexplained failure. Collapsed: the tenant does not need to know which.
$jobIssues = [];
foreach ([['stk_poll', 900, 'confirming payments'],
          ['retry_provisions', 1800, 'retrying provisioning'],
          ['check_expiry', 3600, 'enforcing expiry']] as [$name, $limit, $what]) {
    $hb = cron_last_run($pdo, $name);
    if (!$hb['ran'])            { $jobIssues[] = $what . ' (never run)'; }
    elseif ($hb['age'] > $limit) { $jobIssues[] = $what . ' (stopped ' . cron_age_human((int)$hb['age']) . ' ago)'; }
}

if ($jobIssues) {
    $add('platform', 'Background jobs running', 'fail',
        'FortuNett\'s scheduled jobs are not running: ' . implode(', ', $jobIssues) . '. Until they are, a payment whose '
        . 'confirmation is delayed may never complete, and expired customers may stay online.',
        'Report it to FortuNett support.');
} else {
    $add('platform', 'Background jobs running', 'ok', 'Payment confirmation, provisioning retry and expiry enforcement are all current.');
}

// ── Roll up ───────────────────────────────────────────────────────────────────
$counts = ['ok' => 0, 'warn' => 0, 'fail' => 0];
foreach ($groups as $g) {
    foreach ($g['checks'] as $c) { $counts[$c['level']] = ($counts[$c['level']] ?? 0) + 1; }
}

// A break the tenant cannot act on is reported differently from one they can —
// telling someone to fix what is not theirs is the failure this page had.
$platformFails = 0;
foreach ($groups['platform']['checks'] as $c) if ($c['level'] === 'fail') $platformFails++;
$ownFails = $counts['fail'] - $platformFails;

if ($counts['fail'] > 0) {
    $verdict = ['level' => 'fail',
                'title' => $counts['fail'] . ' break' . ($counts['fail'] === 1 ? '' : 's') . ' in the automation chain',
                'message' => $ownFails === 0
                    ? 'Everything on your side is configured correctly — the breaks below are on FortuNett\'s side and '
                    . 'are being reported to them, not to you.'
                    : ($platformFails > 0
                        ? $ownFails . ' you can fix and ' . $platformFails . ' on FortuNett\'s side. Payments will not '
                        . 'reliably turn into internet access until both are cleared.'
                        : 'Payments will not reliably turn into internet access until these are cleared.')];
} elseif ($counts['warn'] > 0) {
    $verdict = ['level' => 'warn',
                'title' => 'Automation working, ' . $counts['warn'] . ' caveat' . ($counts['warn'] === 1 ? '' : 's'),
                'message' => 'Nothing is blocking activation, but the items below will cause trouble under load or over time.'];
} else {
    $verdict = ['level' => 'ok',
                'title' => 'Full automation verified',
                'message' => 'Money arrives, is recorded without faults, and turns into router access without anyone '
                           . 'intervening.'];
}

ob_clean();
echo json_encode([
    'success'         => true,
    'verdict'         => $verdict,
    'counts'          => $counts,
    'collection_mode' => $mode,
    'groups'          => $groups,
]);
