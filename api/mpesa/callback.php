<?php
/**
 * API Endpoint: M-Pesa Callback Handler
 */
header('Content-Type: application/json');
require_once '../../includes/config.php';

// Get raw input
$content = file_get_contents('php://input');
$data = json_decode($content);

// Log raw callback for debugging
file_put_contents(__DIR__ . '/../../logs/mpesa_callbacks.log', date('Y-m-d H:i:s') . " - " . $content . "\n", FILE_APPEND);

if (!$data) {
    die('Create a log file first');
}

// Extract details
$body = $data->Body->stkCallback;
$resultCode = $body->ResultCode;
$resultDesc = $body->ResultDesc;
$checkoutRequestId = $body->CheckoutRequestID;

try {
    if ($resultCode == 0) {
        // Successful payment
        $meta = $body->CallbackMetadata->Item;
        $amount = 0;
        $receipt = '';
        $phone = '';
        
        foreach ($meta as $item) {
            if ($item->Name == 'Amount') $amount = $item->Value;
            if ($item->Name == 'MpesaReceiptNumber') $receipt = $item->Value;
            if ($item->Name == 'PhoneNumber') $phone = $item->Value;
        }
        
        // Update transaction
        $stmt = $pdo->prepare("UPDATE mpesa_transactions SET 
            status = 'completed', 
            result_code = ?, 
            result_desc = ?, 
            transaction_id = ?,
            callback_data = ?
            WHERE checkout_request_id = ?");
            
        $stmt->execute([$resultCode, $resultDesc, $receipt, $content, $checkoutRequestId]);
        
        // Find client
        $tStmt = $pdo->prepare("SELECT client_id FROM mpesa_transactions WHERE checkout_request_id = ?");
        $tStmt->execute([$checkoutRequestId]);
        $transaction = $tStmt->fetch();
        
        if ($transaction) {
            // Update client subscription or balance here
            // Example: Extend expiry date
            
            // UPDATE existing pending payment in payments table
            // We match by transaction_id (which holds the CheckoutRequestID)
            
            $payStmt = $pdo->prepare("UPDATE payments SET 
                status = 'completed', 
                transaction_id = ? 
                WHERE transaction_id = ?"); 
                
            $payStmt->execute([$receipt, $checkoutRequestId]);
            
            // ACTIVATE SERVICE
            // Get client and package details
            $clientStmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
            $clientStmt->execute([$transaction['client_id']]);
            $client = $clientStmt->fetch();
            
            if ($client && $client['package_id']) {
                $pkgStmt = $pdo->prepare("SELECT * FROM packages WHERE id = ?");
                $pkgStmt->execute([$client['package_id']]);
                $package = $pkgStmt->fetch();
                
                if ($package) {
                    $validityValue = $package['validity_value'] ?? 30;
                    $validityUnit = $package['validity_unit'] ?? 'days';
                    
                    // Determine start date (Extend from now, or from current expiry if valid?)
                    // Usually extend from NOW for seamless renewal if expired.
                    // If active, extend from current expiry? Let's stick to NOW for simplicity or specific rule.
                    // Request implies "validity of the package".
                    
                    $expiryDate = date('Y-m-d H:i:s', strtotime('+' . $validityValue . ' ' . $validityUnit));
                    
                    $updateClient = $pdo->prepare("UPDATE clients SET 
                        status = 'active',
                        expiry_date = ?,
                        subscription_plan = ?
                        WHERE id = ?");
                    $updateClient->execute([$expiryDate, $package['name'], $client['id']]);
                    
                    // Log
                    $log = $pdo->prepare("INSERT INTO customer_activity_log (client_id, activity_type, description) VALUES (?, ?, ?)");
                    $log->execute([$client['id'], 'payment_success', 'Service activated until ' . $expiryDate]);
                }
            }
            
            // If no row updated (maybe stk_push didn't insert pending?), then insert new
            if ($payStmt->rowCount() == 0) {
                 $insertStmt = $pdo->prepare("INSERT INTO payments (client_id, amount, transaction_id, status, payment_date) VALUES (?, ?, ?, 'Completed', NOW())");
                 $insertStmt->execute([$transaction['client_id'], $amount, $receipt]);
            }
        }
        
    } else {
        // Failed/Cancelled
        $stmt = $pdo->prepare("UPDATE mpesa_transactions SET 
            status = 'failed', 
            result_code = ?, 
            result_desc = ?, 
            callback_data = ?
            WHERE checkout_request_id = ?");
            
        $stmt->execute([$resultCode, $resultDesc, $content, $checkoutRequestId]);
    }
    
    echo json_encode(['result' => 'success']);

} catch (Exception $e) {
    file_put_contents(__DIR__ . '/../../logs/mpesa_errors.log', $e->getMessage() . "\n", FILE_APPEND);
    echo json_encode(['result' => 'error']);
}
