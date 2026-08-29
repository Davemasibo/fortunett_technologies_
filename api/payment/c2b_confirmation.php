<?php
/**
 * M-Pesa C2B Confirmation Handler — Platform Shared Paybill
 *
 * For tenants that don't have their own M-Pesa API credentials, FortuNett
 * provides a shared paybill. Each customer uses a unique account number that
 * encodes their tenant + client identity:
 *
 *   Account format:  {PREFIX}{CLIENT_ID_PADDED}
 *   Example:         J0023   → tenant with account_prefix "J", client ID 23
 *
 * The PREFIX is the first alphanumeric character of the ISP admin's username,
 * stored in users.account_prefix (set at signup). If two tenants share the
 * same starting letter the second gets e.g. "J2".
 *
 * Register this URL in Safaricom Daraja as the C2B Confirmation URL for the
 * platform shortcode (FortuNett's own production shortcode).
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../../includes/db_master.php';
require_once __DIR__ . '/../../includes/auto_provision.php';
require_once __DIR__ . '/../../includes/payment_pipeline.php';
require_once __DIR__ . '/../../includes/account_resolver.php';
require_once __DIR__ . '/../../includes/platform_billing.php';
require_once __DIR__ . '/../../includes/schema_guard.php';
require_once __DIR__ . '/../../includes/unmatched_payments.php';

// The pipeline flips clients.status to 'active'; guard the enums so a missing
// migration can't silently swallow an activation after the money is taken.
ensurePaymentStatusEnums($pdo);

$logDir = __DIR__ . '/../../logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

$content = file_get_contents('php://input');
file_put_contents($logDir . '/mpesa_c2b.log', date('Y-m-d H:i:s') . " -- " . $content . "\n", FILE_APPEND | LOCK_EX);

$data = json_decode($content, true);

if (!$data) {
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    exit;
}

// C2B fields from Safaricom
$transactionId  = $data['TransID']         ?? ($data['TransactionID'] ?? '');
$amount         = (float)($data['TransAmount'] ?? 0);
$phone          = $data['MSISDN']           ?? '';
$accountRef     = strtoupper(trim($data['BillRefNumber'] ?? ''));
$transTime      = $data['TransTime']        ?? date('YmdHis');

$payerName      = trim(preg_replace('/\s+/', ' ', ($data['FirstName'] ?? '') . ' ' . ($data['MiddleName'] ?? '') . ' ' . ($data['LastName'] ?? '')));
$txType         = (string)($data['TransactionType'] ?? '');

// A Buy Goods (Till) confirmation carries NO BillRefNumber - there is no
// account field on the customer's phone for a till, only an amount. This block
// used to exit here, so every shilling paid to a till was written to the log
// and thrown away. An empty ref is not a reason to stop: resolveAccountRef()
// can still identify the payer by their MSISDN, and when it cannot,
// record_unmatched_payment() queues the money for a human below. Only a zero
// amount is genuinely nothing to act on.
if ($amount <= 0) {
    file_put_contents($logDir . '/mpesa_c2b.log', date('Y-m-d H:i:s') . " SKIPPED: zero amount tx={$transactionId}\n", FILE_APPEND | LOCK_EX);
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    exit;
}
if ($accountRef === '') {
    file_put_contents($logDir . '/mpesa_c2b.log',
        date('Y-m-d H:i:s') . " NO REF (till / buy-goods, type='{$txType}') - resolving by MSISDN {$phone}, tx={$transactionId}\n",
        FILE_APPEND | LOCK_EX);
}

try {
    // ── Is this a TENANT paying FortuNett, not an end customer? ───────────────
    // The same paybill serves both flows, so the reference decides. Checked
    // first because a tenant billing code ("FN5") must never fall through to
    // end-customer matching and get credited to somebody's internet.
    $platformTenantId = resolvePlatformBillingRef($pdo, $accountRef);
    if ($platformTenantId) {
        $res = applyPlatformPayment(
            $pdo, $platformTenantId, $amount, $transactionId,
            $phone, $accountRef, 'c2b', $content
        );
        file_put_contents($logDir . '/mpesa_c2b.log',
            date('Y-m-d H:i:s') . " PLATFORM BILLING: tenant={$platformTenantId} ref={$accountRef} "
            . "amount={$amount} tx={$transactionId} -> " . ($res['ok'] ? $res['message'] : 'FAILED: ' . $res['message']) . "\n",
            FILE_APPEND | LOCK_EX);
        echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
        exit;
    }

    // ── Work out who paid ─────────────────────────────────────────────────────
    // Accepts the account number, the customer's phone (what the captive
    // portal's paybill instructions actually tell them to enter), PREFIX+id, or
    // a bare client id. See includes/account_resolver.php.
    $match = resolveAccountRef($pdo, $accountRef, $phone, null);

    if (!$match) {
        file_put_contents($logDir . '/mpesa_c2b.log',
            date('Y-m-d H:i:s') . " UNROUTABLE: account=$accountRef phone=$phone amount=$amount tx=$transactionId\n",
            FILE_APPEND | LOCK_EX);
        // The money is banked and Safaricom never replays a C2B confirmation.
        // The tenant handler has always queued these; the platform handler only
        // logged them, so platform paybill / till money that failed to route was
        // invisible in every UI. Capture it for one-click assignment instead.
        record_unmatched_payment(
            $pdo, null, $transactionId, $amount, $phone, $accountRef,
            'unrouted', 'c2b_platform', $content, $payerName
        );

        echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
        exit;
    }

    $tenant_id = $match['tenant_id'];
    $client_id = $match['client_id'];
    file_put_contents($logDir . '/mpesa_c2b.log',
        date('Y-m-d H:i:s') . " MATCHED via {$match['matched_by']}: account=$accountRef -> tenant=$tenant_id client=$client_id\n",
        FILE_APPEND | LOCK_EX);

    // Verify client belongs to this tenant
    $clientStmt = $pdo->prepare("SELECT id, package_id FROM clients WHERE id = ? AND tenant_id = ? LIMIT 1");
    $clientStmt->execute([$client_id, $tenant_id]);
    $client = $clientStmt->fetch(PDO::FETCH_ASSOC);

    if (!$client) {
        file_put_contents($logDir . '/mpesa_c2b.log', date('Y-m-d H:i:s') . " CLIENT NOT FOUND: tenant=$tenant_id client=$client_id\n", FILE_APPEND | LOCK_EX);
        record_unmatched_payment(
            $pdo, (int)$tenant_id, $transactionId, $amount, $phone, $accountRef,
            'unmatched', 'c2b_platform', $content, $payerName
        );

        echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
        exit;
    }

    // Check for duplicate (idempotency)
    $dupCheck = $pdo->prepare("SELECT id FROM payments WHERE transaction_id = ? LIMIT 1");
    $dupCheck->execute([$transactionId]);
    if ($dupCheck->fetchColumn()) {
        echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
        exit;
    }

    // Record payment
    $pdo->prepare("
        INSERT INTO payments (client_id, tenant_id, amount, payment_method, transaction_id, status, payment_date, collection_type, notes)
        VALUES (?, ?, ?, 'mpesa_paybill', ?, 'completed', NOW(), 'platform', ?)
    ")->execute([$client_id, $tenant_id, $amount, $transactionId, 'C2B via platform paybill — acct: ' . $accountRef]);

    // Log the raw C2B transaction
    $pdo->prepare("
        INSERT INTO mpesa_transactions
            (client_id, tenant_id, phone_number, amount, merchant_request_id,
             checkout_request_id, result_code, result_desc, mpesa_receipt_number, raw_callback, created_at, updated_at)
        VALUES (?, ?, ?, ?, 'C2B', ?, 0, 'C2B Payment', ?, ?, NOW(), NOW())
    ")->execute([
        $client_id, $tenant_id, $phone, $amount,
        $accountRef . '-' . $transTime,
        $transactionId,
        $content
    ]);

    // Full pipeline: invoice, ledger, commission, payout queue, RADIUS, SMS, provision
    // platformCollected=true — FortuNett holds the money; ISP payout must be queued
    process_payment_success(
        $pdo,
        (int)$client_id,
        (int)$tenant_id,
        $amount,
        $transactionId,
        'mpesa_paybill',
        $client['package_id'] ? (int)$client['package_id'] : null,
        true
    );

    file_put_contents($logDir . '/mpesa_c2b.log', date('Y-m-d H:i:s') . " OK: tenant=$tenant_id client=$client_id amount=$amount tx=$transactionId\n", FILE_APPEND | LOCK_EX);
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);

} catch (Throwable $e) {
    file_put_contents($logDir . '/mpesa_errors.log', date('Y-m-d H:i:s') . " C2B error: " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
    // Always return 0 to Safaricom so they don't retry indefinitely
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
}
