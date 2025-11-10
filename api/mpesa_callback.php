<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

// Safaricom will POST JSON callbacks here
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
http_response_code(200);

// Basic logging to help debug callback delivery and matching
$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
$logFile = $logDir . '/mpesa_callbacks.log';
file_put_contents($logFile, "\n---\n" . date('c') . "\n" . $raw . "\n", FILE_APPEND);

// Parse minimal fields from callback (structure varies)
$resultCode = $data['Body']['stkCallback']['ResultCode'] ?? -1;
$items = $data['Body']['stkCallback']['CallbackMetadata']['Item'] ?? [];

// ResultCode === 0 means success. Non-zero indicates failure or cancellation.
if ($resultCode === 0) {
    $amount = 0; $phone = ''; $receipt = ''; $date = date('Y-m-d H:i:s');
    foreach ($items as $it) {
        if (($it['Name'] ?? '') === 'Amount') $amount = (float)($it['Value'] ?? 0);
        if (($it['Name'] ?? '') === 'MpesaReceiptNumber') $receipt = $it['Value'] ?? '';
        if (($it['Name'] ?? '') === 'PhoneNumber') $phone = (string)($it['Value'] ?? '');
        if (($it['Name'] ?? '') === 'TransactionDate') $date = (string)($it['Value'] ?? date('YmdHis'));
    }

    $callbackJson = json_encode($data);

    // Try to match by CheckoutRequestID first (if we stored it at STK initiation in payments.transaction_id)
    $checkoutId = $data['Body']['stkCallback']['CheckoutRequestID'] ?? null;
    $payId = null;
    $clientId = null;
    $client = null;

    if (!empty($checkoutId)) {
        $finder = $pdo->prepare("SELECT id, client_id, invoice FROM payments WHERE transaction_id = ? AND status = 'pending' LIMIT 1");
        $finder->execute([$checkoutId]);
        $p = $finder->fetch(PDO::FETCH_ASSOC);
        if ($p) {
            $payId = $p['id'];
            $clientId = $p['client_id'] ?? null;
        }
    }

    // If not matched by checkout id, try phone -> client -> amount match
    if (!$payId) {
        $stmt = $pdo->prepare("SELECT id, account_number FROM clients WHERE phone LIKE ? LIMIT 1");
        $like = '%' . substr(preg_replace('/\D/', '', $phone), -9);
        $stmt->execute([$like]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        $clientId = $client['id'] ?? null;

        if ($clientId) {
            // First try to find a pending payment matching client_id and amount (rounded)
            $find = $pdo->prepare("SELECT id, invoice FROM payments WHERE client_id = ? AND status = 'pending' AND ROUND(amount,2) = ROUND(?,2) ORDER BY payment_date DESC LIMIT 1");
            $find->execute([$clientId, $amount]);
            $pay = $find->fetch(PDO::FETCH_ASSOC);
            $payId = $pay['id'] ?? null;

            // If not found by amount, try any pending payment for this client
            if (!$payId) {
                $find2 = $pdo->prepare("SELECT id, invoice FROM payments WHERE client_id = ? AND status = 'pending' ORDER BY payment_date DESC LIMIT 1");
                $find2->execute([$clientId]);
                $pay = $find2->fetch(PDO::FETCH_ASSOC);
                $payId = $pay['id'] ?? null;
            }
        } else {
            file_put_contents($logFile, "No client match for phone={$phone}, amount={$amount}\n", FILE_APPEND);
        }
    }

    if ($payId) {
        // Update the pending payment row.
        $invoiceVal = $client['account_number'] ?? (!empty($clientId) ? getAccountNumber($pdo, $clientId) : null);
        $update = $pdo->prepare("UPDATE payments SET status = 'completed', transaction_id = ?, payment_date = ?, message = CONCAT(IFNULL(message,''), ' | callback: ', ?), invoice = COALESCE(NULLIF(invoice, ''), ?) WHERE id = ?");
        $update->execute([$receipt, date('Y-m-d H:i:s'), $callbackJson, $invoiceVal, $payId]);
        file_put_contents($logFile, "Updated payment id={$payId} for client={$clientId}, amount={$amount} \n", FILE_APPEND);
    } else {
        $insert = $pdo->prepare("INSERT INTO payments (client_id, amount, payment_method, payment_date, transaction_id, status, message, invoice) VALUES (?, ?, 'mpesa', ?, ?, 'completed', ?, ?)");
        $insert->execute([$clientId ?? 0, $amount, date('Y-m-d H:i:s'), $receipt, $callbackJson, $client['account_number'] ?? (!empty($clientId) ? getAccountNumber($pdo, $clientId) : null)]);
        $newId = $pdo->lastInsertId();
        file_put_contents($logFile, "Inserted payment id={$newId} for client={$clientId}, amount={$amount} \n", FILE_APPEND);
    }
} else {
    // Non-zero ResultCode: treat as failed/cancelled. Try to update a pending payment
    $resultDesc = $data['Body']['stkCallback']['ResultDesc'] ?? 'Failed';
    file_put_contents($logFile, "Non-zero ResultCode {$resultCode}: {$resultDesc}\n", FILE_APPEND);

    // Try to obtain some identifying fields from CallbackMetadata (if present)
    $phone = '';
    foreach ($items as $it) {
        if (($it['Name'] ?? '') === 'PhoneNumber') $phone = (string)($it['Value'] ?? '');
    }

    if ($phone) {
        $like = '%' . substr(preg_replace('/\D/', '', $phone), -9);
        $stmt = $pdo->prepare("SELECT id FROM clients WHERE phone LIKE ? LIMIT 1");
        $stmt->execute([$like]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        $clientId = $client['id'] ?? null;
    } else {
        $clientId = null;
    }

    if ($clientId) {
        // find pending payment
        $find = $pdo->prepare("SELECT id FROM payments WHERE client_id = ? AND status = 'pending' ORDER BY payment_date DESC LIMIT 1");
        $find->execute([$clientId]);
        $payId = $find->fetchColumn();
        $callbackJson = json_encode($data);
        if ($payId) {
                $upd = $pdo->prepare("UPDATE payments SET status = 'failed', message = CONCAT(IFNULL(message,''), ' | callback: ', ?), transaction_id = ? WHERE id = ?");
                $upd->execute([$callbackJson, $data['Body']['stkCallback']['CheckoutRequestID'] ?? null, $payId]);
            file_put_contents($logFile, "Marked payment id={$payId} failed for client={$clientId} ResultCode={$resultCode}\n", FILE_APPEND);
        } else {
            file_put_contents($logFile, "No pending payment to mark failed for client={$clientId} ResultCode={$resultCode}\n", FILE_APPEND);
        }
    } else {
        file_put_contents($logFile, "Unable to map failure callback to client; ResultCode={$resultCode}\n", FILE_APPEND);
    }
}

echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);


