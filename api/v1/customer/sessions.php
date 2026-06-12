<?php
/**
 * GET /api/v1/customer/sessions.php
 * Returns recent portal login sessions for the authenticated customer.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../../../includes/db_master.php';
require_once __DIR__ . '/../../../includes/env.php';
require_once __DIR__ . '/../../../includes/jwt.php';
require_once __DIR__ . '/../../../includes/customer_auth.php';

$auth     = require_customer_auth();
$clientId = $auth['client_id'];
$tenantId = $auth['tenant_id'];

try {
    $stmt = $pdo->prepare("
        SELECT id, ip_address, mac_address, user_agent,
               created_at, last_activity, expires_at
        FROM customer_sessions
        WHERE client_id = ? AND tenant_id = ?
        ORDER BY last_activity DESC
        LIMIT 20
    ");
    $stmt->execute([$clientId, $tenantId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sessions = array_map(fn($r) => [
        'id'            => (int)$r['id'],
        'ip_address'    => $r['ip_address'] ?? '—',
        'mac_address'   => $r['mac_address'],
        'user_agent'    => $r['user_agent'],
        'created_at'    => $r['created_at'],
        'last_activity' => $r['last_activity'],
        'expires_at'    => $r['expires_at'],
    ], $rows);

    echo json_encode(['data' => $sessions]);

} catch (Throwable $e) {
    error_log('Customer sessions API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load sessions']);
}
