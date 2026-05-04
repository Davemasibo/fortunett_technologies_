<?php
/**
 * Public Packages API — Customer Registration
 * No authentication required. Tenant is detected from subdomain.
 * Returns all active packages for the tenant so the register page can list them.
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/../../includes/db_master.php';
require_once __DIR__ . '/../../includes/tenant.php';

$tenantManager = TenantManager::getInstance($pdo);
$tenant        = $tenantManager->detectTenantFromSubdomain();

// Allow ?tenant_id= override on localhost for testing
if (!$tenant && $_SERVER['HTTP_HOST'] === 'localhost' && isset($_GET['tenant_id'])) {
    $tenant = $tenantManager->getTenantById((int)$_GET['tenant_id']);
}

if (!$tenant || $tenant['status'] === 'suspended') {
    echo json_encode(['success' => false, 'packages' => [], 'message' => 'Service unavailable']);
    exit;
}

$tenantId = (int)$tenant['id'];

$typeFilter = trim($_GET['type'] ?? '');

$sql = "
    SELECT id, name, type, price, duration, description, features,
           download_speed, upload_speed, data_limit,
           validity_value, validity_unit, status,
           COALESCE(NULLIF(connection_type,''), NULLIF(type,''), 'hotspot') AS connection_type
    FROM packages
    WHERE tenant_id = ? AND status = 'active'
";
$params = [$tenantId];

if ($typeFilter !== '') {
    $sql .= " AND COALESCE(NULLIF(connection_type,''), NULLIF(type,''), 'hotspot') = ?";
    $params[] = $typeFilter;
}

$sql .= " ORDER BY price ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$packages = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['success' => true, 'packages' => $packages, 'tenant_id' => $tenantId]);
