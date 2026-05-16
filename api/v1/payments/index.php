<?php
/**
 * GET /api/v1/payments/index.php — paginated payment history for admin app
 *
 * Query params:
 *   page, per_page, client_id, status (completed|pending|failed), date_from, date_to
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

$page     = max(1, (int)($_GET['page']     ?? 1));
$perPage  = min(100, max(1, (int)($_GET['per_page'] ?? 25)));
$offset   = ($page - 1) * $perPage;
$clientId = !empty($_GET['client_id']) ? (int)$_GET['client_id'] : null;
$status   = $_GET['status'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo   = $_GET['date_to']   ?? '';

$where  = ['p.tenant_id = ?'];
$params = [$tenantId];

if ($clientId) {
    $where[]  = 'p.client_id = ?';
    $params[] = $clientId;
}

$allowed = ['completed', 'pending', 'failed'];
if (in_array($status, $allowed, true)) {
    $where[]  = 'p.status = ?';
    $params[] = $status;
}

if ($dateFrom) {
    $where[]  = 'p.payment_date >= ?';
    $params[] = $dateFrom;
}
if ($dateTo) {
    $where[]  = 'p.payment_date <= ?';
    $params[] = $dateTo . ' 23:59:59';
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM payments p $whereClause");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$listStmt = $pdo->prepare("
    SELECT p.id, p.amount, p.payment_method, p.transaction_id,
           p.status, p.payment_date, p.notes,
           COALESCE(c.full_name, c.name) AS client_name,
           c.phone AS client_phone, c.account_number
    FROM payments p
    LEFT JOIN clients c ON c.id = p.client_id
    $whereClause
    ORDER BY p.payment_date DESC
    LIMIT ? OFFSET ?
");
$listStmt->execute(array_merge($params, [$perPage, $offset]));
$payments = $listStmt->fetchAll(PDO::FETCH_ASSOC);

// Summary totals for the filtered period
$sumStmt = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0) AS total_amount
    FROM payments p
    $whereClause AND p.status = 'completed'
");
$sumParams = $params;
$sumParams[] = null; // placeholder — the WHERE already has tenant_id
// re-build params without the LIMIT/OFFSET additions
$sumStmt = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0) AS total_amount
    FROM payments p
    $whereClause AND p.status = 'completed'
");
$sumStmt->execute($params);
$totalAmount = (float)$sumStmt->fetchColumn();

echo json_encode([
    'data'         => $payments,
    'total'        => $total,
    'page'         => $page,
    'per_page'     => $perPage,
    'total_pages'  => (int)ceil($total / $perPage),
    'total_amount' => $totalAmount,
]);
