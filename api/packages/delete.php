<?php
ob_start(); ini_set('display_errors',0);
require_once __DIR__.'/../../includes/db_master.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/dashboard_sync.php';
header('Content-Type: application/json');
try {
    if ($_SERVER['REQUEST_METHOD']!=='POST' || !isLoggedIn()) throw new RuntimeException('Sign in to delete a package.');
    $st=$pdo->prepare('SELECT tenant_id FROM users WHERE id=?');$st->execute([$_SESSION['user_id']]);$tenant=(int)$st->fetchColumn();
    $id=(int)($_POST['id'] ?? 0);if (!$tenant || !$id) throw new RuntimeException('Package ID required.');
    dashboardSyncSchema($pdo);
    $pdo->beginTransaction();
    $st=$pdo->prepare('SELECT id FROM packages WHERE id=? AND tenant_id=? FOR UPDATE');$st->execute([$id,$tenant]);
    if (!$st->fetchColumn()) throw new RuntimeException('Package not found.');
    $st=$pdo->prepare('SELECT COUNT(*) FROM clients WHERE package_id=? AND tenant_id=?');$st->execute([$id,$tenant]);
    if ($st->fetchColumn()>0) throw new RuntimeException('Cannot delete a package assigned to customers.');
    $pdo->prepare('DELETE FROM packages WHERE id=? AND tenant_id=?')->execute([$id,$tenant]);
    dashboardQueuePortal($pdo,$tenant);$pdo->commit();
    $result=['success'=>true,'sync_pending'=>true,'message'=>'Package deleted. Refreshing router plan lists.'];
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $result=['success'=>false,'message'=>$e->getMessage()];
}
ob_clean();echo json_encode($result);
