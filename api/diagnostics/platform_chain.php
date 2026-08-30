<?php
/**
 * Platform-level automation verification — the breaks that belong to FortuNett.
 *
 * api/diagnostics/automation_chain.php answers "is automation working for THIS
 * tenant". That question cannot be answered honestly per tenant, because most
 * of the chain is not the tenant's: the shared paybill, the callback host, the
 * crons, the schema and the SMS account are one installation serving everyone.
 * A platform fault therefore showed up as N identical tenant-level failures,
 * each phrased as something the tenant should go and fix — and none of them
 * could be fixed by the tenant at all.
 *
 * This endpoint checks the shared half exactly once and says who has to act.
 * Clear these first; whatever is still red afterwards is genuinely per-tenant.
 *
 * Read-only. Super-admin session required. POST or GET, no parameters.
 */
ob_start();
ini_set('display_errors', 0);
header('Content-Type: application/json');

require_once __DIR__ . '/../../includes/db_master.php';
require_once __DIR__ . '/../../includes/cron_heartbeat.php';
require_once __DIR__ . '/../../includes/sms_config.php';
require_once __DIR__ . '/../../super_admin/includes/auth.php';

if (!isSuperAdmin()) {
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Super admin session required']);
    exit;
}

$groups = [
    'collection' => ['title' => 'The shared paybill',      'checks' => []],
    'reach'      => ['title' => 'Being reachable',         'checks' => []],
    'schema'     => ['title' => 'The database, once',      'checks' => []],
    'jobs'       => ['title' => 'Scheduled jobs',          'checks' => []],
    'comms'      => ['title' => 'Telling customers',       'checks' => []],
    'payouts'    => ['title' => 'Paying the ISPs back',    'checks' => []],
    'tenants'    => ['title' => 'Who this affects',        'checks' => []],
];

$add = function (string $group, string $label, string $level, string $detail, ?string $action = null) use (&$groups) {
    $groups[$group]['checks'][] = ['label' => $label, 'level' => $level, 'detail' => $detail, 'action' => $action];
};

$setting = function (string $key, string $default = '') use ($pdo): string {
    try {
        $s = $pdo->prepare("SELECT setting_value FROM platform_settings WHERE setting_key = ? LIMIT 1");
        $s->execute([$key]);
        $v = $s->fetchColumn();
        return $v !== false ? (string)$v : $default;
    } catch (Throwable $e) { return $default; }
};

// ═══ A. The shared paybill ════════════════════════════════════════════════════
// Everything a tenant without their own Daraja credentials depends on. When
// this is incomplete, every such tenant's customers get "Payment could not be
// initiated" and each tenant is told to check credentials they do not own.

$plat = [];
try {
    $plat = $pdo->query("SELECT * FROM platform_mpesa_config WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $add('collection', 'Platform M-Pesa account', 'fail',
        'platform_mpesa_config is unreadable: ' . $e->getMessage(),
        'Open super_admin/mpesa.php once — it creates the table.');
}

