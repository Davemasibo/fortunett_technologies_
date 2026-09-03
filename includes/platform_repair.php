<?php
/**
 * Every platform-level break that can be repaired without a human decision.
 *
 * One implementation, two front ends: the button on super_admin/diagnostics.php
 * (via api/super_admin/platform_repair.php) and `php tools/platform_repair.php`.
 * They must never disagree about what "repaired" means — a fix that behaves one
 * way from the browser and another from the shell is worse than no fix.
 *
 * The line this file draws:
 *
 *   MECHANICAL — a missing migration, a retired API endpoint stored in a
 *                column, a derivable setting, a C2B registration. Nobody has to
 *                decide anything, so it is done here.
 *
 *   A DECISION — a Daraja consumer secret, a TalkSasa token, an SMTP password,
 *                sandbox-vs-live. Values only the operator holds. Inventing a
 *                default for any of them is worse than leaving them empty, so
 *                they are reported as `manual` and never guessed at.
 *
 * Crontab installation is deliberately NOT here: it needs a shell the web user
 * usually does not have, and it is the one repair that touches the machine
 * rather than the database. It lives in tools/platform_repair.php alone.
 *
 * Every mechanical repair is ADDITIVE and idempotent — enum members are only
 * added, columns are only created. Running twice reports "already correct".
 */

require_once __DIR__ . '/schema_guard.php';
require_once __DIR__ . '/sms_config.php';
require_once __DIR__ . '/payment_routing.php';

function _prGet(PDO $pdo, string $key, string $default = ''): string
{
    try {
        $s = $pdo->prepare("SELECT setting_value FROM platform_settings WHERE setting_key = ? LIMIT 1");
        $s->execute([$key]);
        $v = $s->fetchColumn();
        return $v !== false ? (string)$v : $default;
    } catch (Throwable $e) { return $default; }
}

