<?php
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

// Expect JSON payload: { client_username, amount, method, transaction_id, status, payment_date }
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    exit;
}

$clientUsername = $input['client_username'] ?? '';
$amount = (float)($input['amount'] ?? 0);
$method = strtolower($input['method'] ?? 'mpesa');
$transactionId = $input['transaction_id'] ?? null;
$status = strtolower($input['status'] ?? 'completed');
$paymentDate = $input['payment_date'] ?? date('Y-m-d');

if (!$clientUsername || $amount <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Missing username or amount']);
    exit;
}

try {
    // Find client by mikrotik_username
    $stmt = $pdo->prepare("SELECT id FROM clients WHERE mikrotik_username = ? LIMIT 1");
    $stmt->execute([$clientUsername]);
    $client = $stmt->fetch();
    if (!$client) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Client not found']);
        exit;
    }

    // Insert payment
    $stmt = $pdo->prepare("INSERT INTO payments (client_id, amount, payment_method, payment_date, transaction_id, status) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$client['id'], $amount, $method, $paymentDate, $transactionId, $status]);

    echo json_encode(['success' => true, 'message' => 'Payment recorded']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}


