<?php
/**
 * Customer Registration API
 * Handles new customer registration with payment
 */
header('Content-Type: application/json');
require_once '../../includes/config.php';
require_once '../../classes/MpesaAPI.php';
require_once '../../classes/CustomerAuth.php';

try {
    // Get registration data
    $fullName = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $packageId = $_POST['package_id'] ?? 0;
    $packagePrice = $_POST['package_price'] ?? 0;
    
    // Validate required fields
    if (empty($fullName) || empty($phone) || empty($packageId)) {
        echo json_encode(['success' => false, 'message' => 'Name, phone, and package are required']);
        exit;
    }
    
    // Check if phone already exists
    $stmt = $pdo->prepare("SELECT id FROM clients WHERE phone = ?");
    $stmt->execute([$phone]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Phone number already registered. Please login instead.']);
        exit;
    }
    
    // Get package details
    $stmt = $pdo->prepare("SELECT * FROM packages WHERE id = ? AND status = 'active'");
    $stmt->execute([$packageId]);
    $package = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$package) {
        echo json_encode(['success' => false, 'message' => 'Invalid package selected']);
        exit;
    }
    
    // Generate username from phone
    $username = 'user_' . substr($phone, -8);
    $mikrotikUsername = $username;
    
    // Generate random password
    $randomPassword = bin2hex(random_bytes(4)); // 8 character password
    $hashedPassword = password_hash($randomPassword, PASSWORD_DEFAULT);
    
    // Calculate expiry (will be set after payment)
    $expiryDate = date('Y-m-d H:i:s', strtotime('+30 days'));
    
    // Create customer account
    $stmt = $pdo->prepare("
        INSERT INTO clients 
        (full_name, name, email, phone, address, username, auth_password, mikrotik_username, mikrotik_password,
         package_id, subscription_plan, package_price, expiry_date, status, connection_type) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'inactive', 'hotspot')
    ");
    
    $stmt->execute([
        $fullName,
        $fullName,
        $email,
        $phone,
        $address,
        $username,
        $hashedPassword,
        $mikrotikUsername,
        $randomPassword,
        $packageId,
        $package['name'],
        $package['price'],
        $expiryDate
    ]);
    
    $clientId = $pdo->lastInsertId();
    
    // Get account number
    require_once '../../includes/auth.php';
    $accountNumber = getAccountNumber($pdo, $clientId);
    
    // SAVE THE ACCOUNT NUMBER to the database
    $stmt = $pdo->prepare("UPDATE clients SET account_number = ? WHERE id = ?");
    $stmt->execute([$accountNumber, $clientId]);
    
    // Create pending payment record
    $stmt = $pdo->prepare("
        INSERT INTO payments 
        (client_id, amount, payment_method, payment_date, status, notes, invoice) 
        VALUES (?, ?, 'mpesa', NOW(), 'pending', ?, ?)
    ");
    $stmt->execute([
        $clientId,
        $package['price'],
        'Initial registration for package: ' . $package['name'],
        $accountNumber
    ]);
    
    $paymentId = $pdo->lastInsertId();
    
    // Check if free package
    if ($package['price'] <= 0) {
        // Activate immediately
        $stmt = $pdo->prepare("UPDATE clients SET status = 'active' WHERE id = ?");
        $stmt->execute([$clientId]);
        
        // Log activity
        $auth = new CustomerAuth($pdo);
        $auth->logActivity($clientId, 'activation', 'Free package activation: ' . $package['name']);
        
        echo json_encode([
            'success' => true,
            'message' => 'Registration successful. You are now connected!',
            'checkout_request_id' => null, // No payment
            'is_free' => true,
            'client_id' => $clientId,
            'username' => $username,
            'password' => $randomPassword, // In a real app we might auto-login, but here we return creds
            'redirect' => 'login.html'
        ]);
        exit;
    }
    
    // Initiate M-Pesa STK Push
    $mpesa = new MpesaAPI();
    $description = 'Registration - ' . $package['name'];
    
    $response = $mpesa->stkPush($phone, $package['price'], $accountNumber, $description);
    
    if (isset($response->ResponseCode) && $response->ResponseCode == '0') {
        // Update payment with checkout request ID
        $stmt = $pdo->prepare("UPDATE payments SET transaction_id = ? WHERE id = ?");
        $stmt->execute([$response->CheckoutRequestID, $paymentId]);
        
        // Log activity
        $auth = new CustomerAuth($pdo);
        $auth->logActivity($clientId, 'login', 'New customer registration initiated');
        
        echo json_encode([
            'success' => true,
            'message' => 'Registration successful. Please complete payment.',
            'checkout_request_id' => $response->CheckoutRequestID,
            'client_id' => $clientId,
            'username' => $username,
            'password' => $randomPassword
        ]);
    } else {
        // Delete the created customer if payment initiation fails
        $pdo->prepare("DELETE FROM clients WHERE id = ?")->execute([$clientId]);
        $pdo->prepare("DELETE FROM payments WHERE id = ?")->execute([$paymentId]);
        
        $errorMessage = $response->errorMessage ?? $response->ResponseDescription ?? 'Payment initiation failed';
        echo json_encode(['success' => false, 'message' => $errorMessage]);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
