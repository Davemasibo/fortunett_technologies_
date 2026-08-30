<?php
/**
 * API Endpoint: Initiate M-Pesa STK Push
 * Works for both customer payments (with client_id) and tenant invoice payments (no client_id)
 */
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../includes/auth.php';
require_once '../../classes/MpesaAPI.php';
require_once '../../includes/credential_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$uStmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$uStmt->execute([$user_id]);
$current_tenant_id = $uStmt->fetchColumn();

if (!$current_tenant_id) {
    echo json_encode(['success' => false, 'message' => 'Tenant context missing']);
    exit;
}

// client_id is OPTIONAL — billing invoice payments won't have one
$phone       = trim($_POST['phone'] ?? '');
$amount      = (float)($_POST['amount'] ?? 0);
$client_id   = (int)($_POST['client_id'] ?? 0);
$account_ref = trim($_POST['account_ref'] ?? ($_POST['reference'] ?? 'Payment'));

if (empty($phone) || $amount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Phone number and amount are required']);
    exit;
}

try {
    $tenant_id = $current_tenant_id;

    if ($client_id > 0) {
        $stmt = $pdo->prepare("SELECT tenant_id, account_number FROM clients WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$client_id, $current_tenant_id]);
        $clientRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$clientRow) {
            echo json_encode(['success' => false, 'message' => 'Invalid customer or access denied']);
            exit;
        }
        if ($account_ref === 'Payment') {
            // M-Pesa AccountReference max = 12 characters
            $acctNo = $clientRow['account_number'] ?? '';
            $account_ref = !empty($acctNo)
                ? substr($acctNo, 0, 12)
                : ('C' . str_pad($client_id, 4, '0', STR_PAD_LEFT));
        } else {
            $account_ref = substr($account_ref, 0, 12);
        }
    } else {
        $account_ref = substr($account_ref, 0, 12);
    }

    // Check whether this tenant has their own active M-Pesa API gateway
    $gwCheck = $pdo->prepare(
        "SELECT credentials FROM payment_gateways
         WHERE tenant_id = ? AND gateway_type = 'mpesa_api' AND is_active = 1
         ORDER BY is_default DESC LIMIT 1"
    );
    $gwCheck->execute([$tenant_id]);
    $tenantGw = $gwCheck->fetch(PDO::FETCH_ASSOC);
    $hasTenantCreds = false;
    if ($tenantGw) {
        // Credentials are stored as an AES blob. A raw json_decode() reads that
        // as NULL, so a tenant with a perfectly good gateway looked
        // unconfigured and their customers' payments were pushed through — and
        // collected by — FortuNett's shared paybill instead of their own.
        // decrypt_gateway_credentials() also passes legacy plain-JSON rows
        // through unchanged.
        $gwCreds = decrypt_gateway_credentials((string)($tenantGw['credentials'] ?? ''));
        $hasTenantCreds = !empty($gwCreds['consumer_key'])
                       && !empty($gwCreds['consumer_secret'])
                       && !empty($gwCreds['passkey'])
                       && !empty($gwCreds['shortcode']);
    }

    // Initialize M-Pesa: use tenant's own credentials if available, else platform fallback
    $mpesa         = new MpesaAPI($pdo, $hasTenantCreds ? $tenant_id : null);
    $usingPlatform = false;

    if (!$hasTenantCreds) {
        // No tenant-specific gateway — load platform (FortuNett shared) credentials
        try {
            $plStmt = $pdo->query(
                "SELECT consumer_key, consumer_secret, passkey, shortcode, shortcode_type, store_number, environment, callback_url
                 FROM platform_mpesa_config LIMIT 1"
            );
            $platCreds = $plStmt ? $plStmt->fetch(PDO::FETCH_ASSOC) : null;
            if ($platCreds && !empty($platCreds['consumer_key'])) {
                if (empty($platCreds['callback_url'])) {
                    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $host   = explode(':', $_SERVER['HTTP_HOST'] ?? 'localhost')[0];
                    $platCreds['callback_url'] = $scheme . '://' . $host . '/api/payment/callback.php';
                }
                $mpesa->loadFromArray($platCreds);
                $usingPlatform = true;
            }
        } catch (Exception $pe) {
            // platform_mpesa_config table may not exist yet
        }
    }

    if (!$hasTenantCreds && !$usingPlatform) {
        echo json_encode([
            'success' => false,
            'message' => 'M-Pesa is not yet configured for this account. Either add your own API credentials in Settings → Payments, or ask your FortuNett admin to configure the platform M-Pesa account.'
        ]);
        exit;
    }

    if (!$mpesa->hasValidCredentials()) {
        echo json_encode([
            'success' => false,
            'message' => 'M-Pesa credentials are incomplete (missing passkey or shortcode). Go to Settings → Payments and complete your gateway setup.'
        ]);
        exit;
    }

    $isSandbox = $mpesa->getEnvironment() !== 'production';
    $response = $mpesa->stkPush($phone, $amount, $account_ref);

    // Log every STK push attempt
    $logDir = __DIR__ . '/../../logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    @file_put_contents(
        $logDir . '/stk_push_all.log',
        date('Y-m-d H:i:s') . " tenant={$tenant_id} env=" . $mpesa->getEnvironment()
            . " sc=" . $mpesa->getShortcode() . " phone={$phone} amount={$amount}"
            . " platform=" . ($usingPlatform ? 'yes' : 'no')
            . " callback=" . $mpesa->getLastCallbackUrl()
            . " response=" . json_encode($response) . "\n",
        FILE_APPEND | LOCK_EX
    );

    if ($response === null) {
        echo json_encode([
            'success' => false,
            'message' => 'M-Pesa API returned no response. Check: (1) your Consumer Key/Secret are correct, (2) the environment (sandbox/production) matches your credentials, (3) the server has internet access.'
        ]);
        exit;
    }

    $responseCode = $response->ResponseCode ?? $response->errorCode ?? null;

    if ($responseCode === '0' || $responseCode === 0) {

        $bill_id = (int)($_POST['bill_id'] ?? 0);

        if ($client_id > 0 || $bill_id > 0) {
            try {
                $txDesc = $client_id > 0 ? 'STK Push Initiated' : ('BILLING:' . $bill_id);
                $txStmt = $pdo->prepare(
                    "INSERT INTO mpesa_transactions
                     (client_id, tenant_id, phone_number, amount, merchant_request_id, checkout_request_id,
                      result_code, result_desc, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, 0, ?, NOW(), NOW())"
                );
                $txStmt->execute([
                    $client_id > 0 ? $client_id : null, $tenant_id, $phone, $amount,
                    $response->MerchantRequestID ?? 'N/A',
                    $response->CheckoutRequestID ?? 'N/A',
                    $txDesc
                ]);

                if ($client_id > 0) {
                    $collectionType = $usingPlatform ? 'platform' : 'direct';
                    try {
                        $payStmt = $pdo->prepare(
                            "INSERT INTO payments
                             (client_id, tenant_id, amount, payment_method, payment_date, transaction_id, status, collection_type)
                             VALUES (?, ?, ?, 'mpesa', NOW(), ?, 'pending', ?)"
                        );
                        $payStmt->execute([
                            $client_id, $tenant_id, $amount,
                            $response->CheckoutRequestID ?? ('STK-' . time()),
                            $collectionType
                        ]);
                    } catch (PDOException $colEx) {
                        $payStmt = $pdo->prepare(
                            "INSERT INTO payments
                             (client_id, tenant_id, amount, payment_method, payment_date, transaction_id, status)
                             VALUES (?, ?, ?, 'mpesa', NOW(), ?, 'pending')"
                        );
                        $payStmt->execute([
                            $client_id, $tenant_id, $amount,
                            $response->CheckoutRequestID ?? ('STK-' . time())
                        ]);
                    }
                }
            } catch (Exception $dbEx) {
                error_log("STK push DB error: " . $dbEx->getMessage());
            }
        }

        $custMsg = $response->CustomerMessage ?? 'Request accepted.';
        if ($isSandbox) {
            $custMsg = 'SANDBOX MODE: Request accepted but no real phone prompt is sent. Switch to Production in Settings → Payments.';
        }
        if ($usingPlatform) {
            $custMsg .= ' (via FortuNett shared paybill ' . $mpesa->getShortcode() . ')';
        }

        echo json_encode([
            'success'             => true,
            'sandbox'             => $isSandbox,
            'environment'         => $mpesa->getEnvironment(),
            'using_platform'      => $usingPlatform,
            'ResponseCode'        => '0',
            'CustomerMessage'     => $custMsg,
            'checkout_request_id' => $response->CheckoutRequestID ?? '',
            'shortcode'           => $mpesa->getShortcode(),
            'phone_sent'          => $phone,
        ]);

    } else {
        $msg = $response->errorMessage
            ?? $response->ResultDesc
            ?? $response->ResponseDescription
            ?? null;

        if (!$msg) {
            $msg = 'M-Pesa request failed (Code: ' . ($responseCode ?? 'unknown') . '). Check your credentials in Settings → Payments.';
        }

        $hints = [
            '400.002.02'   => ' — Invalid Consumer Key or Consumer Secret.',
            '404.001.04'   => ' — Shortcode not found or not registered for LNM Online.',
            '400.002.05'   => ' — Account Reference exceeds 12 characters.',
            '500.001.1001' => ' — Wrong passkey for this shortcode/environment.',
            '404.001.02'   => ' — Shortcode not enrolled for Lipa Na Mpesa Online.',
            '1032'         => ' — Request cancelled by user.',
            '1037'         => ' — STK Push request timed out.',
        ];
        $hint = $hints[(string)$responseCode] ?? '';

        $logDir = __DIR__ . '/../../logs';
        if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
        @file_put_contents(
            $logDir . '/stk_push_errors.log',
            date('Y-m-d H:i:s') . " tenant={$tenant_id} env=" . $mpesa->getEnvironment()
                . " code={$responseCode} msg=" . $msg . " phone={$phone} ref={$account_ref}\n",
            FILE_APPEND | LOCK_EX
        );

        echo json_encode([
            'success'         => false,
            'message'         => $msg . $hint,
            'response_code'   => $responseCode,
            'environment'     => $mpesa->getEnvironment(),
            'account_ref'     => $account_ref,
            'using_platform'  => $usingPlatform,
        ]);
    }

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
