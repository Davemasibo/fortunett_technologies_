<?php
/**
 * GET /api/clients/sms_history.php?client_id=X
 * Returns SMS messages sent to a client from sms_outbox
 */
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }

$st = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$st->execute([$_SESSION['user_id']]);
$tenant_id = $st->fetchColumn();
if (!$tenant_id) { echo json_encode(['success'=>false,'message'=>'No tenant']); exit; }

$client_id = (int)($_GET['client_id'] ?? 0);
if (!$client_id) { echo json_encode(['success'=>false,'message'=>'client_id required']); exit; }

$chk = $pdo->prepare("SELECT id FROM clients WHERE id = ? AND tenant_id = ?");
$chk->execute([$client_id, $tenant_id]);
if (!$chk->fetchColumn()) { echo json_encode(['success'=>false,'message'=>'Not found']); exit; }

try {
    $st = $pdo->prepare("
        SELECT id, recipient_phone AS phone, message, status, sent_at
        FROM sms_outbox
        WHERE client_id = ? AND tenant_id = ?
        ORDER BY sent_at DESC
        LIMIT 100
    ");
    $st->execute([$client_id, $tenant_id]);
    $messages = $st->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success'=>true,'messages'=>$messages]);

} catch (Exception $e) {
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
