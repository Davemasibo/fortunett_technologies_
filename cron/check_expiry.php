<?php
/**
 * Client Expiry Enforcer — FortuNett Technologies
 *
 * Runs every 15 minutes. For each active client whose expiry_date has passed:
 *   1. Updates DB status to 'inactive'
 *   2. Disables the user on MikroTik (PPPoE or hotspot) and kicks the active session
 *
 * Cron schedule (every 15 minutes):
 *   * /15 * * * * php /var/www/html/cron/check_expiry.php >> /var/log/fortunett_expiry.log 2>&1
 */

define('CRON_MODE', true);
chdir(dirname(__DIR__));

require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/../classes/MikrotikAPI.php';
require_once __DIR__ . '/../classes/SMSHelper.php';
require_once __DIR__ . '/../includes/radius_client.php';

// Add missing columns silently (safe to run repeatedly)
try { $pdo->exec("ALTER TABLE mikrotik_routers ADD COLUMN vpn_ip VARCHAR(45) NULL DEFAULT NULL"); } catch (Throwable $_) {}
try { $pdo->exec("ALTER TABLE clients ADD COLUMN last_seen DATETIME NULL DEFAULT NULL"); } catch (Throwable $_) {}

$log = function(string $msg) {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
};

$graceDays = 3; // Days between expiry and full block (walled garden window)

$log("=== Expiry Check: " . date('Y-m-d H:i:s') . " | grace={$graceDays}d ===");

// Ensure every status value the app writes is a valid enum member.
//
// This previously ran a hard-coded MODIFY to
//   ENUM('active','inactive','suspended','grace')
// every 15 minutes, which NARROWED the column and silently deleted the
// 'pending' and 'expired' members that the hotspot signup and expiry paths
// depend on. Any migration that widened the enum was reverted within the
// quarter hour, so the STK push kept failing with
// "1265 Data truncated for column 'status'" no matter how often it was fixed.
// The guard only ever adds members, never removes them.
require_once __DIR__ . '/../includes/schema_guard.php';
ensurePaymentStatusEnums($pdo);
// Silently add reminder flag columns (no-op if already present)
try {
    $cols = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clients' AND COLUMN_NAME IN ('expiry_reminder_3d_sent','expiry_reminder_1d_sent')")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('expiry_reminder_3d_sent', $cols)) { $pdo->exec("ALTER TABLE clients ADD COLUMN expiry_reminder_3d_sent TINYINT(1) NOT NULL DEFAULT 0"); }
    if (!in_array('expiry_reminder_1d_sent', $cols)) { $pdo->exec("ALTER TABLE clients ADD COLUMN expiry_reminder_1d_sent TINYINT(1) NOT NULL DEFAULT 0"); }
} catch (Throwable $_) {}

