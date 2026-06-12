<?php
/**
 * POST /api/v1/vouchers/delete.php
 * Body (JSON): id  OR  ids: [1,2,3]
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../../../includes/db_master.php';
require_once __DIR__ . '/../../../includes/api_auth.php';

api_cors_headers();
$auth = require_api_auth($pdo);

$tenantId = $auth['tenant_id'];
if (!$tenantId) { http_response_code(403); echo json_encode(['error' => 'Tenant context required']); exit; }

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$ids  = [];
if (!empty($body['ids']) && is_array($body['ids'])) {
    $ids = array_map('intval', $body['ids']);
} elseif (!empty($body['id'])) {
    $ids = [(int)$body['id']];
}

if (empty($ids)) { http_response_code(422); echo json_encode(['error' => 'id or ids required']); exit; }

// Only allow deleting unused vouchers
$ph    = implode(',', array_fill(0, count($ids), '?'));
$check = $pdo->prepare("SELECT COUNT(*) FROM vouchers WHERE id IN ($ph) AND tenant_id = ? AND status = 'used'");
$check->execute(array_merge($ids, [$tenantId]));
if ((int)$check->fetchColumn() > 0) {
    http_response_code(409);
    echo json_encode(['error' => 'Cannot delete already-used vouchers']);
    exit;
}

$del = $pdo->prepare("DELETE FROM vouchers WHERE id IN ($ph) AND tenant_id = ?");
$del->execute(array_merge($ids, [$tenantId]));
echo json_encode(['success' => true, 'deleted' => $del->rowCount()]);
