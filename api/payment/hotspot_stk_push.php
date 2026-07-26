<?php
/**
 * Public M-Pesa STK Push for Hotspot Captive Portal
 * No admin session required — tenant identified from subdomain.
 * Used by the captive portal login.html for new customer registration + payment.
 *
 * Canonical path: /api/payment/hotspot_stk_push.php
 * (api/mpesa/ name rejected by Safaricom in callback URLs)
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

require_once __DIR__ . '/../../includes/db_master.php';
require_once __DIR__ . '/../../classes/MpesaAPI.php';
require_once __DIR__ . '/../../includes/account_number_generator.php';
require_once __DIR__ . '/../../includes/auto_provision.php';
require_once __DIR__ . '/../../includes/credential_helper.php';
require_once __DIR__ . '/../../includes/schema_guard.php';

// This endpoint writes clients.status='pending' and payments/mpesa_transactions
// status='pending'. On a strict-mode server missing the enum migration those
// writes throw 1265 "Data truncated" and the customer never gets the STK prompt.
// Widen the columns in place if the migration was never applied.
ensurePaymentStatusEnums($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'POST required']);
    exit;
}

$action     = trim($_POST['action'] ?? '');        // 'free', 'paid', or 'renew'
$phone      = trim($_POST['phone'] ?? '');
$fullName   = trim($_POST['full_name'] ?? '');
$password   = trim($_POST['password'] ?? '');
$packageId  = (int)($_POST['package_id'] ?? 0);
$macAddress = strtoupper(preg_replace('/[^a-fA-F0-9:]/', '', $_POST['mac_address'] ?? ''));
$tenantId   = (int)($_POST['tenant_id'] ?? 0);
// NOTE: no $amount is read from the request — both paid flows charge the price
// on the package row. See the 'paid' and 'renew' branches below.

if (!$tenantId) {
    // Fallback: detect from HTTP_HOST
    $host      = explode(':', $_SERVER['HTTP_HOST'] ?? '')[0];
    $hostParts = explode('.', $host);
    if (count($hostParts) >= 3 && $hostParts[0] !== 'www') {
        try {
            $tSt = $pdo->prepare("SELECT id FROM tenants WHERE subdomain = ? LIMIT 1");
            $tSt->execute([$hostParts[0]]);
            $tenantId = (int)($tSt->fetchColumn() ?: 0);
        } catch (Exception $_e) {}
    }
}

if (!$tenantId || !$packageId || !$phone) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
    exit;
}

// Captive portal sends phone-only; fall back gracefully
if (!$fullName) $fullName = 'Customer-' . substr(preg_replace('/\D/', '', $phone), -4);

// Format phone: 0712... → 254712...
$phone = preg_replace('/[^0-9]/', '', $phone);
if (substr($phone, 0, 1) === '0') $phone = '254' . substr($phone, 1);
if (strlen($phone) < 10) {
    echo json_encode(['success' => false, 'message' => 'Invalid phone number.']);
    exit;
}

try {
    // Verify package belongs to tenant
    $pkgSt = $pdo->prepare("SELECT * FROM packages WHERE id = ? AND tenant_id = ? AND status = 'active' LIMIT 1");
    $pkgSt->execute([$packageId, $tenantId]);
    $package = $pkgSt->fetch(PDO::FETCH_ASSOC);
    if (!$package) {
        echo json_encode(['success' => false, 'message' => 'Package not found.']);
        exit;
    }

    $isFree = (float)$package['price'] == 0;

    // The price on the package row decides whether this is free — never the
    // client's action parameter. Posting action=free with a paid package_id
    // must not hand out paid internet.
    if ($action === 'free' && !$isFree) {
        $action = 'paid';
    }

    // ── FREE PACKAGE FLOW ──────────────────────────────────────────────────────
    if ($isFree) {
        // Check MAC has not already used a free trial
        if ($macAddress) {
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS device_free_trials (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    tenant_id INT NOT NULL,
                    mac_address VARCHAR(20) NOT NULL,
                    client_id INT DEFAULT NULL,
                    used_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY tenant_mac (tenant_id, mac_address)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $chk = $pdo->prepare("SELECT 1 FROM device_free_trials WHERE tenant_id = ? AND mac_address = ? LIMIT 1");
                $chk->execute([$tenantId, $macAddress]);
                if ($chk->fetchColumn()) {
                    echo json_encode(['success' => false, 'message' => 'This device has already used the free trial.']);
                    exit;
                }
            } catch (Exception $_e) {}
        }

        // Check if phone already registered for this tenant
        $existSt = $pdo->prepare("SELECT id, mikrotik_username, mikrotik_password, status FROM clients WHERE tenant_id = ? AND phone = ? LIMIT 1");
        $existSt->execute([$tenantId, $phone]);
        $existClient = $existSt->fetch(PDO::FETCH_ASSOC);

        if ($existClient && $existClient['status'] === 'active') {
            echo json_encode(['success' => false, 'message' => 'Phone already registered. Please sign in instead.']);
            exit;
        }

        // Generate account and MikroTik credentials
        $gen           = new AccountNumberGenerator($pdo);
        $accountNumber = $gen->generateAccountNumber($tenantId);
        $mikUsername   = 'hs' . substr(preg_replace('/\D/', '', $phone), -8);
        $mikPassword   = bin2hex(random_bytes(4));
        $username      = $mikUsername;

        // Calculate expiry
        $val    = (int)($package['validity_value'] ?? 1);
        $unit   = $package['validity_unit'] ?? 'days';
        $unitMap = ['hours' => 'hour', 'days' => 'day', 'weeks' => 'week', 'months' => 'month'];
        $expiry = date('Y-m-d H:i:s', strtotime('+' . $val . ' ' . ($unitMap[$unit] ?? 'day')));

        if ($existClient) {
            // Reuse existing client record
            $clientId = (int)$existClient['id'];
            $pdo->prepare("UPDATE clients SET status = 'active', expiry_date = ?, mikrotik_username = COALESCE(NULLIF(mikrotik_username,''), ?), mikrotik_password = COALESCE(NULLIF(mikrotik_password,''), ?), package_id = ? WHERE id = ?"
            )->execute([$expiry, $mikUsername, $mikPassword, $packageId, $clientId]);
            $mikUsername = $existClient['mikrotik_username'] ?: $mikUsername;
            $mikPassword = $existClient['mikrotik_password'] ?: $mikPassword;
        } else {
            // Create new client
            $pdo->prepare("
                INSERT INTO clients
                    (tenant_id, full_name, name, phone, username, auth_password,
                     account_number, package_id, connection_type, status, expiry_date,
                     mikrotik_username, mikrotik_password)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'hotspot', 'active', ?, ?, ?)
            ")->execute([
                $tenantId, $fullName, $fullName, $phone, $username,
                password_hash($mikPassword, PASSWORD_BCRYPT),
                $accountNumber, $packageId, $expiry, $mikUsername, $mikPassword
            ]);
            $clientId = (int)$pdo->lastInsertId();
        }

        // Mark MAC as used
        if ($macAddress) {
            try {
                $pdo->prepare("INSERT IGNORE INTO device_free_trials (tenant_id, mac_address, client_id) VALUES (?, ?, ?)")
                    ->execute([$tenantId, $macAddress, $clientId]);
            } catch (Exception $_e) {}
        }

        // Provision on router
        autoProvisionClient($pdo, $clientId, $tenantId);

        // Create auto-login token for customer portal
        $portalToken = bin2hex(random_bytes(16));
        try {
            $pdo->prepare("INSERT INTO payment_auto_logins (client_id, login_token, expires_at, status) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE), 'pending')")
                ->execute([$clientId, $portalToken]);
        } catch (Exception $_e) { $portalToken = null; }

        echo json_encode([
            'success'      => true,
            'username'     => $mikUsername,
            'password'     => $mikPassword,
            'portal_token' => $portalToken,
        ]);
        exit;
    }

    // ── PAID PACKAGE FLOW ──────────────────────────────────────────────────────
    if ($action === 'paid') {
        // Price comes from the package row, never from the POST body — this
        // endpoint is public, so a client-supplied amount would let anyone pay
        // KES 1 for any plan.
        $amount = (float)$package['price'];
        if ($amount <= 0) {
            echo json_encode(['success' => false, 'message' => 'Package price is zero — use free registration.']);
            exit;
        }
        // Password is optional from the captive portal buy tab (phone-only flow).
        // Generate a secure random one when not supplied; stored hashed so
        // the customer can later set their own via the portal.
        if (strlen($password) < 6) {
            $password = bin2hex(random_bytes(6)); // 12-char random
        }

        // Check if phone already registered
        $existSt = $pdo->prepare("SELECT id, status, expiry_date, mikrotik_username, mikrotik_password FROM clients WHERE tenant_id = ? AND phone = ? LIMIT 1");
        $existSt->execute([$tenantId, $phone]);
        $existClient = $existSt->fetch(PDO::FETCH_ASSOC);

        $gen           = new AccountNumberGenerator($pdo);
        $accountNumber = $gen->generateAccountNumber($tenantId);
        $mikUsername   = 'hs' . substr(preg_replace('/\D/', '', $phone), -8);
        $mikPassword   = bin2hex(random_bytes(4));

        if ($existClient) {
            $clientId = (int)$existClient['id'];
            // Update password + package for renewal
            $pdo->prepare("UPDATE clients SET auth_password = ?, package_id = ?, mikrotik_username = COALESCE(NULLIF(mikrotik_username,''), ?), mikrotik_password = COALESCE(NULLIF(mikrotik_password,''), ?) WHERE id = ?"
            )->execute([password_hash($password, PASSWORD_BCRYPT), $packageId, $mikUsername, $mikPassword, $clientId]);
            $mikUsername = $existClient['mikrotik_username'] ?: $mikUsername;
            $mikPassword = $existClient['mikrotik_password'] ?: $mikPassword;
        } else {
            $pdo->prepare("
                INSERT INTO clients
                    (tenant_id, full_name, name, phone, username, auth_password,
                     account_number, package_id, connection_type, status,
                     mikrotik_username, mikrotik_password)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'hotspot', 'pending', ?, ?)
            ")->execute([
                $tenantId, $fullName, $fullName, $phone,
                'hs' . substr(preg_replace('/\D/', '', $phone), -8),
                password_hash($password, PASSWORD_BCRYPT),
                $accountNumber, $packageId, $mikUsername, $mikPassword
            ]);
            $clientId = (int)$pdo->lastInsertId();
        }

        // Determine M-Pesa credentials for this tenant
        $gwCheck = $pdo->prepare("SELECT credentials FROM payment_gateways WHERE tenant_id = ? AND gateway_type = 'mpesa_api' AND is_active = 1 ORDER BY is_default DESC LIMIT 1");
        $gwCheck->execute([$tenantId]);
        $tenantGw       = $gwCheck->fetch(PDO::FETCH_ASSOC);
        $hasTenantCreds = false;
        if ($tenantGw) {
            $gwCreds = decrypt_gateway_credentials($tenantGw['credentials']);
            $hasTenantCreds = !empty($gwCreds['consumer_key']) && !empty($gwCreds['consumer_secret'])
                           && !empty($gwCreds['passkey']) && !empty($gwCreds['shortcode']);
        }

        $mpesa         = new MpesaAPI($pdo, $hasTenantCreds ? $tenantId : null);
        $usingPlatform = false;

        if (!$hasTenantCreds) {
            try {
                $plSt = $pdo->query("SELECT * FROM platform_mpesa_config LIMIT 1");
                $platCreds = $plSt ? $plSt->fetch(PDO::FETCH_ASSOC) : null;
                if ($platCreds && !empty($platCreds['consumer_key'])) {
                    if (empty($platCreds['callback_url'])) {
                        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                        $platCreds['callback_url'] = $scheme . '://' . explode(':', $_SERVER['HTTP_HOST'] ?? 'localhost')[0] . '/api/payment/callback.php';
                    }
                    $mpesa->loadFromArray($platCreds);
                    $usingPlatform = true;
                }
            } catch (Exception $_e) {}
        }

        if (!$hasTenantCreds && !$usingPlatform) {
            echo json_encode(['success' => false, 'message' => 'M-Pesa is not configured for this ISP. Contact support.']);
            exit;
        }

        // Fetch client account_number for reference
        $acctSt = $pdo->prepare("SELECT account_number FROM clients WHERE id = ? LIMIT 1");
        $acctSt->execute([$clientId]);
        $acctNo = $acctSt->fetchColumn() ?: ('C' . str_pad($clientId, 4, '0', STR_PAD_LEFT));
        $accountRef = substr($acctNo, 0, 12);

        $response = $mpesa->stkPush($phone, $amount, $accountRef);

        if (!$response || ($response->ResponseCode ?? null) !== '0' && ($response->ResponseCode ?? null) !== 0) {
            $msg = $response->errorMessage ?? $response->ResultDesc ?? $response->ResponseDescription ?? 'STK push failed';
            echo json_encode(['success' => false, 'message' => $msg]);
            exit;
        }

        // Log the transaction linked to this client
        $checkoutId = $response->CheckoutRequestID ?? ('STK-' . time());
        try {
            $pdo->prepare("INSERT INTO mpesa_transactions
                (client_id, tenant_id, phone_number, amount, merchant_request_id, checkout_request_id, status, result_code, result_desc, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, 'pending', NULL, 'Hotspot STK Push', NOW(), NOW())"
            )->execute([$clientId, $tenantId, $phone, $amount, $response->MerchantRequestID ?? 'N/A', $checkoutId]);
        } catch (Exception $_e) {}

        try {
            $pdo->prepare("INSERT INTO payments (client_id, tenant_id, amount, payment_method, payment_date, transaction_id, status) VALUES (?, ?, ?, 'mpesa', NOW(), ?, 'pending')")
                ->execute([$clientId, $tenantId, $amount, $checkoutId]);
        } catch (Exception $_e) {}

        echo json_encode([
            'success'             => true,
            'checkout_request_id' => $checkoutId,
            'client_id'           => $clientId,
        ]);
        exit;
    }

    // ── RENEWAL FLOW ───────────────────────────────────────────────────────────
    if ($action === 'renew') {
        $accountOrPhone = trim($_POST['account'] ?? $phone);
        if (!$accountOrPhone) {
            echo json_encode(['success' => false, 'message' => 'Account number or phone required.']);
            exit;
        }
        // Find client
        $clSt = $pdo->prepare("SELECT id, phone, account_number, mikrotik_username, mikrotik_password FROM clients WHERE tenant_id = ? AND (account_number = ? OR phone = ?) LIMIT 1");
        $clSt->execute([$tenantId, $accountOrPhone, $accountOrPhone]);
        $renewClient = $clSt->fetch(PDO::FETCH_ASSOC);
        if (!$renewClient) {
            echo json_encode(['success' => false, 'message' => 'Account not found.']);
            exit;
        }
        $clientId = (int)$renewClient['id'];
        $amount   = (float)$package['price'];   // same rule as the paid flow
        if ($amount <= 0) {
            echo json_encode(['success' => false, 'message' => 'Selected plan has no price set.']);
            exit;
        }
        $renewPhone = preg_replace('/[^0-9]/', '', $renewClient['phone'] ?: $phone);
        if (substr($renewPhone, 0, 1) === '0') $renewPhone = '254' . substr($renewPhone, 1);
        if (!$renewPhone || strlen($renewPhone) < 10) {
            echo json_encode(['success' => false, 'message' => 'No phone number on this account.']);
            exit;
        }

        // Update package_id to renew with selected package
        $pdo->prepare("UPDATE clients SET package_id = ? WHERE id = ?")->execute([$packageId, $clientId]);

        // Determine M-Pesa credentials
        $gwCheck = $pdo->prepare("SELECT credentials FROM payment_gateways WHERE tenant_id = ? AND gateway_type = 'mpesa_api' AND is_active = 1 ORDER BY is_default DESC LIMIT 1");
        $gwCheck->execute([$tenantId]);
        $tenantGw       = $gwCheck->fetch(PDO::FETCH_ASSOC);
        $hasTenantCreds = false;
        if ($tenantGw) {
            $gwCreds = decrypt_gateway_credentials($tenantGw['credentials']);
            $hasTenantCreds = !empty($gwCreds['consumer_key']) && !empty($gwCreds['consumer_secret'])
                           && !empty($gwCreds['passkey']) && !empty($gwCreds['shortcode']);
        }
        $mpesa = new MpesaAPI($pdo, $hasTenantCreds ? $tenantId : null);
        $usingPlatform = false;
        if (!$hasTenantCreds) {
            try {
                $plSt = $pdo->query("SELECT * FROM platform_mpesa_config LIMIT 1");
                $platCreds = $plSt ? $plSt->fetch(PDO::FETCH_ASSOC) : null;
                if ($platCreds && !empty($platCreds['consumer_key'])) {
                    if (empty($platCreds['callback_url'])) {
                        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                        $platCreds['callback_url'] = $scheme . '://' . explode(':', $_SERVER['HTTP_HOST'] ?? 'localhost')[0] . '/api/payment/callback.php';
                    }
                    $mpesa->loadFromArray($platCreds);
                    $usingPlatform = true;
                }
            } catch (Exception $_e) {}
        }
        if (!$hasTenantCreds && !$usingPlatform) {
            echo json_encode(['success' => false, 'message' => 'M-Pesa is not configured for this ISP. Contact support.']);
            exit;
        }

        $acctNo = $renewClient['account_number'] ?: ('C' . str_pad($clientId, 4, '0', STR_PAD_LEFT));
        $response = $mpesa->stkPush($renewPhone, $amount, substr($acctNo, 0, 12));
        if (!$response || (($response->ResponseCode ?? null) !== '0' && ($response->ResponseCode ?? null) !== 0)) {
            $msg = $response->errorMessage ?? $response->ResultDesc ?? $response->ResponseDescription ?? 'STK push failed';
            echo json_encode(['success' => false, 'message' => $msg]);
            exit;
        }
        $checkoutId = $response->CheckoutRequestID ?? ('STK-' . time());
        try {
            $pdo->prepare("INSERT INTO mpesa_transactions (client_id, tenant_id, phone_number, amount, merchant_request_id, checkout_request_id, status, result_code, result_desc, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 'pending', NULL, 'Renewal STK Push', NOW(), NOW())")
                ->execute([$clientId, $tenantId, $renewPhone, $amount, $response->MerchantRequestID ?? 'N/A', $checkoutId]);
        } catch (Exception $_e) {}
        try {
            $pdo->prepare("INSERT INTO payments (client_id, tenant_id, amount, payment_method, payment_date, transaction_id, status) VALUES (?, ?, ?, 'mpesa', NOW(), ?, 'pending')")
                ->execute([$clientId, $tenantId, $amount, $checkoutId]);
        } catch (Exception $_e) {}

        echo json_encode(['success' => true, 'checkout_request_id' => $checkoutId, 'client_id' => $clientId]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action.']);

} catch (Throwable $e) {
    error_log('[hotspot_stk_push] ' . $e->getMessage());
    // Surface the real reason (M-Pesa token failure, DB error, etc.) instead of a
    // generic "server error" — the portal alert()s this message, and operators need
    // the actual cause to diagnose. stkPush() throws here when Safaricom credentials
    // are invalid or the access token can't be fetched.
    echo json_encode([
        'success' => false,
        'message' => 'Payment could not be initiated: ' . $e->getMessage(),
    ]);
}
