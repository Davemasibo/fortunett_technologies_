<?php
/**
 * GET  /api/v1/clients/index.php          — paginated client list
 * GET  /api/v1/clients/index.php?id=5     — single client detail
 *
 * Query params (GET list):
 *   page     int   default 1
 *   per_page int   default 25, max 100
 *   search   str   searches name, phone, email, account_number
 *   status   str   active|inactive|suspended — omit for all
 *
 * Auth: Bearer JWT or session.
 * Tenant isolation: always filtered by auth['tenant_id'].
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../../../includes/db_master.php';
require_once __DIR__ . '/../../../includes/api_auth.php';

api_cors_headers();

$auth = require_api_auth($pdo);

$tenantId = $auth['tenant_id'];
if (!$tenantId && !$auth['is_super_admin']) {
    http_response_code(403);
    echo json_encode(['error' => 'Tenant context required']);
    exit;
}

// ── Single client ─────────────────────────────────────────────────────────────
if (!empty($_GET['id'])) {
    $clientId = (int)$_GET['id'];
    $stmt = $pdo->prepare("
        SELECT c.*, p.name AS package_name, p.download_speed, p.upload_speed, p.price,
               p.validity_value, p.validity_unit
        FROM clients c
        LEFT JOIN packages p ON p.id = c.package_id
        WHERE c.id = ? AND c.tenant_id = ?
        LIMIT 1
    ");
    $stmt->execute([$clientId, $tenantId]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$client) {
        http_response_code(404);
        echo json_encode(['error' => 'Client not found']);
        exit;
    }

    unset($client['mikrotik_password'], $client['auth_password']); // never expose passwords
    echo json_encode($client);
    exit;
}

// ── Client list ───────────────────────────────────────────────────────────────
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = min(100, max(1, (int)($_GET['per_page'] ?? 25)));
$offset  = ($page - 1) * $perPage;
$search  = trim($_GET['search'] ?? '');
$status  = $_GET['status'] ?? '';

$where  = ['c.tenant_id = ?'];
$params = [$tenantId];

if ($search !== '') {
    $like = '%' . $search . '%';
    $where[]  = '(c.full_name LIKE ? OR c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ? OR c.account_number LIKE ?)';
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}

$allowedStatuses = ['active', 'inactive', 'suspended'];
if (in_array($status, $allowedStatuses, true)) {
    $where[]  = 'c.status = ?';
    $params[] = $status;
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

// Total count
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM clients c $whereClause");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

// Rows — bind LIMIT/OFFSET explicitly as INT to avoid PDO string-quoting them
$listStmt = $pdo->prepare("
    SELECT c.id,
           COALESCE(c.full_name, c.name, c.username) AS full_name,
           COALESCE(c.username, '') AS username,
           c.phone, c.email,
           c.account_number, c.status,
           c.connection_type AS service_type,
           c.expiry_date,
           COALESCE(p.name, '') AS package_name,
           c.created_at
    FROM clients c
    LEFT JOIN packages p ON p.id = c.package_id
    $whereClause
    ORDER BY c.id DESC
    LIMIT ? OFFSET ?
");
foreach ($params as $i => $v) {
    $listStmt->bindValue($i + 1, $v);
}
$listStmt->bindValue(count($params) + 1, $perPage, PDO::PARAM_INT);
$listStmt->bindValue(count($params) + 2, $offset,  PDO::PARAM_INT);
$listStmt->execute();
$clients = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$lastPage = (int)ceil($total / $perPage) ?: 1;
echo json_encode([
    'data'       => $clients,
    'pagination' => [
        'total'     => $total,
        'page'      => $page,
        'per_page'  => $perPage,
        'last_page' => $lastPage,
    ],
]);
