<?php
header('Content-Type: application/json');
require_once '../../includes/db_master.php';

// disable error reporting to screen
ini_set('display_errors', 0);

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Invalid request method");
    }

    $code = trim($_POST['code'] ?? '');
    
    // In a real app, you would verify against a session customer_id
    // For now, we'll log it for admin review
    
    if (empty($code)) {
        throw new Exception("Transaction code is required");
    }

    // Insert into a hypothetical manual_verifications table or similar
    // For now, let's assume we use the payments table with a 'pending_verification' status
    
    // We need to know which client is submitting. 
    // In actual use, this would be from session. 
    session_start();
    $customer_id = $_SESSION['customer_id'] ?? 0;
    
    if (!$customer_id) {
         throw new Exception("Unauthorized. Please log in.");
    }

    // Check if code was already submitted
    $chk = $pdo->prepare("SELECT id FROM payments WHERE transaction_id = ?");
    $chk->execute([$code]);
    if ($chk->fetch()) {
        throw new Exception("This transaction code has already been submitted.");
    }

    // Log the manual payment request
    $stmt = $pdo->prepare("INSERT INTO payments (amount, transaction_id, status, payment_method, payment_date) VALUES (0, ?, 'pending', 'manual_verification', NOW())");
    $stmt->execute([$code]);

    echo json_encode(['success' => true, 'message' => 'Code submitted. Admin will verify soon.']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
