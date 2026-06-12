<?php
/**
 * GET /api/v1/vouchers/index.php
 * Query: page, per_page, status (active|used|expired), search
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../../../includes/db_master.php';
require_once __DIR__ . '/../../../includes/api_auth.php';

api_cors_headers();
$auth = require_api_auth($pdo);

$tenantId = $auth['tenant_id'];
if (!$tenantId) { http_response_code(403); echo json_encode(['error' => 'Tenant context required']); exit; }

$page    = max(1, (int)($_GET['page']     ?? 1));
$perPage = min(100, max(1, (int)($_GET['per_page'] ?? 50)));
$offset  = ($page - 1) * $perPage;
$status  = $_GET['status'] ?? '';
$search  = trim($_GET['search'] ?? '');

$where  = ['v.tenant_id = ?'];
$params = [$tenantId];

if (in_array($status, ['active', 'used', 'expired'], true)) {
    $where[]  = 'v.status = ?';
    $params[] = $status;
}
if ($search !== '') {
    $where[]  = 'v.voucher_code LIKE ?';
    $params[] = '%' . $search . '%';
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM vouchers v $whereClause");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$listStmt = $pdo->prepare("
    SELECT v.id, v.voucher_code, v.status, v.price, v.expiry_date, v.created_at,
           p.name AS package_name,
           COALESCE(NULLIF(p.connection_type,''), NULLIF(p.type,''), 'pppoe') AS connection_type,
           c.full_name AS used_by_name, c.phone AS used_by_phone
    FROM vouchers v
    LEFT JOIN packages p ON p.id = v.package_id
    LEFT JOIN clients  c ON c.id = v.used_by_client_id
    $whereClause
    ORDER BY v.created_at DESC
    LIMIT ? OFFSET ?
");
foreach ($params as $i => $v) $listStmt->bindValue($i + 1, $v);
$listStmt->bindValue(count($params) + 1, $perPage, PDO::PARAM_INT);
$listStmt->bindValue(count($params) + 2, $offset,  PDO::PARAM_INT);
$listStmt->execute();
$vouchers = $listStmt->fetchAll(PDO::FETCH_ASSOC);

// Summary counts
$statsStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(status='active') AS available,
        SUM(status='used')   AS used,
        SUM(status='expired') AS expired
    FROM vouchers WHERE tenant_id = ?
");
$statsStmt->execute([$tenantId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    'data'  => $vouchers,
    'stats' => $stats,
    'pagination' => [
        'total'     => $total,
        'page'      => $page,
        'per_page'  => $perPage,
        'last_page' => (int)ceil($total / $perPage) ?: 1,
    ],
]);
