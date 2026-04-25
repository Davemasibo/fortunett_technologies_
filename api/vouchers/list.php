<?php
ob_start(); ini_set('display_errors', 0);
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { ob_clean(); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }

$t = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$t->execute([$_SESSION['user_id']]);
$tenant_id = (int)$t->fetchColumn();

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset  = ($page - 1) * $perPage;
$status  = $_GET['status'] ?? '';
$search  = trim($_GET['search'] ?? '');

$where  = "v.tenant_id = ?";
$params = [$tenant_id];

if ($status && in_array($status, ['active','used','expired'])) {
    $where .= " AND v.status = ?";
    $params[] = $status;
}
if ($search) {
    $where .= " AND v.voucher_code LIKE ?";
    $params[] = '%' . $search . '%';
}

try {
    $total = $pdo->prepare("SELECT COUNT(*) FROM vouchers v WHERE $where");
    $total->execute($params);
    $totalCount = (int)$total->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT v.*, p.name AS package_name,
               c.full_name AS used_by_name, c.phone AS used_by_phone
        FROM vouchers v
        LEFT JOIN packages p ON p.id = v.package_id
        LEFT JOIN clients  c ON c.id = v.used_by_client_id
        WHERE $where
        ORDER BY v.created_at DESC
        LIMIT $perPage OFFSET $offset
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    ob_clean();
    echo json_encode(['success' => true, 'vouchers' => $rows, 'total' => $totalCount, 'page' => $page, 'per_page' => $perPage]);
} catch (Throwable $e) {
    ob_clean(); echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
