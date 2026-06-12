<?php
/**
 * GET /api/v1/customer/payment_status.php?checkout_request_id=xxx
 * Poll the status of an M-Pesa STK push payment.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../../../includes/db_master.php';
require_once __DIR__ . '/../../../includes/customer_auth.php';

$auth     = require_customer_auth();
$clientId = $auth['client_id'];
$tenantId = $auth['tenant_id'];

$checkoutId = trim($_GET['checkout_request_id'] ?? '');
if ($checkoutId === '') {
    http_response_code(422);
    echo json_encode(['error' => 'checkout_request_id required']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT status, result_code, result_desc, amount, created_at, updated_at
        FROM mpesa_transactions
        WHERE checkout_request_id = ? AND client_id = ? AND tenant_id = ?
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$checkoutId, $clientId, $tenantId]);
    $tx = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tx) {
        echo json_encode(['status' => 'pending', 'message' => 'Awaiting payment confirmation']);
        exit;
    }

    $status  = $tx['status'];
    $message = match($status) {
        'completed' => 'Payment successful',
        'failed'    => 'Payment failed: ' . ($tx['result_desc'] ?? 'Unknown error'),
        'cancelled' => 'Payment was cancelled',
        default     => 'Processing payment…',
    };

    echo json_encode([
        'status'  => $status,
        'amount'  => $tx['amount'] ? (float)$tx['amount'] : null,
        'message' => $message,
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Status check failed']);
}
