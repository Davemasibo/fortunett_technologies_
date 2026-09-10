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
require_once '../../includes/package_profile.php';
require_once __DIR__ . '/../../includes/dashboard_sync.php';

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

    $lockName = 'payment-client-' . $tenant_id . '-' . $id;
    $locked = false;
    try {
        $lock = $pdo->prepare('SELECT GET_LOCK(?,30)');
        $lock->execute([$lockName]);
        $locked = (int)$lock->fetchColumn() === 1;
        if (!$locked) throw new Exception('Customer update in progress. Please try again.');
        dashboardSyncSchema($pdo);
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
    
        if (!in_array($connection_type, ['hotspot', 'pppoe'], true)) throw new Exception('Choose Hotspot or PPPoE.');
        if (!in_array($status, ['active', 'inactive', 'suspended', 'expired', 'blocked'], true)) throw new Exception('Invalid customer status.');
        if (trim($mikrotik_username) === '') throw new Exception('Router username is required.');
        $owner = $pdo->prepare('SELECT id FROM clients WHERE tenant_id=? AND mikrotik_username=? AND id<>?');
        $owner->execute([$tenant_id, $mikrotik_username, $id]);
        if ($owner->fetchColumn()) throw new Exception('This router username belongs to another customer.');
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
        'mikrotik_username = ?', 'status = ?', 'connection_type = ?'
    ];
    $values = [
        $name, $name, $email, $phone, $address, $username, 
        $mikrotik_username, $status, $connection_type
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
    
    if ($expiry_date && (strtotime($expiry_date) === false || empty($oldClient['expiry_date']) || strtotime($expiry_date) > strtotime($oldClient['expiry_date']))) {
        throw new Exception('Access time can only be extended through a successful payment.');
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
    
    $networkChanged = $mikrotik_username !== $oldClient['mikrotik_username']
        || $status !== $oldClient['status'] || $connection_type !== $oldClient['connection_type']
        || ($package_id && (int)$package_id !== (int)$oldClient['package_id'])
        || ($mikrotik_password !== '' && $mikrotik_password !== $oldClient['mikrotik_password'])
        || ($expiry_date && strtotime($expiry_date) !== strtotime($oldClient['expiry_date']));
    if ($networkChanged) dashboardQueueCustomer($pdo, (int)$tenant_id, (int)$id, $oldClient);
    $pdo->commit();
    ob_clean();
    echo json_encode(['success' => true, 'sync_pending' => (bool)$networkChanged, 'message' => $networkChanged ? 'Customer saved. Applying access settings.' : 'Customer saved.']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Exception $re) {} }
    ob_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

finally { if ($locked) $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]); }
