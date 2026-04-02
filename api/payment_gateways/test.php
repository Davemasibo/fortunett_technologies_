<?php
/**
 * Test a payment gateway configuration
 * POST: gateway_id — attempts a real connectivity check
 */
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$uStmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$uStmt->execute([$user_id]);
$tenant_id = $uStmt->fetchColumn();

if (!$tenant_id) {
    echo json_encode(['success' => false, 'message' => 'Tenant context missing']);
    exit;
}

$gateway_id = (int)($_POST['gateway_id'] ?? 0);
if (!$gateway_id) {
    echo json_encode(['success' => false, 'message' => 'Gateway ID required']);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM payment_gateways WHERE id = ? AND tenant_id = ?");
$stmt->execute([$gateway_id, $tenant_id]);
$gateway = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$gateway) {
    echo json_encode(['success' => false, 'message' => 'Gateway not found']);
    exit;
}

$creds = json_decode($gateway['credentials'], true) ?? [];
$type  = $gateway['gateway_type'];

try {
    if ($type === 'mpesa_api') {
        // Test by requesting an access token from Safaricom Daraja
        $consumerKey    = $creds['consumer_key']    ?? '';
        $consumerSecret = $creds['consumer_secret'] ?? '';
        $env            = $creds['environment']     ?? 'sandbox';

        if (empty($consumerKey) || empty($consumerSecret)) {
            echo json_encode(['success' => false, 'message' => 'Consumer Key and Secret are required for M-Pesa API testing.']);
            exit;
        }

        $baseUrl = in_array($env, ['production', 'live'], true)
            ? 'https://api.safaricom.co.ke'
            : 'https://sandbox.safaricom.co.ke';

        $url  = $baseUrl . '/oauth/v1/generate?grant_type=client_credentials';
        $auth = base64_encode($consumerKey . ':' . $consumerSecret);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_HTTPHEADER     => ['Authorization: Basic ' . $auth],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            echo json_encode(['success' => false, 'message' => 'Network error: ' . $curlErr]);
            exit;
        }

        $resp = json_decode($raw);
        $envLabel = in_array($env, ['production', 'live'], true) ? 'Production' : 'Sandbox';
        if ($resp && isset($resp->access_token)) {
            echo json_encode([
                'success' => true,
                'message' => '✅ M-Pesa connection successful! Got access token from ' . $envLabel . '.',
                'detail'  => 'Environment: ' . $envLabel . ' | Shortcode: ' . ($creds['shortcode'] ?? 'not set')
            ]);
        } else {
            $errMsg = $resp->errorMessage ?? $resp->error_description ?? $resp->error ?? ('HTTP ' . $httpCode . ' — ' . substr($raw, 0, 200));
            echo json_encode(['success' => false, 'message' => 'M-Pesa rejected credentials (' . $envLabel . '): ' . $errMsg]);
        }

    } elseif ($type === 'paybill_no_api') {
        $paybill = $creds['paybill_number'] ?? '';
        if (empty($paybill)) {
            echo json_encode(['success' => false, 'message' => 'Paybill number is not set.']);
        } else {
            echo json_encode([
                'success' => true,
                'message' => '✅ Paybill ' . htmlspecialchars($paybill) . ' is configured correctly (no API validation needed for manual paybill).',
                'detail'  => isset($creds['use_generated_accounts']) && $creds['use_generated_accounts'] === '1'
                    ? 'Auto account numbers: enabled'
                    : 'Account number: ' . ($creds['account_number'] ?: 'not set')
            ]);
        }

    } elseif ($type === 'bank_account') {
        $bankName = $creds['bank_name'] ?? '';
        $acctNo   = $creds['account_number'] ?? '';
        if (empty($bankName) || empty($acctNo)) {
            echo json_encode(['success' => false, 'message' => 'Bank name and account number must be configured.']);
        } else {
            echo json_encode([
                'success' => true,
                'message' => '✅ Bank account configured: ' . htmlspecialchars($bankName),
                'detail'  => 'Account: ' . htmlspecialchars($acctNo)
            ]);
        }

    } elseif ($type === 'paypal') {
        $clientId = $creds['client_id'] ?? '';
        $mode     = $creds['mode'] ?? ($creds['environment'] ?? 'sandbox');
        if (empty($clientId)) {
            echo json_encode(['success' => false, 'message' => 'PayPal Client ID is not set.']);
        } else {
            echo json_encode([
                'success' => true,
                'message' => '✅ PayPal configured (Client ID present). Mode: ' . ucfirst($mode) . '.',
                'detail'  => 'Live API validation requires a sandbox transaction.'
            ]);
        }

    } else {
        echo json_encode(['success' => false, 'message' => 'No test available for gateway type: ' . $type]);
    }

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Test error: ' . $e->getMessage()]);
}
