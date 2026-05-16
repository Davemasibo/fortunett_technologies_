<?php
/**
 * GET /api/v1/auth/me.php
 *
 * Return the authenticated user's profile.
 * The Kotlin app calls this on startup to confirm the token is valid
 * and to load user context (name, tenant, role, permissions).
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../../../includes/db_master.php';
require_once __DIR__ . '/../../../includes/api_auth.php';

api_cors_headers();

$auth = require_api_auth($pdo);

try {
    $stmt = $pdo->prepare("
        SELECT u.id, u.username, u.email, u.full_name, u.name, u.role,
               u.tenant_id, u.is_super_admin,
               t.name      AS tenant_name,
               t.subdomain AS tenant_subdomain,
               t.status    AS tenant_status
        FROM users u
        LEFT JOIN tenants t ON t.id = u.tenant_id
        WHERE u.id = ?
        LIMIT 1
    ");
    $stmt->execute([$auth['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode(['error' => 'User not found']);
        exit;
    }

    echo json_encode([
        'id'               => (int)$user['id'],
        'name'             => $user['full_name'] ?? $user['name'] ?? $user['username'],
        'email'            => $user['email'],
        'username'         => $user['username'],
        'role'             => $user['role'],
        'tenant_id'        => $user['tenant_id'] ? (int)$user['tenant_id'] : null,
        'tenant_name'      => $user['tenant_name'],
        'tenant_subdomain' => $user['tenant_subdomain'],
        'tenant_status'    => $user['tenant_status'],
        'is_super_admin'   => !empty($user['is_super_admin']),
        'auth_method'      => $auth['auth_method'],
    ]);

} catch (Throwable $e) {
    error_log('API me error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch user profile']);
}
