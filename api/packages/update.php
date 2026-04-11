<?php
/**
 * API Endpoint: Update Package
 */
ob_start();
ini_set('display_errors', 0);
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_clean();
        if (!headers_sent()) header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $err['message']]);
    }
});
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../classes/MikrotikAPI.php';

// Validate Inputs
$id = (int)($_POST['id'] ?? 0);
$name = $_POST['name'] ?? '';
$price = isset($_POST['price']) && $_POST['price'] !== '' ? (float)$_POST['price'] : 0;
$download_speed = isset($_POST['download_speed']) && $_POST['download_speed'] !== '' ? (int)$_POST['download_speed'] : 0; 
$upload_speed = isset($_POST['upload_speed']) && $_POST['upload_speed'] !== '' ? (int)$_POST['upload_speed'] : 0;
$data_limit = isset($_POST['data_limit']) && $_POST['data_limit'] !== '' ? (int)$_POST['data_limit'] : 0;
$description = $_POST['description'] ?? '';
$mikrotik_profile = $_POST['mikrotik_profile'] ?? '';
$rate_limit = $_POST['rate_limit'] ?? ($upload_speed . 'M/' . $download_speed . 'M');
$connection_type = $_POST['connection_type'] ?? 'pppoe';
$speed_display = $download_speed . "Mbps / " . $upload_speed . "Mbps";

if (empty($id) || empty($name)) {
    ob_clean(); echo json_encode(['success' => false, 'message' => 'ID and Name are required']);
    exit;
}

// Security: Check Tenant
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    ob_clean(); echo json_encode(['success' => false, 'message' => 'Unauthorized']);
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
    ob_clean(); echo json_encode(['success' => false, 'message' => 'Package not found or access denied']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Update DB
    $stmt = $pdo->prepare("UPDATE packages SET name = ?, price = ?, description = ?, rate_limit = ?, connection_type = ?, download_speed = ?, upload_speed = ?, data_limit = ?, type = ?, validity_value = ?, validity_unit = ?, device_limit = ? WHERE id = ?");
    $stmt->execute([
        $name, $price, $description, $rate_limit, $connection_type,
        $download_speed, $upload_speed, $data_limit, $connection_type,
        isset($_POST['validity_value']) && $_POST['validity_value'] !== '' ? (int)$_POST['validity_value'] : 30,
        $_POST['validity_unit'] ?? 'days',
        isset($_POST['device_limit']) && $_POST['device_limit'] !== '' ? (int)$_POST['device_limit'] : 1,
        $id
    ]);
    
    // Sync to Router (Optional: Update profile limits)
    // As per current API capability, we might not update existing profiles easily without ID.
    // For now, assume DB update is primary.
    
    // TODO: Implement profile update on router if needed.
    
    $pdo->commit();
    ob_clean();
    echo json_encode(['success' => true, 'message' => 'Package updated successfully']);

} catch (Throwable $e) {
    try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable $re) {}
    ob_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
