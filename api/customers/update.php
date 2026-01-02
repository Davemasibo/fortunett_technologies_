<?php
/**
 * API Endpoint: Update Customer
 */
header('Content-Type: application/json');
require_once '../../includes/config.php';
require_once '../../classes/MikrotikAPI.php';

// Validate Inputs
$id = $_POST['id'] ?? 0;
$name = $_POST['name'] ?? '';
$email = $_POST['email'] ?? '';
$phone = $_POST['phone'] ?? '';
$username = $_POST['username'] ?? '';
$mikrotik_username = $_POST['mikrotik_username'] ?? '';
$mikrotik_password = $_POST['mikrotik_password'] ?? '';
$package_id = $_POST['package_id'] ?? 0;
$address = $_POST['address'] ?? '';
$status = $_POST['status'] ?? 'active';

if (empty($id) || empty($name)) {
    echo json_encode(['success' => false, 'message' => 'Customer ID and Name are required']);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Get Old Details (to check if package changed)
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
    $stmt->execute([$id]);
    $oldClient = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$oldClient) {
        throw new Exception("Customer not found");
    }

    // 2. Get Package Details
    $pkgName = $oldClient['subscription_plan'];
    if ($package_id) {
        $stmt = $pdo->prepare("SELECT * FROM packages WHERE id = ?");
        $stmt->execute([$package_id]);
        $package = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($package) {
            $pkgName = $package['name'];
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
    }
    
    if ($expiry_date) {
        $fields[] = 'expiry_date = ?';
        $values[] = $expiry_date;
    }
    
    // Portal Password update
    if (!empty($_POST['password'])) {
         $fields[] = 'password = ?'; // This was likely the missing column causing error before
         $values[] = $portal_password;
    }
    
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
