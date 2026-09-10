<?php
/**
 * POST /api/clients/change_expiry.php
 * Change a client's expiry date
 * Body: client_id, action (add_minutes|set_date|change_package), minutes|expiry_date|package_id, grace_hours
 */
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../includes/auth.php';
require_once '../../classes/MikrotikAPI.php';
require_once __DIR__ . '/../../includes/validity.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }

$st = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$st->execute([$_SESSION['user_id']]);
$tenant_id = $st->fetchColumn();
if (!$tenant_id) { echo json_encode(['success'=>false,'message'=>'No tenant']); exit; }

$client_id = (int)($_POST['client_id'] ?? 0);
$action    = trim($_POST['action'] ?? '');
if (!$client_id || !$action) {
    echo json_encode(['success'=>false,'message'=>'client_id and action required']);
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
    if ((int)($_POST['grace_hours'] ?? 0) !== 0) throw new RuntimeException('Grace period is zero. Extra access requires payment.');
    $newExpiry = $client['expiry_date'];
    if ($action === 'set_date') {
        $requested = strtotime($_POST['expiry_date'] ?? '');
        if (!$requested || !$newExpiry || $requested > strtotime($newExpiry)) throw new RuntimeException('Access time can only be extended through a successful payment.');
        $newExpiry = date('Y-m-d H:i:s', $requested);
        $pdo->prepare('UPDATE clients SET expiry_date=? WHERE id=? AND tenant_id=?')->execute([$newExpiry, $client_id, $tenant_id]);
    } elseif ($action === 'change_package') {
        $pkg = $pdo->prepare('SELECT * FROM packages WHERE id=? AND tenant_id=?');
        $pkg->execute([(int)($_POST['package_id'] ?? 0), $tenant_id]);
        $package = $pkg->fetch(PDO::FETCH_ASSOC);
        if (!$package || ($package['connection_type'] ?: $package['type']) !== $client['connection_type']) throw new RuntimeException('Choose a package matching this customer connection type.');
        $pdo->prepare('UPDATE clients SET package_id=?, subscription_plan=? WHERE id=? AND tenant_id=?')->execute([$package['id'], $package['name'], $client_id, $tenant_id]);
    } else {
        throw new RuntimeException('Extra access requires a successful payment.');
    }
    dashboardQueueCustomer($pdo, (int)$tenant_id, $client_id);
    $pdo->commit();
    echo json_encode(['success'=>true, 'sync_pending'=>true, 'message'=>'Saved. Applying access settings; no extra time added.', 'new_expiry'=>$newExpiry]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success'=>false, 'message'=>$e->getMessage()]);
} finally {
    if ($locked) $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
}
