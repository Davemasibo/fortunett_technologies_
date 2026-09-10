<?php
/**
 * Public Hotspot Login API — MikroTik Captive Portal
 * No session required. Validates hotspot customer credentials.
 * Returns MikroTik credentials + portal token for auto-login.
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

require_once __DIR__ . '/../../includes/db_master.php';
require_once __DIR__ . '/../../includes/tenant.php';
require_once __DIR__ . '/../../includes/auto_provision.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'POST required']);
    exit;
}

$username   = trim($_POST['username']    ?? '');
$password   = trim($_POST['password']   ?? '');
$macAddress = strtoupper(preg_replace('/[^a-fA-F0-9:]/', '', $_POST['mac_address'] ?? ''));
$tenantId   = (int)($_POST['tenant_id'] ?? 0);

if (!$username || !$password) {
    echo json_encode(['success' => false, 'message' => 'Username and password are required.']);
    exit;
}

// ── Resolve tenant ────────────────────────────────────────────────────────────
if (!$tenantId) {
    $tenantManager = TenantManager::getInstance($pdo);
    $tenant        = $tenantManager->detectTenantFromSubdomain();

    // Allow ?tenant_id= override on localhost for testing
    if (!$tenant && isset($_POST['tenant_id'])) {
        $tenant = $tenantManager->getTenantById((int)$_POST['tenant_id']);
    }

    if ($tenant) {
        $tenantId = (int)$tenant['id'];
    }
}

if (!$tenantId) {
    echo json_encode(['success' => false, 'message' => 'Portal not configured. Contact support.']);
    exit;
}

try {
    // ── Normalize phone: 07xx → 254xx format ─────────────────────────────────
    $loginPhone254 = $username;
    if (preg_match('/^0[0-9]{9}$/', $username)) {
        $loginPhone254 = '254' . substr($username, 1);
    }

    // ── Find hotspot client by username, phone (both formats), account_number, or mikrotik_username ─
    // COALESCE guard accepts NULL connection_type (legacy rows) as hotspot.
    // Excluding PPPoE clients prevents returning PPPoE MikroTik credentials to a
    // hotspot portal, which would cause MikroTik authentication to fail.
    $clSt = $pdo->prepare("
        SELECT c.*, p.name AS pkg_name, p.price AS pkg_price,
               p.validity_value, p.validity_unit
        FROM clients c
        LEFT JOIN packages p ON p.id = c.package_id
        WHERE c.tenant_id = ?
          AND COALESCE(NULLIF(c.connection_type,''), 'hotspot') = 'hotspot'
          AND (c.username = ? OR c.phone = ? OR c.phone = ?
               OR c.account_number = ? OR c.mikrotik_username = ?)
        LIMIT 1
    ");
    $clSt->execute([$tenantId, $username, $username, $loginPhone254, $username, $username]);
    $client = $clSt->fetch(PDO::FETCH_ASSOC);

    if (!$client) {
        echo json_encode(['success' => false, 'message' => 'Account not found. Check your credentials or register a new account.']);
        exit;
    }

    // ── Verify password ───────────────────────────────────────────────────────
    // auth_password is the hashed portal password (set at registration or by admin).
    // mikrotik_password is the plain-text router credential (some customers use this directly).
    $authHash = $client['auth_password'] ?? '';
    $pwMatch  = ($authHash !== '' && password_verify($password, $authHash))
             || ($password !== '' && $password === ($client['mikrotik_password'] ?? "\0NEVER\0"));

    if (!$pwMatch) {
        echo json_encode(['success' => false, 'message' => 'Incorrect password. Please try again.']);
        exit;
    }

    // ── Check account status ──────────────────────────────────────────────────
    require_once __DIR__ . '/../../includes/hotspot_device.php';
    rememberHotspotDevice($pdo, $tenantId, (int)$client['id'], $_POST['mac_address'] ?? '');
    require_once __DIR__ . '/../../includes/stk_reconciliation.php';
    $recovering = recoverCustomerPayments($pdo, (int)$client['id'], $tenantId);
    $fresh = $pdo->prepare('SELECT * FROM clients WHERE id=? AND tenant_id=?');
    $fresh->execute([$client['id'], $tenantId]);
    $client = array_merge($client, $fresh->fetch(PDO::FETCH_ASSOC) ?: []);
    if ($recovering && ($client['status'] !== 'active' || empty($client['expiry_date']) || strtotime($client['expiry_date']) <= time())) {
        echo json_encode(['success'=>false,'processing'=>true,'message'=>'We are checking your previous payment. Do not pay again; connection recovery will retry automatically.']); exit;
    }

    if ($client['status'] === 'suspended') {
        echo json_encode(['success' => false, 'message' => 'Account suspended. Contact your ISP.']);
        exit;
    }

    if ($client['status'] === 'pending') {
        echo json_encode(['success' => false, 'message' => 'Account pending activation.', 'processing' => true]);
        exit;
    }

    // 'grace' clients have expired but are in the grace window — treat as expired so they renew.
    // 'inactive' = fully expired. Either way, redirect to buy.
    $isExpired = in_array($client['status'], ['inactive', 'grace', 'expired'])
              || (!empty($client['expiry_date']) && strtotime($client['expiry_date']) <= time());

    if ($isExpired) {
        echo json_encode([
            'success'        => false,
            'expired'        => true,
            'message'        => 'Subscription expired. Please renew to continue.',
            'account_number' => $client['account_number'] ?? '',
        ]);
        exit;
    }

    // ── Create portal auto-login token (30 min expiry) ────────────────────────
    $portalToken = null;
    try {
        $portalToken = bin2hex(random_bytes(16));
        $pdo->prepare("INSERT INTO payment_auto_logins (client_id, login_token, expires_at, status) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE), 'pending')")
            ->execute([$client['id'], $portalToken]);
    } catch (Exception $_e) {
        $portalToken = null;
    }

    // ── Resolve MikroTik credentials — provision if missing ──────────────────
    $provision = autoProvisionClient($pdo, (int)$client['id'], $tenantId, 0, false);
    if (!($provision['success'] ?? false)) {
        echo json_encode(['success' => false, 'processing' => true, 'message' => 'Payment received. Your connection is being retried. Do not pay again.']);
        exit;
    }
    $mkUsername = $provision['username'] ?? '';
    $mkPassword = $provision['password'] ?? '';

    // If still no credentials (router unreachable and never provisioned), block login
    if (empty($mkUsername)) {
        echo json_encode([
            'success' => false,
            'message' => 'Your account is not yet configured on the router. Please try again in a minute, or contact your ISP.',
        ]);
        exit;
    }

    // ── Return MikroTik credentials ───────────────────────────────────────────
    $firstName = explode(' ', trim($client['full_name'] ?? $client['username'] ?? ''))[0];

    echo json_encode([
        'success'           => true,
        'mikrotik_username' => $mkUsername,
        'mikrotik_password' => $mkPassword,
        'portal_token'      => $portalToken,
        'client_name'       => $firstName,
    ]);

} catch (Throwable $e) {
    error_log('[hotspot_login] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Sign-in error: ' . $e->getMessage()]);
}
