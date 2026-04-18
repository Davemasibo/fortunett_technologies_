<?php
/**
 * Customer Portal — Initiate M-Pesa STK Push
 *
 * Authenticated via $_SESSION['customer_token'] (not admin session).
 * Supports two modes driven by the 'type' POST field:
 *   - 'package' (default): payment for a service package → callback activates it
 *   - 'topup': balance top-up → client_id is omitted from mpesa_transactions so
 *              the standard callback does NOT activate a package; check_status.php
 *              handles the balance credit when polling detects completion.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../../includes/db_master.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../../classes/MpesaAPI.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'POST required']);
    exit;
}

if (!isCustomerLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$customer = getCurrentCustomer();
if (!$customer) {
    echo json_encode(['success' => false, 'message' => 'Session invalid. Please log in again.']);
    exit;
}

$clientId = (int)$customer['id'];
$tenantId = (int)$customer['tenant_id'];
$acctNo   = $customer['account_number'] ?? '';

$gatewayId = (int)($_POST['gateway_id'] ?? 0);
$phone     = trim($_POST['phone'] ?? '');
$amount    = (float)($_POST['amount'] ?? 0);
$type      = ($_POST['type'] ?? 'package') === 'topup' ? 'topup' : 'package';

if (!$gatewayId || !$phone || $amount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Gateway, phone and amount are required.']);
    exit;
}

// gateway_id = 0 means "use platform credentials directly" (tenant has no own gateway)
$usingPlatformGateway = ($gatewayId === 0);
$gateway = null;

if (!$usingPlatformGateway) {
    // Verify gateway belongs to this tenant and is an M-Pesa API gateway
    $gwStmt = $pdo->prepare(
        "SELECT * FROM payment_gateways
         WHERE id = ? AND tenant_id = ? AND is_active = 1 AND gateway_type = 'mpesa_api'"
    );
    $gwStmt->execute([$gatewayId, $tenantId]);
    $gateway = $gwStmt->fetch(PDO::FETCH_ASSOC);

    if (!$gateway) {
        echo json_encode(['success' => false, 'message' => 'Payment gateway unavailable. Please contact support.']);
        exit;
    }
}

// AccountReference — must be ≤ 12 chars (Safaricom hard limit)
$accountRef = !empty($acctNo)
    ? substr($acctNo, 0, 12)
    : ('C' . str_pad($clientId, 4, '0', STR_PAD_LEFT));

try {
    // If gateway_id = 0, go straight to platform credentials
    $mpesa = new MpesaAPI($pdo, $tenantId);
    $usingPlatform = $usingPlatformGateway;

    if ($usingPlatformGateway || !$mpesa->hasValidCredentials()) {
        // Use platform shared paybill
        try {
            $plStmt = $pdo->query(
                "SELECT consumer_key, consumer_secret, passkey, shortcode, shortcode_type, store_number, environment, callback_url
                 FROM platform_mpesa_config LIMIT 1"
            );
            $platCreds = $plStmt ? $plStmt->fetch(PDO::FETCH_ASSOC) : null;
        } catch (Exception $e) {
            $platCreds = null; // Table may not exist
        }

        if ($platCreds && !empty($platCreds['consumer_key'])) {
            if (empty($platCreds['callback_url'])) {
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host   = explode(':', $_SERVER['HTTP_HOST'] ?? 'localhost')[0];
                $platCreds['callback_url'] = $scheme . '://' . $host . '/api/mpesa/callback.php';
            }
            $mpesa->loadFromArray($platCreds);
            $usingPlatform = true;
        } else {
            echo json_encode(['success' => false, 'message' => 'M-Pesa is not configured. Please contact support.']);
            exit;
        }
    }

    if (!$mpesa->hasValidCredentials()) {
        echo json_encode(['success' => false, 'message' => 'M-Pesa credentials are incomplete. Please contact support.']);
        exit;
    }

    $isSandbox = $mpesa->getEnvironment() !== 'production';
    $response  = $mpesa->stkPush($phone, $amount, $accountRef);

    if ($response === null) {
        echo json_encode(['success' => false, 'message' => 'M-Pesa API returned no response. Check internet connectivity.']);
        exit;
    }

    $responseCode = $response->ResponseCode ?? $response->errorCode ?? null;

    if ($responseCode === '0' || $responseCode === 0) {
        $checkoutId = $response->CheckoutRequestID ?? '';
        $merchantId = $response->MerchantRequestID ?? 'N/A';

        // For TOPUP: omit client_id so the standard callback doesn't activate a package.
        // check_status.php detects NULL client_id → credits account_balance instead.
        $txClientId = ($type === 'topup') ? null : $clientId;
        $txDesc     = ($type === 'topup') ? 'TOPUP:STK Push Initiated' : 'STK Push Initiated';

        try {
            $pdo->prepare(
                "INSERT INTO mpesa_transactions
                 (client_id, tenant_id, phone_number, amount, merchant_request_id, checkout_request_id,
                  result_code, result_desc, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, NOW(), NOW())"
            )->execute([$txClientId, $tenantId, $phone, $amount, $merchantId, $checkoutId, $txDesc]);

            // Pending payment row — always link to the real client_id for accounting
            try {
                $pdo->prepare(
                    "INSERT INTO payments
                     (client_id, tenant_id, amount, payment_method, payment_date, transaction_id, status)
                     VALUES (?, ?, ?, 'mpesa', NOW(), ?, 'pending')"
                )->execute([$clientId, $tenantId, $amount, $checkoutId]);
            } catch (PDOException $pe) {
                // Non-fatal if collection_type column or other mismatch
            }
        } catch (Exception $dbEx) {
            error_log("Customer STK DB error: " . $dbEx->getMessage());
        }

        $custMsg = $isSandbox
            ? 'SANDBOX MODE: No real PIN prompt is sent. Switch to Production in gateway settings.'
            : ($response->CustomerMessage ?? 'Check your phone for the M-Pesa PIN prompt.');
        if ($usingPlatform) {
            $custMsg .= ' (via FortuNett shared paybill ' . $mpesa->getShortcode() . ')';
        }

        echo json_encode([
            'success'     => true,
            'checkout_id' => $checkoutId,
            'sandbox'     => $isSandbox,
            'message'     => $custMsg,
            'type'        => $type,
        ]);

    } else {
        $msg = $response->errorMessage
            ?? $response->ResultDesc
            ?? $response->ResponseDescription
            ?? 'M-Pesa request failed (Code: ' . ($responseCode ?? 'unknown') . ').';

        $hints = [
            '400.002.02'   => ' — Invalid Consumer Key or Secret.',
            '404.001.04'   => ' — Shortcode not found.',
            '400.002.05'   => ' — Account reference too long.',
            '500.001.1001' => ' — Wrong passkey for this environment.',
            '1032'         => ' — Request cancelled.',
            '1037'         => ' — Timed out (no phone response).',
        ];
        $msg .= ($hints[(string)$responseCode] ?? '');

        echo json_encode(['success' => false, 'message' => $msg]);
    }

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
