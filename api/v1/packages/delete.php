<?php
/**
 * POST /api/v1/packages/delete.php
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

$check = $pdo->prepare("SELECT id FROM packages WHERE id = ? AND tenant_id = ? LIMIT 1");
$check->execute([$id, $tenantId]);
if (!$check->fetch()) { http_response_code(404); echo json_encode(['error' => 'Package not found']); exit; }

// Check if clients are on this package
$inUse = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE package_id = ? AND tenant_id = ?");
$inUse->execute([$id, $tenantId]);
if ((int)$inUse->fetchColumn() > 0) {
    http_response_code(409);
    echo json_encode(['error' => 'Cannot delete: customers are assigned to this package']);
    exit;
}

$pdo->prepare("DELETE FROM packages WHERE id = ? AND tenant_id = ?")->execute([$id, $tenantId]);
echo json_encode(['success' => true, 'message' => 'Package deleted']);
