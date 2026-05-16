<?php
/**
 * GET /api/v1/customer/profile.php
 *
 * Returns the authenticated customer's full profile, active subscription,
 * recent payments, and session status — everything the customer app home screen needs.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../../../includes/db_master.php';
require_once __DIR__ . '/../../../includes/env.php';
require_once __DIR__ . '/../../../includes/jwt.php';
require_once __DIR__ . '/../../../includes/api_auth.php';

api_cors_headers();

// Customer-specific auth: Bearer JWT with type='customer'
$auth = _require_customer_jwt();

$clientId = $auth['client_id'];
$tenantId = $auth['tenant_id'];

try {
    // Client record + package
    $stmt = $pdo->prepare("
        SELECT c.id, c.full_name, c.name, c.email, c.phone,
               c.account_number, c.status, c.connection_type,
               c.expiry_date, c.created_at, c.mikrotik_username,
               c.address, c.company,
               p.id         AS package_id,
               p.name       AS package_name,
               p.download_speed, p.upload_speed, p.price,
               p.validity_value, p.validity_unit, p.data_limit
        FROM clients c
        LEFT JOIN packages p ON p.id = c.package_id
        WHERE c.id = ? AND c.tenant_id = ?
        LIMIT 1
    ");
    $stmt->execute([$clientId, $tenantId]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$client) {
        http_response_code(404);
        echo json_encode(['error' => 'Account not found']);
        exit;
    }

    // Expiry countdown in seconds
    $expiresInSeconds = null;
    if ($client['expiry_date']) {
        $expiresInSeconds = max(0, strtotime($client['expiry_date']) - time());
    }

    // Recent payments (last 10)
    $payStmt = $pdo->prepare("
        SELECT id, amount, payment_method, transaction_id, status, payment_date
        FROM payments
        WHERE client_id = ? AND tenant_id = ?
        ORDER BY payment_date DESC
        LIMIT 10
    ");
    $payStmt->execute([$clientId, $tenantId]);
    $payments = $payStmt->fetchAll(PDO::FETCH_ASSOC);

    // ISP tenant info (for branding in the customer app)
    $tenantStmt = $pdo->prepare("SELECT name, subdomain, brand_color, logo_url FROM tenants WHERE id = ? LIMIT 1");
    $tenantStmt->execute([$tenantId]);
    $tenant = $tenantStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'customer' => [
            'id'             => (int)$client['id'],
            'name'           => $client['full_name'] ?? $client['name'] ?? '',
            'email'          => $client['email'],
            'phone'          => $client['phone'],
            'account_number' => $client['account_number'],
            'address'        => $client['address'],
            'company'        => $client['company'],
            'status'         => $client['status'],
            'connection_type'=> $client['connection_type'],
            'mikrotik_username' => $client['mikrotik_username'],
            'member_since'   => $client['created_at'],
        ],
        'subscription' => [
            'package_id'       => $client['package_id'] ? (int)$client['package_id'] : null,
            'package_name'     => $client['package_name'],
            'download_speed'   => $client['download_speed'],
            'upload_speed'     => $client['upload_speed'],
            'price'            => $client['price'],
            'data_limit'       => $client['data_limit'],
            'expiry_date'      => $client['expiry_date'],
            'expires_in_seconds' => $expiresInSeconds,
            'is_expired'       => $expiresInSeconds !== null && $expiresInSeconds === 0,
        ],
        'recent_payments' => $payments,
        'isp' => [
            'name'        => $tenant['name']       ?? '',
            'subdomain'   => $tenant['subdomain']  ?? '',
            'brand_color' => $tenant['brand_color'] ?? '#3B6EA5',
            'logo_url'    => $tenant['logo_url']   ?? '',
        ],
        'fetched_at' => date('c'),
    ]);

} catch (Throwable $e) {
    error_log('Customer API profile error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load profile']);
}

/**
 * Validate a customer JWT (type='customer') from the Authorization header.
 */
function _require_customer_jwt(): array {
    require_once __DIR__ . '/../../../includes/env.php';
    require_once __DIR__ . '/../../../includes/jwt.php';

    $authHeader = _resolve_auth_header();
    if (!$authHeader || !preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
        http_response_code(401);
        echo json_encode(['error' => 'Authentication required', 'code' => 'UNAUTHENTICATED']);
        exit;
    }

    $secret  = get_env_var('JWT_SECRET', '');
    $payload = $secret ? jwt_decode($m[1], $secret) : null;

    if (!$payload || ($payload['type'] ?? '') !== 'customer') {
        http_response_code(401);
        echo json_encode(['error' => 'Token expired or invalid', 'code' => 'TOKEN_EXPIRED']);
        exit;
    }

    return ['client_id' => (int)$payload['uid'], 'tenant_id' => (int)$payload['tid']];
}
