<?php
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../includes/auth.php';

// Security: Tenant Context
if (session_status() === PHP_SESSION_NONE) session_start();
$user_id = $_SESSION['user_id'] ?? 0;

if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$stmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$tenant_id = $stmt->fetchColumn();

if (!$tenant_id) {
    echo json_encode(['success' => false, 'message' => 'Tenant context missing']);
    exit;
}

// Get Input
$client_id = $_POST['client_id'] ?? 0;
$amount = $_POST['amount'] ?? 0;
$phone = $_POST['phone'] ?? ''; // Optional for cash, but good for record

if (!$client_id || !$amount) {
    echo json_encode(['success' => false, 'message' => 'Client and Amount are required']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Verify Client belongs to Tenant
    $check = $pdo->prepare("SELECT id, full_name, phone FROM clients WHERE id = ? AND tenant_id = ?");
    $check->execute([$client_id, $tenant_id]);
    $client = $check->fetch(PDO::FETCH_ASSOC);

    if (!$client) {
        throw new Exception("Invalid customer selected");
    }

    // Generate Transaction ID (CASH-...)
    $trans_id = 'CASH-' . strtoupper(substr(md5(uniqid()), 0, 8));

    // Insert into mpesa_transactions (using it as a unified transaction ledger for now)
    // We map 'Cash' to a pseudo-structure or use a separate table. 
    // Given the previous code reads from mpesa_transactions for the table, we'll insert there
    // but with specific flags to indicate manual cash.
    
    // Schema of mpesa_transactions: 
    // id, tenant_id, client_id, mpesa_receipt_number, amount, phone, ... result_code, result_desc
    // We will use '0' for result_code (success) and 'Manual Cash Payment' for result_desc
    
    $sql = "INSERT INTO mpesa_transactions 
            (tenant_id, client_id, mpesa_receipt_number, amount, phone, result_code, result_desc, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, '0', 'Manual Cash Payment', NOW(), NOW())";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $tenant_id,
        $client_id,
        $trans_id,
        $amount,
        $phone ?: $client['phone']
    ]);

    // Update Client Expiry? (Business logic: manual payment usually implies subscription renewal)
    // For now, we just record the transaction as requested. 
    // Subscription extension logic is usually separate or triggered by this. 
    // Let's assume just recording for now unless requested.

    $pdo->commit();

    echo json_encode([
        'success' => true, 
        'message' => 'Cash payment recorded successfully',
        'transaction_id' => $trans_id
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
