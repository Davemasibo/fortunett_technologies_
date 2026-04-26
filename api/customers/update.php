<?php
/**
 * API Endpoint: Update Customer
 */
ob_start();
error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../classes/MikrotikAPI.php';

// Validate Inputs
$id               = $_POST['id'] ?? 0;
$name             = $_POST['name'] ?? '';
$email            = $_POST['email'] ?? '';
$phone            = $_POST['phone'] ?? '';
$username         = $_POST['mikrotik_username'] ?? '';
$mikrotik_username = $_POST['mikrotik_username'] ?? '';
$mikrotik_password = $_POST['mikrotik_password'] ?? '';
$package_id       = $_POST['package_id'] ?? 0;
$address          = $_POST['address'] ?? '';
$status           = $_POST['status'] ?? 'active';
$connection_type  = $_POST['connection_type'] ?? 'pppoe';

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

        // Keep existing status if not explicitly passed by the form
        if (empty($_POST['status'])) {
            $status = $oldClient['status'];
        }
    
        // 2. Get Package Details (if changed)
        $pkgName = $oldClient['subscription_plan'];
        if ($package_id) {
            $stmt = $pdo->prepare("SELECT *, COALESCE(NULLIF(type,''), 'pppoe') AS pkg_type FROM packages WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL)");
            $stmt->execute([$package_id, $tenant_id]);
            $package = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($package) {
                $pkgName = $package['name'];
                // Validate package type matches customer connection type
                $pkgType = strtolower($package['pkg_type'] ?? 'pppoe');
                $custType = strtolower($connection_type);
                if ($custType !== 'static' && $pkgType !== $custType) {
                    throw new Exception("Package type mismatch: cannot assign a " . strtoupper($pkgType) . " package to a " . strtoupper($custType) . " customer.");
                }
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
    
    // 4. Update on MikroTik (tenant-scoped)
    $router_stmt = $pdo->prepare("SELECT id, ip_address, vpn_ip, username, password, api_port FROM mikrotik_routers WHERE status IN ('active','online') AND tenant_id = ? LIMIT 1");
    $router_stmt->execute([$tenant_id]);
    $router = $router_stmt->fetch(PDO::FETCH_ASSOC);

    if ($router && !empty($mikrotik_username)) {
        try {
            $connectIp = !empty($router['vpn_ip']) ? $router['vpn_ip'] : $router['ip_address'];
            $api = new MikrotikAPI($connectIp, $router['username'], $router['password'], $router['api_port']);
            if ($api->connect()) {
                $profile = null;
                $hotspot_server = 'all';
                if ($package_id && !empty($package)) {
                    $profile = $package['mikrotik_profile'] ?? 'default';
                    $hotspot_server = !empty($package['hotspot_server']) ? $package['hotspot_server'] : 'all';
                }

                $pass = !empty($mikrotik_password) ? $mikrotik_password : null;

                $targetUser = $oldClient['mikrotik_username']; // The name currently on router

                if ($connection_type === 'hotspot') {
                    try {
                        $api->updateHotspotUser($targetUser, $pass, $profile);
                    } catch (Exception $e) {
                        // Try adding if update failed
                        if (!empty($mikrotik_password)) {
                            $api->addHotspotUser($mikrotik_username, $mikrotik_password, $profile ?? 'default', $hotspot_server);
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
    ob_clean();
    echo json_encode(['success' => true, 'message' => 'Customer updated successfully']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Exception $re) {} }
    ob_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
