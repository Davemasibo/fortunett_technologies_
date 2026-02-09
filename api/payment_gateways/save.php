<?php
/**
 * Save Payment Gateway Configuration
 */
header('Content-Type: application/json');
require_once '../../includes/auth.php';
require_once '../../config/database.php';
require_once '../../includes/payment_gateway.php';

redirectIfNotLoggedIn();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Get tenant ID from session
    $tenantId = $_SESSION['tenant_id'] ?? null;
    
    if (!$tenantId) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Tenant context not found. Please log in again.'
        ]);
        exit;
    }
    
    // Get form data
    $gatewayType = $_POST['gateway_type'] ?? '';
    $gatewayName = $_POST['gateway_name'] ?? '';
    $isDefault = isset($_POST['is_default']) && $_POST['is_default'] == '1';
    
    // Validate gateway type
    $validTypes = ['paybill_no_api', 'mpesa_api', 'bank_account', 'kopo_kopo', 'paypal'];
    if (!in_array($gatewayType, $validTypes)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid gateway type'
        ]);
        exit;
    }
    
    // Prepare credentials based on gateway type
    $credentials = [];
    
    switch ($gatewayType) {
        case 'paybill_no_api':
            $credentials = [
                'paybill_number' => $_POST['paybill_number'] ?? '',
                'account_number' => $_POST['account_number'] ?? '',
                'currency' => $_POST['currency'] ?? 'KES'
            ];
            break;
            
        case 'mpesa_api':
            $credentials = [
                'consumer_key' => $_POST['consumer_key'] ?? '',
                'consumer_secret' => $_POST['consumer_secret'] ?? '',
                'passkey' => $_POST['passkey'] ?? '',
                'shortcode' => $_POST['shortcode'] ?? '',
                'environment' => $_POST['environment'] ?? 'sandbox'
            ];
            break;
            
        case 'bank_account':
            $credentials = [
                'bank_name' => $_POST['bank_name'] ?? '',
                'account_number' => $_POST['account_number'] ?? '',
                'account_name' => $_POST['account_name'] ?? '',
                'branch' => $_POST['branch'] ?? '',
                'swift_code' => $_POST['swift_code'] ?? ''
            ];
            break;
            
        case 'kopo_kopo':
            $credentials = [
                'api_key' => $_POST['api_key'] ?? '',
                'client_id' => $_POST['client_id'] ?? '',
                'client_secret' => $_POST['client_secret'] ?? '',
                'webhook_url' => $_POST['webhook_url'] ?? ''
            ];
            break;
            
        case 'paypal':
            $credentials = [
                'client_id' => $_POST['client_id'] ?? '',
                'secret' => $_POST['secret'] ?? '',
                'environment' => $_POST['environment'] ?? 'sandbox'
            ];
            break;
    }
    
    // Validate that we have at least some credentials
    $hasCredentials = false;
    foreach ($credentials as $value) {
        if (!empty($value)) {
            $hasCredentials = true;
            break;
        }
    }
    
    if (!$hasCredentials) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Please provide at least one credential field'
        ]);
        exit;
    }
    
    // Save gateway
    $paymentGateway = new PaymentGatewayManager($db);
    $gatewayId = $paymentGateway->saveGateway(
        $tenantId,
        $gatewayType,
        $gatewayName,
        $credentials,
        $isDefault
    );
    
    if ($gatewayId) {
        echo json_encode([
            'success' => true,
            'message' => 'Payment gateway saved successfully',
            'gateway_id' => $gatewayId
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to save payment gateway'
        ]);
    }
    
} catch (Exception $e) {
    error_log("Payment gateway save error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while saving the gateway'
    ]);
}
