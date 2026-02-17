<?php
/**
 * API Endpoint: Initiate M-Pesa Payment
 */
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../includes/auth.php'; // For session helper if needed, but we do manual check often
require_once '../../classes/MpesaAPI.php';

// Security: Tenant Context
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
// Get current user's tenant
$uStmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$uStmt->execute([$user_id]);
$current_tenant_id = $uStmt->fetchColumn();

if (!$current_tenant_id) {
    echo json_encode(['success' => false, 'message' => 'Tenant context missing']);
    exit;
}

// Validate Inputs
$phone = $_POST['phone'] ?? '';
$amount = $_POST['amount'] ?? 0;
$client_id = $_POST['client_id'] ?? 0;

if (empty($phone) || empty($amount) || empty($client_id)) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

try {
    // Verify Client belongs to THIS Tenant
    $stmt = $pdo->prepare("SELECT tenant_id FROM clients WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$client_id, $current_tenant_id]);
    $client_tenant_id = $stmt->fetchColumn();
    
    if (!$client_tenant_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid customer or access denied']);
        exit;
    }

    $tenant_id = $client_tenant_id; // Confirmed matches current session tenant

    // Initialize M-Pesa with Tenant Context
    $mpesa = new MpesaAPI($pdo, $tenant_id);
    
    // Generate unique reference
    $reference = 'PAY-' . $client_id . '-' . time();
    
    $response = $mpesa->stkPush($phone, $amount, $reference);
    
    if (isset($response->ResponseCode) && $response->ResponseCode == '0') {
        // Save pending transaction in Mpesa Log
        $stmt = $pdo->prepare("INSERT INTO mpesa_transactions 
            (client_id, phone_number, amount, merchant_request_id, checkout_request_id, status) 
            VALUES (?, ?, ?, ?, ?, 'pending')");
            
        $stmt->execute([
            $client_id,
            $phone,
            $amount,
            $response->MerchantRequestID,
            $response->CheckoutRequestID
        ]);

        // ALSO Save pending transaction in Payments Table (for immediate UI visibility)
        // We use checkout_request_id as transaction_id temporarily
        $payStmt = $pdo->prepare("INSERT INTO payments 
            (client_id, amount, payment_date, status, transaction_id) 
            VALUES (?, ?, NOW(), 'pending', ?)");
        
        $payStmt->execute([
            $client_id, 
            $amount, 
            $response->CheckoutRequestID 
        ]);
        
        echo json_encode([
            'success' => true, 
            'message' => 'STK Push sent to ' . $phone,
            'checkout_request_id' => $response->CheckoutRequestID
        ]);
    } else {
        echo json_encode([
            'success' => false, 
            'message' => 'M-Pesa Error: ' . ($response->errorMessage ?? 'Unknown error')
        ]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
