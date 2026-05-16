<?php
/**
 * POST /api/v1/customer/pay.php
 *
 * Initiate an M-Pesa STK Push renewal from the customer Kotlin app.
 * The customer taps "Renew" → enters their phone → a prompt appears on their phone.
 *
 * Request body (JSON):
 *   { "phone": "0712345678", "package_id": 3 }
 *   package_id is optional — defaults to the customer's current package.
 *
 * Response (success):
 *   { "success": true, "message": "Check your phone for the M-Pesa prompt.", "checkout_request_id": "..." }
 *
 * Response (error):
 *   { "success": false, "error": "..." }
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../../../includes/db_master.php';
require_once __DIR__ . '/../../../includes/env.php';
require_once __DIR__ . '/../../../includes/jwt.php';
require_once __DIR__ . '/../../../includes/api_auth.php';
require_once __DIR__ . '/../../../classes/MpesaAPI.php';

api_cors_headers();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

// Customer JWT auth
$authHeader = _resolve_auth_header();
if (!$authHeader || !preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$secret  = get_env_var('JWT_SECRET', '');
$payload = $secret ? jwt_decode($m[1], $secret) : null;
if (!$payload || ($payload['type'] ?? '') !== 'customer') {
    http_response_code(401);
    echo json_encode(['error' => 'Token expired or invalid', 'code' => 'TOKEN_EXPIRED']);
    exit;
}

$clientId = (int)$payload['uid'];
$tenantId = (int)$payload['tid'];

$body      = json_decode(file_get_contents('php://input'), true) ?? [];
$phone     = trim($body['phone'] ?? '');
$packageId = !empty($body['package_id']) ? (int)$body['package_id'] : null;

if (!$phone) {
    http_response_code(422);
    echo json_encode(['error' => 'Phone number is required']);
    exit;
}

try {
    // Load client
    $clientStmt = $pdo->prepare("SELECT * FROM clients WHERE id = ? AND tenant_id = ? LIMIT 1");
    $clientStmt->execute([$clientId, $tenantId]);
    $client = $clientStmt->fetch(PDO::FETCH_ASSOC);

    if (!$client) {
        http_response_code(404);
        echo json_encode(['error' => 'Account not found']);
        exit;
    }

    // Resolve package
    $resolvedPackageId = $packageId ?? $client['package_id'];
    if (!$resolvedPackageId) {
        http_response_code(422);
        echo json_encode(['error' => 'No package selected. Please choose a package to renew.']);
        exit;
    }

    $pkgStmt = $pdo->prepare("SELECT * FROM packages WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL) LIMIT 1");
    $pkgStmt->execute([$resolvedPackageId, $tenantId]);
    $package = $pkgStmt->fetch(PDO::FETCH_ASSOC);

    if (!$package || !$package['price']) {
        http_response_code(422);
        echo json_encode(['error' => 'Invalid package']);
        exit;
    }

    $amount = (int)$package['price'];

    // Account reference (max 12 chars per Safaricom limit)
    $accountRef = $client['account_number']
        ?? ('C' . str_pad($clientId, 6, '0', STR_PAD_LEFT));
    $accountRef = substr($accountRef, 0, 12);

    // Init M-Pesa with tenant credentials
    $mpesa = new MpesaAPI($pdo, $tenantId);

    // Log pending payment
    $pdo->prepare("
        INSERT INTO payments (client_id, tenant_id, amount, payment_method, status, payment_date, notes)
        VALUES (?, ?, ?, 'mpesa_stk', 'pending', NOW(), ?)
    ")->execute([$clientId, $tenantId, $amount, 'STK Push via customer app — package: ' . $package['name']]);
    $paymentId = $pdo->lastInsertId();

    // Log STK attempt
    $mpesaStmt = $pdo->prepare("
        INSERT INTO mpesa_transactions
            (client_id, tenant_id, phone_number, amount, merchant_request_id,
             checkout_request_id, result_code, result_desc, created_at, updated_at)
        VALUES (?, ?, ?, ?, '', '', -1, 'STK Push initiated via app', NOW(), NOW())
    ");
    $mpesaStmt->execute([$clientId, $tenantId, $phone, $amount]);
    $mpesaTxId = $pdo->lastInsertId();

    $response = $mpesa->stkPush($phone, $amount, $accountRef, 'Internet renewal — ' . $package['name']);

    if (isset($response->ResponseCode) && $response->ResponseCode === '0') {
        $checkoutId = $response->CheckoutRequestID ?? '';
        $merchantId = $response->MerchantRequestID  ?? '';

        // Update transaction with Safaricom IDs
        $pdo->prepare("
            UPDATE mpesa_transactions
            SET merchant_request_id = ?, checkout_request_id = ?, result_desc = 'STK Push sent'
            WHERE id = ?
        ")->execute([$merchantId, $checkoutId, $mpesaTxId]);

        // Update payment with transaction reference
        $pdo->prepare("UPDATE payments SET transaction_id = ? WHERE id = ?")
            ->execute([$checkoutId, $paymentId]);

        echo json_encode([
            'success'              => true,
            'message'              => 'Check your phone for the M-Pesa prompt. Enter your PIN to complete payment.',
            'checkout_request_id'  => $checkoutId,
            'amount'               => $amount,
            'package'              => $package['name'],
        ]);
    } else {
        $errMsg = $response->errorMessage ?? $response->ResponseDescription ?? 'M-Pesa request failed';
        $pdo->prepare("UPDATE payments SET status = 'failed' WHERE id = ?")->execute([$paymentId]);

        http_response_code(502);
        echo json_encode(['success' => false, 'error' => $errMsg]);
    }

} catch (Throwable $e) {
    error_log('Customer API pay error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Payment initiation failed. Please try again.']);
}
