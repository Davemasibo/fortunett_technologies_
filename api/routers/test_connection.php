<?php
header('Content-Type: application/json');
require_once '../../includes/config.php';
require_once '../../classes/RouterOSAPI.php';

$id = $_POST['id'] ?? null;
// Force update password if requested (hardcoded for this user session as requested)
$password = '123456'; 

if (!$id) {
    echo json_encode(['status' => 'error', 'message' => 'ID Required']);
    exit;
}

try {
    // Get Router
    $stmt = $pdo->prepare("SELECT * FROM mikrotik_routers WHERE id = ?");
    $stmt->execute([$id]);
    $router = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$router) {
        echo json_encode(['status' => 'error', 'message' => 'Router not found']);
        exit;
    }

    // Update Password in DB first (Since user provided it)
    $update = $pdo->prepare("UPDATE mikrotik_routers SET password = ? WHERE id = ?");
    $update->execute([$password, $id]);

    // Test Connection
    $api = new RouterOSAPI();
    // Use the IP. If local dev and router is executing provisioning, 
    // the router is the one connecting TO us.
    // BUT for US to connect TO router, we need the router's IP to be reachable.
    // Use the IP stored in DB.
    
    // NOTE: If using Ngrok and router is behind NAT, we cannot connect TO it unless it has a public IP or VPN.
    // However, usually the user is on the same LAN (admin@MikroTik suggests local access).
    // So we try the IP.
    
    if ($api->connect($router['ip_address'], 'admin', $password)) {
        // Fetch Resource
        $api->write('/system/resource/print');
        $read = $api->read(false); // Simple read
        // Parse raw response would be better but for "Verifying Connection" just success is enough
        
        $api->disconnect();
        echo json_encode(['status' => 'success', 'message' => 'Connection Successful!', 'details' => 'Authenticated as admin']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Could not connect to ' . $router['ip_address']]);
    }

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
