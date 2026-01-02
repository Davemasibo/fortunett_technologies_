<?php
/**
 * API Endpoint: Test MikroTik Connection
 */
header('Content-Type: application/json');
require_once '../../includes/config.php';
require_once '../../classes/MikrotikAPI.php';

// Get router details from DB or POST request
$host = $_POST['host'] ?? '';
$username = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';
$port = $_POST['port'] ?? 8728;

// If not provided in POST, try to get from DB (default router)
if (empty($host)) {
    try {
        $stmt = $pdo->query("SELECT * FROM mikrotik_routers WHERE status = 'active' LIMIT 1");
        $router = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($router) {
            $host = $router['ip_address'];
            $username = $router['username'];
            $password = $router['password']; // In production, decrypt this
            $port = $router['api_port'];
        } else {
            // Fallback to config file
            $config = require '../../config/mikrotik.php';
            $host = $config['default_router']['host'];
            $username = $config['default_router']['username'];
            $password = $config['default_router']['password'];
            $port = $config['default_router']['port'];
        }
    } catch (Exception $e) {
        // Fallback to config
        $config = require '../../config/mikrotik.php';
        $host = $config['default_router']['host'];
        $username = $config['default_router']['username'];
        $password = $config['default_router']['password'];
        $port = $config['default_router']['port'];
    }
}

if (empty($host)) {
    echo json_encode(['success' => false, 'message' => 'No router configuration found']);
    exit;
}

try {
    $api = new MikrotikAPI($host, $username, $password, $port);
    
    if ($api->connect()) {
        // Fetch some basic system resource info to prove connection
        $resources = $api->getResources();
        $api->disconnect();
        
        // Update last connected time in DB if it was from DB
        if (isset($router['id'])) {
            $update = $pdo->prepare("UPDATE mikrotik_routers SET last_connected = NOW(), status = 'active' WHERE id = ?");
            $update->execute([$router['id']]);
        }

        echo json_encode([
            'success' => true, 
            'message' => 'Connected successfully',
            'data' => $resources
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Connection failed (unknown error)']);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'message' => 'Connection Error: ' . $e->getMessage()
    ]);
}
