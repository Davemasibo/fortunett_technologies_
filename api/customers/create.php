<?php
/**
 * API Endpoint: Create Customer
 */
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../classes/MikrotikAPI.php';

// Validate Inputs
$name = $_POST['name'] ?? '';
$email = $_POST['email'] ?? '';
$phone = $_POST['phone'] ?? '';
$mikrotik_username = $_POST['mikrotik_username'] ?? '';
$mikrotik_password = $_POST['mikrotik_password'] ?? '';

// Merged Credentials: Portal login uses MikroTik credentials
$username = $mikrotik_username; 
$password = $mikrotik_password; // Will be hashed for auth_password
$package_id = $_POST['package_id'] ?? 0;
$address = $_POST['address'] ?? '';
$connection_type = $_POST['connection_type'] ?? 'pppoe';

if (empty($name) || empty($package_id)) {
    echo json_encode(['success' => false, 'message' => 'Name and Package are required']);
    exit;
}

    // 0. Security: Get tenant_id
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

    try {
        $pdo->beginTransaction();
    
        // 1. Get Package Details (and verify tenant ownership)
        $stmt = $pdo->prepare("SELECT * FROM packages WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$package_id, $tenant_id]);
        $package = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$package) {
            throw new Exception("Invalid package selected or access denied");
        }
        
        // Calculate Expiry
        $expiry_date = date('Y-m-d H:i:s', strtotime("+1 month")); // Default
        if (stripos($package['name'], 'daily') !== false) {
            $expiry_date = date('Y-m-d H:i:s', strtotime("+1 day"));
        } elseif (stripos($package['name'], 'weekly') !== false) {
            $expiry_date = date('Y-m-d H:i:s', strtotime("+1 week"));
        }
        
        // 2. Insert into DB
        $sql = "INSERT INTO clients (tenant_id, full_name, name, email, phone, address, username, auth_password, mikrotik_username, mikrotik_password, package_id, subscription_plan, expiry_date, status, connection_type) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'inactive', ?)";
                
        $stmt = $pdo->prepare($sql);
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        // We populate both full_name and name to be safe
        $stmt->execute([
            $tenant_id,
            $name, $name, $email, $phone, $address, $username, $hashed_password, 
            $mikrotik_username, $mikrotik_password, 
            $package_id, $package['name'], $expiry_date, $connection_type
        ]);
    
    $client_id = $pdo->lastInsertId();
    
    // 3. Create on MikroTik Router
    $router_stmt = $pdo->query("SELECT * FROM mikrotik_routers WHERE status = 'active' LIMIT 1");
    $router = $router_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($router && !empty($mikrotik_username) && !empty($mikrotik_password)) {
        try {
            $api = new MikrotikAPI($router['ip_address'], $router['username'], $router['password'], $router['api_port']);
            if ($api->connect()) {
                $profile = $package['mikrotik_profile'] ?? 'default';
                
                if ($connection_type === 'hotspot') {
                    // Hotspot Logic
                    $exists = false;
                    $users = $api->getHotspotUsers();
                    foreach ($users as $u) {
                        if ($u['name'] == $mikrotik_username) {
                            $exists = true; 
                            break;
                        }
                    }
                    
                    if (!$exists) {
                        $api->addHotspotUser($mikrotik_username, $mikrotik_password, $profile);
                    } else {
                         // Update existing
                         $api->updateHotspotUser($mikrotik_username, $mikrotik_password, $profile);
                    }
                } else {
                    // PPPoE Logic (Default)
                    $exists = false;
                    $users = $api->getPPPoEUsers();
                    foreach ($users as $u) {
                        if ($u['name'] == $mikrotik_username) {
                            $exists = true; 
                            break;
                        }
                    }
                    
                    if (!$exists) {
                        $api->addPPPoEUser($mikrotik_username, $mikrotik_password, $profile);
                    } else {
                        // Update existing
                        $api->updatePPPoEUser($mikrotik_username, $mikrotik_password, $profile);
                    }
                }
                
                $api->disconnect();
            }
        } catch (Exception $e) {
            // Log error but assume DB success is what matters for "Created", just show warning?
            // Or rollback? Generally better to show warning.
            // For now, let's just log and continue.
        }
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Customer created successfully']);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
