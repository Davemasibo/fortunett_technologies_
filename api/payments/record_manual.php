<?php
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../includes/auth.php';

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
    echo json_encode(['success' => false, 'message' => 'Tenant not found']);
    exit;
}

$client_id        = (int)($_POST['client_id'] ?? 0);
$reference_code   = trim($_POST['reference_code'] ?? '');
$amount           = (float)($_POST['amount'] ?? 0);
$rawMethod        = strtolower(trim($_POST['method'] ?? 'cash'));
// Normalize to DB-safe values (avoids ENUM truncation errors)
$methodMap = [
    'm-pesa' => 'mpesa', 'mpesa' => 'mpesa', 'safaricom' => 'mpesa',
    'cash' => 'cash',
    'bank_transfer' => 'bank_transfer', 'bank transfer' => 'bank_transfer', 'bank' => 'bank_transfer',
    'card' => 'card', 'credit card' => 'card', 'debit card' => 'card',
    'cheque' => 'bank_transfer', 'check' => 'bank_transfer',
];
$method           = $methodMap[$rawMethod] ?? 'cash';
$transaction_date = trim($_POST['transaction_date'] ?? date('Y-m-d H:i:s'));
$is_verified      = (int)($_POST['is_verified'] ?? 1);
$notes            = trim($_POST['notes'] ?? '');

if (!$client_id || !$amount || !$reference_code) {
    echo json_encode(['success' => false, 'message' => 'Customer, reference code, and amount are required']);
    exit;
}

try {
    // Verify client belongs to this tenant
    $check = $pdo->prepare("SELECT id, full_name, phone FROM clients WHERE id = ? AND tenant_id = ?");
    $check->execute([$client_id, $tenant_id]);
    $client = $check->fetch(PDO::FETCH_ASSOC);

    if (!$client) {
        throw new Exception("Invalid customer or not in your account");
    }

    // Parse the provided date — handle both datetime-local (YYYY-MM-DDTHH:MM:SS)
    // and plain date (YYYY-MM-DD). For date-only values, use current time so the
    // stored record always has a meaningful timestamp, not midnight 00:00.
    $parsedTs = strtotime(str_replace('T', ' ', $transaction_date));
    if (!$parsedTs) {
        $parsedTs = time();
    } elseif (!preg_match('/\d{2}:\d{2}/', $transaction_date)) {
        // Date-only string was given — keep the date but use current time
        $parsedTs = strtotime(date('Y-m-d', $parsedTs) . ' ' . date('H:i:s'));
    }
    $txDate = date('Y-m-d H:i:s', $parsedTs);

    // result_code: '0' = verified/success, 'MANUAL' = unverified manual entry
    $result_code = $is_verified ? '0' : 'MANUAL';
    $result_desc = 'Manual:' . $method . ($notes ? ' | ' . substr($notes, 0, 200) : '');

    // Insert into mpesa_transactions — include tenant_id and status to satisfy NOT NULL constraints
    $sql = "INSERT INTO mpesa_transactions
                (client_id, tenant_id, phone_number, amount, merchant_request_id, checkout_request_id,
                 status, result_code, result_desc, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

    $payStatus2 = $is_verified ? 'completed' : 'pending';
    $stmt2 = $pdo->prepare($sql);
    $stmt2->execute([
        $client_id,
        $tenant_id,
        $client['phone'],
        $amount,
        'MANUAL-' . strtoupper(substr(md5(uniqid()), 0, 6)), // merchant_request_id placeholder
        $reference_code,                                       // checkout_request_id = ref code
        $payStatus2,
        $result_code,
        $result_desc,
        $txDate
    ]);

    // Record in payments table — try with notes column first, fall back without it
    $payStatus = $is_verified ? 'completed' : 'pending';
    try {
        $payStmt = $pdo->prepare("INSERT INTO payments
                (client_id, tenant_id, amount, payment_method, payment_date, transaction_id, status, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $payStmt->execute([$client_id, $tenant_id, $amount, $method, $txDate, $reference_code, $payStatus, $notes]);
    } catch (PDOException $colEx) {
        // If 'notes' column doesn't exist, insert without it
        if (strpos($colEx->getMessage(), '1054') !== false || strpos($colEx->getMessage(), 'notes') !== false) {
            $payStmt = $pdo->prepare("INSERT INTO payments
                    (client_id, tenant_id, amount, payment_method, payment_date, transaction_id, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?)");
            $payStmt->execute([$client_id, $tenant_id, $amount, $method, $txDate, $reference_code, $payStatus]);
        } else {
            throw $colEx;
        }
    }

    echo json_encode([
        'success'        => true,
        'message'        => 'Transaction recorded successfully',
        'transaction_id' => $reference_code
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
