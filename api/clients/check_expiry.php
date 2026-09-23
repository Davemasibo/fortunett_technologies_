<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/db_master.php';
require_once __DIR__ . '/../../includes/auth.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'POST required']); exit; }
if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Sign in again.']); exit; }
if (empty($_SESSION['dashboard_sync_csrf']) || !hash_equals($_SESSION['dashboard_sync_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403); echo json_encode(['success'=>false,'message'=>'Reload the page and try again.']); exit;
}
$st = $pdo->prepare('SELECT tenant_id,role,is_super_admin FROM users WHERE id=?');
$st->execute([$_SESSION['user_id']]); $actor = $st->fetch(PDO::FETCH_ASSOC);
if (!$actor || empty($actor['tenant_id']) || (!in_array($actor['role'],['admin','superadmin'],true) && empty($actor['is_super_admin']))) {
    http_response_code(403); echo json_encode(['success'=>false,'message'=>'An administrator must run the expiry check.']); exit;
}
session_write_close();
try {
    require_once __DIR__ . '/../../includes/manual_expiry_check.php';
    $result = runTenantExpiryCheck($pdo,(int)$actor['tenant_id']);
    echo json_encode(array_merge($result,['success'=>true,'sync_pending'=>$result['updated']>0,'message'=> $result['updated'] . ' account(s) marked inactive. Router disconnection is queued.' . ($result['remaining'] ? ' Run the check again for remaining accounts.' : '')]));
} catch (Throwable $e) {
    error_log('Manual expiry check: '.$e->getMessage());
    http_response_code(500); echo json_encode(['success'=>false,'message'=>'The check could not finish. Refresh the list and retry; completed updates are saved.']);
}
