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

// Add missing columns silently (safe to run repeatedly)
try { $pdo->exec("ALTER TABLE mikrotik_routers ADD COLUMN vpn_ip VARCHAR(45) NULL DEFAULT NULL"); } catch (Throwable $_) {}
try { $pdo->exec("ALTER TABLE clients ADD COLUMN last_seen DATETIME NULL DEFAULT NULL"); } catch (Throwable $_) {}

$log = function(string $msg) {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
};

$log("=== Expiry Check: " . date('Y-m-d H:i:s') . " ===");

// ── Find expired-but-still-active clients ─────────────────────────────────────
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
");
$stmt->execute();
$expired = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch platform domain once
$platformDomain = 'fortunetttech.site';
try {
    $pdStmt = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'platform_domain' LIMIT 1");
    $pd = $pdStmt ? $pdStmt->fetchColumn() : null;
    if ($pd) $platformDomain = $pd;
} catch (Throwable $_e) {}

$log("Found " . count($expired) . " expired active client(s).");

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
        $clientId  = $client['id'];
        $name      = $client['full_name'];
        $uname     = $client['mikrotik_username'];
        $connType  = strtolower($client['connection_type'] ?? 'hotspot');

        // 1. Mark client inactive in DB
        $upd = $pdo->prepare("UPDATE clients SET status = 'inactive', updated_at = NOW() WHERE id = ? AND tenant_id = ?");
        $upd->execute([$clientId, $tenantId]);
        $log("  [{$tenantId}] #{$clientId} {$name} — DB set to inactive (was active, expired {$client['expiry_date']})");

        // 2. Disable on router + kick active session
        if ($api && !empty($uname)) {
            try {
                if ($connType === 'pppoe') {
                    $disabled = $api->disablePPPoEUser($uname);
                    $api->kickPPPoESession($uname);
                } else {
                    // disableHotspotUser already calls kickHotspotSession internally
                    $disabled = $api->disableHotspotUser($uname);
                }
                $log("  [{$tenantId}] #{$clientId} {$name} — router disable " . ($disabled ? 'OK' : 'FAILED (user not found)') . " ({$connType}: {$uname})");
            } catch (Throwable $e) {
                $log("  [{$tenantId}] #{$clientId} {$name} — router disable error: " . $e->getMessage());
            }
        } elseif (empty($uname)) {
            $log("  [{$tenantId}] #{$clientId} {$name} — no mikrotik_username, skipping router step");
        }

        // 3. Send SMS notification to customer
        $clientPhone = $client['phone'] ?? '';
        if ($clientPhone) {
            try {
                $sms = new SMSHelper($pdo, $tenantId);
                if ($sms->hasConfig()) {
                    $renewUrl = 'https://' . ($client['subdomain'] ?? '') . '.' . $platformDomain . '/customer/renew.php';
                    $accNo    = $client['account_number'] ?? '';
                    $pkgPrice = isset($client['pkg_price']) ? 'KES ' . number_format((float)$client['pkg_price'], 0) : '';
                    $company  = $client['tenant_name'] ?? 'Your ISP';
                    $firstName = explode(' ', $name)[0];

                    // Fetch M-Pesa paybill for this tenant (for SMS instructions)
                    $paybill = '';
                    try {
                        $gwSt = $pdo->prepare("SELECT credentials FROM payment_gateways WHERE tenant_id = ? AND gateway_type = 'mpesa_api' AND is_active = 1 LIMIT 1");
                        $gwSt->execute([$tenantId]);
                        $gwRow = $gwSt->fetch(PDO::FETCH_ASSOC);
                        if ($gwRow) {
                            $gwCreds = json_decode($gwRow['credentials'], true) ?? [];
                            $paybill = $gwCreds['shortcode'] ?? '';
                        }
                    } catch (Throwable $_e) {}

                    if ($paybill && $accNo && $pkgPrice) {
                        $msg = "Hi {$firstName}, your {$company} internet has expired. Renew: M-Pesa Paybill {$paybill} Acc {$accNo} {$pkgPrice}. Or visit {$renewUrl}";
                    } else {
                        $msg = "Hi {$firstName}, your {$company} internet subscription has expired. Visit {$renewUrl} to renew and get back online.";
                    }

                    // Trim to 160 chars
                    if (strlen($msg) > 160) $msg = substr($msg, 0, 157) . '...';

                    $smsResult = $sms->send($clientPhone, $msg, $clientId);
                    $log("  [{$tenantId}] #{$clientId} {$name} — SMS " . ($smsResult['success'] ? 'sent' : 'failed: ' . ($smsResult['message'] ?? '?')));
                } else {
                    $log("  [{$tenantId}] #{$clientId} {$name} — SMS skipped (not configured)");
                }
            } catch (Throwable $smsEx) {
                $log("  [{$tenantId}] #{$clientId} {$name} — SMS error: " . $smsEx->getMessage());
            }
        }
    }

    if ($api) {
        try { $api->disconnect(); } catch (Throwable $e) { /* ignore */ }
    }
}

$log("=== Done ===");
