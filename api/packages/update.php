<?php
 /**
 * API Endpoint: Update Package
 */
error_reporting(E_ALL);
ini_set('display_errors', 0); // Prevent HTML error output
header('Content-Type: application/json');
require_once '../../includes/config.php';
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

try {
    $pdo->beginTransaction();

    // Update DB
    $stmt = $pdo->prepare("UPDATE packages SET name = ?, price = ?, description = ?, mikrotik_profile = ?, rate_limit = ?, connection_type = ?, download_speed = ?, upload_speed = ?, data_limit = ?, type = ? WHERE id = ?");
    $stmt->execute([
        $name, $price, $description, $mikrotik_profile, $rate_limit, $connection_type,
        $download_speed, $upload_speed, $data_limit, $connection_type, $id
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
