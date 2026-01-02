<?php
/**
 * API Endpoint: Get Live Router Statistics
 */
header('Content-Type: application/json');
require_once '../../includes/config.php';
require_once '../../classes/MikrotikAPI.php';

// Get router credentials
$stmt = $pdo->query("SELECT * FROM mikrotik_routers WHERE status = 'active' LIMIT 1");
$router = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$router) {
    echo json_encode(['success' => false, 'message' => 'No active router found']);
    exit;
}

try {
    $api = new MikrotikAPI($router['ip_address'], $router['username'], $router['password'], $router['api_port']);
    
    if ($api->connect()) {
        // 1. Get Active Sessions
        $activeSessions = $api->getActiveSessions();
        $hotspotActive = 0;
        $pppoeActive = 0;
        
        foreach ($activeSessions as $session) {
            if (isset($session['service']) && $session['service'] == 'pppoe') {
                $pppoeActive++;
            } else {
                $hotspotActive++;
            }
        }
        
        // 2. Get Resources (CPU, Uptime)
        $resources = $api->getResources();
        
        // 3. Get Interface Traffic (WAN interface usually ether1, assuming)
        $interfaces = $api->getInterfaces();
        $wanTx = 0;
        $wanRx = 0;
        
        // Try to find WAN or aggregate all
        foreach ($interfaces as $iface) {
            if ($iface['name'] == 'ether1' || $iface['name'] == 'wan') {
                $wanTx = $iface['tx-byte'] ?? 0;
                $wanRx = $iface['rx-byte'] ?? 0;
                break;
            }
        }
        
        // Disconnect
        $api->disconnect();
        
        echo json_encode([
            'success' => true,
            'data' => [
                'active_users' => count($activeSessions),
                'pppoe_users' => $pppoeActive,
                'hotspot_users' => $hotspotActive,
                'cpu_load' => $resources['cpu-load'] ?? 0,
                'uptime' => $resources['uptime'] ?? '0s',
                'download_speed' => 0, // Need to calc delta, for now 0
                'upload_speed' => 0    // Need to calc delta
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Connection failed']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
