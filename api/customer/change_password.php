<?php
/**
 * Customer Password Change API
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
    
    // Get passwords
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    
    if (empty($currentPassword) || empty($newPassword)) {
        echo json_encode(['success' => false, 'message' => 'All fields are required']);
        exit;
    }
    
    if (strlen($newPassword) < 6) {
        echo json_encode(['success' => false, 'message' => 'New password must be at least 6 characters']);
        exit;
    }
    
    // Verify current password
    if (!empty($customer['auth_password'])) {
        if (!password_verify($currentPassword, $customer['auth_password'])) {
            echo json_encode(['success' => false, 'message' => 'Current password is incorrect']);
            exit;
        }
    } elseif (!empty($customer['mikrotik_password'])) {
        if ($currentPassword !== $customer['mikrotik_password']) {
            echo json_encode(['success' => false, 'message' => 'Current password is incorrect']);
            exit;
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'No password set']);
        exit;
    }
    
    // Update password
    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("UPDATE clients SET auth_password = ? WHERE id = ?");
    $stmt->execute([$hashedPassword, $customer['id']]);
    
    // Log activity
    $auth->logActivity($customer['id'], 'password_change', 'Changed account password');
    
    echo json_encode(['success' => true, 'message' => 'Password updated successfully']);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
