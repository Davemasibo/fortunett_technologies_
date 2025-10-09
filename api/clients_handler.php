<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

redirectIfNotLoggedIn();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$response = ['success' => false, 'message' => ''];

try {
    switch ($action) {
        case 'create':
            $stmt = $pdo->prepare("INSERT INTO clients (full_name, name, email, phone, address, company, mikrotik_username, status, subscription_plan, monthly_fee) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $_POST['full_name'],
                $_POST['full_name'],
                $_POST['email'],
                $_POST['phone'],
                $_POST['address'] ?? '',
                $_POST['company'] ?? '',
                $_POST['mikrotik_username'] ?? '',
                $_POST['status'] ?? 'inactive',
                $_POST['subscription_plan'] ?? '',
                $_POST['monthly_fee'] ?? 0
            ]);
            $response = ['success' => true, 'message' => 'Client created successfully'];
            break;

        case 'update':
            $stmt = $pdo->prepare("UPDATE clients SET full_name = ?, name = ?, email = ?, phone = ?, address = ?, company = ?, mikrotik_username = ?, status = ?, subscription_plan = ?, monthly_fee = ? WHERE id = ?");
            $stmt->execute([
                $_POST['full_name'],
                $_POST['full_name'],
                $_POST['email'],
                $_POST['phone'],
                $_POST['address'] ?? '',
                $_POST['company'] ?? '',
                $_POST['mikrotik_username'] ?? '',
                $_POST['status'] ?? 'inactive',
                $_POST['subscription_plan'] ?? '',
                $_POST['monthly_fee'] ?? 0,
                $_POST['id']
            ]);
            $response = ['success' => true, 'message' => 'Client updated successfully'];
            break;

        case 'delete':
            $id = $_POST['id'] ?? $_GET['id'];
            $stmt = $pdo->prepare("DELETE FROM clients WHERE id = ?");
            $stmt->execute([$id]);
            $response = ['success' => true, 'message' => 'Client deleted successfully'];
            break;

        case 'get':
            $id = $_GET['id'];
            $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
            $stmt->execute([$id]);
            $client = $stmt->fetch();
            $response = ['success' => true, 'data' => $client];
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
