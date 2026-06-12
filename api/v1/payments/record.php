<?php
/**
 * POST /api/v1/payments/record.php
 *
 * Record a manual/cash payment OR initiate M-Pesa STK Push.
 *
 * Body (JSON):
 *   client_id, amount
 *   method:  "cash" | "bank_transfer" | "mpesa_manual" | "mpesa_stk"
 *   reference_code  (required for manual methods)
 *   phone           (required for mpesa_stk)
 *   notes           (optional)
 *   transaction_date (optional ISO date, defaults to now)
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../../../includes/db_master.php';
require_once __DIR__ . '/../../../includes/api_auth.php';

api_cors_headers();
$auth = require_api_auth($pdo);

$tenantId = $auth['tenant_id'];
if (!$tenantId) { http_response_code(403); echo json_encode(['error' => 'Tenant context required']); exit; }

$body     = json_decode(file_get_contents('php://input'), true) ?? [];
$clientId = (int)($body['client_id'] ?? 0);
$amount   = (float)($body['amount'] ?? 0);
$method   = strtolower(trim($body['method'] ?? 'cash'));
$notes    = trim($body['notes'] ?? '');
$refCode  = trim($body['reference_code'] ?? '');
$phone    = trim($body['phone'] ?? '');
$txDate   = trim($body['transaction_date'] ?? '');

if (!$clientId || $amount <= 0) {
    http_response_code(422);
    echo json_encode(['error' => 'client_id and amount are required']);
    exit;
}

// Resolve client
$clientStmt = $pdo->prepare("SELECT id, full_name, phone FROM clients WHERE id = ? AND tenant_id = ? LIMIT 1");
$clientStmt->execute([$clientId, $tenantId]);
$client = $clientStmt->fetch(PDO::FETCH_ASSOC);
if (!$client) { http_response_code(404); echo json_encode(['error' => 'Client not found']); exit; }

// Normalise method
$methodMap = [
    'mpesa' => 'mpesa', 'm-pesa' => 'mpesa', 'mpesa_manual' => 'mpesa', 'safaricom' => 'mpesa',
    'cash' => 'cash',
    'bank_transfer' => 'bank_transfer', 'bank' => 'bank_transfer',
    'mpesa_stk' => 'mpesa',
];
$dbMethod = $methodMap[$method] ?? 'cash';

// ── M-Pesa STK Push ───────────────────────────────────────────────────────────
if ($method === 'mpesa_stk') {
    if (empty($phone)) { http_response_code(422); echo json_encode(['error' => 'phone required for STK push']); exit; }

    require_once __DIR__ . '/../../../classes/MpesaAPI.php';

    // Load tenant gateway credentials
    $gwStmt = $pdo->prepare("SELECT credentials FROM payment_gateways WHERE tenant_id = ? AND gateway_type = 'mpesa' AND is_active = 1 LIMIT 1");
    $gwStmt->execute([$tenantId]);
    $gw   = $gwStmt->fetch(PDO::FETCH_ASSOC);
    $creds = $gw ? json_decode($gw['credentials'], true) : [];

    $mpesa = new MpesaAPI($creds);
    // Use client account number as reference; fall back to client ID
    $acctStmt = $pdo->prepare("SELECT account_number FROM clients WHERE id = ? LIMIT 1");
    $acctStmt->execute([$clientId]);
    $acct = $acctStmt->fetchColumn() ?: ('ACC' . $clientId);

    $result = $mpesa->stkPush($phone, $amount, $acct, 'Internet Bill');
    if ($result['success'] ?? false) {
        echo json_encode([
            'success'              => true,
            'method'               => 'mpesa_stk',
            'checkout_request_id'  => $result['CheckoutRequestID'] ?? null,
            'message'              => 'STK push sent to ' . $phone,
        ]);
    } else {
        http_response_code(502);
        echo json_encode(['error' => $result['errorMessage'] ?? 'STK push failed']);
    }
    exit;
}

// ── Manual / Cash recording ───────────────────────────────────────────────────
if (empty($refCode)) {
    $refCode = strtoupper(substr(md5(uniqid()), 0, 8));
}

$parsedTs = $txDate ? strtotime(str_replace('T', ' ', $txDate)) : time();
if (!$parsedTs) $parsedTs = time();
$txDateFmt = date('Y-m-d H:i:s', $parsedTs);

try {
    $resultDesc = 'Manual:' . $dbMethod . ($notes ? ' | ' . substr($notes, 0, 200) : '');

    $pdo->prepare("INSERT INTO mpesa_transactions
            (client_id, tenant_id, phone_number, amount, merchant_request_id, checkout_request_id,
             status, result_code, result_desc, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, 'completed', '0', ?, ?, NOW())")
        ->execute([
            $clientId, $tenantId, $client['phone'] ?? '', $amount,
            'MANUAL-' . strtoupper(substr(md5(uniqid()), 0, 6)),
            $refCode, $resultDesc, $txDateFmt,
        ]);

    try {
        $pdo->prepare("INSERT INTO payments (client_id, tenant_id, amount, payment_method, payment_date, transaction_id, status, notes) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$clientId, $tenantId, $amount, $dbMethod, $txDateFmt, $refCode, 'completed', $notes]);
    } catch (PDOException $e) {
        // notes column may not exist on older schemas
        $pdo->prepare("INSERT INTO payments (client_id, tenant_id, amount, payment_method, payment_date, transaction_id, status) VALUES (?,?,?,?,?,?,?)")
            ->execute([$clientId, $tenantId, $amount, $dbMethod, $txDateFmt, $refCode, 'completed']);
    }

    echo json_encode(['success' => true, 'reference' => $refCode, 'message' => 'Payment recorded']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
