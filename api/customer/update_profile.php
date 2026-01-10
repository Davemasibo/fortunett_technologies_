<?php
/**
 * Customer Profile Update API
 */
header('Content-Type: application/json');
require_once '../../includes/config.php';
require_once '../../classes/CustomerAuth.php';

session_start();

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
    
    // Get update data
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    
    if (empty($fullName)) {
        echo json_encode(['success' => false, 'message' => 'Full name is required']);
        exit;
    }
    
    // Update customer profile
    $stmt = $pdo->prepare("
        UPDATE clients 
        SET full_name = ?, name = ?, email = ?, phone = ?, address = ? 
        WHERE id = ?
    ");
    $stmt->execute([$fullName, $fullName, $email, $phone, $address, $customer['id']]);
    
    // Log activity
    $auth->logActivity($customer['id'], 'profile_update', 'Updated profile information');
    
    echo json_encode(['success' => true, 'message' => 'Profile updated successfully']);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
