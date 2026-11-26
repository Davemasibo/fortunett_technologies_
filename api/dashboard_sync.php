<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/../includes/dashboard_sync.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) {
    http_response_code(401); echo json_encode(['success' => false]); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (empty($_SESSION['dashboard_sync_csrf']) || !hash_equals($_SESSION['dashboard_sync_csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    http_response_code(403); echo json_encode(['success' => false]); exit;
}
$stmt = $pdo->prepare('SELECT tenant_id FROM users WHERE id=?');
$stmt->execute([$_SESSION['user_id']]);
$tenant = (int)$stmt->fetchColumn();
session_write_close(); // Router I/O must not block other dashboard requests.
if (!$tenant) { http_response_code(403); echo json_encode(['success' => false]); exit; }
try {
    dashboardSyncSchema($pdo);
    // Drain a small batch so one old retry cannot make newer saved changes
    // appear stalled. Failed jobs are deferred by next_retry_at independently.
    if ($_SERVER['REQUEST_METHOD'] === 'POST') dashboardProcessSync($pdo, $tenant, 3);
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(blocked_at IS NULL),0) AS pending, COALESCE(SUM(blocked_at IS NULL AND attempts>0),0) AS waiting, COALESCE(SUM(blocked_at IS NOT NULL),0) AS blocked FROM dashboard_sync_jobs WHERE tenant_id=? AND applied_at IS NULL');
    $stmt->execute([$tenant]);
    $status = $stmt->fetch(PDO::FETCH_ASSOC);
    $errors = $pdo->prepare('SELECT j.kind,j.entity_id,j.attempts,j.last_error,(j.blocked_at IS NOT NULL) AS blocked,r.name AS router_name FROM dashboard_sync_jobs j LEFT JOIN mikrotik_routers r ON r.id=j.router_id AND r.tenant_id=j.tenant_id WHERE j.tenant_id=? AND j.applied_at IS NULL AND j.last_error IS NOT NULL ORDER BY (j.blocked_at IS NOT NULL) DESC,j.next_retry_at,j.id LIMIT 5');
    $errors->execute([$tenant]);
    echo json_encode(['success' => true, 'pending' => (int)$status['pending'], 'waiting' => (int)$status['waiting'], 'blocked'=>(int)$status['blocked'], 'errors'=>$errors->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Throwable $e) {
    error_log('Dashboard sync status: ' . $e->getMessage());
    http_response_code(503); echo json_encode(['success' => false, 'message' => 'Update status is temporarily unavailable. Saved changes will retry automatically.']);
}
