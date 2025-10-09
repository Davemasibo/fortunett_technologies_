<?php
require_once __DIR__ . '/../includes/config.php';

// Safaricom will POST JSON callbacks here
$data = json_decode(file_get_contents('php://input'), true);
http_response_code(200);

// Parse minimal fields from callback (structure varies)
$resultCode = $data['Body']['stkCallback']['ResultCode'] ?? -1;
$items = $data['Body']['stkCallback']['CallbackMetadata']['Item'] ?? [];

if ($resultCode === 0) {
    $amount = 0; $phone = ''; $receipt = ''; $date = date('Y-m-d');
    foreach ($items as $it) {
        if ($it['Name'] === 'Amount') $amount = (float)$it['Value'];
        if ($it['Name'] === 'MpesaReceiptNumber') $receipt = $it['Value'];
        if ($it['Name'] === 'PhoneNumber') $phone = (string)$it['Value'];
        if ($it['Name'] === 'TransactionDate') $date = substr($it['Value'],0,8);
    }
    // Try to map phone to client
    $stmt = $pdo->prepare("SELECT id FROM clients WHERE phone LIKE ? LIMIT 1");
    $like = '%' . substr($phone, -9);
    $stmt->execute([$like]);
    $clientId = $stmt->fetchColumn();
    if ($clientId) {
        $stmt = $pdo->prepare("INSERT INTO payments (client_id, amount, payment_method, payment_date, transaction_id, status) VALUES (?, ?, 'mpesa', ?, ?, 'completed')");
        $stmt->execute([$clientId, $amount, date('Y-m-d'), $receipt]);
    }
}

echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);


