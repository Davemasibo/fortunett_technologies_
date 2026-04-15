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

    // Resolve the real router WAN IP.
    // When the site is behind Cloudflare, REMOTE_ADDR is a Cloudflare edge IP
    // (e.g. 172.71.x.x), NOT the router's actual IP.
    // Cloudflare always sends the real client IP in CF-Connecting-IP.
    // We also accept X-Real-IP (nginx) and the first entry of X-Forwarded-For.
    $real_ip = '';
    foreach ([
        'HTTP_CF_CONNECTING_IP',   // Cloudflare — most reliable
        'HTTP_X_REAL_IP',          // nginx proxy
        'HTTP_X_FORWARDED_FOR',    // generic proxy (may be comma-separated)
        'REMOTE_ADDR',             // direct connection fallback
    ] as $key) {
        $val = $_SERVER[$key] ?? '';
        // X-Forwarded-For can be "client, proxy1, proxy2" — take the first
        $val = trim(explode(',', $val)[0]);
        if ($val && filter_var($val, FILTER_VALIDATE_IP)) {
            $real_ip = $val;
            break;
        }
    }

    // Use the posted router_ip only if it is a routable public IP.
    // The provision script posts 192.168.88.1 (a placeholder), so we always
    // fall back to the real IP derived above for private/invalid values.
    $posted_ip = $_POST['router_ip'] ?? '';
    $router_ip = (
        $posted_ip &&
        filter_var($posted_ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
    ) ? $posted_ip : $real_ip;
    
    $router_mac      = $_POST['router_mac']      ?? '';
    $router_identity = $_POST['router_identity'] ?? '';
    $router_username = $_POST['router_username'] ?? 'admin';
    $router_password = $_POST['router_password'] ?? '';
    $provision_id    = $_POST['provision_id']    ?? '';
    $vpn_ip_posted   = trim($_POST['vpn_ip']     ?? '');
    
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
    
    // Resolve VPN IP: trust the posted value (it came from the RSC we generated)
    // but only if it's in our VPN subnet
    $vpn_ip = (filter_var($vpn_ip_posted, FILTER_VALIDATE_IP) &&
               str_starts_with($vpn_ip_posted, '10.200.200.'))
              ? $vpn_ip_posted : null;

    // Check if router already exists — first by provision_id (new flow), then by MAC
    $existingRouter = null;
    if ($provision_id) {
        $stmt = $pdo->prepare("SELECT id, status FROM mikrotik_routers WHERE provision_id = ? AND tenant_id = ?");
        $stmt->execute([$provision_id, $tenantId]);
        $existingRouter = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$existingRouter && $router_mac) {
        $stmt = $pdo->prepare("SELECT id, status FROM mikrotik_routers WHERE mac_address = ? AND tenant_id = ?");
        $stmt->execute([$router_mac, $tenantId]);
        $existingRouter = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    if ($existingRouter) {
        // Update existing router — also store MAC and VPN IP if available
        $stmt = $pdo->prepare("
            UPDATE mikrotik_routers
            SET ip_address  = ?,
                mac_address = COALESCE(NULLIF(?, ''), mac_address),
                identity    = ?,
                username    = ?,
                password    = ?,
                vpn_ip      = COALESCE(?, vpn_ip),
                status      = 'active',
                last_seen   = CURRENT_TIMESTAMP,
                updated_at  = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([
            $router_ip,
            $router_mac,
            $router_identity,
            $router_username,
            $router_password,
            $vpn_ip,
            $existingRouter['id'],
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
                tenant_id, name, ip_address, mac_address,
                identity, username, password, vpn_ip,
                status, last_seen
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            $tenantId, $routerName, $router_ip, $router_mac,
            $router_identity, $router_username, $router_password, $vpn_ip,
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
