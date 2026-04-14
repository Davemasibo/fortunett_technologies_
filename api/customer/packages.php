<?php
/**
 * Public Packages API — Customer Registration
 * No authentication required. Tenant is detected from subdomain.
 * Returns all active packages for the tenant so the register page can list them.
 */
header('Content-Type: application/json');

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

$stmt = $pdo->prepare("
    SELECT id, name, type, price, duration, description, features,
           download_speed, upload_speed, data_limit,
           validity_value, validity_unit, status
    FROM packages
    WHERE tenant_id = ? AND status = 'active'
    ORDER BY price ASC
");
$stmt->execute([$tenantId]);
$packages = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['success' => true, 'packages' => $packages]);
