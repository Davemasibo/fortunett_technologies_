<?php
/**
 * Customer Registration API
 * Handles new customer self-registration through the ISP's customer portal.
 * Tenant is detected from subdomain — all data is fully isolated per tenant.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../../includes/db_master.php';
require_once __DIR__ . '/../../includes/tenant.php';
require_once __DIR__ . '/../../classes/MpesaAPI.php';
require_once __DIR__ . '/../../classes/CustomerAuth.php';
require_once __DIR__ . '/../../includes/account_number_generator.php';
require_once __DIR__ . '/../../includes/auto_provision.php';

try {
    // ── Resolve tenant from subdomain ─────────────────────────────────────────
    $tenantManager = TenantManager::getInstance($pdo);
    $tenant = $tenantManager->detectTenantFromSubdomain();

    // Allow ?tenant_id= override for localhost testing only
    if (!$tenant && $_SERVER['HTTP_HOST'] === 'localhost' && isset($_GET['tenant_id'])) {
        $tenant = $tenantManager->getTenantById((int)$_GET['tenant_id']);
    }

    if (!$tenant || $tenant['status'] === 'suspended') {
        echo json_encode(['success' => false, 'message' => 'Service unavailable. Please contact support.']);
        exit;
    }

    $tenantId = (int)$tenant['id'];

    // ── Input validation ──────────────────────────────────────────────────────
    $fullName  = trim($_POST['full_name'] ?? '');
    $phone     = trim($_POST['phone']     ?? '');
    $email     = trim($_POST['email']     ?? '');
    $address   = trim($_POST['address']   ?? '');
    $packageId = (int)($_POST['package_id'] ?? 0);

    if (empty($fullName) || empty($phone) || !$packageId) {
        echo json_encode(['success' => false, 'message' => 'Name, phone, and package are required']);
        exit;
    }

    // ── Phone uniqueness: scoped to this tenant only ──────────────────────────
    $dupStmt = $pdo->prepare("SELECT id FROM clients WHERE phone = ? AND tenant_id = ?");
    $dupStmt->execute([$phone, $tenantId]);
    if ($dupStmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Phone number already registered. Please login instead.']);
        exit;
    }

    // ── Package must belong to this tenant ────────────────────────────────────
    $pkgStmt = $pdo->prepare("SELECT * FROM packages WHERE id = ? AND tenant_id = ? AND status = 'active'");
    $pkgStmt->execute([$packageId, $tenantId]);
    $package = $pkgStmt->fetch(PDO::FETCH_ASSOC);

    if (!$package) {
        echo json_encode(['success' => false, 'message' => 'Invalid package selected']);
        exit;
    }

    // ── Generate credentials ──────────────────────────────────────────────────
    $username       = 'user_' . substr(preg_replace('/\D/', '', $phone), -8);
    $randomPassword = bin2hex(random_bytes(4));
    $hashedPassword = password_hash($randomPassword, PASSWORD_DEFAULT);

    // Expiry starts after payment; set a provisional date
    $expiryDate = date('Y-m-d H:i:s', strtotime('+' . ($package['validity_value'] ?? 30) . ' ' . ($package['validity_unit'] ?? 'days')));
    $connType   = $package['type'] === 'pppoe' ? 'pppoe' : 'hotspot';

    // ── Insert client with tenant_id ──────────────────────────────────────────
    $insertStmt = $pdo->prepare("
        INSERT INTO clients
            (tenant_id, full_name, name, email, phone, address,
             username, auth_password, mikrotik_username, mikrotik_password,
             package_id, subscription_plan, package_price, expiry_date,
             status, connection_type)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'inactive', ?)
    ");
    $insertStmt->execute([
        $tenantId,
        $fullName, $fullName,
        $email, $phone, $address,
        $username, $hashedPassword,
        $username, $randomPassword,
        $packageId, $package['name'], $package['price'],
        $expiryDate, $connType,
    ]);
    $clientId = (int)$pdo->lastInsertId();

    // ── Generate and persist account number ───────────────────────────────────
    $accountGenerator = new AccountNumberGenerator($pdo);
    $accountNumber    = $accountGenerator->generateAccountNumber($tenantId);
    $pdo->prepare("UPDATE clients SET account_number = ? WHERE id = ?")->execute([$accountNumber, $clientId]);

    // ── Free package: activate immediately, no payment required ─────────────
    if ((float)$package['price'] <= 0) {
        $pdo->prepare("UPDATE clients SET status = 'active' WHERE id = ?")->execute([$clientId]);

        $auth = new CustomerAuth($pdo);
        $auth->logActivity($clientId, 'activation', 'Free package activation: ' . $package['name']);

        // Auto-provision on the tenant's first active router (best-effort)
        $provision = autoProvisionClient($pdo, $clientId, $tenantId);

        $provisionMsg = $provision['success']
            ? 'Your service has been provisioned on the router.'
            : 'Account ready. Router provisioning will complete shortly.';

        echo json_encode([
            'success'      => true,
            'message'      => 'Registration successful. You are now connected!',
            'is_free'      => true,
            'client_id'    => $clientId,
            'username'     => $provision['username'] ?? $username,
            'password'     => $provision['password'] ?? $randomPassword,
            'provision_ok' => $provision['success'],
            'provision_msg'=> $provisionMsg,
            'redirect'     => 'login.html',
        ]);
        exit;
    }

    // ── Paid package: create pending payment record and initiate STK Push ────
    $payStmt = $pdo->prepare("
        INSERT INTO payments
            (tenant_id, client_id, amount, payment_method, payment_date, status, notes, invoice)
        VALUES (?, ?, ?, 'mpesa', NOW(), 'pending', ?, ?)
    ");
    $payStmt->execute([
        $tenantId, $clientId, $package['price'],
        'Initial registration: ' . $package['name'],
        $accountNumber,
    ]);
    $paymentId = (int)$pdo->lastInsertId();

    // ── Initiate M-Pesa STK Push using tenant's gateway credentials ───────────
    $mpesa    = new MpesaAPI($pdo, $tenantId);
    $desc     = 'Registration - ' . $package['name'];
    $response = $mpesa->stkPush($phone, $package['price'], $accountNumber, $desc);

    if (isset($response->ResponseCode) && $response->ResponseCode == '0') {
        $pdo->prepare("UPDATE payments SET transaction_id = ? WHERE id = ?")
            ->execute([$response->CheckoutRequestID, $paymentId]);

        $auth = new CustomerAuth($pdo);
        $auth->logActivity($clientId, 'login', 'Customer registration initiated');

        echo json_encode([
            'success'              => true,
            'message'              => 'Registration successful. Please complete payment on your phone.',
            'checkout_request_id'  => $response->CheckoutRequestID,
            'client_id'            => $clientId,
            'username'             => $username,
            'password'             => $randomPassword,
        ]);
    } else {
        // Roll back: remove the created client and payment record
        $pdo->prepare("DELETE FROM payments WHERE id = ?")->execute([$paymentId]);
        $pdo->prepare("DELETE FROM clients WHERE id = ?")->execute([$clientId]);

        $errorMsg = $response->errorMessage ?? $response->ResponseDescription ?? 'Payment initiation failed';
        echo json_encode(['success' => false, 'message' => $errorMsg]);
    }

} catch (Exception $e) {
    error_log("customer/register.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error. Please try again.']);
}
