<?php
/**
 * API Endpoint: Deploy branded hotspot login page to the tenant's router.
 * The router pulls the file from this server via /tool/fetch — no FTP needed.
 */
ob_start();
ini_set('display_errors', 0);
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../includes/auto_provision.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    ob_clean(); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}

$t = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$t->execute([$_SESSION['user_id']]);
$tenant_id = (int)$t->fetchColumn();

$router_id = (int)($_POST['router_id'] ?? 0);

$rq = $pdo->prepare(
    "SELECT * FROM mikrotik_routers WHERE id = ? AND tenant_id = ? AND status IN ('active','online')"
);
$rq->execute([$router_id, $tenant_id]);
$router = $rq->fetch(PDO::FETCH_ASSOC);

if (!$router) {
    ob_clean(); echo json_encode(['success' => false, 'message' => 'Router not found or not active']); exit;
}

try {
    _uploadHotspotLoginPage($pdo, $router, $tenant_id);
    ob_clean();
    echo json_encode(['success' => true, 'message' => 'Hotspot login page deployed — router is pulling the file now.']);
} catch (Throwable $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
