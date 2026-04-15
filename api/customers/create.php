<?php
/**
 * API Endpoint: Create Customer
 */
ob_start();
error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json');

require_once '../../includes/db_master.php';
require_once '../../includes/account_number_generator.php';
require_once '../../classes/MikrotikAPI.php';

// Auth check first
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
$user_id = $_SESSION['user_id'];
$t_stmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$t_stmt->execute([$user_id]);
$tenant_id = $t_stmt->fetchColumn();
if (!$tenant_id) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'No tenant assigned']);
    exit;
}

// Validate Inputs
$name              = trim($_POST['name']              ?? '');
$email             = trim($_POST['email']             ?? '');
$phone             = trim($_POST['phone']             ?? '');
$mikrotik_username = trim($_POST['mikrotik_username'] ?? '');
$mikrotik_password = $_POST['mikrotik_password']      ?? '';
$username          = $mikrotik_username;
$password          = $mikrotik_password;
$package_id        = (int)($_POST['package_id']       ?? 0);
$address           = trim($_POST['address']           ?? '');
$connection_type   = $_POST['connection_type']        ?? 'pppoe';

if (empty($name) || empty($package_id)) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Name and Package are required']);
    exit;
}

// Duplicate check: username must be unique per tenant
if (!empty($mikrotik_username)) {
    $dup = $pdo->prepare("SELECT id FROM clients WHERE tenant_id = ? AND mikrotik_username = ? LIMIT 1");
    $dup->execute([$tenant_id, $mikrotik_username]);
    if ($dup->fetch()) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => "Username \"$mikrotik_username\" is already in use."]);
        exit;
    }
}
// Duplicate check: phone must be unique per tenant
if (!empty($phone)) {
    $dup = $pdo->prepare("SELECT id FROM clients WHERE tenant_id = ? AND phone = ? LIMIT 1");
    $dup->execute([$tenant_id, $phone]);
    if ($dup->fetch()) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => "A customer with phone \"$phone\" already exists."]);
        exit;
    }
}

try {
    $pdo->beginTransaction();

    // 1. Get Package Details
    $stmt = $pdo->prepare("SELECT *, COALESCE(NULLIF(type,''), 'pppoe') AS pkg_type FROM packages WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$package_id, $tenant_id]);
    $package = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$package) throw new Exception("Invalid package selected or access denied");
    // Validate package type matches customer connection type
    $pkgType  = strtolower($package['pkg_type'] ?? 'pppoe');
    $custType = strtolower($connection_type);
    if ($custType !== 'static' && $pkgType !== $custType) {
        throw new Exception("Package type mismatch: cannot assign a " . strtoupper($pkgType) . " package to a " . strtoupper($custType) . " customer.");
    }

    // 2. Calculate Expiry
    if (!empty($_POST['expiry_date'])) {
        $expiry_date = date('Y-m-d H:i:s', strtotime($_POST['expiry_date']));
    } elseif (!empty($package['validity_value']) && !empty($package['validity_unit'])) {
        $val  = (int)$package['validity_value'];
        $unit = $package['validity_unit'];
        $expiry_date = date('Y-m-d H:i:s', strtotime("+$val $unit"));
    } else {
        $expiry_date = date('Y-m-d H:i:s', strtotime('+1 month'));
    }

    // 3. Insert client
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO clients
        (tenant_id, full_name, name, email, phone, address, username, auth_password,
         mikrotik_username, mikrotik_password, package_id, subscription_plan,
         expiry_date, status, connection_type)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'inactive', ?)");
    $stmt->execute([
        $tenant_id, $name, $name, $email, $phone, $address,
        $username, $hashed_password, $mikrotik_username, $mikrotik_password,
        $package_id, $package['name'], $expiry_date, $connection_type
    ]);
    $client_id = $pdo->lastInsertId();

    // 4. Commit the main transaction BEFORE account number generation
    //    (AccountNumberGenerator uses LOCK TABLES which auto-commits InnoDB txn)
    $pdo->commit();

    // 5. Generate and assign account number (separate operation, after commit)
    try {
        $acctGen = new AccountNumberGenerator($pdo);
        $account_number = $acctGen->generateAccountNumber($tenant_id);
        $pdo->prepare("UPDATE clients SET account_number = ? WHERE id = ?")
            ->execute([$account_number, $client_id]);
    } catch (Exception $e) {
        error_log("Account number generation failed: " . $e->getMessage());
    }

    // 6. Sync to MikroTik (non-fatal, short timeout)
    if (!empty($mikrotik_username) && !empty($mikrotik_password)) {
        try {
            $router_stmt = $pdo->prepare(
                "SELECT id, ip_address, vpn_ip, username, password, api_port FROM mikrotik_routers WHERE status = 'active' AND tenant_id = ? LIMIT 1"
            );
            $router_stmt->execute([$tenant_id]);
            $router = $router_stmt->fetch(PDO::FETCH_ASSOC);

            if ($router) {
                $connectIp = !empty($router['vpn_ip']) ? $router['vpn_ip'] : $router['ip_address'];
                $api = new MikrotikAPI(
                    $connectIp, $router['username'],
                    $router['password'],  (int)($router['api_port'] ?? 8728)
                );
                if ($api->connect()) {
                    $profile = $package['name'] ?? 'default';
                    if ($connection_type === 'hotspot') {
                        $exists = false;
                        foreach ($api->getHotspotUsers() as $u) {
                            if (($u['name'] ?? '') === $mikrotik_username) { $exists = true; break; }
                        }
                        $exists
                            ? $api->updateHotspotUser($mikrotik_username, $mikrotik_password, $profile)
                            : $api->addHotspotUser($mikrotik_username, $mikrotik_password, $profile);
                    } else {
                        $exists = false;
                        foreach ($api->getPPPoEUsers() as $u) {
                            if (($u['name'] ?? '') === $mikrotik_username) { $exists = true; break; }
                        }
                        $exists
                            ? $api->updatePPPoEUser($mikrotik_username, $mikrotik_password, $profile)
                            : $api->addPPPoEUser($mikrotik_username, $mikrotik_password, $profile);
                    }
                    $api->disconnect();
                }
            }
        } catch (Exception $e) {
            error_log("MikroTik sync on create failed: " . $e->getMessage());
        }
    }

    ob_clean();
    echo json_encode(['success' => true, 'message' => 'Customer created successfully', 'client_id' => $client_id]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        try { $pdo->rollBack(); } catch (Exception $re) {}
    }
    ob_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
