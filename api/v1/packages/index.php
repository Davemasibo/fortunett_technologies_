<?php
/**
 * GET /api/v1/packages/index.php         — paginated package list
 * GET /api/v1/packages/index.php?id=5    — single package detail
 *
 * Query params (list):
 *   page, per_page, search, type (pppoe|hotspot), status (active|inactive)
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../../../includes/db_master.php';
require_once __DIR__ . '/../../../includes/api_auth.php';

api_cors_headers();
$auth = require_api_auth($pdo);

$tenantId = $auth['tenant_id'];
if (!$tenantId) {
    http_response_code(403);
    echo json_encode(['error' => 'Tenant context required']);
    exit;
}

// ── Single package ────────────────────────────────────────────────────────────
if (!empty($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM packages WHERE id = ? AND tenant_id = ? LIMIT 1");
    $stmt->execute([(int)$_GET['id'], $tenantId]);
    $pkg = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$pkg) { http_response_code(404); echo json_encode(['error' => 'Package not found']); exit; }
    echo json_encode($pkg);
    exit;
}

// ── Package list ──────────────────────────────────────────────────────────────
$page    = max(1, (int)($_GET['page']     ?? 1));
$perPage = min(100, max(1, (int)($_GET['per_page'] ?? 50)));
$offset  = ($page - 1) * $perPage;
$search  = trim($_GET['search'] ?? '');
$type    = $_GET['type']   ?? '';
$status  = $_GET['status'] ?? '';

$where  = ['tenant_id = ?'];
$params = [$tenantId];

if ($search !== '') {
    $like = '%' . $search . '%';
    $where[]  = '(name LIKE ? OR description LIKE ?)';
    $params[] = $like; $params[] = $like;
}
if (in_array($type, ['pppoe', 'hotspot'], true)) {
    $where[]  = '(COALESCE(NULLIF(connection_type,""), NULLIF(type,""), "pppoe") = ?)';
    $params[] = $type;
}
if (in_array($status, ['active', 'inactive'], true)) {
    $where[]  = 'status = ?';
    $params[] = $status;
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM packages $whereClause");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$listStmt = $pdo->prepare("
    SELECT id, name, price, description,
           download_speed, upload_speed, data_limit,
           COALESCE(NULLIF(connection_type,''), NULLIF(type,''), 'pppoe') AS connection_type,
           mikrotik_profile, validity_value, validity_unit, device_limit,
           hotspot_server, rate_limit, status
    FROM packages
    $whereClause
    ORDER BY name ASC
    LIMIT ? OFFSET ?
");
foreach ($params as $i => $v) $listStmt->bindValue($i + 1, $v);
$listStmt->bindValue(count($params) + 1, $perPage, PDO::PARAM_INT);
$listStmt->bindValue(count($params) + 2, $offset,  PDO::PARAM_INT);
$listStmt->execute();
$packages = $listStmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'data'       => $packages,
    'pagination' => [
        'total'     => $total,
        'page'      => $page,
        'per_page'  => $perPage,
        'last_page' => (int)ceil($total / $perPage) ?: 1,
    ],
]);
