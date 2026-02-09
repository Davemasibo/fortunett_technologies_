<?php
/**
 * Deploy PPPoE Service to MikroTik Router
 * Creates PPPoE profile and secret based on package and client details
 */
header('Content-Type: application/json');
require_once '../../includes/auth.php';
require_once '../../config/database.php';
require_once '../../classes/RouterOSAPI.php';

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
    
    // Generate username and password (or use existing mikrotik_username)
    $username = $client['mikrotik_username'] ?: strtolower($client['account_number'] ?: substr($client['name'], 0, 10));
    $password = bin2hex(random_bytes(4)); // 8 character password
    
    // Connect to MikroTik
    $api = new RouterOSAPI();
    if (!$api->connect($router['ip_address'], $router['username'], $router['password'])) {
        http_response_code(503);
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to connect to router'
        ]);
        exit;
    }
    
    // Create PPPoE Profile
    $profileName = "profile_" . $username;
    
    // Check if profile exists
    $api->write('/ppp/profile/print', false);
    $api->write('?name=' . $profileName, false);
    $existingProfiles = $api->read(false);
    
    if (empty($existingProfiles)) {
        // Create new profile
        $api->write('/ppp/profile/add', false);
        $api->write('=name=' . $profileName, false);
        $api->write('=local-address=10.0.0.1', false);
        $api->write('=remote-address=pool1', false);
        $api->write('=rate-limit=' . $package['download_speed'] . 'M/' . $package['upload_speed'] . 'M', false);
        $api->read();
    }
    
    // Create or update PPPoE Secret
    $api->write('/ppp/secret/print', false);
    $api->write('?name=' . $username, false);
    $existingSecrets = $api->read(false);
    
    if (!empty($existingSecrets)) {
        // Update existing secret
        $secretId = $existingSecrets[0]['.id'];
        $api->write('/ppp/secret/set', false);
        $api->write('=.id=' . $secretId, false);
        $api->write('=password=' . $password, false);
        $api->write('=profile=' . $profileName, false);
        $api->write('=service=pppoe', false);
        $api->read();
    } else {
        // Create new secret
        $api->write('/ppp/secret/add', false);
        $api->write('=name=' . $username, false);
        $api->write('=password=' . $password, false);
        $api->write('=profile=' . $profileName, false);
        $api->write('=service=pppoe', false);
        $api->write('=comment=' . $client['full_name'], false);
        $api->read();
    }
    
    $api->disconnect();
    
    // Update client record
    $updateStmt = $db->prepare("
        UPDATE clients 
        SET mikrotik_username = ?, status = 'active'
        WHERE id = ?
    ");
    $updateStmt->execute([$username, $clientId]);
    
    // Record deployment in router_services table
    $stmt = $db->prepare("
        INSERT INTO router_services (
            tenant_id, router_id, client_id, service_type, package_id,
            username, password, status
        ) VALUES (?, ?, ?, 'pppoe', ?, ?, ?, 'active')
        ON DUPLICATE KEY UPDATE
            password = VALUES(password),
            status = VALUES(status),
            deployed_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$tenantId, $routerId, $clientId, $packageId, $username, $password]);
    
    echo json_encode([
        'status' => 'success',
        'message' => 'PPPoE service deployed successfully',
        'credentials' => [
            'username' => $username,
            'password' => $password,
            'service' => 'pppoe',
            'speed' => $package['download_speed'] . 'Mbps / ' . $package['upload_speed'] . 'Mbps'
        ]
    ]);
    
} catch (Exception $e) {
    error_log("PPPoE deployment error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Deployment failed: ' . $e->getMessage()
    ]);
}
