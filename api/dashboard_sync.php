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
    if ($_SERVER['REQUEST_METHOD'] === 'POST') dashboardProcessSync($pdo, $tenant);
    $stmt = $pdo->prepare('SELECT COUNT(*) AS pending, COALESCE(SUM(attempts>0),0) AS waiting FROM dashboard_sync_jobs WHERE tenant_id=? AND applied_at IS NULL');
    $stmt->execute([$tenant]);
    $status = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'pending' => (int)$status['pending'], 'waiting' => (int)$status['waiting']]);
} catch (Throwable $e) {
    error_log('Dashboard sync status: ' . $e->getMessage());
    http_response_code(503); echo json_encode(['success' => false, 'message' => 'Update status is temporarily unavailable. Saved changes will retry automatically.']);
}
