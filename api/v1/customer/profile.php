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
               p.id         AS package_id,
               p.name       AS package_name,
               p.download_speed, p.upload_speed, p.price,
               p.validity_value, p.validity_unit
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
        SELECT id, amount,
               payment_method AS method,
               status,
               created_at
        FROM payments
        WHERE client_id = ? AND tenant_id = ?
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $payStmt->execute([$clientId, $tenantId]);
    $payments = $payStmt->fetchAll(PDO::FETCH_ASSOC);

    // ISP tenant info (for branding in the customer app)
    $tenantStmt = $pdo->prepare("SELECT company_name AS name, subdomain FROM tenants WHERE id = ? LIMIT 1");
    $tenantStmt->execute([$tenantId]);
    $tenant = $tenantStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // Subscription status derived from expiry
    $subStatus = 'inactive';
    if ($client['status'] === 'active' && $expiresInSeconds > 0) {
        $subStatus = 'active';
    } elseif ($expiresInSeconds === 0) {
        $subStatus = 'expired';
    }

    // Flat structure matching the Kotlin ProfileResponse model
    echo json_encode([
        'id'             => (int)$client['id'],
        'full_name'      => $client['full_name'] ?? $client['name'] ?? '',
        'username'       => $client['mikrotik_username'] ?? '',
        'email'          => $client['email'],
        'phone'          => $client['phone'],
        'account_number' => $client['account_number'],
        'status'         => $client['status'],
        'subscription'   => [
            'package_name'       => $client['package_name'],
            'package_price'      => $client['price'] ? (float)$client['price'] : null,
            'expiry_date'        => $client['expiry_date'],
            'expires_in_seconds' => $expiresInSeconds,
            'status'             => $subStatus,
        ],
        'recent_payments' => array_map(fn($p) => [
            'id'         => (int)$p['id'],
            'amount'     => (float)$p['amount'],
            'method'     => $p['method'] ?? '',
            'status'     => $p['status'],
            'created_at' => $p['created_at'],
        ], $payments),
        'isp' => [
            'name'        => $tenant['name'] ?? '',
            'brand_color' => null,
        ],
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
