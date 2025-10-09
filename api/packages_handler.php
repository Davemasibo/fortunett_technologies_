<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

redirectIfNotLoggedIn();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$response = ['success' => false, 'message' => ''];

try {
    switch ($action) {
        case 'create':
            $stmt = $pdo->prepare("INSERT INTO packages (name, description, price, duration, features, download_speed, upload_speed, data_limit, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $_POST['name'],
                $_POST['description'] ?? '',
                $_POST['price'],
                $_POST['duration'] ?? '30 days',
                $_POST['features'] ?? '',
                $_POST['download_speed'] ?? 0,
                $_POST['upload_speed'] ?? 0,
                $_POST['data_limit'] ?? 0,
                $_POST['status'] ?? 'active'
            ]);
            $response = ['success' => true, 'message' => 'Package created successfully'];
            break;

        case 'update':
            $stmt = $pdo->prepare("UPDATE packages SET name = ?, description = ?, price = ?, duration = ?, features = ?, download_speed = ?, upload_speed = ?, data_limit = ?, status = ? WHERE id = ?");
            $stmt->execute([
                $_POST['name'],
                $_POST['description'] ?? '',
                $_POST['price'],
                $_POST['duration'] ?? '30 days',
                $_POST['features'] ?? '',
                $_POST['download_speed'] ?? 0,
                $_POST['upload_speed'] ?? 0,
                $_POST['data_limit'] ?? 0,
                $_POST['status'] ?? 'active',
                $_POST['id']
            ]);
            $response = ['success' => true, 'message' => 'Package updated successfully'];
            break;

        case 'delete':
            $id = $_POST['id'] ?? $_GET['id'];
            $stmt = $pdo->prepare("DELETE FROM packages WHERE id = ?");
            $stmt->execute([$id]);
            $response = ['success' => true, 'message' => 'Package deleted successfully'];
            break;

        case 'get':
            $id = $_GET['id'];
            $stmt = $pdo->prepare("SELECT * FROM packages WHERE id = ?");
            $stmt->execute([$id]);
            $package = $stmt->fetch();
            $response = ['success' => true, 'data' => $package];
            break;

        default:
            $response['message'] = 'Invalid action';
    }
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

header('Content-Type: application/json');
echo json_encode($response);
?>
