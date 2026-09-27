<?php
/**
 * Deploy Hotspot Service to MikroTik Router
 * Creates Hotspot user profile based on package and client details
 */
header('Content-Type: application/json');
require_once '../../includes/auth.php';
require_once '../../config/database.php';
require_once '../../classes/RouterOSAPI.php';
require_once '../../includes/auto_provision.php';

redirectIfNotLoggedIn();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $tenantId = $_SESSION['tenant_id'] ?? null;
    $routerId = $_POST['router_id'] ?? null;
    $clientId = $_POST['client_id'] ?? null;
    $packageId = $_POST['package_id'] ?? null;
    
    if (!$tenantId || !$routerId || !$clientId || !$packageId) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Missing required parameters'
        ]);
        exit;
    }
    
    // Get router details
    $stmt = $db->prepare("
        SELECT * FROM mikrotik_routers 
        WHERE id = ? AND tenant_id = ? AND status = 'active'
    ");
    $stmt->execute([$routerId, $tenantId]);
    $router = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$router) {
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'Router not found or inactive'
        ]);
        exit;
    }
    
    // Get client details
    $stmt = $db->prepare("
        SELECT * FROM clients WHERE id = ? AND tenant_id = ?
    ");
    $stmt->execute([$clientId, $tenantId]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$client) {
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'Client not found'
        ]);
        exit;
    }
    
    // Get package details
    $stmt = $db->prepare("
        SELECT * FROM packages WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL)
    ");
    $stmt->execute([$packageId, $tenantId]);
    $package = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$package) {
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'Package not found'
        ]);
        exit;
    }
    
    if (($client['connection_type'] ?? '') !== 'hotspot' || (int)$client['package_id'] !== (int)$packageId) {
        http_response_code(422);
        echo json_encode(['status'=>'error','message'=>'Select the active hotspot package assigned to this customer.']);
        exit;
    }
    $result = autoProvisionClient($db, (int)$clientId, (int)$tenantId, (int)$routerId);
    if (empty($result['success'])) {
        http_response_code(422);
        echo json_encode(['status'=>'error','message'=>$result['message'] ?? 'Hotspot provisioning failed.']);
        exit;
    }
    $username = $result['username'];
    $password = $result['password'];

    echo json_encode([
        'status' => 'success',
        'message' => 'Hotspot service deployed with package speed and expiry enforcement',
        'credentials' => [
            'username' => $username,
            'password' => $password,
            'service' => 'hotspot',
            'speed' => $package['download_speed'] . 'Mbps / ' . $package['upload_speed'] . 'Mbps'
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Hotspot deployment error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Deployment failed: ' . $e->getMessage()
    ]);
}
