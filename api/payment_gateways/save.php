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
                'paybill_number'        => $_POST['paybill_number'] ?? '',
                'account_number'        => $_POST['account_number'] ?? '',
                'use_generated_accounts'=> isset($_POST['use_generated_accounts']) ? '1' : '0',
                'currency'              => $_POST['currency'] ?? 'KES',
                'instructions'          => $_POST['instructions'] ?? ''
            ];
            break;
            
        case 'mpesa_api':
            $credentials = [
                'consumer_key'    => $_POST['mpesa_consumer_key']    ?? $_POST['consumer_key']    ?? '',
                'consumer_secret' => $_POST['mpesa_consumer_secret'] ?? $_POST['consumer_secret'] ?? '',
                'passkey'         => $_POST['mpesa_passkey']         ?? $_POST['passkey']         ?? '',
                'shortcode'       => $_POST['mpesa_shortcode']       ?? $_POST['shortcode']       ?? '',
                'shortcode_type'  => $_POST['mpesa_shortcode_type']  ?? $_POST['shortcode_type']  ?? 'paybill',
                'store_number'    => trim($_POST['mpesa_store_number'] ?? $_POST['store_number'] ?? ''),
                'environment'     => $_POST['mpesa_env']             ?? $_POST['environment']     ?? 'sandbox',
                'callback_url'    => trim($_POST['mpesa_callback_url'] ?? ''),
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
    
    // Save or Update gateway
    $paymentGateway = new PaymentGatewayManager($db);
    $gatewayIdPost = $_POST['gateway_id'] ?? null;
    
    if (!empty($gatewayIdPost)) {
        // Update existing gateway
        $existing = $paymentGateway->getGatewayById($gatewayIdPost, true);
        if (!$existing || $existing['tenant_id'] != $tenantId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            exit;
        }
        
        // Merge blank secrets to prevent overwriting with empty
        if ($gatewayType === 'mpesa_api' && isset($existing['credentials'])) {
            if (empty($credentials['consumer_secret'])) $credentials['consumer_secret'] = $existing['credentials']['consumer_secret'] ?? '';
            if (empty($credentials['passkey']))         $credentials['passkey']         = $existing['credentials']['passkey']         ?? '';
            if (empty($credentials['callback_url']))    $credentials['callback_url']    = $existing['credentials']['callback_url']    ?? '';
        } elseif ($gatewayType === 'paypal' && isset($existing['credentials'])) {
            if (empty($credentials['secret'])) $credentials['secret'] = $existing['credentials']['secret'] ?? '';
        } elseif ($gatewayType === 'kopo_kopo' && isset($existing['credentials'])) {
            if (empty($credentials['client_secret'])) $credentials['client_secret'] = $existing['credentials']['client_secret'] ?? '';
        }

        $success = $paymentGateway->updateGateway(
            $gatewayIdPost,
            $gatewayName,
            $credentials,
            $existing['is_active'],
            $isDefault
        );
        
        if ($success) {
            echo json_encode([
                'success' => true,
                'message' => 'Payment gateway updated successfully',
                'gateway_id' => $gatewayIdPost
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to update payment gateway']);
        }
    } else {
        // Insert new gateway
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
            echo json_encode(['success' => false, 'message' => 'Failed to save payment gateway']);
        }
    }
    
} catch (Exception $e) {
    error_log("Payment gateway save error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while saving the gateway'
    ]);
}
