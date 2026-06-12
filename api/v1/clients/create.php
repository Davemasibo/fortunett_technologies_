<?php
/**
 * POST /api/v1/clients/create.php
 * Body (JSON): full_name, phone, email, package_id, connection_type,
 *              username (opt), password (opt), expiry_date (opt), address (opt)
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../../../includes/db_master.php';
require_once __DIR__ . '/../../../includes/api_auth.php';
require_once __DIR__ . '/../../../includes/account_number_generator.php';
require_once __DIR__ . '/../../../includes/auto_provision.php';

api_cors_headers();
$auth = require_api_auth($pdo);

$tenantId = $auth['tenant_id'];
if (!$tenantId) { http_response_code(403); echo json_encode(['error' => 'Tenant context required']); exit; }

$body = json_decode(file_get_contents('php://input'), true) ?? [];

$name       = trim($body['full_name'] ?? '');
$phone      = trim($body['phone'] ?? '');
$email      = trim($body['email'] ?? '');
$packageId  = (int)($body['package_id'] ?? 0);
$connType   = in_array($body['connection_type'] ?? '', ['pppoe','hotspot','static']) ? $body['connection_type'] : 'pppoe';
$address    = trim($body['address'] ?? '');
$expiryDate = trim($body['expiry_date'] ?? '');

if ($name === '' || !$packageId) {
    http_response_code(422);
    echo json_encode(['error' => 'full_name and package_id are required']);
    exit;
}

// Validate package belongs to tenant
$pkgStmt = $pdo->prepare("SELECT *, COALESCE(NULLIF(connection_type,''),NULLIF(type,''),'pppoe') AS pkg_type FROM packages WHERE id = ? AND tenant_id = ? LIMIT 1");
$pkgStmt->execute([$packageId, $tenantId]);
$package = $pkgStmt->fetch(PDO::FETCH_ASSOC);
if (!$package) { http_response_code(404); echo json_encode(['error' => 'Package not found']); exit; }

$pkgType = strtolower($package['pkg_type']);
if ($connType !== 'static' && $pkgType !== $connType) {
    http_response_code(422);
    echo json_encode(['error' => "Package type ($pkgType) does not match connection type ($connType)"]);
    exit;
}

// Build username
$username = trim($body['username'] ?? '');
if ($username === '') {
    if ($phone !== '') {
        $digits   = preg_replace('/\D/', '', $phone);
        $username = 'u' . substr($digits, -8);
    } else {
        $slug     = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', strtok($name, ' ')));
        $username = substr($slug ?: 'user', 0, 8) . rand(100, 999);
    }
    $base = $username; $n = 1;
    while (true) {
        $chk = $pdo->prepare("SELECT id FROM clients WHERE mikrotik_username = ? LIMIT 1");
        $chk->execute([$username]);
        if (!$chk->fetch()) break;
        $username = $base . $n++;
    }
}

// Build password
$plainPassword = trim($body['password'] ?? '');
if ($plainPassword === '') {
    $chars = 'abcdefghjkmnpqrstuvwxyz23456789';
    $plainPassword = '';
    for ($i = 0; $i < 8; $i++) $plainPassword .= $chars[random_int(0, strlen($chars) - 1)];
}

// Duplicate checks
if ($phone !== '') {
    $dup = $pdo->prepare("SELECT id FROM clients WHERE tenant_id = ? AND phone = ? LIMIT 1");
    $dup->execute([$tenantId, $phone]);
    if ($dup->fetch()) { http_response_code(409); echo json_encode(['error' => "Phone \"$phone\" already in use"]); exit; }
}
$dupU = $pdo->prepare("SELECT id FROM clients WHERE tenant_id = ? AND mikrotik_username = ? LIMIT 1");
$dupU->execute([$tenantId, $username]);
if ($dupU->fetch()) { http_response_code(409); echo json_encode(['error' => "Username \"$username\" already in use"]); exit; }

// Expiry date
if ($expiryDate !== '') {
    $expiry = date('Y-m-d H:i:s', strtotime($expiryDate));
} elseif (!empty($package['validity_value'])) {
    $val    = (int)$package['validity_value'];
    $unit   = $package['validity_unit'] ?? 'days';
    $expiry = date('Y-m-d H:i:s', strtotime("+$val $unit"));
} else {
    $expiry = date('Y-m-d H:i:s', strtotime('+1 month'));
}

try {
    $hashedPw = password_hash($plainPassword, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO clients
        (tenant_id, full_name, name, email, phone, address, username, auth_password,
         mikrotik_username, mikrotik_password, package_id, subscription_plan,
         expiry_date, status, connection_type)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)");
    $stmt->execute([
        $tenantId, $name, $name, $email, $phone, $address,
        $username, $hashedPw, $username, $plainPassword,
        $packageId, $package['name'], $expiry, $connType
    ]);
    $clientId = (int)$pdo->lastInsertId();

    // Generate account number
    try {
        $gen           = new AccountNumberGenerator($pdo);
        $accountNumber = $gen->generateAccountNumber($tenantId);
        $pdo->prepare("UPDATE clients SET account_number = ? WHERE id = ?")->execute([$accountNumber, $clientId]);
    } catch (Throwable $e) {
        error_log("Account number generation failed: " . $e->getMessage());
    }

    // Provision to MikroTik (best-effort)
    $prov = autoProvisionClient($pdo, $clientId, $tenantId);

    echo json_encode([
        'success'         => true,
        'id'              => $clientId,
        'username'        => $username,
        'password'        => $plainPassword,
        'mikrotik_synced' => $prov['success'],
        'message'         => $prov['success'] ? 'Client created and provisioned' : 'Client created (router sync failed: ' . ($prov['message'] ?? '') . ')',
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) try { $pdo->rollBack(); } catch (Throwable $_) {}
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
