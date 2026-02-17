<?php
 /**
 * API Endpoint: Update Package
 */
error_reporting(E_ALL);
ini_set('display_errors', 0); // Prevent HTML error output
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../classes/MikrotikAPI.php';

// Validate Inputs
$id = $_POST['id'] ?? 0;
$name = $_POST['name'] ?? '';
$price = $_POST['price'] ?? 0;
$download_speed = $_POST['download_speed'] ?? 0; 
$upload_speed = $_POST['upload_speed'] ?? 0;
$data_limit = $_POST['data_limit'] ?? 0;
$description = $_POST['description'] ?? '';
$mikrotik_profile = $_POST['mikrotik_profile'] ?? '';
$rate_limit = $_POST['rate_limit'] ?? ($upload_speed . 'M/' . $download_speed . 'M');
$connection_type = $_POST['connection_type'] ?? 'pppoe';
$speed_display = $download_speed . "Mbps / " . $upload_speed . "Mbps";

if (empty($id) || empty($name)) {
    echo json_encode(['success' => false, 'message' => 'ID and Name are required']);
    exit;
}

// Security: Check Tenant
session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
$user_id = $_SESSION['user_id'];
$t_stmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$t_stmt->execute([$user_id]);
$tenant_id = $t_stmt->fetchColumn();

// Check if package belongs to tenant
$check = $pdo->prepare("SELECT id FROM packages WHERE id = ? AND tenant_id = ?");
$check->execute([$id, $tenant_id]);
if (!$check->fetch()) {
    echo json_encode(['success' => false, 'message' => 'Package not found or access denied']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Update DB
    $stmt = $pdo->prepare("UPDATE packages SET name = ?, price = ?, description = ?, mikrotik_profile = ?, rate_limit = ?, connection_type = ?, download_speed = ?, upload_speed = ?, data_limit = ?, type = ?, validity_value = ?, validity_unit = ?, device_limit = ? WHERE id = ?");
    $stmt->execute([
        $name, $price, $description, $mikrotik_profile, $rate_limit, $connection_type,
        $download_speed, $upload_speed, $data_limit, $connection_type,
        $_POST['validity_value'] ?? 30,
        $_POST['validity_unit'] ?? 'days',
        $_POST['device_limit'] ?? 1,
        $id
    ]);
    
    // Sync to Router (Optional: Update profile limits)
    // As per current API capability, we might not update existing profiles easily without ID.
    // For now, assume DB update is primary.
    
    // TODO: Implement profile update on router if needed.
    
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Package updated successfully']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
