<?php
/**
 * Dual-mode API authentication middleware.
 *
 * Every /api/v1/ endpoint calls require_api_auth($pdo) at the top.
 * It accepts two auth methods transparently:
 *
 *   1. Bearer JWT  — mobile apps (Kotlin admin + customer apps)
 *      Authorization: Bearer <access_token>
 *
 *   2. PHP session — web portal (unchanged behavior for the browser UI)
 *      $_SESSION['user_id'] must be set
 *
 * Returns an $auth context array:
 *   ['user_id', 'tenant_id', 'role', 'is_super_admin', 'auth_method']
 *
 * Also exposes api_cors_headers() for cross-origin browser/React clients.
 */

function api_cors_headers(): void {
    // Allow any origin in development; tighten to specific domains in production.
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Access-Control-Max-Age: 86400');
    header('Vary: Origin');

    // Preflight — browser sends OPTIONS before the real request
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function require_api_auth(PDO $pdo): array {
    // ── 1. Try Bearer JWT (mobile apps) ──────────────────────────────────────
    $authHeader = _resolve_auth_header();

    if ($authHeader && preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
        require_once __DIR__ . '/jwt.php';
        require_once __DIR__ . '/env.php';

        $secret = get_env_var('JWT_SECRET', '');
        if (!$secret) {
            http_response_code(500);
            echo json_encode(['error' => 'Server misconfiguration: JWT_SECRET not set']);
            exit;
        }

        $payload = jwt_decode($m[1], $secret);
        if (!$payload) {
            http_response_code(401);
            echo json_encode(['error' => 'Token expired or invalid. Please log in again.', 'code' => 'TOKEN_EXPIRED']);
            exit;
        }

        return [
            'user_id'        => (int)$payload['uid'],
            'tenant_id'      => isset($payload['tid']) ? (int)$payload['tid'] : null,
            'role'           => $payload['role'] ?? 'admin',
            'is_super_admin' => !empty($payload['sa']),
            'auth_method'    => 'jwt',
        ];
    }

    // ── 2. Fall back to PHP session (web portal) ──────────────────────────────
    if (session_status() === PHP_SESSION_NONE) session_start();

    if (!empty($_SESSION['user_id'])) {
        return [
            'user_id'        => (int)$_SESSION['user_id'],
            'tenant_id'      => !empty($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : null,
            'role'           => $_SESSION['role'] ?? 'admin',
            'is_super_admin' => !empty($_SESSION['is_super_admin']),
            'auth_method'    => 'session',
        ];
    }

    http_response_code(401);
    echo json_encode(['error' => 'Authentication required', 'code' => 'UNAUTHENTICATED']);
    exit;
}

/**
 * Assert the authenticated user owns the requested tenant.
 * Super admins bypass this check.
 */
function assert_tenant(array $auth, int $requestedTenantId): void {
    if ($auth['is_super_admin']) return;
    if ($auth['tenant_id'] !== $requestedTenantId) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied to this tenant']);
        exit;
    }
}

/**
 * Resolve the Authorization header across different server configurations.
 * Apache may not pass it through to PHP by default; several fallbacks handle this.
 */
function _resolve_auth_header(): ?string {
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        return $_SERVER['HTTP_AUTHORIZATION'];
    }
    // Apache mod_rewrite rewrites the header name
    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    // Some FastCGI setups
    if (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        if (!empty($headers['Authorization'])) return $headers['Authorization'];
        if (!empty($headers['authorization'])) return $headers['authorization'];
    }
    return null;
}
