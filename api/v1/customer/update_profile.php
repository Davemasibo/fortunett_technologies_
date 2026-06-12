<?php
/**
 * POST /api/v1/customer/update_profile.php
 * Body (JSON): full_name, phone, email  (all optional — only provided fields updated)
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../../../includes/db_master.php';
require_once __DIR__ . '/../../../includes/customer_auth.php';

$auth     = require_customer_auth();
$clientId = $auth['client_id'];
$tenantId = $auth['tenant_id'];

$body = json_decode(file_get_contents('php://input'), true) ?? [];

$set  = [];
$vals = [];

if (isset($body['full_name']) && trim($body['full_name']) !== '') {
    $name = trim($body['full_name']);
    $set[] = 'full_name=?'; $vals[] = $name;
    $set[] = 'name=?';      $vals[] = $name;
}
if (isset($body['phone']) && trim($body['phone']) !== '') {
    $phone = trim($body['phone']);
    // Duplicate check
    $dup = $pdo->prepare("SELECT id FROM clients WHERE tenant_id = ? AND phone = ? AND id != ? LIMIT 1");
    $dup->execute([$tenantId, $phone, $clientId]);
    if ($dup->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'Phone number already used by another account']);
        exit;
    }
    $set[] = 'phone=?'; $vals[] = $phone;
}
if (isset($body['email'])) {
    $set[] = 'email=?'; $vals[] = trim($body['email']);
}

if (empty($set)) {
    http_response_code(422);
    echo json_encode(['error' => 'No fields to update']);
    exit;
}

try {
    $vals[] = $clientId;
    $vals[] = $tenantId;
    $pdo->prepare("UPDATE clients SET " . implode(',', $set) . " WHERE id=? AND tenant_id=?")->execute($vals);
    echo json_encode(['success' => true, 'message' => 'Profile updated']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
