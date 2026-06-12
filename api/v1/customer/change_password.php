<?php
/**
 * POST /api/v1/customer/change_password.php
 * Body (JSON): current_password, new_password
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../../../includes/db_master.php';
require_once __DIR__ . '/../../../includes/customer_auth.php';

$auth     = require_customer_auth();
$clientId = $auth['client_id'];
$tenantId = $auth['tenant_id'];

$body       = json_decode(file_get_contents('php://input'), true) ?? [];
$currentPw  = $body['current_password'] ?? '';
$newPw      = $body['new_password']     ?? '';

if ($currentPw === '' || $newPw === '') {
    http_response_code(422);
    echo json_encode(['error' => 'current_password and new_password are required']);
    exit;
}
if (strlen($newPw) < 6) {
    http_response_code(422);
    echo json_encode(['error' => 'New password must be at least 6 characters']);
    exit;
}

$stmt = $pdo->prepare("SELECT auth_password, password, mikrotik_password FROM clients WHERE id = ? AND tenant_id = ? LIMIT 1");
$stmt->execute([$clientId, $tenantId]);
$client = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$client) { http_response_code(404); echo json_encode(['error' => 'Account not found']); exit; }

// Verify current password (same fallback chain as login)
$verified = false;
if (!empty($client['auth_password']) && password_verify($currentPw, $client['auth_password'])) $verified = true;
elseif (!empty($client['password'])  && password_verify($currentPw, $client['password']))      $verified = true;
elseif (!empty($client['mikrotik_password']) && $currentPw === $client['mikrotik_password'])   $verified = true;

if (!$verified) {
    http_response_code(403);
    echo json_encode(['error' => 'Current password is incorrect']);
    exit;
}

try {
    $hashed = password_hash($newPw, PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE clients SET auth_password=? WHERE id=? AND tenant_id=?")->execute([$hashed, $clientId, $tenantId]);
    echo json_encode(['success' => true, 'message' => 'Password changed successfully']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