// ── 0. Pre-expiry SMS reminders (3-day and 1-day warnings) ───────────────────
try {
    $reminders = [
        ['days' => 3, 'flag' => 'expiry_reminder_3d_sent', 'label' => '3-day'],
        ['days' => 1, 'flag' => 'expiry_reminder_1d_sent', 'label' => '1-day'],
    ];

    foreach ($reminders as $r) {
        $days = $r['days'];
        $flag = $r['flag'];

        // Window: expiry is between now+$days-0.5h and now+$days+0.5h
        // The cron runs every 15 min so a ±30-min window avoids duplicates
        // while ensuring the reminder fires even if cron is slightly late.
        $rStmt = $pdo->prepare("
            SELECT c.id, c.full_name, c.phone, c.account_number, c.tenant_id,
                   c.expiry_date,
                   p.name AS pkg_name, p.price AS pkg_price,
                   t.company_name AS tenant_name, t.subdomain
            FROM clients c
            LEFT JOIN packages p ON p.id = c.package_id
            LEFT JOIN tenants t ON t.id = c.tenant_id
            WHERE c.status = 'active'
              AND c.expiry_date IS NOT NULL
              AND c.expiry_date BETWEEN NOW() + INTERVAL ? HOUR AND NOW() + INTERVAL ? HOUR
              AND c.{$flag} = 0
              AND c.phone IS NOT NULL AND c.phone != ''
        ");
        // Window: ($days * 24 - 12) to ($days * 24 + 12) hours from now
        $rStmt->execute([$days * 24 - 12, $days * 24 + 12]);
        $upcoming = $rStmt->fetchAll(PDO::FETCH_ASSOC);

        $log("Pre-expiry {$r['label']} reminders: " . count($upcoming) . " client(s).");

        foreach ($upcoming as $client) {
            $tenantId = (int)$client['tenant_id'];
            $clientId = (int)$client['id'];
            try {
                $sms = new SMSHelper($pdo, $tenantId);
                if (!$sms->hasConfig()) continue;

                $subdomain     = $client['subdomain'] ?? '';
                $renewUrl      = 'https://' . $subdomain . '.' . $platformDomain . '/customer/renew.php';
                $accNo         = $client['account_number'] ?? '';
                $pkgPrice      = isset($client['pkg_price']) ? 'KES ' . number_format((float)$client['pkg_price'], 0) : '';
                $company       = $client['tenant_name'] ?? 'Your ISP';
                $firstName     = explode(' ', trim($client['full_name']))[0];
                $expiryHuman   = date('d M H:i', strtotime($client['expiry_date']));

                require_once __DIR__ . '/../includes/credential_helper.php';
                $paybill = '';
                try {
                    $gwSt = $pdo->prepare("SELECT credentials FROM payment_gateways WHERE tenant_id = ? AND gateway_type = 'mpesa_api' AND is_active = 1 LIMIT 1");
                    $gwSt->execute([$tenantId]);
                    $gwRow = $gwSt->fetch(PDO::FETCH_ASSOC);
                    if ($gwRow) { $paybill = decrypt_gateway_credentials($gwRow['credentials'])['shortcode'] ?? ''; }
                } catch (Throwable $_e) {}

                if ($paybill && $accNo && $pkgPrice) {
                    $msg = "Hi {$firstName}, your {$company} internet expires {$expiryHuman}. Renew now: M-Pesa Paybill {$paybill} Acc {$accNo} {$pkgPrice}.";
                } else {
                    $msg = "Hi {$firstName}, your {$company} internet expires {$expiryHuman}. Renew at {$renewUrl}";
                }
                if (strlen($msg) > 160) $msg = substr($msg, 0, 157) . '...';

                $smsResult = $sms->send($client['phone'], $msg, $clientId);
                $sent = $smsResult['success'] ?? false;

                if ($sent) {
                    $pdo->prepare("UPDATE clients SET {$flag} = 1 WHERE id = ? AND tenant_id = ?")
                        ->execute([$clientId, $tenantId]);
                }
                $log("  [{$tenantId}] #{$clientId} {$client['full_name']} — {$r['label']} reminder " . ($sent ? 'sent' : 'failed'));
            } catch (Throwable $smsEx) {
                $log("  [{$tenantId}] #{$clientId} — reminder SMS error: " . $smsEx->getMessage());
            }
        }
    }
} catch (Throwable $reminderEx) {
    $log("Pre-expiry reminder block error: " . $reminderEx->getMessage());
}

// ── 1. Clients newly expired (active → grace) ─────────────────────────────────
// Expiry just passed — throttle but don't kick yet
$stmt = $pdo->prepare("
    SELECT c.id, c.full_name, c.mikrotik_username, c.connection_type, c.tenant_id,
           c.expiry_date, c.phone, c.account_number,
           p.name AS pkg_name, p.price AS pkg_price,
           t.company_name AS tenant_name, t.subdomain
    FROM clients c
    LEFT JOIN packages p ON p.id = c.package_id
    LEFT JOIN tenants t ON t.id = c.tenant_id
    WHERE c.status = 'active'
      AND c.expiry_date IS NOT NULL
      AND c.expiry_date < NOW()
      AND c.expiry_date >= NOW() - INTERVAL ? DAY
");
$stmt->execute([$graceDays]);
$newlyExpired = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── 2. Clients in grace whose grace period has elapsed (grace → fully blocked) ─
$stmtBlock = $pdo->prepare("
    SELECT c.id, c.full_name, c.mikrotik_username, c.connection_type, c.tenant_id,
           c.expiry_date, c.phone, c.account_number,
           p.name AS pkg_name, p.price AS pkg_price,
           t.company_name AS tenant_name, t.subdomain
    FROM clients c
    LEFT JOIN packages p ON p.id = c.package_id
    LEFT JOIN tenants t ON t.id = c.tenant_id
    WHERE c.status IN ('active', 'grace')
      AND c.expiry_date IS NOT NULL
      AND c.expiry_date < NOW() - INTERVAL ? DAY
");
$stmtBlock->execute([$graceDays]);
$toBlock = $stmtBlock->fetchAll(PDO::FETCH_ASSOC);

$expired = array_merge($newlyExpired, $toBlock); // used for router-grouping below

// Fetch platform domain once
$platformDomain = 'fortunetttech.site';
try {
    $pdStmt = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'platform_domain' LIMIT 1");
    $pd = $pdStmt ? $pdStmt->fetchColumn() : null;
    if ($pd) $platformDomain = $pd;
} catch (Throwable $_e) {}

// Index by ID for quick lookup
$toBlockIds     = array_column($toBlock, 'id');
$newlyExpiredIds = array_column($newlyExpired, 'id');

$log("Found " . count($newlyExpired) . " newly expired client(s) → grace period.");
$log("Found " . count($toBlock)      . " client(s) past grace period → full block.");

if (empty($expired)) {
    $log("Nothing to do.");
    exit(0);
}

// ── Group by tenant so we only open one router connection per tenant ──────────
$byTenant = [];
foreach ($expired as $client) {
    $byTenant[$client['tenant_id']][] = $client;
}

foreach ($byTenant as $tenantId => $clients) {
    // Fetch the first active router for this tenant
    $rStmt = $pdo->prepare("
        SELECT id, name, ip_address, vpn_ip, username, password, api_port
        FROM mikrotik_routers
        WHERE tenant_id = ? AND status IN ('active','online')
        ORDER BY id ASC LIMIT 1
    ");
    $rStmt->execute([$tenantId]);
    $router = $rStmt->fetch(PDO::FETCH_ASSOC);

    // Connect to router once per tenant (may fail — DB update still proceeds)
    $api         = null;
    $routerError = null;
    if ($router) {
        $connectIp = !empty($router['vpn_ip']) ? $router['vpn_ip'] : $router['ip_address'];
        $port      = (int)($router['api_port'] ?: 8728);
        try {
            $sock = @fsockopen($connectIp, $port, $errno, $errstr, 4);
            if ($sock) {
                fclose($sock);
                $api = new MikrotikAPI($connectIp, $router['username'], $router['password'], $port);
                $api->connect();
            } else {
                $routerError = "TCP unreachable ({$connectIp}:{$port})";
            }
        } catch (Throwable $e) {
            $routerError = $e->getMessage();
        }
    } else {
        $routerError = "No active router configured for tenant {$tenantId}";
    }

    if ($routerError) {
        $log("  Tenant {$tenantId}: router unavailable — {$routerError} (DB status will still update)");
    }

    foreach ($clients as $client) {
        $clientId  = (int)$client['id'];
        $name      = $client['full_name'];
        $uname     = $client['mikrotik_username'];
        $connType  = strtolower($client['connection_type'] ?? 'hotspot');
        $isFullBlock = in_array($clientId, $toBlockIds);

        if ($isFullBlock) {
            // ── Full block: disable on router + kick + RADIUS reject ──────────
            $pdo->prepare("UPDATE clients SET status = 'inactive', updated_at = NOW() WHERE id = ? AND tenant_id = ?")
                ->execute([$clientId, $tenantId]);
            $log("  [{$tenantId}] #{$clientId} {$name} — FULL BLOCK (grace expired)");

            if ($api && !empty($uname)) {
                try {
                    if ($connType === 'pppoe') {
                        $api->disablePPPoEUser($uname);
                        $api->kickPPPoESession($uname);
                        radius_disable_client($pdo, $uname);
                    } else {
                        $api->disableHotspotUser($uname);
                    }
                    $log("  [{$tenantId}] #{$clientId} {$name} — router disabled+kicked ({$connType})");
                } catch (Throwable $e) {
                    $log("  [{$tenantId}] #{$clientId} {$name} — router error: " . $e->getMessage());
                }
            }
        } else {
            // ── Grace: throttle only — do NOT kick the active session ─────────
            // Client can still reach the payment page but general internet is throttled.
            $pdo->prepare("UPDATE clients SET status = 'grace', updated_at = NOW() WHERE id = ? AND tenant_id = ? AND status = 'active'")
                ->execute([$clientId, $tenantId]);
            $log("  [{$tenantId}] #{$clientId} {$name} — GRACE PERIOD (throttled, not kicked)");

            if (!empty($uname)) {
                // RADIUS walled-garden (PPPoE — speed throttled, session stays up)
                radius_walled_garden_client($pdo, $uname);

                if ($api && $connType === 'hotspot') {
                    try {
                        // If a "walled-garden" hotspot profile exists on the router, switch to it.
                        // If not, disable the user (fallback to old behaviour for hotspot).
                        $profileSet = @$api->setHotspotUserProfile($uname, 'walled-garden');
                        if (!$profileSet) {
                            $api->disableHotspotUser($uname);
                        }
                    } catch (Throwable $e) {
                        $log("  [{$tenantId}] #{$clientId} — hotspot grace error: " . $e->getMessage());
                    }
                } elseif ($api && $connType === 'pppoe') {
                    // PPPoE grace was a no-op on MikroTik: the branch above only ever
                    // ran for hotspot, and radius_walled_garden_client() does nothing
                    // for tenants not using RADIUS. Expired PPPoE customers therefore
                    // stayed online at full speed for the whole grace window — and
                    // stayed online indefinitely if the full-block pass later failed.
                    try {
                        $throttled = @$api->updatePPPoEUser($uname, null, 'walled-garden');
                        if ($throttled) {
                            // Kick so the CPE redials onto the throttled profile —
                            // an established session keeps its original rate otherwise.
                            $api->kickPPPoESession($uname);
                            $log("  [{$tenantId}] #{$clientId} {$name} — pppoe throttled to walled-garden");
                        } else {
                            // No walled-garden profile on this router: better to cut
                            // access than to leave an expired customer at full speed.
                            $api->disablePPPoEUser($uname);
                            $api->kickPPPoESession($uname);
                            $log("  [{$tenantId}] #{$clientId} {$name} — no walled-garden profile, pppoe disabled+kicked");
                        }
                    } catch (Throwable $e) {
                        $log("  [{$tenantId}] #{$clientId} — pppoe grace error: " . $e->getMessage());
                    }
                }
            }
        }

        // ── Send SMS on first expiry (status was 'active' → now 'grace') ─────
        if (!$isFullBlock && $client['phone'] ?? '') {
            try {
                $sms = new SMSHelper($pdo, $tenantId);
                if ($sms->hasConfig()) {
                    $renewUrl  = 'https://' . ($client['subdomain'] ?? '') . '.' . $platformDomain . '/customer/renew.php';
                    $accNo     = $client['account_number'] ?? '';
                    $pkgPrice  = isset($client['pkg_price']) ? 'KES ' . number_format((float)$client['pkg_price'], 0) : '';
                    $company   = $client['tenant_name'] ?? 'Your ISP';
                    $firstName = explode(' ', $name)[0];

                    // Fetch paybill for this tenant
                    require_once __DIR__ . '/../includes/credential_helper.php';
                    $paybill = '';
                    try {
                        $gwSt = $pdo->prepare("SELECT credentials FROM payment_gateways WHERE tenant_id = ? AND gateway_type = 'mpesa_api' AND is_active = 1 LIMIT 1");
                        $gwSt->execute([$tenantId]);
                        $gwRow = $gwSt->fetch(PDO::FETCH_ASSOC);
                        if ($gwRow) { $paybill = decrypt_gateway_credentials($gwRow['credentials'])['shortcode'] ?? ''; }
                    } catch (Throwable $_e) {}

                    if ($paybill && $accNo && $pkgPrice) {
                        $msg = "Hi {$firstName}, your {$company} internet has expired. Renew: M-Pesa Paybill {$paybill} Acc {$accNo} {$pkgPrice}. Or visit {$renewUrl}";
                    } else {
                        $msg = "Hi {$firstName}, your {$company} internet has expired. Visit {$renewUrl} to renew.";
                    }
                    if (strlen($msg) > 160) $msg = substr($msg, 0, 157) . '...';

                    $smsResult = $sms->send($client['phone'], $msg, $clientId);
                    $log("  [{$tenantId}] #{$clientId} {$name} — expiry SMS " . ($smsResult['success'] ? 'sent' : 'failed'));
                }
            } catch (Throwable $smsEx) {
                $log("  [{$tenantId}] #{$clientId} — SMS error: " . $smsEx->getMessage());
            }
        }
    }

    if ($api) {
        try { $api->disconnect(); } catch (Throwable $e) { /* ignore */ }
    }
}

// ── 4. Enforcement sweep — anyone online who shouldn't be ─────────────────────
//
// Everything above only looks at clients whose DB status is 'active' or 'grace'
// and whose expiry just passed. That leaves a permanent hole: a client already
// marked inactive/expired/suspended — by an admin, a failed earlier run, a
// router that was unreachable at the time, or a manual DB edit — is never
// revisited, so their PPPoE secret stays enabled and their session stays up
// forever. That is why expired customers show as online in Active Clients.
//
// This pass works from the ROUTER's point of view instead: list who is actually
// connected right now, and cut anyone whose account is not currently active.
$log("--- Enforcement sweep: live sessions vs account status ---");

$routers = $pdo->query("
    SELECT id, tenant_id, name, ip_address, vpn_ip, username, password, api_port
    FROM mikrotik_routers
    WHERE status IN ('active','online')
    ORDER BY tenant_id, id
")->fetchAll(PDO::FETCH_ASSOC);

$sweptOff = 0;

foreach ($routers as $r) {
    $tid       = (int)$r['tenant_id'];
    $connectIp = !empty($r['vpn_ip']) ? $r['vpn_ip'] : $r['ip_address'];
    $rName     = $r['name'] ?: $connectIp;

    // Everyone under this tenant who is entitled to be online right now.
    // Compared case-insensitively — RouterOS treats usernames that way.
    $okSt = $pdo->prepare("
        SELECT LOWER(mikrotik_username) AS u
        FROM clients
        WHERE tenant_id = ?
          AND status = 'active'
          AND (expiry_date IS NULL OR expiry_date > NOW())
          AND mikrotik_username IS NOT NULL AND mikrotik_username <> ''
    ");
    $okSt->execute([$tid]);
    $entitled = array_flip($okSt->fetchAll(PDO::FETCH_COLUMN));

    $sweepApi = null;
    try {
        $sweepApi = new MikrotikAPI($connectIp, $r['username'], $r['password'], (int)($r['api_port'] ?? 8728));
        if (!$sweepApi->isReachable(4)) {
            $log("  [{$tid}] {$rName} — unreachable, skipped");
            continue;
        }
        $sweepApi->connect();
    } catch (Throwable $e) {
        $log("  [{$tid}] {$rName} — connect failed: " . $e->getMessage());
        continue;
    }

    foreach (['pppoe', 'hotspot'] as $svc) {
        try {
            $live = ($svc === 'pppoe')
                ? $sweepApi->getActiveSessionsMap()
                : $sweepApi->getActiveHotspotSessionsMap();
        } catch (Throwable $e) {
            continue;   // service not configured on this router
        }

        foreach (array_keys($live) as $sessionUser) {
            if ($sessionUser === '' || isset($entitled[$sessionUser])) {
                continue;
            }

            // Only act on sessions we can tie to a client of THIS tenant. Anything
            // else — the ISP's own admin PPPoE link, a MAC-auth hotspot session, a
            // manually created account — is left alone. Cutting an unknown session
            // risks knocking the operator off their own network.
            $whoSt = $pdo->prepare("
                SELECT id, full_name, status, expiry_date
                FROM clients
                WHERE tenant_id = ? AND LOWER(mikrotik_username) = ? LIMIT 1
            ");
            $whoSt->execute([$tid, $sessionUser]);
            $who = $whoSt->fetch(PDO::FETCH_ASSOC);
            if (!$who) {
                continue;
            }

            $why = $who['status'] !== 'active'
                ? "status={$who['status']}"
                : 'expired ' . ($who['expiry_date'] ?? '?');

            try {
                if ($svc === 'pppoe') {
                    $sweepApi->disablePPPoEUser($sessionUser);
                    $sweepApi->kickPPPoESession($sessionUser);
                    radius_disable_client($pdo, $sessionUser);
                } else {
                    $sweepApi->disableHotspotUser($sessionUser);
                    $sweepApi->kickHotspotSession($sessionUser);
                }
                $sweptOff++;
                $log("  [{$tid}] {$rName} — CUT {$svc} '{$sessionUser}' (#{$who['id']} {$who['full_name']}, {$why})");
            } catch (Throwable $e) {
                $log("  [{$tid}] {$rName} — could not cut '{$sessionUser}': " . $e->getMessage());
            }
        }
    }

    try { $sweepApi->disconnect(); } catch (Throwable $e) {}
}

$log("Enforcement sweep: {$sweptOff} session(s) cut.");

$log("=== Done ===");
