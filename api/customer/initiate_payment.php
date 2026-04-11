<?php
/**
 * Customer Payment Initiation API
 * Initiates M-Pesa STK Push for customer payments
 */
ob_start();
error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../classes/MpesaAPI.php';
require_once '../../classes/CustomerAuth.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// Check authentication
if (!isset($_SESSION['customer_token'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

try {
    // Validate session
    $auth = new CustomerAuth($pdo);
    $result = $auth->validateSession($_SESSION['customer_token']);
    
    if (!$result['valid']) {
        echo json_encode(['success' => false, 'message' => 'Invalid session']);
        exit;
    }
    
    $customer = $result['client'];
    
    // Get payment details
    $phone = $_POST['phone'] ?? $customer['phone'];
    $packageId = $_POST['package_id'] ?? $customer['package_id'];
    $amount = $_POST['amount'] ?? 0;
    
    if (empty($phone) || empty($packageId) || $amount <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid payment details']);
        exit;
    }
    
    // Get package details
    $stmt = $pdo->prepare("SELECT * FROM packages WHERE id = ?");
    $stmt->execute([$packageId]);
    $package = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$package) {
        echo json_encode(['success' => false, 'message' => 'Package not found']);
        exit;
    }
    
    // Initiate M-Pesa STK Push
    // Pass tenant_id from customer record (multi-tenancy support)
    $mpesa = new MpesaAPI($pdo, $customer['tenant_id']);
    $accountRef = $customer['account_number'] ?? 'ACC' . $customer['id'];
    $description = 'Payment for ' . $package['name'];
    
    $response = $mpesa->stkPush($phone, $amount, $accountRef, $description);
    
    if (isset($response->ResponseCode) && $response->ResponseCode == '0') {
        // Save payment record
        $stmt = $pdo->prepare("
            INSERT INTO payments
            (client_id, tenant_id, amount, payment_method, payment_date, transaction_id, status, notes)
            VALUES (?, ?, ?, 'mpesa', NOW(), ?, 'pending', ?)
        ");
        $stmt->execute([
            $customer['id'],
            $customer['tenant_id'],
            $amount,
            $response->CheckoutRequestID,
            'Package: ' . $package['name']
        ]);
        
        $paymentId = $pdo->lastInsertId();
        
        // Update customer package if changing
        if ($packageId != $customer['package_id']) {
            $stmt = $pdo->prepare("
                UPDATE clients 
                SET package_id = ?, package_price = ? 
                WHERE id = ?
            ");
            $stmt->execute([$packageId, $package['price'], $customer['id']]);
        }
        
        // Log activity
        $auth->logActivity($customer['id'], 'payment', 'Initiated M-Pesa payment of ' . $amount);
        
        ob_clean();
        echo json_encode([
            'success' => true,
            'message' => 'STK Push sent — check your phone for the M-Pesa prompt.',
            'checkout_request_id' => $response->CheckoutRequestID,
            'payment_id' => $paymentId
        ]);
    } else {
        $errorMessage = $response->errorMessage ?? $response->ResponseDescription ?? 'Payment initiation failed';
        ob_clean();
        echo json_encode(['success' => false, 'message' => $errorMessage]);
    }

} catch (Throwable $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
