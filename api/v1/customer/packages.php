<?php
/**
 * GET /api/v1/customer/packages.php
 * Returns active packages for the customer's tenant (so they can choose what to pay for).
 * Query: type (pppoe|hotspot) — optional filter
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../../../includes/db_master.php';
require_once __DIR__ . '/../../../includes/customer_auth.php';

$auth     = require_customer_auth();
$tenantId = $auth['tenant_id'];

$type = $_GET['type'] ?? '';

$where  = ["p.tenant_id = ?", "p.status = 'active'"];
$params = [$tenantId];

if (in_array($type, ['pppoe', 'hotspot'], true)) {
    $where[]  = "(COALESCE(NULLIF(p.connection_type,''), NULLIF(p.type,''), 'pppoe') = ?)";
    $params[] = $type;
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

try {
    $stmt = $pdo->prepare("
        SELECT p.id, p.name, p.price, p.description,
               p.download_speed, p.upload_speed, p.data_limit,
               p.validity_value, p.validity_unit, p.device_limit,
               COALESCE(NULLIF(p.connection_type,''), NULLIF(p.type,''), 'pppoe') AS connection_type
        FROM packages p
        $whereClause
        ORDER BY p.price ASC
    ");
    $stmt->execute($params);
    $packages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['data' => $packages]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load packages']);
}
