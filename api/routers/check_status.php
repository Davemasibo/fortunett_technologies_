<?php
header('Content-Type: application/json');
require_once '../../includes/config.php';

$identity = $_GET['identity'] ?? '';

if (!$identity) {
    echo json_encode(['connected' => false]);
    exit;
}

try {
    // Check for recent online status
    $stmt = $pdo->prepare("SELECT id, ip_address FROM mikrotik_routers WHERE name = ? AND status = 'online' AND last_connected >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
    $stmt->execute([$identity]);
    $router = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($router) {
        echo json_encode(['connected' => true, 'router' => $router]);
    } else {
        echo json_encode(['connected' => false]);
    }

} catch (Exception $e) {
    echo json_encode(['connected' => false, 'error' => $e->getMessage()]);
}
?>
