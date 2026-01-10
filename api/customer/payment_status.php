<?php
/**
 * Payment Status Check API
 * Check the status of a payment by checkout request ID
 */
header('Content-Type: application/json');
require_once '../../includes/config.php';

session_start();

// Check authentication
if (!isset($_SESSION['customer_token'])) {
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

try {
    $checkoutRequestId = $_GET['checkout_request_id'] ?? '';
    
    if (empty($checkoutRequestId)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
        exit;
    }
    
    // Check payment status in database
    $stmt = $pdo->prepare("
        SELECT * FROM payments 
        WHERE transaction_id = ? 
        ORDER BY id DESC 
        LIMIT 1
    ");
    $stmt->execute([$checkoutRequestId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$payment) {
        echo json_encode(['status' => 'pending', 'message' => 'Payment not found']);
        exit;
    }
    
    if ($payment['status'] === 'completed') {
        echo json_encode([
            'status' => 'completed',
            'message' => 'Payment successful',
            'amount' => $payment['amount']
        ]);
    } elseif ($payment['status'] === 'failed') {
        echo json_encode([
            'status' => 'failed',
            'message' => 'Payment failed'
        ]);
    } else {
        echo json_encode([
            'status' => 'pending',
            'message' => 'Payment pending'
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Server error: ' . $e->getMessage()]);
}
