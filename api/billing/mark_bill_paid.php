<?php
/**
 * POST /api/billing/mark_bill_paid.php
 * Record a manual invoice payment and mark the bill as paid.
 */
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$uStmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$uStmt->execute([$user_id]);
$tenant_id = (int)$uStmt->fetchColumn();

if (!$tenant_id) {
    echo json_encode(['success' => false, 'message' => 'Tenant context missing']);
    exit;
}

$bill_id   = (int)($_POST['bill_id']   ?? 0);
$reference = trim($_POST['reference']  ?? '');
$method    = trim($_POST['method']     ?? 'manual');
$amount    = (float)($_POST['amount']  ?? 0);

if (!$bill_id) {
    echo json_encode(['success' => false, 'message' => 'bill_id is required']);
    exit;
}
if (strlen($reference) < 4) {
    echo json_encode(['success' => false, 'message' => 'Please provide a valid payment reference (at least 4 characters)']);
    exit;
}

try {
    // Verify bill belongs to this tenant
    $check = $pdo->prepare("SELECT id, status, total_collections FROM tenant_bills WHERE id = ? AND tenant_id = ? LIMIT 1");
    $check->execute([$bill_id, $tenant_id]);
    $bill = $check->fetch(PDO::FETCH_ASSOC);

    if (!$bill) {
        echo json_encode(['success' => false, 'message' => 'Invoice not found']);
        exit;
    }
    if ($bill['status'] === 'paid') {
        echo json_encode(['success' => true, 'message' => 'Invoice is already marked as paid']);
        exit;
    }

    $pdo->beginTransaction();

    // Mark bill as paid
    $pdo->prepare("UPDATE tenant_bills SET status = 'paid', paid_at = NOW() WHERE id = ? AND tenant_id = ?")
        ->execute([$bill_id, $tenant_id]);

    // Record the payment reference in mpesa_transactions (for audit trail)
    try {
        $pdo->prepare("
            INSERT INTO mpesa_transactions
                (client_id, tenant_id, phone_number, amount, merchant_request_id,
                 checkout_request_id, result_code, result_desc, transaction_id, created_at, updated_at)
            VALUES (NULL, ?, '', ?, 'MANUAL', ?, '0', ?, ?, NOW(), NOW())
        ")->execute([
            $tenant_id,
            $amount ?: ($bill['total_collections'] ?? 0),
            'MANUAL-' . $bill_id . '-' . time(),
            'Manual payment via ' . $method . ' — ref: ' . $reference,
            $reference,
        ]);
    } catch (Exception $e) {
        // mpesa_transactions may have constraints — not fatal
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Invoice marked as paid. Reference: ' . $reference]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
