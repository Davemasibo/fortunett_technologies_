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
require_once '../../includes/auto_provision.php';

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
$mikrotik_password = trim($_POST['mikrotik_password'] ?? '');
$package_id        = (int)($_POST['package_id']       ?? 0);
$address           = trim($_POST['address']           ?? '');
$connection_type   = $_POST['connection_type']        ?? 'pppoe';

// Auto-generate username if not provided (from phone number or name slug)
$autoGenUsername = false;
if (empty($mikrotik_username)) {
    $autoGenUsername = true;
    if (!empty($phone)) {
        $digits = preg_replace('/\D/', '', $phone);
        $mikrotik_username = 'u' . substr($digits, -8);
    } else {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', strtok($name, ' ')));
        $mikrotik_username = substr($slug ?: 'user', 0, 8) . rand(100, 999);
    }
    // Ensure uniqueness — append suffix if taken
    $base = $mikrotik_username; $n = 1;
    while (true) {
        $chk = $pdo->prepare("SELECT id FROM clients WHERE mikrotik_username = ? LIMIT 1");
        $chk->execute([$mikrotik_username]);
        if (!$chk->fetch()) break;
        $mikrotik_username = $base . $n++;
    }
}

// Auto-generate password if not provided
$autoGenPassword = false;
if (empty($mikrotik_password)) {
    $autoGenPassword = true;
    $chars = 'abcdefghjkmnpqrstuvwxyz23456789'; // no confusable chars (0/O, 1/I/l)
    $mikrotik_password = '';
    for ($i = 0; $i < 8; $i++) {
        $mikrotik_password .= $chars[random_int(0, strlen($chars) - 1)];
    }
}

$username = $mikrotik_username;
$password = $mikrotik_password;

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

// Ensure mikrotik_profile column exists on packages table (one-time migration)
try { $pdo->exec("ALTER TABLE packages ADD COLUMN mikrotik_profile VARCHAR(100) DEFAULT NULL"); } catch (Exception $_e) {}

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
    // Note: status is 'inactive' until MikroTik sync succeeds; updated below.
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

    // 6. Sync to MikroTik via autoProvisionClient (non-fatal — client saved regardless)
    $provResult     = autoProvisionClient($pdo, $client_id, $tenant_id);
    $mikrotikSynced = $provResult['success'];
    $mikrotikError  = $provResult['success'] ? null : ($provResult['message'] ?? 'Provisioning failed');
    $noRouter       = (strpos($mikrotikError ?? '', 'No active router') !== false);
    $profileUsed    = null;
    $routerIp       = null;

    // Mark active if MikroTik user was created (credentials will work immediately)
    if ($mikrotikSynced) {
        $pdo->prepare("UPDATE clients SET status = 'active' WHERE id = ?")->execute([$client_id]);
    }

    ob_clean();
    echo json_encode([
        'success'           => true,
        'client_id'         => $client_id,
        'mikrotik_synced'   => $mikrotikSynced,
        'mikrotik_error'    => $mikrotikError,
        'no_router'         => $noRouter,
        'router_ip'         => $routerIp,
        'profile_used'      => $profileUsed,
        'username'          => $mikrotik_username,
        'password'          => $mikrotik_password,
        'auto_gen_username' => $autoGenUsername,
        'auto_gen_password' => $autoGenPassword,
        'connection_type'   => $connection_type,
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        try { $pdo->rollBack(); } catch (Exception $re) {}
    }
    ob_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
