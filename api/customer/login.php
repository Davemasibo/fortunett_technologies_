<?php
/**
 * Customer Login API Endpoint
 */
header('Content-Type: application/json');
require_once '../../includes/config.php';
require_once '../../classes/CustomerAuth.php';

session_start();

$auth = new CustomerAuth($pdo);

try {
    // Check for auto-login token
    if (isset($_POST['auto_login_token'])) {
        $token = $_POST['auto_login_token'];
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $macAddress = $_POST['mac'] ?? null;
        
        $result = $auth->autoLogin($token, $ipAddress, $macAddress);
        
        if ($result['success']) {
            $_SESSION['customer_token'] = $result['session_token'];
            $_SESSION['customer_data'] = $result['client'];
        }
        
        echo json_encode($result);
        exit;
    }
    
    // Check for voucher login
    if (isset($_POST['voucher'])) {
        $voucher = trim($_POST['voucher']);
        
        $result = $auth->loginWithVoucher($voucher);
        
        if ($result['success']) {
            $_SESSION['customer_token'] = $result['session_token'];
            $_SESSION['customer_data'] = $result['client'];
        }
        
        echo json_encode($result);
        exit;
    }
    
    // Regular credentials login
    if (isset($_POST['username']) && isset($_POST['password'])) {
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        
        $result = $auth->login($username, $password);
        
        if ($result['success']) {
            $_SESSION['customer_token'] = $result['session_token'];
            $_SESSION['customer_data'] = $result['client'];
        }
        
        echo json_encode($result);
        exit;
    }
    
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
