<?php
header('Content-Type: application/json');
require_once '../../includes/config.php';
require_once '../../classes/MikrotikAPI.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid method']);
    exit;
}

$ip = $_POST['ip'] ?? '';
$username = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';
$port = $_POST['port'] ?? 8728;

if (empty($ip) || empty($username)) {
    echo json_encode(['success' => false, 'message' => 'Missing credentials']);
    exit;
}

try {
    $api = new MikrotikAPI($ip, $username, $password, $port);
    if ($api->connect()) {
        $api->disconnect();
        echo json_encode(['success' => true, 'message' => 'Connected successfully']);
    } else {
         echo json_encode(['success' => false, 'message' => 'Authentication failed or unreachable']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
