<?php
/**
 * Router Auto-Registration Endpoint
 * Called by MikroTik routers during provisioning to automatically register themselves
 */
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../includes/tenant.php';

// Allow CORS for MikroTik requests
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

try {
    // Get POST data
    $provisioning_token = $_POST['provisioning_token'] ?? '';
    // If router_ip is not provided or is a local IP, use REMOTE_ADDR
    $router_ip = $_POST['router_ip'] ?? '';
    if (!$router_ip || substr($router_ip, 0, 8) === '192.168.' || $router_ip === '127.0.0.1') {
        $router_ip = $_SERVER['REMOTE_ADDR'];
    }
    
    $router_mac = $_POST['router_mac'] ?? '';
    $router_identity = $_POST['router_identity'] ?? '';
    $router_username = $_POST['router_username'] ?? 'admin';
    $router_password = $_POST['router_password'] ?? '';
    
    // Validate required fields
    if (empty($provisioning_token)) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Provisioning token is required'
        ]);
        exit;
    }
    
    if (empty($router_mac)) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Router MAC address is required'
        ]);
        exit;
    }
    
    // Validate provisioning token and get tenant ID
    $tenantManager = TenantManager::getInstance($pdo);
    $tenantId = $tenantManager->validateProvisioningToken($provisioning_token);
    
    if (!$tenantId) {
        http_response_code(401);
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid or expired provisioning token'
        ]);
        exit;
    }
    
    // Check if router already exists (by MAC address)
    $stmt = $pdo->prepare("
        SELECT id, status FROM mikrotik_routers 
        WHERE mac_address = ? AND tenant_id = ?
    ");
    $stmt->execute([$router_mac, $tenantId]);
    $existingRouter = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingRouter) {
        // Update existing router
        $stmt = $pdo->prepare("
            UPDATE mikrotik_routers 
            SET ip_address = ?,
                identity = ?,
                username = ?,
                password = ?,
                status = 'active',
                last_seen = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([
            $router_ip,
            $router_identity,
            $router_username,
            $router_password,
            $existingRouter['id']
        ]);
        
        echo json_encode([
            'status' => 'success',
            'message' => 'Router updated successfully',
            'router_id' => $existingRouter['id'],
            'action' => 'updated'
        ]);
    } else {
        // Insert new router
        $routerName = $router_identity ?: "Router-" . substr($router_mac, -8);
        
        $stmt = $pdo->prepare("
            INSERT INTO mikrotik_routers (
                tenant_id,
                name,
                ip_address,
                mac_address,
                identity,
                username,
                password,
                status,
                last_seen
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', CURRENT_TIMESTAMP)
        ");
        
        $stmt->execute([
            $tenantId,
            $routerName,
            $router_ip,
            $router_mac,
            $router_identity,
            $router_username,
            $router_password
        ]);
        
        $routerId = $pdo->lastInsertId();
        
        echo json_encode([
            'status' => 'success',
            'message' => 'Router registered successfully',
            'router_id' => $routerId,
            'action' => 'created'
        ]);
    }
    
} catch (PDOException $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>
