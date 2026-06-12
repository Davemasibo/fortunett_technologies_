<?php
/**
 * Shared helper: validate a customer JWT (type='customer').
 * Included by customer-facing /api/v1/customer/ endpoints.
 * Returns ['client_id' => int, 'tenant_id' => int]
 */

function require_customer_auth(): array {
    require_once __DIR__ . '/env.php';
    require_once __DIR__ . '/jwt.php';
    require_once __DIR__ . '/api_auth.php';

    api_cors_headers();

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
