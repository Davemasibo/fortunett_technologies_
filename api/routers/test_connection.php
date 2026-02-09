<?php
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../includes/auth.php';
require_once '../../classes/RouterOSAPI.php';

// disable error reporting to screen to prevent HTML in JSON
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Start session if not started
if (session_status() === PHP_SESSION_NONE) session_start();
$user_id = $_SESSION['user_id'] ?? 0;

$id = $_POST['id'] ?? null;

if (!$user_id) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

if (!$id) {
    echo json_encode(['status' => 'error', 'message' => 'Router ID Required']);
    exit;
}

try {
    // Get Tenant ID
    $tStmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
    $tStmt->execute([$user_id]);
    $tenant_id = $tStmt->fetchColumn();

    if (!$tenant_id) {
        throw new Exception("Tenant not found");
    }

    // Get Router with Tenant Validation
    $stmt = $pdo->prepare("SELECT * FROM mikrotik_routers WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$id, $tenant_id]);
    $router = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$router) {
        throw new Exception("Router not found or access denied");
    }

    $ip = $router['ip_address'];
    $user = $router['username'];
    $pass = $router['password']; 
    $port = $router['api_port'] ?? 8728;

    // Test Connection
    $api = new RouterOSAPI();
    
    // Attempt Connection
    // Connection timeout defaults are generally handled by fsockopen inside RouterOSAPI
    if ($api->connect($ip, $user, $pass)) {
        
        // Try to read identity
        $api->write('/system/identity/print');
        $read = $api->read(false);
        $identity = $read[0]['name'] ?? 'Unknown';

        // Update status to online
        $upd = $pdo->prepare("UPDATE mikrotik_routers SET status = 'online', last_seen = NOW() WHERE id = ?");
        $upd->execute([$id]);

        $api->disconnect();
        echo json_encode([
            'status' => 'success', 
            'message' => "Connected successfully to '$identity'",
            'debug' => "IP: $ip, User: $user"
        ]);
    } else {
        $upd = $pdo->prepare("UPDATE mikrotik_routers SET status = 'offline' WHERE id = ?");
        $upd->execute([$id]);
        
        echo json_encode([
            'status' => 'error', 
            'message' => "Connection failed to $ip. Verify Request: Is the router reachable from this server?"
        ]);
    }

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
