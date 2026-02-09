<?php
/**
 * List Payment Gateways for Current Tenant
 */
header('Content-Type: application/json');
require_once '../../includes/auth.php';
require_once '../../config/database.php';
require_once '../../includes/payment_gateway.php';

redirectIfNotLoggedIn();

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Get tenant ID from session
    $tenantId = $_SESSION['tenant_id'] ?? null;
    
    if (!$tenantId) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Tenant context not found'
        ]);
        exit;
    }
    
    $paymentGateway = new PaymentGatewayManager($db);
    $gateways = $paymentGateway->getActiveGateways($tenantId, false); // Don't decrypt for listing
    
    // Format gateway types for display
    $gatewayTypeLabels = [
        'paybill_no_api' => 'Paybill - Without API Keys',
        'mpesa_api' => 'M-Pesa - With API Keys',
        'bank_account' => 'Bank Account',
        'kopo_kopo' => 'Kopo Kopo',
        'paypal' => 'PayPal'
    ];
    
    foreach ($gateways as &$gateway) {
        $gateway['gateway_type_label'] = $gatewayTypeLabels[$gateway['gateway_type']] ?? $gateway['gateway_type'];
    }
    
    echo json_encode([
        'success' => true,
        'gateways' => $gateways
    ]);
    
} catch (Exception $e) {
    error_log("Payment gateway list error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while retrieving payment gateways'
    ]);
}
