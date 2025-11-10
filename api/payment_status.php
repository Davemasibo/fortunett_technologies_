<?php

/**
 * Public polling endpoint for payment status.
 * Note: this endpoint intentionally does not require authentication so the
 * frontend waiting page can poll with same-origin cookies or without auth.
 */

require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');
$paymentId = isset($_GET['payment_id']) ? intval($_GET['payment_id']) : 0;
if (!$paymentId) {
    echo json_encode(['error' => 'payment_id required']);
    exit;
}

$database = new Database();
$db = $database->getConnection();

$stmt = $db->prepare("SELECT status, message, invoice, payment_date, updated_at FROM payments WHERE id = ? LIMIT 1");
$stmt->execute([$paymentId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    echo json_encode(['error' => 'payment not found']);
    exit;
}

echo json_encode([
    'status' => $row['status'],
    'message' => $row['message'],
    'invoice' => $row['invoice'] ?? null,
    'updated_at' => $row['updated_at'] ?? $row['payment_date'] ?? null,
]);

?>