function _prSet(PDO $pdo, string $key, string $value): void
{
    $pdo->prepare("INSERT INTO platform_settings (setting_key, setting_value) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$key, $value]);
}

function _prIsPrivateIp(string $ip): bool
{
    return (bool)preg_match('/^(10\.|127\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $ip);
}

/**
 * Run (or plan) every mechanical repair.
 *
 * @param array $opts apply(bool) — write changes; base_url, external_ip — values
 *                    the caller supplies rather than having derived.
 * @return array{steps:array,counts:array,manual:array}
 *   Each step: id, title, status (ok|fixed|would|manual|error), detail, action.
 */
function platformRepairRun(PDO $pdo, array $opts = []): array
{
    $apply     = !empty($opts['apply']);
    $argBase   = trim((string)($opts['base_url'] ?? ''));
    $argExtIp  = trim((string)($opts['external_ip'] ?? ''));

    $steps = [];
    $add = function (string $id, string $title, string $status, string $detail, ?string $action = null) use (&$steps) {
        $steps[] = ['id' => $id, 'title' => $title, 'status' => $status, 'detail' => $detail, 'action' => $action];
    };

    // ── 1. Database migrations ───────────────────────────────────────────────
    // Under STRICT_TRANS_TABLES each of these is a hard throw mid-handler,
    // landing BEFORE the activation step: the money is recorded and the customer
    // is never connected, with no error anywhere the operator looks.
    if ($apply) {
        try {
            ensurePaymentStatusEnums($pdo);
            $add('enums', 'Status and method enums', 'fixed',
                'clients.status, payments.payment_method and payments.status now accept every value the code writes. '
                . 'Only missing members are added — nothing is ever removed.');
        } catch (Throwable $e) {
            $add('enums', 'Status and method enums', 'error', $e->getMessage());
        }
        try {
            ensurePlatformBillingSchema($pdo);
            $add('billing_schema', 'Platform billing columns', 'fixed',
                'platform_invoices reconciled, including amount_paid — the column whose absence returns a blank 500 on '
                . 'every tenant billing page while curl still reports a healthy 302.');
        } catch (Throwable $e) {
            $add('billing_schema', 'Platform billing columns', 'error', $e->getMessage());
        }
        try {
            ensureSmsTables($pdo);
            $add('sms_schema', 'SMS log tables', 'fixed',
                'sms_outbox and sms_logs exist. sms_logs is defined in no schema file, so the payment SMS had no dedupe '
                . 'key and left no record anywhere the operator could see it — the reason "is SMS sending?" could not be '
                . 'answered from the admin UI.');
        } catch (Throwable $e) {
            $add('sms_schema', 'SMS log tables', 'error', $e->getMessage());
        }
    } else {
        $add('enums', 'Status and method enums', 'would',
            'Add any missing enum member on clients.status, payments.payment_method and payments.status.');
        $add('billing_schema', 'Platform billing columns', 'would',
            'Add platform_invoices.amount_paid and the rest of the 2026-07-26 migration.');
        $add('sms_schema', 'SMS log tables', 'would',
            'Create sms_outbox and sms_logs so customer SMS is deduped and recorded.');
    }

    // Columns those two do not cover. The mpesa_transactions ones sit BEFORE
    // process_payment_success() in both C2B handlers, so a missing one aborts
    // the handler and nobody is activated even though the money arrived.
    foreach ([
        ['mpesa_transactions', 'tenant_id',            'INT DEFAULT NULL'],
        ['mpesa_transactions', 'mpesa_receipt_number', 'VARCHAR(64) DEFAULT NULL'],
        ['mpesa_transactions', 'raw_callback',         'TEXT DEFAULT NULL'],
        ['payments',           'collection_type',      "ENUM('platform','direct') NOT NULL DEFAULT 'direct'"],
        ['payments',           'released_at',          'DATETIME DEFAULT NULL'],
        ['payments',           'release_note',         'VARCHAR(255) DEFAULT NULL'],
    ] as [$table, $col, $def]) {
        try {
            $exists = (string)$pdo->query("
                SELECT COLUMN_NAME FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($table) . "
                  AND COLUMN_NAME = " . $pdo->quote($col))->fetchColumn();
            if ($exists !== '') {
                $add("col_{$table}_{$col}", "{$table}.{$col}", 'ok', 'Present.');
            } elseif ($apply) {
                ensureColumn($pdo, $table, $col, $def);
                $add("col_{$table}_{$col}", "{$table}.{$col}", 'fixed', 'Added as ' . $def . '.');
            } else {
                $add("col_{$table}_{$col}", "{$table}.{$col}", 'would', 'Add as ' . $def . '.');
            }
        } catch (Throwable $e) {
            $add("col_{$table}_{$col}", "{$table}.{$col}", 'error', $e->getMessage());
        }
    }

    // ── 2. Retired SMS endpoint stored in the database ───────────────────────
    // platform_sms_config was CREATED with a DEFAULT of the TalkSasa v1 URL,
    // which no longer exists, so the platform row is stale on every deployment
    // that never edited it. Sending already works (normalised on read); this
    // stops the settings form showing a URL that is not the one being used.
    $stale = 0;
    foreach (['platform_sms_config', 'sms_configurations'] as $tbl) {
        try {
            foreach ($pdo->query("SELECT api_url FROM {$tbl}")->fetchAll(PDO::FETCH_COLUMN) as $u) {
                if (smsApiUrlIsStale($u)) $stale++;
            }
        } catch (Throwable $e) { /* table may not exist */ }
    }
    if ($stale === 0) {
        $add('sms_url', 'Stored SMS endpoints', 'ok', 'Nothing points at the retired TalkSasa v1 endpoint.');
    } elseif ($apply) {
        $n = smsHealStoredApiUrls($pdo);
        $add('sms_url', 'Stored SMS endpoints', 'fixed', "Rewrote {$n} stored api_url(s) to " . SMS_API_URL_DEFAULT . '.');
    } else {
        $add('sms_url', 'Stored SMS endpoints', 'would', "Rewrite {$stale} stored api_url(s) to " . SMS_API_URL_DEFAULT . '.');
    }

    // ── 3. Derivable platform settings ───────────────────────────────────────
    // public_base_url is what anything running from CRON uses to build a
    // callback, where there is no request host to fall back on. A relative path
    // sails past every empty-string check and becomes an unreachable ResultURL:
    // the payout goes out and its confirmation never comes back.
    $base = trim(_prGet($pdo, 'public_base_url', ''));
    if ($base !== '' && stripos($base, 'https://') === 0) {
        $add('base_url', 'Public base URL', 'ok', $base);
    } else {
        $cand = $argBase;
        if ($cand === '') {
            $domain = trim(_prGet($pdo, 'platform_domain', ''));
            if ($domain !== '') $cand = 'https://' . preg_replace('#^https?://#i', '', rtrim($domain, '/'));
        }
        if ($cand === '' || stripos($cand, 'https://') !== 0) {
            $add('base_url', 'Public base URL', 'manual',
                'Not set, and platform_domain is empty too, so it cannot be derived.',
                'Set it under Platform → Settings, or pass --base-url=https://your-domain to the CLI tool.');
        } elseif ($apply) {
            _prSet($pdo, 'public_base_url', $cand);
            $base = $cand;
            $add('base_url', 'Public base URL', 'fixed', 'Set to ' . $cand
                . ($argBase ? '.' : ' (derived from platform_domain).'));
        } else {
            $base = $cand;
            $add('base_url', 'Public base URL', 'would', 'Set to ' . $cand
                . ($argBase ? '.' : ' (derived from platform_domain).'));
        }
    }

    // server_external_ip is the WireGuard endpoint. Provisioning otherwise
    // falls back to resolving the request host, which on a proxied deployment
    // is the CDN edge — and the CDN does not answer UDP 51820.
    $extIp = trim(_prGet($pdo, 'server_external_ip', ''));
    if ($extIp !== '' && filter_var($extIp, FILTER_VALIDATE_IP) && !_prIsPrivateIp($extIp)) {
        $add('ext_ip', 'WireGuard endpoint IP', 'ok', $extIp);
    } else {
        $cand = $argExtIp;
        if ($cand === '') {
            // Detected, never invented. A wrong endpoint IP disables every
            // tunnel provisioned with it, so a failed or private lookup leaves
            // the value alone rather than writing a guess.
            foreach (['https://api.ipify.org', 'https://ifconfig.me/ip', 'https://icanhazip.com'] as $probe) {
                $ctx = stream_context_create(['http' => ['timeout' => 5], 'https' => ['timeout' => 5]]);
                $v   = @trim((string)@file_get_contents($probe, false, $ctx));
                if ($v !== '' && filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && !_prIsPrivateIp($v)) {
                    $cand = $v; break;
                }
            }
        }
        if ($cand === '' || !filter_var($cand, FILTER_VALIDATE_IP) || _prIsPrivateIp($cand)) {
            $add('ext_ip', 'WireGuard endpoint IP', 'manual',
                'Empty or private, and could not be detected from this server.',
                'Set the VPS public IP under Platform → Settings, or pass --external-ip=<ip> to the CLI tool.');
        } elseif ($apply) {
            _prSet($pdo, 'server_external_ip', $cand);
            $add('ext_ip', 'WireGuard endpoint IP', 'fixed', 'Set to ' . $cand . ($argExtIp ? '.' : ' (detected).'));
        } else {
            $add('ext_ip', 'WireGuard endpoint IP', 'would', 'Set to ' . $cand . ($argExtIp ? '.' : ' (detected).'));
        }
    }

    // ── 4. Platform C2B registration ─────────────────────────────────────────
    // The only thing that captures a customer who pays the shared paybill from
    // their own M-Pesa menu instead of through the portal. Without it that money
    // lands in FortuNett's account and no tenant, customer or payment row ever
    // hears about it.
    $plat = [];
    try { $plat = $pdo->query("SELECT * FROM platform_mpesa_config WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: []; }
    catch (Throwable $e) { /* reported in the credentials section */ }

    $credsComplete = $plat
        && trim((string)($plat['consumer_key'] ?? '')) !== ''
        && trim((string)($plat['consumer_secret'] ?? '')) !== ''
        && trim((string)($plat['passkey'] ?? '')) !== ''
        && trim((string)($plat['shortcode'] ?? '')) !== '';

    if (!$credsComplete) {
        $add('c2b', 'Platform C2B registration', 'manual',
            'Nothing to register against yet — the platform Daraja credentials are incomplete.',
            'Complete them under Platform → M-Pesa, then run this again; registration is automatic.');
    } elseif (trim((string)($plat['c2b_confirmation_url'] ?? '')) !== '') {
        $add('c2b', 'Platform C2B registration', 'ok', 'Registered → ' . $plat['c2b_confirmation_url']);
    } elseif ($base === '' || stripos($base, 'https://') !== 0) {
        $add('c2b', 'Platform C2B registration', 'manual',
            'Cannot build the callback URLs until the public base URL is usable.',
            'Fix the Public base URL above, then run this again.');
    } else {
        $host = rtrim($base, '/');
        $urls = [
            'validation'   => $host . '/api/payment/c2b_validation.php',
            'confirmation' => $host . '/api/payment/c2b_confirmation.php',
        ];
        // Safaricom rejects any callback URL containing "mpesa" — the reason
        // these endpoints live under /api/payment/ rather than /api/mpesa/.
        if (stripos($urls['confirmation'], 'mpesa') !== false) {
            $add('c2b', 'Platform C2B registration', 'manual',
                'The derived URL contains the word "mpesa", which Safaricom rejects.',
                'Register against a platform domain that does not contain "mpesa".');
        } elseif (!$apply) {
            $add('c2b', 'Platform C2B registration', 'would', 'Register ' . $urls['confirmation']);
        } else {
            try {
                require_once __DIR__ . '/../classes/MpesaAPI.php';
                $api = new MpesaAPI($pdo, null);
                $api->loadFromArray($plat);
                $res = $api->registerC2B($urls['validation'], $urls['confirmation']);
                if (!empty($res['success'])) {
                    $pdo->prepare("UPDATE platform_mpesa_config
                                   SET c2b_validation_url = ?, c2b_confirmation_url = ? WHERE id = 1")
                        ->execute([$urls['validation'], $urls['confirmation']]);
                    $add('c2b', 'Platform C2B registration', 'fixed', 'Registered → ' . $urls['confirmation']);
                } else {
                    $err = (string)($res['error'] ?? ($res['message'] ?? 'unknown error'));
                    // A Buy Goods till registers against the STORE number, not
                    // the till the customer pays to. Without it Safaricom
                    // returns an error that reads exactly like bad credentials.
                    if (($plat['shortcode_type'] ?? '') === 'till' && trim((string)($plat['store_number'] ?? '')) === '') {
                        $err .= ' — and this shortcode is a Buy Goods till with NO store number set. Registration keys '
                              . 'on the store / head-office number, so this error looks like bad credentials but is not.';
                    }
                    $add('c2b', 'Platform C2B registration', 'manual', 'Safaricom rejected it: ' . $err,
                        'Platform → M-Pesa.');
                }
            } catch (Throwable $e) {
                $add('c2b', 'Platform C2B registration', 'error', $e->getMessage());
            }
        }
    }

    // ── 5. Mis-tagged hand-entered receipts ──────────────────────────────────
    // Delegated rather than duplicated: repair_collection_type.php also cancels
    // the queued payouts and refuses to touch anything already released.
    try {
        $manualSql = manuallyRecordedSql('p');
        $m = $pdo->query("
            SELECT COUNT(*) AS n, COALESCE(SUM(p.amount),0) AS amount, COUNT(DISTINCT p.tenant_id) AS tenants
            FROM payments p
            WHERE p.status = 'completed' AND p.collection_type = 'platform' AND ({$manualSql})
        ")->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0, 'amount' => 0, 'tenants' => 0];

        if ((int)$m['n'] === 0) {
            $add('manual_tags', 'Hand-entered receipts tagged as platform money', 'ok',
                'None — every hand-entered receipt is booked as direct.');
        } else {
            $add('manual_tags', 'Hand-entered receipts tagged as platform money', 'manual',
                (int)$m['n'] . ' payment(s) worth KES ' . number_format((float)$m['amount'], 2) . ' across '
                . (int)$m['tenants'] . ' tenant(s). Typing a receipt in asserts the money is already in that ISP\'s own '
                . 'account, so these can never be platform money — each one tells an ISP FortuNett owes them their own '
                . 'takings, and may have queued a real payout.',
                'Run on the server: php tools/repair_collection_type.php --undo-manual  (then add --apply). It is kept '
                . 'separate because it also cancels the queued payouts and refuses to touch anything already released.');
        }
    } catch (Throwable $e) {
        $add('manual_tags', 'Hand-entered receipts tagged as platform money', 'error', $e->getMessage());
    }

    // ── 6. Values only the operator holds ────────────────────────────────────
    if (!$credsComplete) {
        $miss = [];
        foreach (['consumer_key' => 'Consumer Key', 'consumer_secret' => 'Consumer Secret',
                  'passkey' => 'Passkey', 'shortcode' => 'Shortcode'] as $k => $lbl) {
            if (trim((string)($plat[$k] ?? '')) === '') $miss[] = $lbl;
        }
        $add('mpesa_creds', 'Platform M-Pesa credentials', 'manual',
            'Missing: ' . implode(', ', $miss) . '. Every tenant with no Daraja credentials of their own routes through '
            . 'this shortcode, so their STK pushes fail at token exchange before a phone ever rings.',
            'Platform → M-Pesa (super_admin/mpesa.php).');
    } else {
        $env = strtolower((string)($plat['environment'] ?? 'sandbox'));
        if ($env !== 'live' && $env !== 'production') {
            $add('mpesa_creds', 'Platform M-Pesa credentials', 'manual',
                "Complete, but the environment is '{$env}'. Sandbox returns a success code and never pushes to a real "
                . 'phone, so every tenant on the shared paybill sees payments that look initiated and never arrive.',
                'Platform → M-Pesa — switch to live once Safaricom has approved the shortcode.');
        } else {
            $add('mpesa_creds', 'Platform M-Pesa credentials', 'ok',
                'Live on shortcode ' . $plat['shortcode'] . '.');
        }
        if (($plat['shortcode_type'] ?? '') === 'till' && trim((string)($plat['store_number'] ?? '')) === '') {
            $add('store_number', 'Buy Goods store number', 'manual',
                'The platform shortcode is a Buy Goods till with no store number. STK push and C2B registration both '
                . 'authenticate against the store / head-office number, and Safaricom\'s error reads like bad credentials.',
                'Platform → M-Pesa — add the store number.');
        }
    }

    if (smsPlatformConfig($pdo) === null) {
        $raw = null;
        try { $raw = $pdo->query("SELECT * FROM platform_sms_config WHERE id = 1")->fetch(PDO::FETCH_ASSOC) ?: null; }
        catch (Throwable $e) {}
        if ($raw && empty($raw['is_active'])) {
            $add('sms_creds', 'Platform SMS credentials', 'manual',
                'The shared SMS account is switched OFF. Every tenant relying on it sends nothing.',
                'Platform → Settings → SMS — enable it.');
        } else {
            $add('sms_creds', 'Platform SMS credentials', 'manual',
                'No API key, so no tenant without their own TalkSasa token can send anything.',
                'Platform → Settings → SMS — paste the token, then use Send Test. A saved key is not a working key: '
                . 'TalkSasa returns "Unauthenticated." with an HTTP 200.');
        }
    } else {
        $add('sms_creds', 'Platform SMS credentials', 'ok',
            'Present. Use Send Test on the settings page to prove they work.');
    }

    try {
        $mail = $pdo->query("SELECT * FROM platform_email_config WHERE id = 1 AND is_active = 1")->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$mail || trim((string)($mail['smtp_host'] ?? '')) === '') {
            $add('smtp', 'Platform email (SMTP)', 'manual',
                'Not configured. Platform invoices, suspension warnings and dunning notices never leave the server.',
                'Platform → Settings → Email.');
        } else {
            $add('smtp', 'Platform email (SMTP)', 'ok', $mail['smtp_host'] . ':' . ($mail['smtp_port'] ?: 587));
        }
    } catch (Throwable $e) {
        $add('smtp', 'Platform email (SMTP)', 'error', $e->getMessage());
    }

    $counts = ['ok' => 0, 'fixed' => 0, 'would' => 0, 'manual' => 0, 'error' => 0];
    foreach ($steps as $s) $counts[$s['status']] = ($counts[$s['status']] ?? 0) + 1;

    return [
        'steps'  => $steps,
        'counts' => $counts,
        'manual' => array_values(array_filter($steps, fn($s) => $s['status'] === 'manual')),
    ];
}
