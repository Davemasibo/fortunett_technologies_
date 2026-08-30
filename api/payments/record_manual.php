<?php
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../includes/auth.php';
require_once '../../includes/payment_pipeline.php';

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
    //
    // collection_type is written EXPLICITLY as 'direct' rather than left to the
    // column default. Recording a payment by hand asserts that the money is
    // already in the ISP's own account, so it can never be platform-held — and
    // an explicitly tagged row cannot be mistaken later for an untagged one that
    // merely fell back to the same default. $method here is 'mpesa' for an
    // M-Pesa entry, identical to what an STK push writes, so the tag is the
    // only thing standing between the two.
    $payStatus = $is_verified ? 'completed' : 'pending';
    try {
        $payStmt = $pdo->prepare("INSERT INTO payments
                (client_id, tenant_id, amount, payment_method, payment_date, transaction_id, status, collection_type, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'direct', ?)");
        $payStmt->execute([$client_id, $tenant_id, $amount, $method, $txDate, $reference_code, $payStatus, $notes]);
    } catch (PDOException $colEx) {
        // Older schema without one of the optional columns — record it anyway.
        try {
            $payStmt = $pdo->prepare("INSERT INTO payments
                    (client_id, tenant_id, amount, payment_method, payment_date, transaction_id, status, collection_type)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'direct')");
            $payStmt->execute([$client_id, $tenant_id, $amount, $method, $txDate, $reference_code, $payStatus]);
        } catch (PDOException $colEx2) {
            $payStmt = $pdo->prepare("INSERT INTO payments
                    (client_id, tenant_id, amount, payment_method, payment_date, transaction_id, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?)");
            $payStmt->execute([$client_id, $tenant_id, $amount, $method, $txDate, $reference_code, $payStatus]);
        }
    }

    // ── Reconnect the customer ────────────────────────────────────────────────
    // This endpoint used to insert two rows and stop. The money was recorded and
    // the customer stayed disconnected — the ISP had to go and extend the
    // subscription by hand afterwards, for every single payment. An ISP taking
    // payment into their own paybill and recording it here got no automation at
    // all, which is the same "paid but not connected" failure the M-Pesa
    // callbacks were fixed for.
    //
    // Same pipeline a live callback uses: extend, invoice, ledger, provision,
    // SMS. Idempotent on the reference, so re-recording the same receipt is
    // safe. platformCollected is explicitly false — see the collection_type
    // note above.
    $activation = ['attempted' => false];
    if ($payStatus === 'completed') {
        try {
            $pkgSt = $pdo->prepare("SELECT package_id FROM clients WHERE id = ? AND tenant_id = ? LIMIT 1");
            $pkgSt->execute([$client_id, $tenant_id]);
            $packageId = $pkgSt->fetchColumn();

            $res = process_payment_success(
                $pdo, $client_id, (int)$tenant_id, $amount, $reference_code,
                $method === 'mpesa' ? 'mpesa' : $method,
                $packageId ? (int)$packageId : null,
                false
            );
            $activation = [
                'attempted'   => true,
                'expiry_date' => $res['expiry_date'] ?? null,
                'steps'       => $res['steps'] ?? [],
            ];
        } catch (Throwable $pipeErr) {
            // Never fail the recording because provisioning failed — the money
            // is real and must be on the books either way. A router that was
            // unreachable is queued for cron/retry_provisions.php.
            error_log("record_manual pipeline [$reference_code]: " . $pipeErr->getMessage());
            $activation = ['attempted' => true, 'error' => $pipeErr->getMessage()];
        }
    }

    $msg = 'Transaction recorded successfully';
    if (!empty($activation['expiry_date'])) {
        $msg = 'Payment recorded — customer reconnected until '
             . date('d M Y H:i', strtotime($activation['expiry_date']));
    } elseif ($payStatus !== 'completed') {
        $msg = 'Payment recorded as unverified — the customer is NOT reconnected until you verify it';
    }

    echo json_encode([
        'success'        => true,
        'message'        => $msg,
        'transaction_id' => $reference_code,
        'activation'     => $activation,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
