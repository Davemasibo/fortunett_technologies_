<?php
/**
 * GET /api/v1/routers/index.php — router list with live status summary
 * GET /api/v1/routers/index.php?id=3 — single router detail
 *
 * The admin Kotlin app shows router health cards on its main screen.
 * Passwords are never returned; only metadata and status.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../../../includes/db_master.php';
require_once __DIR__ . '/../../../includes/api_auth.php';

api_cors_headers();

$auth = require_api_auth($pdo);

$tenantId = $auth['tenant_id'];
if (!$tenantId) {
    http_response_code(403);
    echo json_encode(['error' => 'Tenant context required']);
    exit;
}

try {
    if (!empty($_GET['id'])) {
        $stmt = $pdo->prepare("
            SELECT id, name, ip_address, vpn_ip, model, location,
                   status, last_checked, created_at
            FROM mikrotik_routers
            WHERE id = ? AND tenant_id = ?
            LIMIT 1
        ");
        $stmt->execute([(int)$_GET['id'], $tenantId]);
        $router = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$router) {
            http_response_code(404);
            echo json_encode(['error' => 'Router not found']);
            exit;
        }
        echo json_encode($router);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT id, name, ip_address, vpn_ip, model, location,
               status, last_checked, created_at
        FROM mikrotik_routers
        WHERE tenant_id = ?
        ORDER BY name ASC
    ");
    $stmt->execute([$tenantId]);
    $routers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['data' => $routers, 'total' => count($routers)]);

} catch (Throwable $e) {
    error_log('API routers error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load routers']);
}
