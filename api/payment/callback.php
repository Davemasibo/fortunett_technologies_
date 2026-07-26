<?php
/**
 * M-Pesa STK Push Callback Handler — multi-tenant aware
 *
 * Safaricom calls this URL after an STK Push completes (success or failure).
 * For tenants using their own credentials the callback URL contains their
 * subdomain (e.g. https://acme.fortunetttech.site/api/payment/callback.php),
 * so we detect the tenant from HTTP_HOST.
 *
 * For the platform shared paybill, use /api/payment/c2b_confirmation.php instead.
 */
header('Content-Type: application/json');

// ── Log EVERY request immediately (before DB, before anything else) ───────────
// This must be the very first I/O so we capture even requests that die later.
$logDir = __DIR__ . '/../../logs';
if (!is_dir($logDir)) { @mkdir($logDir, 0755, true); }
$content = file_get_contents('php://input');
@file_put_contents(
    $logDir . '/mpesa_callbacks.log',
    date('Y-m-d H:i:s')
        . ' METHOD=' . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN')
        . ' HOST='   . ($_SERVER['HTTP_HOST'] ?? '')
        . ' IP='     . ($_SERVER['REMOTE_ADDR'] ?? '')
        . ' -- '     . ($content ?: '(empty body)')
        . "\n",
    FILE_APPEND | LOCK_EX
);

// Direct browser/health-check visits (no POST body from Safaricom)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['result' => 'ok', 'message' => 'M-Pesa STK callback endpoint is live. Awaiting Safaricom POST.']);
    exit;
}

// ── Fix 6: Daraja IP allowlist (fail-open in non-production) ─────────────────
// Safaricom callback IPs: 196.201.214.0/24 and 196.201.216.0/24
$_daraja_ip   = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
$_daraja_ip   = trim(explode(',', $_daraja_ip)[0]);
$_allowed_nets = ['196.201.214.', '196.201.216.'];
$_ip_ok = false;
foreach ($_allowed_nets as $_net) {
    if (str_starts_with($_daraja_ip, $_net)) { $_ip_ok = true; break; }
}
if (!$_ip_ok && (getenv('APP_ENV') === 'production' || getenv('MPESA_IP_STRICT') === 'true')) {
    @file_put_contents(
        dirname(__DIR__, 2) . '/logs/mpesa_callbacks.log',
        date('Y-m-d H:i:s') . " BLOCKED IP={$_daraja_ip}\n",
        FILE_APPEND | LOCK_EX
    );
    http_response_code(403);
    echo json_encode(['result' => 'blocked']);
    exit;
}

require_once __DIR__ . '/../../includes/db_master.php';
require_once __DIR__ . '/../../includes/auto_provision.php';
require_once __DIR__ . '/../../includes/payment_pipeline.php';
require_once __DIR__ . '/../../includes/schema_guard.php';

// The callback flips statuses to completed/failed and activates the client.
// Guard the enums here too — a truncation here would take the money but leave
// the customer offline, which is worse than failing the STK push outright.
ensurePaymentStatusEnums($pdo);

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

        // Update mpesa_transactions — use mpesa_receipt_number (schema column)
        // raw_callback stores the full Safaricom payload for auditing
        $pdo->prepare("
            UPDATE mpesa_transactions
            SET status = 'completed', result_code = ?, result_desc = ?,
                mpesa_receipt_number = ?, raw_callback = ?, updated_at = NOW()
            WHERE checkout_request_id = ?
        ")->execute([$resultCode, $resultDesc, $receipt, $content, $checkoutRequestId]);

        // ── Tenant settling their own platform invoice by STK ────────────────
        // Marked by result_desc "BILLING:<id>" when the STK was initiated from
        // the tenant's billing page (no client_id — this is tenant → FortuNett).
        //
        // This used to flip tenant_bills.status only. platform_invoices, which is
        // what check_suspensions.php actually enforces and what the super admin
        // sees, was never touched — so a tenant paid, watched their bill go
        // green, and was suspended that night anyway. Everything now settles
        // through applyPlatformPayment() against platform_invoices.
        if ($tx && !$tx['client_id'] && str_starts_with((string)($tx['result_desc'] ?? ''), 'BILLING:')) {
            $resolvedTenantId = (int)($tenant_id ?? ($tx['tenant_id'] ?? 0));
            $billId = (int)substr($tx['result_desc'], 8);

            if ($resolvedTenantId) {
                require_once __DIR__ . '/../../includes/platform_billing.php';
                $res = applyPlatformPayment(
                    $pdo, $resolvedTenantId, (float)($tx['amount'] ?? 0),
                    $receipt ?: $checkoutRequestId, (string)($tx['phone_number'] ?? ''),
                    'STK', 'stk', $content
                );
                error_log("[callback] platform billing tenant=$resolvedTenantId: " . $res['message']);

                // Keep the legacy row in step so the tenant's page and any old
                // reports do not contradict the invoice.
                if ($billId) {
                    try {
                        $pdo->prepare("UPDATE tenant_bills SET status = 'paid', paid_at = NOW(), transaction_ref = ? WHERE id = ? AND tenant_id = ?")
                            ->execute([$receipt ?: $checkoutRequestId, $billId, $resolvedTenantId]);
                    } catch (Throwable $_e) {}
                }
            }
        }

        // ── Fix 2: Idempotency guard — skip pipeline if already processed ────────
        // Safaricom retries callbacks up to 3×. Without this guard the subscription
        // extension runs again, adding another full validity period.
        if ($tx && $tx['status'] !== 'pending') {
            echo json_encode(['result' => 'success']);
            exit;
        }

        if ($tx && $tx['client_id']) {
            $clientId         = (int)$tx['client_id'];
            $resolvedTenantId = (int)($tenant_id ?? $tx['c_tenant_id']);

            // Convert pending payment row from checkout_request_id to the real receipt
            $rows = $pdo->prepare("
                UPDATE payments
                SET status = 'completed', transaction_id = ?
                WHERE transaction_id = ? AND (tenant_id = ? OR ? IS NULL)
            ");
            $rows->execute([$receipt, $checkoutRequestId, $resolvedTenantId, $resolvedTenantId]);

            if ($rows->rowCount() === 0) {
                $pdo->prepare("
                    INSERT INTO payments (client_id, tenant_id, amount, payment_method, transaction_id, status, payment_date)
                    VALUES (?, ?, ?, 'mpesa', ?, 'completed', NOW())
                ")->execute([$clientId, $resolvedTenantId, $amount, $receipt]);
            }

            // Determine whether FortuNett collected this payment or the tenant's own paybill did
            $ownGw = $pdo->prepare("SELECT id FROM payment_gateways WHERE tenant_id = ? AND gateway_type = 'mpesa' AND is_active = 1 LIMIT 1");
            $ownGw->execute([$resolvedTenantId]);
            $platformCollected = !$ownGw->fetchColumn();

            process_payment_success(
                $pdo,
                $clientId,
                $resolvedTenantId,
                $amount,
                $receipt,
                'mpesa',
                $tx['package_id'] ? (int)$tx['package_id'] : null,
                $platformCollected
            );
        }

    } else {
        // Failed / cancelled
        $pdo->prepare("
            UPDATE mpesa_transactions
            SET status = 'failed', result_code = ?, result_desc = ?,
                raw_callback = ?, updated_at = NOW()
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
