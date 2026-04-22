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

$log = function(string $msg) {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
};

$log("=== Expiry Check: " . date('Y-m-d H:i:s') . " ===");

// ── Find expired-but-still-active clients ─────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT c.id, c.full_name, c.mikrotik_username, c.connection_type, c.tenant_id,
           c.expiry_date, c.router_id
    FROM clients c
    WHERE c.status = 'active'
      AND c.expiry_date IS NOT NULL
      AND c.expiry_date < NOW()
");
$stmt->execute();
$expired = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

        // 2. Disable on router
        if ($api && !empty($uname)) {
            try {
                if ($connType === 'pppoe') {
                    $disabled = $api->disablePPPoEUser($uname);
                    $api->kickPPPoESession($uname);
                } else {
                    $disabled = $api->disableHotspotUser($uname);
                }
                $log("  [{$tenantId}] #{$clientId} {$name} — router disable " . ($disabled ? 'OK' : 'FAILED (user not found)') . " ({$connType}: {$uname})");
            } catch (Throwable $e) {
                $log("  [{$tenantId}] #{$clientId} {$name} — router disable error: " . $e->getMessage());
            }
        } elseif (empty($uname)) {
            $log("  [{$tenantId}] #{$clientId} {$name} — no mikrotik_username, skipping router step");
        }
    }

    if ($api) {
        try { $api->disconnect(); } catch (Throwable $e) { /* ignore */ }
    }
}

$log("=== Done ===");
