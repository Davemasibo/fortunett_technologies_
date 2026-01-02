<?php
header('Content-Type: application/json');
require_once '../../includes/config.php';

$id = $_POST['id'] ?? '';
$name = $_POST['name'] ?? '';
$ip = $_POST['ip_address'] ?? '';
$username = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';

if (!$id || !$name || !$ip) {
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
    exit;
}

try {
    if ($password) {
        $stmt = $pdo->prepare("UPDATE mikrotik_routers SET name=?, ip_address=?, username=?, password=? WHERE id=?");
        $stmt->execute([$name, $ip, $username, $password, $id]);
    } else {
        $stmt = $pdo->prepare("UPDATE mikrotik_routers SET name=?, ip_address=?, username=? WHERE id=?");
        $stmt->execute([$name, $ip, $username, $id]);
    }
    
    echo json_encode(['status' => 'success']);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
