<?php
/**
 * API Endpoint: Get Live Router Statistics
 */
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../classes/MikrotikAPI.php';

// Security: Get tenant_id
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
$user_id = $_SESSION['user_id'];
$t_stmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$t_stmt->execute([$user_id]);
$tenant_id = $t_stmt->fetchColumn();

// Get router credentials — use tenant's first active router
$stmt = $pdo->prepare("SELECT * FROM mikrotik_routers WHERE status = 'active' AND tenant_id = ? LIMIT 1");
$stmt->execute([$tenant_id]);
$router = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$router) {
    echo json_encode(['success' => false, 'message' => 'No active router found']);
    exit;
}

try {
    $api = new MikrotikAPI($router['ip_address'], $router['username'], $router['password'], $router['api_port']);
    
    if ($api->connect()) {
        // 1. Fetch tenant usernames for filtering
        $stmt = $pdo->prepare("SELECT mikrotik_username FROM clients WHERE tenant_id = ? AND mikrotik_username IS NOT NULL AND mikrotik_username != ''");
        $stmt->execute([$tenant_id]);
        $tenant_users = array_map('strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN));

        // 2. PPPoE active sessions
        $pppoeSessions = $api->getActiveSessions();
        $pppoeActive = 0;
        foreach ($pppoeSessions as $session) {
            $name = strtolower($session['name'] ?? $session['user'] ?? '');
            if ($name && (empty($tenant_users) || in_array($name, $tenant_users))) {
                $pppoeActive++;
            }
        }

        // 3. Hotspot active sessions
        $hotspotMap = $api->getActiveHotspotSessionsMap();
        $hotspotActive = 0;
        foreach (array_keys($hotspotMap) as $name) {
            if (empty($tenant_users) || in_array(strtolower($name), $tenant_users)) {
                $hotspotActive++;
            }
        }

        $filteredActiveCount = $pppoeActive + $hotspotActive;

        // 4. Get Resources (CPU, Uptime)
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
                'active_users' => $filteredActiveCount,
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
