<?php
/**
 * Background RouterOS API health check. A TCP-open API port is not counted as
 * online until the RouterOS API login succeeds.
 */
require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/../includes/router_health.php';
require_once __DIR__ . '/../includes/cron_heartbeat.php';
require_once __DIR__ . '/../classes/MikrotikAPI.php';

$healthStorageReady = ensureRouterHealthColumns($pdo);
cron_heartbeat($pdo, 'check_router_status');

$stmt = $pdo->query("SELECT * FROM mikrotik_routers WHERE status IN ('active','online','inactive','offline')");
$routers = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($routers as $router) {
    $status = 'inactive';
    $connectIp = !empty($router['vpn_ip']) ? $router['vpn_ip'] : $router['ip_address'];
    $api = new MikrotikAPI($connectIp, $router['username'], $router['password'], (int)($router['api_port'] ?: 8728));

    try {
        $api->connect();
        $status = 'active';
        $api->disconnect();
    } catch (Throwable $e) {
        $api->disconnect();
        error_log("Router API check error for {$router['name']} ({$connectIp}): " . $e->getMessage());
    }

    if ($status === 'active') {
        if ($healthStorageReady) {
            $pdo->prepare("UPDATE mikrotik_routers SET status='active',last_seen=NOW(),last_api_success_at=NOW() WHERE id=?")
                ->execute([$router['id']]);
        } else {
            $pdo->prepare("UPDATE mikrotik_routers SET status='active',last_seen=NOW() WHERE id=?")
                ->execute([$router['id']]);
        }
    } elseif ($healthStorageReady) {
        // Do not overwrite a successful live API check that raced this probe.
        $pdo->prepare("UPDATE mikrotik_routers SET status='inactive' WHERE id=? AND last_api_success_at <=> ? AND status IN ('active','online','inactive','offline')")
            ->execute([$router['id'], $router['last_api_success_at'] ?? null]);
    } else {
        $pdo->prepare("UPDATE mikrotik_routers SET status='inactive' WHERE id=? AND last_seen <=> ? AND status IN ('active','online','inactive','offline')")
            ->execute([$router['id'], $router['last_seen'] ?? null]);
    }

    echo "Router {$router['name']} ({$connectIp}): " . ($status === 'active' ? 'API login successful' : 'API login failed') . "\n";
}

echo "Router status check completed at " . date('Y-m-d H:i:s') . "\n";
// Existing scheduled status checks also deliver saved ISP profiles on reconnect.
if (PHP_SAPI === 'cli') require __DIR__ . '/apply_wan_profiles.php';
