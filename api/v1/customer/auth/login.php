<?php
/**
 * POST /api/v1/customer/auth/login.php
 *
 * Customer login for the customer self-service Kotlin app.
 * Accepts username / phone / email + password (or MikroTik password fallback).
 *
 * Request body (JSON):
 *   { "username": "...", "password": "...", "device_name": "Customer App Android" }
 *
 * Response:
 *   { "access_token", "refresh_token", "token_type", "expires_in", "customer": {...} }
 *
 * Customer JWTs carry type:"customer" to distinguish from admin tokens.
 * The customer app must store and refresh tokens the same way as the admin app.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../../../../includes/db_master.php';
require_once __DIR__ . '/../../../../includes/env.php';
require_once __DIR__ . '/../../../../includes/jwt.php';
require_once __DIR__ . '/../../../../includes/api_auth.php';

api_cors_headers();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$body       = json_decode(file_get_contents('php://input'), true) ?? [];
$identifier = trim($body['username'] ?? $body['email'] ?? $body['phone'] ?? '');
$password   = $body['password'] ?? '';
$deviceName = trim(substr($body['device_name'] ?? 'Customer App', 0, 150));

if (!$identifier || !$password) {
    http_response_code(422);
    echo json_encode(['error' => 'Username/phone/email and password are required']);
    exit;
}

$secret = get_env_var('JWT_SECRET', '');
if (!$secret) {
    http_response_code(500);
    echo json_encode(['error' => 'Server misconfiguration']);
    exit;
}

// Detect tenant from subdomain (customer app hits their ISP's subdomain)
$httpHost  = explode(':', $_SERVER['HTTP_HOST'] ?? '')[0];
$hostParts = explode('.', $httpHost);
$tenantId  = null;

if (count($hostParts) >= 3 && $hostParts[0] !== 'www') {
    $ts = $pdo->prepare("SELECT id FROM tenants WHERE subdomain = ? LIMIT 1");
    $ts->execute([$hostParts[0]]);
    $tenantId = $ts->fetchColumn() ?: null;
}

try {
    $stmt = $pdo->prepare("
        SELECT * FROM clients
        WHERE (username = ? OR phone = ? OR email = ?)
          AND status != 'suspended'
          " . ($tenantId ? 'AND tenant_id = ?' : '') . "
        LIMIT 1
    ");
    $execParams = [$identifier, $identifier, $identifier];
    if ($tenantId) $execParams[] = $tenantId;
    $stmt->execute($execParams);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);

    $authenticated = false;
    if ($client) {
        if (!empty($client['auth_password']) && password_verify($password, $client['auth_password'])) {
            $authenticated = true;
        } elseif (!empty($client['mikrotik_password']) && $password === $client['mikrotik_password']) {
            $authenticated = true; // MikroTik password fallback
        }
    }

    if (!$authenticated) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid credentials']);
        exit;
    }

    $clientTenantId = (int)$client['tenant_id'];
    $now = time();

    // Access token — type: 'customer' distinguishes from admin JWT
    $accessToken = jwt_encode([
        'uid'  => (int)$client['id'],
        'tid'  => $clientTenantId,
        'type' => 'customer',
        'iat'  => $now,
        'exp'  => $now + 900,
    ], $secret);

    // Refresh token
    $refreshRaw  = bin2hex(random_bytes(32));
    $refreshHash = hash('sha256', $refreshRaw);
    $refreshExp  = date('Y-m-d H:i:s', $now + 86400 * 30);

    // Store in api_tokens with role='customer', user_id = client.id
    $pdo->prepare("DELETE FROM api_tokens WHERE user_id = ? AND role = 'customer' AND expires_at < NOW()")
        ->execute([$client['id']]);

    $pdo->prepare("
        INSERT INTO api_tokens (user_id, tenant_id, role, token_hash, device_name, expires_at)
        VALUES (?, ?, 'customer', ?, ?, ?)
    ")->execute([$client['id'], $clientTenantId, $refreshHash, $deviceName, $refreshExp]);

    // Load package details
    $pkg = null;
    if (!empty($client['package_id'])) {
        $pkgStmt = $pdo->prepare("SELECT name, download_speed, upload_speed, price, validity_value, validity_unit FROM packages WHERE id = ? LIMIT 1");
        $pkgStmt->execute([$client['package_id']]);
        $pkg = $pkgStmt->fetch(PDO::FETCH_ASSOC);
    }

    echo json_encode([
        'access_token'  => $accessToken,
        'refresh_token' => $refreshRaw,
        'token_type'    => 'Bearer',
        'expires_in'    => 900,
        'customer'      => [
            'id'               => (int)$client['id'],
            'name'             => $client['full_name'] ?? $client['name'] ?? '',
            'email'            => $client['email'],
            'phone'            => $client['phone'],
            'account_number'   => $client['account_number'],
            'status'           => $client['status'],
            'connection_type'  => $client['connection_type'],
            'expiry_date'      => $client['expiry_date'],
            'tenant_id'        => $clientTenantId,
            'package'          => $pkg,
        ],
    ]);

} catch (Throwable $e) {
    error_log('Customer API login error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Login failed. Please try again.']);
}
