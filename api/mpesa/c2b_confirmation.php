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

if (empty($accountRef) || $amount <= 0) {
    file_put_contents($logDir . '/mpesa_c2b.log', date('Y-m-d H:i:s') . " SKIPPED: missing account ref or amount\n", FILE_APPEND | LOCK_EX);
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    exit;
}

try {
    // ── Parse account number into prefix + client_id ──────────────────────────
    // e.g. "J0023" → prefix="J", client_id=23
    //      "J20005" → prefix="J2", client_id=5
    // Strategy: try longest prefix match first (up to 3 chars), then 2, then 1
    $tenant_id  = null;
    $client_id  = null;
    $foundPrefix = '';

    for ($prefixLen = 3; $prefixLen >= 1; $prefixLen--) {
        if (strlen($accountRef) <= $prefixLen) continue;
        $candidatePrefix = substr($accountRef, 0, $prefixLen);
        $candidateId     = ltrim(substr($accountRef, $prefixLen), '0') ?: '0';

        if (!ctype_digit($candidateId)) continue;

        // Look up tenant admin with this prefix
        $prefixStmt = $pdo->prepare("
            SELECT u.tenant_id FROM users u
            WHERE u.account_prefix = ? AND u.role = 'admin' AND u.tenant_id IS NOT NULL
            LIMIT 1
        ");
        $prefixStmt->execute([$candidatePrefix]);
        $row = $prefixStmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $tenant_id   = (int)$row['tenant_id'];
            $client_id   = (int)$candidateId;
            $foundPrefix = $candidatePrefix;
            break;
        }
    }

    if (!$tenant_id || !$client_id) {
        file_put_contents($logDir . '/mpesa_c2b.log', date('Y-m-d H:i:s') . " UNROUTABLE: account=$accountRef\n", FILE_APPEND | LOCK_EX);
        echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
        exit;
    }

    // Verify client belongs to this tenant
    $clientStmt = $pdo->prepare("SELECT id, package_id FROM clients WHERE id = ? AND tenant_id = ? LIMIT 1");
    $clientStmt->execute([$client_id, $tenant_id]);
    $client = $clientStmt->fetch(PDO::FETCH_ASSOC);

    if (!$client) {
        file_put_contents($logDir . '/mpesa_c2b.log', date('Y-m-d H:i:s') . " CLIENT NOT FOUND: tenant=$tenant_id client=$client_id\n", FILE_APPEND | LOCK_EX);
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
        INSERT INTO payments (client_id, tenant_id, amount, payment_method, transaction_id, status, payment_date, notes)
        VALUES (?, ?, ?, 'mpesa_paybill', ?, 'completed', NOW(), ?)
    ")->execute([$client_id, $tenant_id, $amount, $transactionId, 'C2B via platform paybill — acct: ' . $accountRef]);

    // Log the raw C2B transaction
    $pdo->prepare("
        INSERT INTO mpesa_transactions
            (client_id, tenant_id, phone_number, amount, merchant_request_id,
             checkout_request_id, result_code, result_desc, transaction_id, callback_data, created_at, updated_at)
        VALUES (?, ?, ?, ?, 'C2B', ?, '0', 'C2B Payment', ?, ?, NOW(), NOW())
    ")->execute([
        $client_id, $tenant_id, $phone, $amount,
        $accountRef . '-' . $transTime,
        $transactionId,
        $content
    ]);

    // Extend client subscription if they have a package
    if ($client['package_id']) {
        $pkg = $pdo->prepare("SELECT validity_value, validity_unit FROM packages WHERE id = ? AND tenant_id = ?");
        $pkg->execute([$client['package_id'], $tenant_id]);
        $package = $pkg->fetch(PDO::FETCH_ASSOC);

        if ($package) {
            $validityValue = max(1, (int)($package['validity_value'] ?? 30));
            $validityUnit  = in_array($package['validity_unit'], ['days','weeks','months']) ? $package['validity_unit'] : 'days';
            $expiryDate    = date('Y-m-d H:i:s', strtotime('+' . $validityValue . ' ' . $validityUnit));

            $pdo->prepare("
                UPDATE clients SET status = 'active', expiry_date = ?
                WHERE id = ? AND tenant_id = ?
            ")->execute([$expiryDate, $client_id, $tenant_id]);

            $pdo->prepare("
                INSERT INTO customer_activity_log (client_id, tenant_id, activity_type, description)
                VALUES (?, ?, 'payment_success', ?)
            ")->execute([$client_id, $tenant_id, 'C2B payment ' . $transactionId . ' (KSH ' . $amount . ') — active until ' . $expiryDate]);
        }
    }

    // Auto-provision to router (best-effort — failure never blocks the confirmation response)
    if ($client['package_id']) {
        autoProvisionClient($pdo, (int)$client_id, (int)$tenant_id);
    }

    file_put_contents($logDir . '/mpesa_c2b.log', date('Y-m-d H:i:s') . " OK: tenant=$tenant_id client=$client_id amount=$amount tx=$transactionId\n", FILE_APPEND | LOCK_EX);
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);

} catch (Throwable $e) {
    file_put_contents($logDir . '/mpesa_errors.log', date('Y-m-d H:i:s') . " C2B error: " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
    // Always return 0 to Safaricom so they don't retry indefinitely
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
}
