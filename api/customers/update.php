<?php
/**
 * API Endpoint: Update Customer
 */
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../classes/MikrotikAPI.php';

// Validate Inputs
$id = $_POST['id'] ?? 0;
$name = $_POST['name'] ?? '';
$email = $_POST['email'] ?? '';
$phone = $_POST['phone'] ?? '';
$username = $_POST['mikrotik_username'] ?? ''; // Sync with Mikrotik Username
$mikrotik_username = $_POST['mikrotik_username'] ?? '';
$mikrotik_password = $_POST['mikrotik_password'] ?? '';
$package_id = $_POST['package_id'] ?? 0;
$address = $_POST['address'] ?? '';
$status = $_POST['status'] ?? 'active';

if (empty($id) || empty($name)) {
    echo json_encode(['success' => false, 'message' => 'Customer ID and Name are required']);
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
    
        // 1. Get Old Details (to check if package changed) AND verify tenant
        $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$id, $tenant_id]);
        $oldClient = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$oldClient) {
            throw new Exception("Customer not found or access denied");
        }
    
        // 2. Get Package Details (if changed)
        $pkgName = $oldClient['subscription_plan'];
        if ($package_id) {
            $stmt = $pdo->prepare("SELECT * FROM packages WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$package_id, $tenant_id]);
            $package = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($package) {
                $pkgName = $package['name'];
            } else {
                 throw new Exception("Invalid package selected or access denied");
            }
        }
    
    // 3. Update DB
    $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
    $portal_password = !empty($_POST['password']) ? password_hash($_POST['password'], PASSWORD_DEFAULT) : null;
    
    // Base fields
    $fields = [
        'full_name = ?', 'name = ?', 'email = ?', 'phone = ?', 'address = ?', 'username = ?', 
        'mikrotik_username = ?', 'status = ?'
    ];
    $values = [
        $name, $name, $email, $phone, $address, $username, 
        $mikrotik_username, $status
    ];
    
    // Add logic for optional fields
    if ($package_id) {
        $fields[] = 'package_id = ?';
        $fields[] = 'subscription_plan = ?';
        $values[] = $package_id;
        $values[] = $pkgName;
    }
    
    if (!empty($mikrotik_password)) {
        $fields[] = 'mikrotik_password = ?';
        $values[] = $mikrotik_password;
        
        // Sync portal password (hash)
        $fields[] = 'auth_password = ?';
        $values[] = password_hash($mikrotik_password, PASSWORD_DEFAULT);
    }
    
    if ($expiry_date) {
        $fields[] = 'expiry_date = ?';
        $values[] = $expiry_date;
    }
    
    // Portal Password update (Removed - use Mikrotik Password)
    /*
    if (!empty($_POST['password'])) {
         $fields[] = 'password = ?'; 
         $values[] = $portal_password;
    }
    */
    
    $sql = "UPDATE clients SET " . implode(', ', $fields) . " WHERE id = ?";
    $values[] = $id;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);
    
    // 4. Update on MikroTik
    $router_stmt = $pdo->query("SELECT * FROM mikrotik_routers WHERE status = 'active' LIMIT 1");
    $router = $router_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($router && !empty($mikrotik_username)) {
        try {
            $api = new MikrotikAPI($router['ip_address'], $router['username'], $router['password'], $router['api_port']);
            if ($api->connect()) {
                $profile = null;
                if ($package_id) {
                     $profile = $package['mikrotik_profile'] ?? 'default';
                }
                
                $pass = !empty($mikrotik_password) ? $mikrotik_password : null;
                
                // We use the OLD username to find the user in case it changed, 
                // but MikroTik API usually keys by name. Updating name is tricky if we don't know internal ID.
                // Our class uses `getPPPoEUsers` to find ID by name.
                // If username changed, we might fail to find old one.
                // Creating a new user if not found is safer?
                
                $targetUser = $oldClient['mikrotik_username']; // The name currently on router
                
                if ($connection_type === 'hotspot') {
                     try {
                        $api->updateHotspotUser($targetUser, $pass, $profile);
                    } catch (Exception $e) {
                         // Try adding if update failed
                         if (!empty($mikrotik_password)) {
                             $api->addHotspotUser($mikrotik_username, $mikrotik_password, $profile ?? 'default');
                         }
                    }
                } else {
                    // PPPoE
                    try {
                        $api->updatePPPoEUser($targetUser, $pass, $profile);
                    } catch (Exception $e) {
                         // Try adding if update failed
                         if (!empty($mikrotik_password)) {
                             $api->addPPPoEUser($mikrotik_username, $mikrotik_password, $profile ?? 'default');
                         }
                    }
                }
                
                if ($status == 'inactive' || $status == 'suspended') {
                    // Disable user logic ideally goes here, for now we skip exact disable command per protocol 
                    // or implement a generic disable method later.
                }

                $api->disconnect();
            }
        } catch (Exception $e) {
            // Log error
        }
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Customer updated successfully']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
