<?php
header('Content-Type: application/json');
require_once '../../includes/config.php';

// Get parameters
$identity = $_GET['identity'] ?? '';
$ip = $_SERVER['REMOTE_ADDR'];

if (!$identity) {
    echo json_encode(['status' => 'error', 'message' => 'Identity required']);
    exit;
}

try {
    // Check if exists
    $stmt = $pdo->prepare("SELECT id FROM mikrotik_routers WHERE name = ? OR ip_address = ?");
    $stmt->execute([$identity, $ip]);
    $existing = $stmt->fetch();

    if ($existing) {
        // Update - using last_connected
        $update = $pdo->prepare("UPDATE mikrotik_routers SET ip_address = ?, status = 'online', last_connected = NOW() WHERE id = ?");
        $update->execute([$ip, $existing['id']]);
        $id = $existing['id'];
    } else {
        // Insert - using api_port and last_connected
        $insert = $pdo->prepare("INSERT INTO mikrotik_routers (name, ip_address, status, username, password, api_port, created_at, last_connected) VALUES (?, ?, 'online', 'admin', '', 8728, NOW(), NOW())");
        $insert->execute([$identity, $ip]);
        $id = $pdo->lastInsertId();
    }

    echo json_encode(['status' => 'success', 'id' => $id, 'message' => 'Router provisioned']);

} catch (Exception $e) {
    file_put_contents(__DIR__ . '/../../logs/provision_error.log', $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
