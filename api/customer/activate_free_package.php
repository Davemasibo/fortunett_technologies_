<?php
/**
 * Activate Free Package API Endpoint
 * Handles activation of free packages without payment
 */
header('Content-Type: application/json');
require_once '../../includes/config.php';
require_once '../../classes/CustomerAuth.php';

session_start();

try {
    // Verify customer is logged in
    if (!isset($_SESSION['customer_token'])) {
        echo json_encode(['success' => false, 'message' => 'Not authenticated']);
        exit;
    }
    
    // Validate session
    $auth = new CustomerAuth($pdo);
    $sessionResult = $auth->validateSession($_SESSION['customer_token']);
    
    if (!$sessionResult['valid']) {
        echo json_encode(['success' => false, 'message' => 'Invalid session']);
        exit;
    }
    
    $client = $sessionResult['client'];
    
    // Get package ID from request
    $packageId = $_POST['package_id'] ?? null;
    
    if (!$packageId) {
        echo json_encode(['success' => false, 'message' => 'Package ID required']);
        exit;
    }
    
    // Get package details
    $stmt = $pdo->prepare("SELECT * FROM packages WHERE id = ? AND status = 'active'");
    $stmt->execute([$packageId]);
    $package = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$package) {
        echo json_encode(['success' => false, 'message' => 'Package not found']);
        exit;
    }
    
    // Verify package is free
    if ($package['price'] > 0) {
        echo json_encode(['success' => false, 'message' => 'This package requires payment']);
        exit;
    }
    
    // Calculate expiry date based on package duration
    $validityValue = $package['validity_value'] ?? 30;
    $validityUnit = $package['validity_unit'] ?? 'days';
    
    $expiryDate = date('Y-m-d H:i:s', strtotime('+' . $validityValue . ' ' . $validityUnit));
    
    // Update client with package details
    $stmt = $pdo->prepare("
        UPDATE clients 
        SET package_id = ?, 
            package_price = ?, 
            subscription_plan = ?,
            expiry_date = ?,
            status = 'active'
        WHERE id = ?
    ");
    
    $stmt->execute([
        $packageId,
        $package['price'],
        $package['name'],
        $expiryDate,
        $client['id']
    ]);
    
    // Log activity
    $auth->logActivity($client['id'], 'plan_change', 'Activated free package: ' . $package['name']);
    
    // Update session data
    $_SESSION['customer_data']['package_id'] = $packageId;
    $_SESSION['customer_data']['expiry_date'] = $expiryDate;
    $_SESSION['customer_data']['subscription_plan'] = $package['name'];
    
    echo json_encode([
        'success' => true,
        'message' => 'Package activated successfully!',
        'expiry_date' => $expiryDate,
        'package_name' => $package['name']
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
