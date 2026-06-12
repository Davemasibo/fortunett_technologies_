<?php
/**
 * POST /api/v1/clients/delete.php
 * Body (JSON): id
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../../../includes/db_master.php';
require_once __DIR__ . '/../../../includes/api_auth.php';

api_cors_headers();
$auth = require_api_auth($pdo);

$tenantId = $auth['tenant_id'];
if (!$tenantId) { http_response_code(403); echo json_encode(['error' => 'Tenant context required']); exit; }

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$id   = (int)($body['id'] ?? 0);
if (!$id) { http_response_code(422); echo json_encode(['error' => 'id required']); exit; }

$check = $pdo->prepare("SELECT id FROM clients WHERE id = ? AND tenant_id = ? LIMIT 1");
$check->execute([$id, $tenantId]);
if (!$check->fetch()) { http_response_code(404); echo json_encode(['error' => 'Client not found']); exit; }

try {
    // Delete related records first (best-effort — missing tables are silently skipped)
    foreach (['payments', 'mpesa_transactions', 'customer_sessions', 'customer_activity_log'] as $tbl) {
        try { $pdo->prepare("DELETE FROM $tbl WHERE client_id = ? AND tenant_id = ?")->execute([$id, $tenantId]); }
        catch (Throwable $_) {}
    }
    $pdo->prepare("DELETE FROM clients WHERE id = ? AND tenant_id = ?")->execute([$id, $tenantId]);
    echo json_encode(['success' => true, 'message' => 'Client deleted']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
