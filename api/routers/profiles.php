<?php
/**
 * API Endpoint: Fetch PPPoE / Hotspot profiles from the active router.
 * GET  ?type=pppoe|hotspot
 */
ob_start();
ini_set('display_errors', 0);
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../classes/MikrotikAPI.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$t_stmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$t_stmt->execute([$_SESSION['user_id']]);
$tenant_id = (int)$t_stmt->fetchColumn();

$type = strtolower(trim($_GET['type'] ?? 'pppoe'));
if (!in_array($type, ['pppoe', 'hotspot', 'hotspot_server'])) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'type must be pppoe, hotspot, or hotspot_server']);
    exit;
}

$router_stmt = $pdo->prepare(
    "SELECT id, ip_address, vpn_ip, username, password, api_port
     FROM mikrotik_routers WHERE status IN ('active','online') AND tenant_id = ? LIMIT 1"
);
$router_stmt->execute([$tenant_id]);
$router = $router_stmt->fetch(PDO::FETCH_ASSOC);

if (!$router) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'No active router found']);
    exit;
}

$connectIp = !empty($router['vpn_ip']) ? $router['vpn_ip'] : $router['ip_address'];

try {
    $api = new MikrotikAPI($connectIp, $router['username'], $router['password'], (int)($router['api_port'] ?? 8728));
    $api->connect();

    $profiles = [];

    if ($type === 'hotspot_server') {
        // Return actual hotspot server names (used as the =server= param when adding users)
        $raw = $api->getHotspotServers();
        foreach ($raw as $item) {
            $name = $item['name'] ?? null;
            if ($name === null) continue;
            $profiles[] = [
                'name'      => $name,
                'profile'   => $item['profile']   ?? '',
                'interface' => $item['interface'] ?? '',
            ];
        }
    } else {
        // PPPoE or hotspot user profiles (rate-limit profiles)
        $raw = $type === 'hotspot' ? $api->getHotspotUserProfiles() : $api->getPPPoEProfiles();
        // Helper methods already strip !re; just check name exists
        foreach ($raw as $item) {
            $name = $item['name'] ?? null;
            if ($name === null) continue;
            $profiles[] = [
                'name'       => $name,
                'rate_limit' => $item['rate-limit'] ?? ($item['rate_limit'] ?? ''),
            ];
        }
    }

    $api->disconnect();

    ob_clean();
    echo json_encode(['success' => true, 'profiles' => $profiles, 'router_ip' => $router['ip_address']]);

} catch (Exception $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