if ($plat) {
    $missing = [];
    foreach (['consumer_key' => 'Consumer Key', 'consumer_secret' => 'Consumer Secret',
              'passkey' => 'Passkey', 'shortcode' => 'Shortcode'] as $k => $lbl) {
        if (trim((string)($plat[$k] ?? '')) === '') $missing[] = $lbl;
    }
    $env = strtolower((string)($plat['environment'] ?? 'sandbox'));

    if ($missing) {
        $add('collection', 'Platform M-Pesa credentials', 'fail',
            'Missing: ' . implode(', ', $missing) . '. Every tenant with no Daraja credentials of their own routes '
            . 'through this shortcode, so an STK push for any of them fails at token exchange — before a phone ever rings.',
            'Complete them under Platform → M-Pesa.');
    } elseif ($env !== 'live' && $env !== 'production') {
        $add('collection', 'Platform M-Pesa credentials', 'fail',
            "Complete, but the environment is '{$env}'. Sandbox returns a success code and never pushes to a real phone: "
            . 'every tenant on the shared paybill sees payments that look initiated and never arrive.',
            'Switch to live under Platform → M-Pesa once Safaricom has approved the shortcode.');
    } else {
        $add('collection', 'Platform M-Pesa credentials', 'ok',
            'Live, shortcode ' . $plat['shortcode'] . ' (' . ($plat['shortcode_type'] ?? 'paybill') . ').');
    }

    // A Buy Goods till registers and pushes against the STORE number, not the
    // till the customer types. Without it the API returns an error that reads
    // exactly like bad credentials.
    if (($plat['shortcode_type'] ?? '') === 'till' && trim((string)($plat['store_number'] ?? '')) === '') {
        $add('collection', 'Buy Goods store number', 'fail',
            'The platform shortcode is a Buy Goods till but no store number is set. STK push and C2B registration both '
            . 'authenticate against the store / head-office number, and Safaricom rejects the call with an error that '
            . 'looks like a credentials problem.',
            'Add the store number under Platform → M-Pesa.');
    }

    // C2B is the only thing that captures a customer paying the shared paybill
    // from their own M-Pesa menu.
    $c2bConf = trim((string)($plat['c2b_confirmation_url'] ?? ''));
    if ($c2bConf === '') {
        $add('collection', 'Platform C2B registration', 'fail',
            'No C2B confirmation URL recorded for the shared paybill. A customer who pays it from their own M-Pesa menu '
            . 'instead of through the portal arrives nowhere: the money lands in FortuNett\'s account and no tenant, '
            . 'customer or payment row ever hears about it.',
            'Register C2B under Platform → M-Pesa.');
    } elseif (stripos($c2bConf, 'mpesa') !== false) {
        $add('collection', 'Platform C2B registration', 'fail',
            'The confirmation URL contains the word "mpesa" — Safaricom rejects those, which is why the endpoints live '
            . 'under /api/payment/ rather than /api/mpesa/. Registration will have silently failed.',
            'Re-register with a /api/payment/ path.');
    } else {
        $add('collection', 'Platform C2B registration', 'ok', $c2bConf);
    }
}

// ═══ B. Being reachable ═══════════════════════════════════════════════════════
// Safaricom and the routers both have to find this server. Both of these are
// single platform values that no tenant can see or set.

$baseUrl = trim($setting('public_base_url', ''));
if ($baseUrl === '') {
    $add('reach', 'Public base URL', 'fail',
        'platform_settings.public_base_url is not set. Anything that builds a callback URL from cron rather than from a '
        . 'browser request has no host to use — B2C result callbacks in particular go out with an unreachable ResultURL, '
        . 'so a payout is sent and its confirmation never comes back.',
        'Set it to https://<your platform domain> under Platform → Settings.');
} elseif (!preg_match('#^https://#i', $baseUrl)) {
    $add('reach', 'Public base URL', 'fail',
        "'{$baseUrl}' is not an absolute https URL. A relative path sails past every empty-string check and becomes a "
        . 'callback URL Safaricom cannot post to.',
        'Use a full https:// URL.');
} else {
    $add('reach', 'Public base URL', 'ok', $baseUrl);
}

