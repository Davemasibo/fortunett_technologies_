<?php
/**
 * API Endpoint: Delete Package
 */
header('Content-Type: application/json');
require_once '../../includes/config.php';
require_once '../../classes/MikrotikAPI.php';

$id = $_POST['id'] ?? 0;

if (empty($id)) {
    echo json_encode(['success' => false, 'message' => 'ID required']);
    exit;
}

try {
    // Check if in use
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE package_id = ?");
    $stmt->execute([$id]);
    if ($stmt->fetchColumn() > 0) {
        throw new Exception("Cannot delete package. It is currently assigned to customers.");
    }

    $stmt = $pdo->prepare("DELETE FROM packages WHERE id = ?");
    $stmt->execute([$id]);
    
    // NOTE: We do NOT delete the profile from router automatically as it might be used by others or have custom config.
    
    echo json_encode(['success' => true, 'message' => 'Package deleted successfully']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
