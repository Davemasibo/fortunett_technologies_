<?php
/**
 * M-Pesa C2B Confirmation Handler — Tenant-Own Paybill
 *
 * Safaricom calls this URL AFTER the transaction is confirmed (money credited).
 * At this point the payment is irreversible — always return ResultCode 0.
 *
 * Flow:
 *   1. Detect tenant from HTTP_HOST subdomain
 *   2. Match BillRefNumber → client (account_number or numeric ID)
 *   3. Record payment in payments + mpesa_transactions
 *   4. Extend client subscription based on their package validity
 *   5. Call autoProvisionClient() to enable router access immediately
 *
 * Register this URL in Daraja as the Confirmation URL for your own shortcode.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../../includes/db_master.php';
require_once __DIR__ . '/../../includes/auto_provision.php';

$logDir = __DIR__ . '/../../logs';
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);

$content = file_get_contents('php://input');
@file_put_contents(
    $logDir . '/tenant_c2b.log',
    date('Y-m-d H:i:s') . ' CONFIRM HOST=' . ($_SERVER['HTTP_HOST'] ?? '') . ' -- ' . $content . "\n",
    FILE_APPEND | LOCK_EX
);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'ok', 'endpoint' => 'tenant_c2b_confirmation']);
    exit;
}

$data = json_decode($content, true) ?? [];

// C2B fields from Safaricom
$transactionId = $data['TransID']        ?? ($data['TransactionID'] ?? '');
$amount        = (float)($data['TransAmount'] ?? 0);
$phone         = $data['MSISDN']          ?? '';
$accountRef    = strtoupper(trim($data['BillRefNumber'] ?? ''));
$transTime     = $data['TransTime']       ?? date('YmdHis');

if (empty($accountRef) || $amount <= 0) {
    @file_put_contents($logDir . '/tenant_c2b.log',
        date('Y-m-d H:i:s') . " CONFIRM SKIPPED: missing ref or zero amount\n", FILE_APPEND | LOCK_EX);
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    exit;
}

// Detect tenant from subdomain
$httpHost  = explode(':', $_SERVER['HTTP_HOST'] ?? '')[0];
$hostParts = explode('.', $httpHost);
$tenantId  = null;

if (count($hostParts) >= 3 && $hostParts[0] !== 'www') {
    $subdomain = $hostParts[0];
    $ts = $pdo->prepare("SELECT id FROM tenants WHERE subdomain = ? LIMIT 1");
    $ts->execute([$subdomain]);
    $tenantId = $ts->fetchColumn() ?: null;
}

if (!$tenantId) {
    @file_put_contents($logDir . '/tenant_c2b.log',
        date('Y-m-d H:i:s') . " CONFIRM UNROUTED: host={$httpHost} tx={$transactionId} amount={$amount}\n", FILE_APPEND | LOCK_EX);
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    exit;
}

try {
    // Idempotency — skip if this transaction was already recorded
    $dupCheck = $pdo->prepare("SELECT id FROM payments WHERE transaction_id = ? LIMIT 1");
    $dupCheck->execute([$transactionId]);
    if ($dupCheck->fetchColumn()) {
        echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
        exit;
    }

    // Resolve client
    $clientId = resolveClientFromRef($pdo, $accountRef, (int)$tenantId);

    if (!$clientId) {
        @file_put_contents($logDir . '/tenant_c2b.log',
            date('Y-m-d H:i:s') . " CONFIRM UNMATCHED: account={$accountRef} tenant={$tenantId} tx={$transactionId}\n", FILE_APPEND | LOCK_EX);
        // Still return 0 — Safaricom considers this final. Log and reconcile manually.
        echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
        exit;
    }

    // Load client's current package
    $clientStmt = $pdo->prepare("SELECT id, package_id FROM clients WHERE id = ? AND tenant_id = ? LIMIT 1");
    $clientStmt->execute([$clientId, $tenantId]);
    $client = $clientStmt->fetch(PDO::FETCH_ASSOC);

    // Record in payments table
    $pdo->prepare("
        INSERT INTO payments (client_id, tenant_id, amount, payment_method, transaction_id, status, payment_date, notes)
        VALUES (?, ?, ?, 'mpesa_paybill', ?, 'completed', NOW(), ?)
    ")->execute([
        $clientId, $tenantId, $amount, $transactionId,
        'Tenant C2B — paybill — ref: ' . $accountRef,
    ]);

    // Raw transaction log
    $pdo->prepare("
        INSERT INTO mpesa_transactions
            (client_id, tenant_id, phone_number, amount, merchant_request_id,
             checkout_request_id, result_code, result_desc, mpesa_receipt_number, raw_callback, created_at, updated_at)
        VALUES (?, ?, ?, ?, 'C2B-TENANT', ?, 0, 'C2B Tenant Paybill', ?, ?, NOW(), NOW())
    ")->execute([
        $clientId, $tenantId, $phone, $amount,
        $accountRef . '-' . $transTime,
        $transactionId,
        $content,
    ]);

    // Extend subscription if client has a package
    $expiryDate = null;
    if (!empty($client['package_id'])) {
        $pkg = $pdo->prepare("SELECT validity_value, validity_unit FROM packages WHERE id = ? LIMIT 1");
        $pkg->execute([$client['package_id']]);
        $package = $pkg->fetch(PDO::FETCH_ASSOC);

        if ($package) {
            $validityValue = max(1, (int)($package['validity_value'] ?? 30));
            $validityUnit  = in_array($package['validity_unit'], ['days', 'weeks', 'months'], true)
                ? $package['validity_unit'] : 'days';
            $expiryDate = date('Y-m-d H:i:s', strtotime('+' . $validityValue . ' ' . $validityUnit));

            $pdo->prepare("UPDATE clients SET status = 'active', expiry_date = ? WHERE id = ? AND tenant_id = ?")
                ->execute([$expiryDate, $clientId, $tenantId]);
        }
    }

    // Activity log
    $pdo->prepare("
        INSERT INTO customer_activity_log (client_id, tenant_id, activity_type, description)
        VALUES (?, ?, 'payment_success', ?)
    ")->execute([
        $clientId, $tenantId,
        'Tenant paybill C2B — ' . $transactionId . ' — KSH ' . $amount
            . ($expiryDate ? ' — active until ' . $expiryDate : ''),
    ]);

    // Auto-provision to router immediately
    if (!empty($client['package_id'])) {
        autoProvisionClient($pdo, (int)$clientId, (int)$tenantId);
    }

    @file_put_contents($logDir . '/tenant_c2b.log',
        date('Y-m-d H:i:s') . " CONFIRM OK: tenant={$tenantId} client={$clientId} amount={$amount} tx={$transactionId}\n",
        FILE_APPEND | LOCK_EX);

    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);

} catch (Throwable $e) {
    @file_put_contents($logDir . '/mpesa_errors.log',
        date('Y-m-d H:i:s') . " tenant_c2b_confirmation error: " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
}

/**
 * Resolve BillRefNumber → client ID under the given tenant.
 * Matches account_number first, falls back to numeric client ID.
 */
function resolveClientFromRef(PDO $pdo, string $ref, int $tenantId): ?int {
    $stmt = $pdo->prepare("SELECT id FROM clients WHERE account_number = ? AND tenant_id = ? LIMIT 1");
    $stmt->execute([$ref, $tenantId]);
    $id = $stmt->fetchColumn();
    if ($id) return (int)$id;

    $numeric = ltrim($ref, '0') ?: '0';
    if (ctype_digit($numeric)) {
        $stmt2 = $pdo->prepare("SELECT id FROM clients WHERE id = ? AND tenant_id = ? LIMIT 1");
        $stmt2->execute([(int)$numeric, $tenantId]);
        $id2 = $stmt2->fetchColumn();
        if ($id2) return (int)$id2;
    }

    return null;
}