$extIp = trim($setting('server_external_ip', ''));
$isPrivate = $extIp !== '' && preg_match('/^(10\.|127\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $extIp);
if ($extIp === '') {
    $add('reach', 'WireGuard endpoint IP', 'fail',
        'platform_settings.server_external_ip is empty. Router provisioning then falls back to resolving the request '
        . 'host, which on a proxied deployment is the CDN edge — and the CDN does not answer UDP 51820, so every tunnel '
        . 'provisioned this way never handshakes.',
        'Set the VPS public IP under Platform → Settings.');
} elseif ($isPrivate) {
    $add('reach', 'WireGuard endpoint IP', 'fail',
        "'{$extIp}' is a private address. No router on the internet can reach it as a WireGuard endpoint.",
        'Use the VPS public IP.');
} else {
    $add('reach', 'WireGuard endpoint IP', 'ok', $extIp);
}

$wgKey = trim($setting('wg_vps_public_key', ''));
$add('reach', 'WireGuard server key cached', $wgKey === '' ? 'warn' : 'ok',
    $wgKey === ''
        ? 'platform_settings.wg_vps_public_key is empty, so provisioning must shell out to `sudo wg` every time. One '
          . 'momentary failure there emits a tunnel with no server key and silently disables it.'
        : 'Cached — provisioning does not depend on `sudo wg` succeeding at that moment.',
    $wgKey === '' ? 'It is cached automatically on the next successful provision; nothing to do if tunnels are working.' : null);

// ═══ C. The database, once ════════════════════════════════════════════════════
// These are one schema serving every tenant. Under STRICT_TRANS_TABLES each is
// a hard throw mid-handler, landing BEFORE the activation step — so the money
// is recorded and the customer is never connected, with no error anywhere the
// operator looks.

$schemaCheck = function (string $label, callable $probe) use ($add) {
    try { $probe(); } catch (Throwable $e) { $add('schema', $label, 'warn', $e->getMessage()); }
};

$schemaCheck('clients.status accepts every state', function () use ($pdo, $add) {
    $t = (string)$pdo->query("
        SELECT COLUMN_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clients' AND COLUMN_NAME = 'status'")->fetchColumn();
    $absent = array_values(array_filter(
        ['active', 'inactive', 'suspended', 'grace', 'pending', 'expired'],
        fn($v) => stripos($t, "'" . $v . "'") === false));
    if ($absent) {
        $add('schema', 'clients.status accepts every state', 'fail',
            'Missing ENUM member(s): ' . implode(', ', $absent) . '. Writing one throws 1265 Data truncated under strict '
            . 'mode and the customer is shown "Payment could not be initiated".',
            'Run: php tools/repair_status_enums.php');
    } else {
        $add('schema', 'clients.status accepts every state', 'ok', 'All six states present.');
    }
});

$schemaCheck('mpesa_transactions has its callback columns', function () use ($pdo, $add) {
    $cols = $pdo->query("
        SELECT COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mpesa_transactions'")->fetchAll(PDO::FETCH_COLUMN);
    $missing = array_values(array_diff(['tenant_id', 'mpesa_receipt_number', 'raw_callback'], $cols));
    if ($missing) {
        $add('schema', 'mpesa_transactions has its callback columns', 'fail',
            'Missing: ' . implode(', ', $missing) . '. That INSERT runs before activation in both C2B handlers, so the '
            . 'handler dies on a 1054 and nobody is activated even though the money arrived.',
            'Run: php tools/repair_status_enums.php');
    } else {
        $add('schema', 'mpesa_transactions has its callback columns', 'ok', 'All present.');
    }
});

$schemaCheck('payments.payment_method accepts paybill', function () use ($pdo, $add) {
    $pm = (string)$pdo->query("
        SELECT COLUMN_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'payment_method'")->fetchColumn();
    if ($pm !== '' && stripos($pm, 'enum') === 0 && stripos($pm, "'mpesa_paybill'") === false) {
        $add('schema', 'payments.payment_method accepts paybill', 'fail',
            "The ENUM has no 'mpesa_paybill' member, so under strict mode a paybill payment is written with a blank "
            . 'method — and every rule that reads the method to decide whose money it is then sees nothing.',
            'Run: php tools/repair_status_enums.php');
    } else {
        $add('schema', 'payments.payment_method accepts paybill', 'ok',
            stripos($pm, 'enum') === 0 ? 'mpesa_paybill accepted.' : 'Free-text column — nothing to constrain.');
    }
});

$schemaCheck('payments has collection_type', function () use ($pdo, $add) {
    $t = (string)$pdo->query("
        SELECT COLUMN_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'collection_type'")->fetchColumn();
    if ($t === '') {
        $add('schema', 'payments has collection_type', 'fail',
            'The column is absent, so nothing can say whose bank a payment landed in. Every settlement figure and payout '
            . 'decision reads it.',
            'Apply sql/migrations/2026-07-26-platform-collections.sql');
    } else {
        $add('schema', 'payments has collection_type', 'ok', $t);
    }
});

$schemaCheck('platform_invoices has amount_paid', function () use ($pdo, $add) {
    $t = (string)$pdo->query("
        SELECT COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'platform_invoices' AND COLUMN_NAME = 'amount_paid'")->fetchColumn();
    if ($t === '') {
        $add('schema', 'platform_invoices has amount_paid', 'fail',
            'billing.php selects this column with no try/catch, so EVERY tenant\'s billing page returns a blank 500 — '
            . 'while curl still reports a healthy 302 to login.php, because the redirect happens long before the query.',
            'Apply sql/migrations/2026-07-26-platform-collections.sql');
    } else {
        $add('schema', 'platform_invoices has amount_paid', 'ok', 'Present.');
    }
});

// ═══ D. Scheduled jobs ════════════════════════════════════════════════════════
// A crontab line that was never added is invisible: nothing errors, the work
// simply never happens. Each script stamps a heartbeat so absence is visible.

$jobs = [
    ['stk_poll',          'STK reconciliation',   120,   '*/2 * * * *',  'A callback Safaricom fails to deliver leaves the payment pending forever and the customer never gets connected.'],
    ['retry_provisions',  'Provisioning retry',   300,   '*/5 * * * *',  'A customer who paid while their router was unreachable stays paid-and-offline.'],
    ['check_expiry',      'Expiry enforcement',   900,   '*/15 * * * *', 'Expired customers stay online indefinitely; the sweep that cuts live sessions lives here.'],
    ['check_router_status', 'Router status',      300,   '*/5 * * * *',  'Router online/offline state goes stale, and provisioning picks the first ACTIVE router.'],
    ['sync_hotspot_pages', 'Captive portal sync', 3600,  '30 * * * *',   'Login-page and package changes never reach reachable routers.'],
    ['check_suspensions', 'Tenant suspensions',   86400, '0 8 * * *',    'Overdue tenants are never suspended and paid-up ones are never reactivated.'],
    ['monthly_billing',   'Monthly billing',      0,     '0 6 1 * *',    'No platform invoices are generated, so nothing is ever billed.'],
    ['disburse_payouts',  'ISP payouts',          86400, '0 9 * * *',    'Money FortuNett is holding for ISPs is never actually sent.'],
];

foreach ($jobs as [$name, $label, $period, $crontab, $why]) {
    $hb = cron_last_run($pdo, $name);
    if (!$hb['ran']) {
        // monthly_billing has no meaningful staleness window on a fresh month,
        // so a never-run is reported as a warning rather than an outage.
        $add('jobs', $label, $period === 0 ? 'warn' : 'fail',
            "cron/{$name}.php has never run on this deployment. " . $why,
            "Add to crontab:  {$crontab} php /var/www/html/cron/{$name}.php");
        continue;
    }
    $age = (int)$hb['age'];
    if ($period === 0) {
        $add('jobs', $label, 'ok', 'Last ran ' . cron_age_human($age) . '.');
        continue;
    }
    $lvl = $age > $period * 4 ? 'fail' : ($age > $period * 2 ? 'warn' : 'ok');
    $add('jobs', $label, $lvl,
        'Last ran ' . cron_age_human($age) . ' (expected ' . cron_age_human($period) . ').'
        . ($lvl === 'fail' ? ' It has stopped. ' . $why : ''),
        $lvl === 'fail' ? "Confirm the crontab line is still there:  {$crontab}" : null);
}

// ═══ E. Telling customers ═════════════════════════════════════════════════════
// The shared SMS and SMTP accounts every tenant falls back to.

$smsPlat = smsPlatformConfig($pdo);
if ($smsPlat === null) {
    // Distinguish "switched off" from "never filled in" — they look identical
    // to every tenant, whose settings page just says SMS is unavailable.
    $raw = null;
    try { $raw = $pdo->query("SELECT * FROM platform_sms_config WHERE id = 1")->fetch(PDO::FETCH_ASSOC) ?: null; } catch (Throwable $e) {}
    if (!$raw) {
        $add('comms', 'Platform SMS', 'fail',
            'platform_sms_config has no row. No tenant without their own TalkSasa key can send any SMS at all.',
            'Open Platform → Settings → SMS; the page creates and seeds the row.');
    } elseif (empty($raw['is_active'])) {
        $add('comms', 'Platform SMS', 'fail',
            'The platform SMS account is switched OFF. Every tenant relying on the shared account sends nothing.',
            'Enable it under Platform → Settings → SMS.');
    } else {
        $add('comms', 'Platform SMS', 'fail',
            'The platform SMS row is active but has no API key, so the shared account cannot send. Tenants see this as '
            . 'SMS silently not arriving, and cannot fix it themselves.',
            'Set the TalkSasa API token under Platform → Settings → SMS, then use Send Test to prove it works.');
    }
} else {
    $stale = smsApiUrlIsStale($smsPlat['api_url'] ?? null);
    $add('comms', 'Platform SMS', 'ok',
        'Sender ID ' . ($smsPlat['sender_id'] ?: '(none)') . ' via ' . $smsPlat['api_url'] . '.'
        . ' A saved key is not a working key — use Send Test on the settings page to confirm.');
    if ($stale) {
        $add('comms', 'Platform SMS endpoint', 'warn',
            'The stored api_url is the retired TalkSasa v1 endpoint. It is corrected on read so sending works, but the '
            . 'settings form is showing a URL that is not the one being used.',
            'Open Platform → Settings → SMS once; it heals the stored value.');
    }
}

try {
    $mail = $pdo->query("SELECT * FROM platform_email_config WHERE id = 1 AND is_active = 1")->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$mail || trim((string)($mail['smtp_host'] ?? '')) === '') {
        $add('comms', 'Platform email (SMTP)', 'fail',
            'No active SMTP host. Platform invoices, suspension warnings and the 3-day dunning notices are all sent by '
            . 'email — none of them leave the server.',
            'Configure it under Platform → Settings → Email.');
    } else {
        $add('comms', 'Platform email (SMTP)', 'ok', $mail['smtp_host'] . ':' . ($mail['smtp_port'] ?: 587));
    }
} catch (Throwable $e) {
    $add('comms', 'Platform email (SMTP)', 'warn', $e->getMessage());
}

// ═══ F. Paying the ISPs back ══════════════════════════════════════════════════
// Reuses the sender's own preflight rather than re-deriving it: if the two ever
// disagree, this page says payouts are ready while the cron refuses to send.

try {
    require_once __DIR__ . '/../../includes/payouts.php';
    $pre = disbursementPreflight($pdo);
    $blocked = $pre['reasons'] ?? [];
    if (!empty($pre['ok'])) {
        $add('payouts', 'Disbursement readiness', 'ok', 'B2C is configured and the platform payout switch is on.');
    } else {
        $add('payouts', 'Disbursement readiness', 'warn',
            'Payouts cannot send: ' . implode('; ', $blocked) . '.',
            'Run `php tools/payout_config.php` for the per-tenant view. This is only urgent if FortuNett is holding '
            . 'money for ISPs — see the outstanding figure below.');
    }
} catch (Throwable $e) {
    $add('payouts', 'Disbursement readiness', 'warn', $e->getMessage());
}

try {
    $owed = $pdo->query("
        SELECT COUNT(DISTINCT p.tenant_id) AS tenants, COALESCE(SUM(p.amount),0) AS amount
        FROM payments p
        WHERE p.status = 'completed' AND p.collection_type = 'platform' AND p.released_at IS NULL
    ")->fetch(PDO::FETCH_ASSOC) ?: ['tenants' => 0, 'amount' => 0];
    $amt = (float)$owed['amount'];
    $add('payouts', 'Money FortuNett is holding', $amt > 0 ? 'warn' : 'ok',
        $amt > 0
            ? 'KES ' . number_format($amt, 2) . ' across ' . (int)$owed['tenants'] . ' tenant(s) is tagged as collected '
              . 'by the platform and not yet released. Each of those tenants is being shown "Awaiting disbursement" on '
              . 'their billing page.'
            : 'Nothing outstanding — every platform-collected payment has been released.',
        $amt > 0 ? 'Verify the tagging is right before disbursing: php tools/collection_type_audit.php' : null);
} catch (Throwable $e) {
    $add('payouts', 'Money FortuNett is holding', 'warn', $e->getMessage());
}

// Hand-entered receipts tagged as platform money. record_manual.php writes
// payment_method 'mpesa' -- byte for byte what stk_push.php writes -- so a
// receipt an ISP typed in after collecting to their own paybill is
// indistinguishable by method from a platform-sent STK push. That is how 97 of
// one tenant's own receipts were booked as ours to disburse. The paired
// mpesa_transactions row carries the only conclusive marker.
try {
    require_once __DIR__ . '/../../includes/payment_routing.php';
    $manualSql = manuallyRecordedSql('p');
    $m = $pdo->query("
        SELECT COUNT(*) AS n, COUNT(DISTINCT p.tenant_id) AS tenants, COALESCE(SUM(p.amount),0) AS amount
        FROM payments p
        WHERE p.status = 'completed' AND p.collection_type = 'platform' AND ({$manualSql})
    ")->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0, 'tenants' => 0, 'amount' => 0];

    if ((int)$m['n'] > 0) {
        $add('payouts', 'Hand-entered receipts tagged as platform money', 'fail',
            (int)$m['n'] . ' payment(s) worth KES ' . number_format((float)$m['amount'], 2) . ' across '
            . (int)$m['tenants'] . ' tenant(s) were RECORDED BY HAND but are tagged platform-collected. Typing a receipt '
            . 'into the system asserts the money is already in that ISP\'s own account, so these can never be platform money. '
            . 'Each one is telling that ISP FortuNett owes them their own takings, and may have queued a real payout.',
            'Run: php tools/repair_collection_type.php --undo-manual --apply  (dry run first without --apply; it also '
            . 'cancels the queued payouts rather than deleting them)');
    } else {
        $add('payouts', 'Hand-entered receipts tagged as platform money', 'ok',
            'None — every hand-entered receipt is booked as direct.');
    }
} catch (Throwable $e) {
    $add('payouts', 'Hand-entered receipts tagged as platform money', 'warn', $e->getMessage());
}

// ═══ G. Who this affects ══════════════════════════════════════════════════════
// The point of the whole page: how many tenants depend on the shared half, and
// therefore how many tenant-level "failures" are actually this page's fault.

require_once __DIR__ . '/../../includes/payment_routing.php';

$dependents = [];
$selfSufficient = 0;
try {
    $tenants = $pdo->query("SELECT id, name, subdomain FROM tenants WHERE status IN ('active','trial') ORDER BY id")
                   ->fetchAll(PDO::FETCH_ASSOC);
    foreach ($tenants as $t) {
        $prof = tenantCollectionProfile($pdo, (int)$t['id']);
        if (!empty($prof['stk_own'])) { $selfSufficient++; continue; }
        $dependents[] = [
            'id'        => (int)$t['id'],
            'name'      => $t['name'],
            'subdomain' => $t['subdomain'],
            'mode'      => !empty($prof['paybill_own']) ? 'own paybill, no API' : 'shared paybill',
        ];
    }

    $platformFails = 0;
    foreach (['collection', 'reach', 'schema', 'jobs', 'comms'] as $g) {
        foreach ($groups[$g]['checks'] as $c) if ($c['level'] === 'fail') $platformFails++;
    }

    $sharedCount = count(array_filter($dependents, fn($d) => $d['mode'] === 'shared paybill'));
    if ($platformFails > 0 && $sharedCount > 0) {
        $add('tenants', 'Tenants on the shared paybill', 'fail',
            $sharedCount . ' tenant(s) route customer payments through the platform shortcode. The ' . $platformFails
            . ' platform-level failure(s) above hit all of them at once, and none of them can fix any of it from their '
            . 'own settings page.',
            'Clear the failures above before looking at any individual tenant.');
    } else {
        $add('tenants', 'Tenants on the shared paybill', $sharedCount > 0 ? 'ok' : 'ok',
            $sharedCount . ' tenant(s) route through the platform shortcode; the shared chain above is clean for them.');
    }

    $noApiCount = count(array_filter($dependents, fn($d) => $d['mode'] === 'own paybill, no API'));
    if ($noApiCount > 0) {
        $add('tenants', 'Tenants on their own paybill with no API', 'warn',
            $noApiCount . ' tenant(s) hold a shortcode of their own but have no Daraja credentials. Their customers pay '
            . 'them directly and nothing captures it — there is no API to register a C2B callback against. Their STK '
            . 'pushes, if any, still leave the PLATFORM shortcode, so those payments are genuinely platform money.',
            'These tenants reconcile by importing their M-Pesa statement. Do not read their "Platform Collections" '
            . 'figure as a bug without checking whether the rows are STK pushes.');
    }

    $add('tenants', 'Tenants with their own Daraja credentials', 'ok',
        $selfSufficient . ' tenant(s) push from their own shortcode and are unaffected by the shared paybill checks above.');
} catch (Throwable $e) {
    $add('tenants', 'Tenant rollup', 'warn', $e->getMessage());
}

// ── Roll up ───────────────────────────────────────────────────────────────────
$counts = ['ok' => 0, 'warn' => 0, 'fail' => 0];
foreach ($groups as $g) {
    foreach ($g['checks'] as $c) { $counts[$c['level']] = ($counts[$c['level']] ?? 0) + 1; }
}

if ($counts['fail'] > 0) {
    $verdict = ['level' => 'fail',
                'title' => $counts['fail'] . ' platform-level break' . ($counts['fail'] === 1 ? '' : 's'),
                'message' => 'These are FortuNett\'s to fix and they affect every tenant that depends on the shared half. '
                           . 'Clear them before diagnosing any individual tenant.'];
} elseif ($counts['warn'] > 0) {
    $verdict = ['level' => 'warn',
                'title' => 'Platform chain intact, ' . $counts['warn'] . ' caveat' . ($counts['warn'] === 1 ? '' : 's'),
                'message' => 'Nothing shared is blocking automation. Anything still failing on a tenant\'s own '
                           . 'diagnostics page is genuinely theirs.'];
} else {
    $verdict = ['level' => 'ok',
                'title' => 'Platform chain verified',
                'message' => 'The shared paybill, the callback host, the schema, the crons and the shared SMS/SMTP '
                           . 'accounts are all sound. Remaining failures are per-tenant.'];
}

ob_clean();
echo json_encode([
    'success'    => true,
    'verdict'    => $verdict,
    'counts'     => $counts,
    'groups'     => $groups,
    'dependents' => $dependents,
]);
