<?php
/**
 * POST /api/clients/pause_subscription.php
 * Pause (suspend) or resume an active subscription without altering expiry.
 * Body: client_id, action (pause|resume)
 */
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$st = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$st->execute([$_SESSION['user_id']]);
$tenant_id = $st->fetchColumn();
if (!$tenant_id) {
    echo json_encode(['success' => false, 'message' => 'No tenant assigned']);
    exit;
}

$client_id = (int)($_POST['client_id'] ?? 0);
$action    = trim($_POST['action'] ?? '');

if (!$client_id || !in_array($action, ['pause', 'resume'])) {
    echo json_encode(['success' => false, 'message' => 'client_id and action (pause|resume) required']);
    exit;
}

require_once __DIR__ . '/../../includes/dashboard_sync.php';
$lockName = 'payment-client-' . $tenant_id . '-' . $client_id;
$locked = false;
try {
    dashboardSyncSchema($pdo);
    $lock = $pdo->prepare('SELECT GET_LOCK(?,30)');
    $lock->execute([$lockName]);
    $locked = (int)$lock->fetchColumn() === 1;
    if (!$locked) throw new RuntimeException('Customer update in progress. Please try again.');
    $pdo->beginTransaction();
    $chk = $pdo->prepare('SELECT * FROM clients WHERE id=? AND tenant_id=? FOR UPDATE');
    $chk->execute([$client_id, $tenant_id]);
    $client = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$client) throw new RuntimeException('Customer not found');
    $newStatus = $action === 'pause' ? 'suspended' : ((!empty($client['expiry_date']) && strtotime($client['expiry_date']) > time()) ? 'active' : 'inactive');
    $pdo->prepare('UPDATE clients SET status=?, updated_at=NOW() WHERE id=? AND tenant_id=?')->execute([$newStatus, $client_id, $tenant_id]);
    dashboardQueueCustomer($pdo, (int)$tenant_id, $client_id);
    $pdo->commit();
    echo json_encode(['success'=>true, 'sync_pending'=>true, 'message'=>'Saved. Applying access settings; paid expiry is unchanged.', 'new_status'=>$newStatus]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success'=>false, 'message'=>$e->getMessage()]);
} finally {
    if ($locked) $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
}
