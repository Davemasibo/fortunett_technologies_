<?php
/**
 * M-Pesa STK Push Callback Handler — multi-tenant aware
 *
 * Safaricom calls this URL after an STK Push completes (success or failure).
 * For tenants using their own credentials the callback URL contains their
 * subdomain (e.g. https://acme.fortunetttech.site/api/mpesa/callback.php),
 * so we detect the tenant from HTTP_HOST.
 *
 * For the platform shared paybill, use /api/mpesa/c2b_confirmation.php instead.
 */
header('Content-Type: application/json');

// Direct browser/health-check visits (no POST body from Safaricom)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['result' => 'ok', 'message' => 'M-Pesa STK callback endpoint is live. Awaiting Safaricom POST.']);
    exit;
}

require_once __DIR__ . '/../../includes/db_master.php';

// Ensure the logs directory exists
$logDir = __DIR__ . '/../../logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

$content = file_get_contents('php://input');
file_put_contents($logDir . '/mpesa_callbacks.log', date('Y-m-d H:i:s') . " HOST=" . ($_SERVER['HTTP_HOST'] ?? '') . " -- " . $content . "\n", FILE_APPEND | LOCK_EX);

$data = json_decode($content);
if (!$data || !isset($data->Body->stkCallback)) {
    echo json_encode(['result' => 'error', 'message' => 'Invalid callback body']);
    exit;
}

// ── Detect tenant from HTTP_HOST ──────────────────────────────────────────────
$tenant_id = null;
$httpHost  = explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]; // strip port
$hostParts = explode('.', $httpHost);

if (count($hostParts) >= 3 && $hostParts[0] !== 'www') {
    $subdomain = $hostParts[0];
    $tstmt = $pdo->prepare("SELECT id FROM tenants WHERE subdomain = ? LIMIT 1");
    $tstmt->execute([$subdomain]);
    $tenant_id = $tstmt->fetchColumn() ?: null;
}

// ── Parse callback ────────────────────────────────────────────────────────────
$body              = $data->Body->stkCallback;
$resultCode        = $body->ResultCode;
$resultDesc        = $body->ResultDesc;
$checkoutRequestId = $body->CheckoutRequestID;
$merchantRequestId = $body->MerchantRequestID ?? null;

try {
    if ((int)$resultCode === 0) {
        // Successful payment — extract metadata
        $meta    = $body->CallbackMetadata->Item ?? [];
        $amount  = 0;
        $receipt = '';
        $phone   = '';

        foreach ($meta as $item) {
            if ($item->Name === 'Amount')             $amount  = (float)$item->Value;
            if ($item->Name === 'MpesaReceiptNumber') $receipt = (string)$item->Value;
            if ($item->Name === 'PhoneNumber')        $phone   = (string)$item->Value;
        }

        // Find the pending transaction
        $txStmt = $pdo->prepare("
            SELECT mt.*, c.tenant_id AS c_tenant_id, c.package_id,
                   c.id AS client_id_val
            FROM mpesa_transactions mt
            LEFT JOIN clients c ON c.id = mt.client_id
            WHERE mt.checkout_request_id = ?
            LIMIT 1
        ");
        $txStmt->execute([$checkoutRequestId]);
        $tx = $txStmt->fetch(PDO::FETCH_ASSOC);

        // Resolve tenant_id: prefer host-detected, fall back to transaction's client tenant
        if (!$tenant_id && $tx) {
            $tenant_id = $tx['c_tenant_id'] ?? null;
        }

        // Update mpesa_transactions
        $pdo->prepare("
            UPDATE mpesa_transactions
            SET status = 'completed', result_code = ?, result_desc = ?,
                transaction_id = ?, callback_data = ?, updated_at = NOW()
            WHERE checkout_request_id = ?
        ")->execute([$resultCode, $resultDesc, $receipt, $content, $checkoutRequestId]);

        if ($tx && $tx['client_id']) {
            $clientId = (int)$tx['client_id'];
            $resolvedTenantId = $tenant_id ?? $tx['c_tenant_id'];

            // Update the pending payment row
            $rows = $pdo->prepare("
                UPDATE payments
                SET status = 'completed', transaction_id = ?
                WHERE transaction_id = ? AND (tenant_id = ? OR ? IS NULL)
            ");
            $rows->execute([$receipt, $checkoutRequestId, $resolvedTenantId, $resolvedTenantId]);

            if ($rows->rowCount() === 0) {
                // No pending row — insert a completed record
                $pdo->prepare("
                    INSERT INTO payments (client_id, tenant_id, amount, payment_method, transaction_id, status, payment_date)
                    VALUES (?, ?, ?, 'mpesa', ?, 'completed', NOW())
                ")->execute([$clientId, $resolvedTenantId, $amount, $receipt]);
            }

            // Extend client subscription
            if ($tx['package_id']) {
                $pkg = $pdo->prepare("SELECT validity_value, validity_unit, name FROM packages WHERE id = ? AND tenant_id = ?");
                $pkg->execute([$tx['package_id'], $resolvedTenantId]);
                $package = $pkg->fetch(PDO::FETCH_ASSOC);

                if ($package) {
                    $validityValue = max(1, (int)($package['validity_value'] ?? 30));
                    $validityUnit  = in_array($package['validity_unit'], ['days','weeks','months']) ? $package['validity_unit'] : 'days';
                    $expiryDate    = date('Y-m-d H:i:s', strtotime('+' . $validityValue . ' ' . $validityUnit));

                    $pdo->prepare("
                        UPDATE clients
                        SET status = 'active', expiry_date = ?
                        WHERE id = ? AND tenant_id = ?
                    ")->execute([$expiryDate, $clientId, $resolvedTenantId]);

                    $pdo->prepare("
                        INSERT INTO customer_activity_log (client_id, tenant_id, activity_type, description)
                        VALUES (?, ?, 'payment_success', ?)
                    ")->execute([$clientId, $resolvedTenantId, 'Service activated via M-Pesa (' . $receipt . ') until ' . $expiryDate]);
                }
            }
        }

    } else {
        // Failed / cancelled
        $pdo->prepare("
            UPDATE mpesa_transactions
            SET status = 'failed', result_code = ?, result_desc = ?,
                callback_data = ?, updated_at = NOW()
            WHERE checkout_request_id = ?
        ")->execute([$resultCode, $resultDesc, $content, $checkoutRequestId]);

        // Mark the pending payment as failed
        $pdo->prepare("
            UPDATE payments SET status = 'failed'
            WHERE transaction_id = ? AND status = 'pending'
        ")->execute([$checkoutRequestId]);
    }

    echo json_encode(['result' => 'success']);

} catch (Throwable $e) {
    file_put_contents($logDir . '/mpesa_errors.log', date('Y-m-d H:i:s') . " " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
    echo json_encode(['result' => 'error']);
}
