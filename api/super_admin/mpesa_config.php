<?php
/**
 * GET  /api/super_admin/mpesa_config.php         — fetch current platform credentials
 * POST /api/super_admin/mpesa_config.php action=save — save/update credentials
 * POST /api/super_admin/mpesa_config.php action=test  — test access-token fetch
 */
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../super_admin/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();
superAdminGuard();

// Ensure platform_mpesa_config table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS platform_mpesa_config (
        id            INT NOT NULL DEFAULT 1,
        consumer_key  VARCHAR(255) DEFAULT '',
        consumer_secret VARCHAR(255) DEFAULT '',
        passkey       VARCHAR(255) DEFAULT '',
        shortcode     VARCHAR(20)  DEFAULT '',
        shortcode_type ENUM('paybill','till') DEFAULT 'paybill',
        store_number  VARCHAR(20)  DEFAULT '',
        environment   ENUM('sandbox','live','production') DEFAULT 'sandbox',
        callback_url  VARCHAR(512) DEFAULT '',
        c2b_validation_url VARCHAR(512) DEFAULT '',
        c2b_confirmation_url VARCHAR(512) DEFAULT '',
        notes         TEXT DEFAULT NULL,
        updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Ensure a default row exists
    $pdo->exec("INSERT IGNORE INTO platform_mpesa_config (id) VALUES (1)");
} catch (Exception $e) {
    // Ignore if already exists with different schema
}

// Also ensure payments.collection_type column exists
try {
    $pdo->exec("ALTER TABLE payments ADD COLUMN collection_type ENUM('direct','platform') NOT NULL DEFAULT 'direct'");
} catch (Exception $e) {} // Already exists — fine

$action = $_POST['action'] ?? ($_GET['action'] ?? 'get');

// ── GET ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' || $action === 'get') {
    $row = $pdo->query("SELECT * FROM platform_mpesa_config WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    if (!$row) $row = ['id'=>1,'consumer_key'=>'','consumer_secret'=>'','passkey'=>'','shortcode'=>'','shortcode_type'=>'paybill','environment'=>'sandbox','callback_url'=>'','notes'=>''];
    // Mask secret fields for display
    $masked = $row;
    if (!empty($masked['consumer_secret'])) $masked['consumer_secret'] = str_repeat('*', 8) . substr($masked['consumer_secret'], -4);
    if (!empty($masked['passkey']))         $masked['passkey']         = str_repeat('*', 8) . substr($masked['passkey'], -4);
    echo json_encode(['success'=>true,'config'=>$masked]);
    exit;
}

// ── SAVE ─────────────────────────────────────────────────────────────────────
if ($action === 'save') {
    $fields = ['consumer_key','consumer_secret','passkey','shortcode','shortcode_type','store_number','environment','callback_url','c2b_validation_url','c2b_confirmation_url','notes'];
    $updates = [];
    $params  = [];
    foreach ($fields as $f) {
        if (isset($_POST[$f])) {
            $val = trim($_POST[$f]);
            // Don't overwrite masked values (all-stars from GET display)
            if (preg_match('/^\*+[^*]*$/', $val)) continue;
            // Don't clear sensitive fields if submitted as blank (preserve existing)
            if ($val === '' && in_array($f, ['consumer_secret', 'passkey'], true)) continue;
            $updates[] = "$f = ?";
            $params[]  = $val;
        }
    }
    if (empty($updates)) {
        echo json_encode(['success'=>false,'message'=>'Nothing to update']);
        exit;
    }
    $params[] = 1; // WHERE id=1
    $pdo->prepare("UPDATE platform_mpesa_config SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);
    echo json_encode(['success'=>true,'message'=>'Platform M-Pesa credentials saved successfully.']);
    exit;
}

// ── TEST ─────────────────────────────────────────────────────────────────────
if ($action === 'test') {
    $row = $pdo->query("SELECT * FROM platform_mpesa_config WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['consumer_key'])) {
        echo json_encode(['success'=>false,'message'=>'No platform credentials configured yet. Save credentials first.']);
        exit;
    }

    $warnings = [];

    // Check for missing STK-critical fields
    if (empty($row['passkey'])) {
        $warnings[] = '⚠️ Passkey is NOT saved — STK Push will fail even if token succeeds.';
    }
    if (empty($row['shortcode'])) {
        $warnings[] = '⚠️ Shortcode is not set.';
    }

    // Warn if callback URL looks like localhost (Safaricom cannot reach it)
    $cbUrl = $row['callback_url'] ?? '';
    if (!empty($cbUrl) && preg_match('/https?:\/\/(localhost|127\.\d+\.\d+)/i', $cbUrl)) {
        $warnings[] = '⚠️ Callback URL points to localhost — Safaricom cannot reach it. Update to your public HTTPS URL.';
    }

    $env = strtolower($row['environment'] ?? 'sandbox');
    $isProduction = in_array($env, ['production', 'live'], true);
    $baseUrl = $isProduction ? 'https://api.safaricom.co.ke' : 'https://sandbox.safaricom.co.ke';

    // Step 1: OAuth token test
    $url  = $baseUrl . '/oauth/v1/generate?grant_type=client_credentials';
    $auth = base64_encode($row['consumer_key'] . ':' . $row['consumer_secret']);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_HTTPHEADER     => ['Authorization: Basic ' . $auth],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $raw      = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        echo json_encode(['success'=>false,'message'=>'Network error: ' . $curlErr]);
        exit;
    }

    $resp = json_decode($raw);
    if (!$resp || empty($resp->access_token)) {
        $sfError = $resp->errorMessage ?? $resp->error_description ?? $resp->error ?? null;
        $detail  = $sfError ? $sfError : ('HTTP ' . $httpCode . ' — ' . substr($raw, 0, 200));
        echo json_encode([
            'success' => false,
            'message' => '❌ Safaricom rejected the credentials: ' . $detail .
                         ' | Environment: ' . ($isProduction ? 'Production' : 'Sandbox')
        ]);
        exit;
    }

    $token = $resp->access_token;
    $shortcodeLabel = !empty($row['shortcode']) ? ' | Shortcode: ' . $row['shortcode'] : '';

    // Step 2: STK push query with a dummy ID to validate passkey + shortcode
    // A valid passkey/shortcode returns "The transaction BillRefNumber is invalid" (error),
    // not a credentials error — which proves the LNM configuration is correct.
    $stkQueryResult = '';
    if (!empty($row['passkey']) && !empty($row['shortcode'])) {
        // For Till: BusinessShortCode = store_number (head-office); for Paybill: same as shortcode
        $validateBSC = (($row['shortcode_type'] ?? 'paybill') === 'till' && !empty($row['store_number']))
            ? $row['store_number']
            : $row['shortcode'];
        $timestamp = date('YmdHis');
        $password  = base64_encode($validateBSC . $row['passkey'] . $timestamp);
        $queryUrl  = $baseUrl . '/mpesa/stkpushquery/v1/query';
        $qBody     = json_encode([
            'BusinessShortCode' => $validateBSC,
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'CheckoutRequestID' => 'ws_CO_test_' . time(),
        ]);
        $qch = curl_init();
        curl_setopt_array($qch, [
            CURLOPT_URL            => $queryUrl,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $qBody,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $qRaw = curl_exec($qch);
        curl_close($qch);
        $qResp = json_decode($qRaw);

        // 500.001.1001 + "not Exist/not found" = Safaricom authenticated the request,
        // passkey is CORRECT — transaction not found is expected with a fake ID.
        // ResultCode "17" = same meaning in older response format.
        // Auth errors (400.002.02, 404.001.xx) = wrong credentials.
        $qCode    = $qResp->ResultCode ?? null;
        $qErrCode = (string)($qResp->errorCode ?? '');
        $qMsg     = (string)($qResp->errorMessage ?? $qResp->ResultDesc ?? $qRaw);

        $credentialsOk = false;
        if ($qCode === '17' || $qCode === 17) {
            $credentialsOk = true;
        } elseif ($qErrCode === '500.001.1001') {
            // Safaricom processed the request (passkey accepted) but the fake
            // CheckoutRequestID doesn't exist — this is the expected response.
            $credentialsOk = true;
        }

        if ($credentialsOk) {
            $stkQueryResult = ' ✅ Passkey accepted — credentials confirmed.';
        } elseif (!empty($qErrCode)) {
            $hint = '';
            if (strpos($qErrCode, '400.002') !== false || strpos($qErrCode, '401') !== false) {
                $hint = ' → Invalid Consumer Key, Secret, or Passkey.';
            } elseif (strpos($qErrCode, '404.001') !== false) {
                $hint = ' → Shortcode not found or not enrolled for Lipa Na M-Pesa Online.';
            }
            $warnings[] = '❌ LNM validation failed (' . $qErrCode . '): ' . substr($qMsg, 0, 100) . $hint;
        }
    }

    $warnText = !empty($warnings) ? ' | WARNINGS: ' . implode(' ', $warnings) : '';
    $envLabel = $isProduction ? 'Production' : 'Sandbox';

    echo json_encode([
        'success' => true,
        'message' => '✅ OAuth token OK (' . $envLabel . ')' . $shortcodeLabel . $stkQueryResult . $warnText
    ]);
    exit;
}

// ── READ STK LOGS ────────────────────────────────────────────────────────────
if ($action === 'stk_log') {
    $which   = $_POST['file'] ?? 'errors';
    $logFile = $which === 'all'
        ? __DIR__ . '/../../logs/stk_push_all.log'
        : __DIR__ . '/../../logs/stk_push_errors.log';

    if (!file_exists($logFile)) {
        $hint = $which === 'all'
            ? 'No attempts logged yet. Trigger a payment from a tenant portal, then refresh.'
            : 'No errors logged. If Safaricom returns code 0 the success log ("All Attempts") will show the response.';
        echo json_encode(['success'=>true,'lines'=>$hint]);
        exit;
    }
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $last  = array_slice($lines, -60);
    echo json_encode(['success'=>true,'lines'=>implode("\n", array_reverse($last))]);
    exit;
}

// ── READ CALLBACK LOGS ───────────────────────────────────────────────────────
if ($action === 'callback_log') {
    $logFile = __DIR__ . '/../../logs/mpesa_callbacks.log';
    if (!file_exists($logFile)) {
        echo json_encode(['success'=>true,'lines'=>"No callbacks received yet.\n\nThis means Safaricom has not called back your server.\nCheck the callback URL shown above — it must be a public HTTPS URL reachable from the internet."]);
        exit;
    }
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $last  = array_slice($lines, -80);
    echo json_encode(['success'=>true,'lines'=>implode("\n", array_reverse($last)), 'total'=>count($lines)]);
    exit;
}

// ── TEST STK PUSH (super admin direct) ───────────────────────────────────────
if ($action === 'test_stk') {
    $phone  = trim($_POST['phone']  ?? '');
    $amount = (int)($_POST['amount'] ?? 1);

    if (empty($phone) || $amount < 1) {
        echo json_encode(['success'=>false,'message'=>'Phone and amount required.']);
        exit;
    }

    $row = $pdo->query("SELECT * FROM platform_mpesa_config WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['consumer_key']) || empty($row['passkey']) || empty($row['shortcode'])) {
        echo json_encode(['success'=>false,'message'=>'Platform credentials incomplete. Save Consumer Key, Consumer Secret, Passkey and Shortcode first.']);
        exit;
    }

    require_once '../../classes/MpesaAPI.php';
    $mpesa = new MpesaAPI($pdo, null); // start blank (no tenant)
    $mpesa->loadFromArray($row);

    // Determine callback URL for this test push
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = explode(':', $_SERVER['HTTP_HOST'] ?? 'localhost')[0];
    $autoCallback = $scheme . '://' . $host . '/api/payment/callback.php';
    if (empty($row['callback_url']) || preg_match('/https?:\/\/(localhost|127\.)/i', $row['callback_url'])) {
        $testRow = $row;
        $testRow['callback_url'] = $autoCallback;
        $mpesa->loadFromArray($testRow);
    }

    if (!$mpesa->hasValidCredentials()) {
        echo json_encode(['success'=>false,'message'=>'Credentials are incomplete after loading. Check passkey and shortcode.']);
        exit;
    }

    try {
        $response = $mpesa->stkPush($phone, $amount, 'SATEST');

        $logDir = __DIR__ . '/../../logs';
        if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
        @file_put_contents(
            $logDir . '/stk_push_all.log',
            date('Y-m-d H:i:s') . ' tenant=SUPERADMIN env=' . $mpesa->getEnvironment()
                . ' sc=' . $mpesa->getShortcode() . ' phone=' . $phone . ' amount=' . $amount
                . ' callback=' . $mpesa->getLastCallbackUrl()
                . ' response=' . json_encode($response) . "\n",
            FILE_APPEND | LOCK_EX
        );

        if ($response === null) {
            echo json_encode(['success'=>false,'message'=>'No response from Safaricom API. Check server internet access.']);
            exit;
        }

        $payload = $mpesa->getLastPayload();
        $rc = $response->ResponseCode ?? $response->errorCode ?? null;
        if ($rc === '0' || $rc === 0) {
            echo json_encode([
                'success'             => true,
                'checkout_request_id' => $response->CheckoutRequestID ?? '',
                'shortcode'           => $mpesa->getShortcode(),
                'callback_url'        => $mpesa->getLastCallbackUrl(),
                'environment'         => $mpesa->getEnvironment(),
                'request_body'        => $payload,
            ]);
        } else {
            $msg = $response->errorMessage ?? $response->ResultDesc ?? $response->ResponseDescription ?? 'Unknown error';
            echo json_encode([
                'success'      => false,
                'message'      => "Safaricom returned code $rc: $msg",
                'request_body' => $payload,
                'raw_response' => $response,
            ]);
        }
    } catch (Exception $e) {
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

// ── QUERY STK PUSH STATUS ────────────────────────────────────────────────────
if ($action === 'stk_query') {
    $checkoutId = trim($_POST['checkout_id'] ?? '');
    if (empty($checkoutId)) {
        echo json_encode(['success'=>false,'message'=>'CheckoutRequestID is required']);
        exit;
    }

    $row = $pdo->query("SELECT * FROM platform_mpesa_config WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['consumer_key'])) {
        echo json_encode(['success'=>false,'message'=>'No platform credentials configured.']);
        exit;
    }

    $env = strtolower($row['environment'] ?? 'sandbox');
    $isProduction = in_array($env, ['production', 'live'], true);
    $baseUrl = $isProduction ? 'https://api.safaricom.co.ke' : 'https://sandbox.safaricom.co.ke';

    // Get access token
    $auth = base64_encode($row['consumer_key'] . ':' . $row['consumer_secret']);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $baseUrl . '/oauth/v1/generate?grant_type=client_credentials',
        CURLOPT_HTTPHEADER     => ['Authorization: Basic ' . $auth],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err || empty(json_decode($raw)->access_token)) {
        echo json_encode(['success'=>false,'message'=>'Could not get access token: ' . ($err ?: substr($raw, 0, 200))]);
        exit;
    }
    $token = json_decode($raw)->access_token;

    // STK push query — for Till, BusinessShortCode must be the store/head-office number
    $queryBSC = (($row['shortcode_type'] ?? 'paybill') === 'till' && !empty($row['store_number']))
        ? $row['store_number']
        : $row['shortcode'];
    $timestamp = date('YmdHis');
    $password  = base64_encode($queryBSC . $row['passkey'] . $timestamp);
    $body = json_encode([
        'BusinessShortCode' => $queryBSC,
        'Password'          => $password,
        'Timestamp'         => $timestamp,
        'CheckoutRequestID' => $checkoutId,
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $baseUrl . '/mpesa/stkpushquery/v1/query',
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $qRaw = curl_exec($ch);
    $qErr = curl_error($ch);
    curl_close($ch);

    if ($qErr) {
        echo json_encode(['success'=>false,'message'=>'cURL error: ' . $qErr]);
        exit;
    }

    $q = json_decode($qRaw);
    $rc   = $q->ResultCode    ?? $q->errorCode        ?? '?';
    $rdesc = $q->ResultDesc   ?? $q->errorMessage     ?? 'No description';

    // Decode result codes
    $meanings = [
        '0'    => 'SUCCESS — payment was completed by user.',
        '1'    => 'INSUFFICIENT FUNDS — user did not have enough balance.',
        '17'   => 'TRANSACTION NOT FOUND — Safaricom has no record of this CheckoutRequestID. The STK prompt likely never reached the phone, possibly due to a wrong passkey or shortcode misconfiguration.',
        '1032' => 'CANCELLED — user dismissed the STK prompt.',
        '1037' => 'TIMEOUT — prompt appeared but user did not respond.',
        '2001' => 'WRONG PIN — user entered wrong M-Pesa PIN.',
        '1001' => 'Unable to lock subscriber — phone is busy.',
        '2029' => "SAFARICOM INTERNAL ERROR — Your passkey is correct and Safaricom received the request, but their backend failed to deliver the STK prompt to the phone.\n\nThis is NOT a code issue. It means shortcode 4524255 is not fully provisioned for Lipa Na M-Pesa Online (STK Push) on Safaricom's side.\n\nAction required: Call Safaricom Business Support and say:\n  \"My STK push returns ResultCode 2029 — Failed due to an unresolved reason type.\n   Shortcode: 4524255. The API accepts the request (ResponseCode 0) but the phone\n   prompt never appears and the query returns 2029. Please check that the shortcode\n   is fully linked to Lipa Na M-Pesa Online on your backend.\"\n\nThis usually means they enabled the Daraja app but did not complete the shortcode provisioning step.",
    ];
    $meaning = $meanings[(string)$rc] ?? null;

    $decoded = "ResultCode: $rc\nResultDesc: $rdesc";
    if ($meaning) $decoded .= "\n\nMeaning: $meaning";
    if (!empty($q->errorCode)) $decoded .= "\n\nFull response: " . json_encode($q, JSON_PRETTY_PRINT);

    echo json_encode(['success'=>true,'message'=>$decoded,'result_code'=>(string)$rc,'raw'=>$qRaw]);
    exit;
}

// ── CHECK CALLBACK URL REACHABILITY ─────────────────────────────────────────
if ($action === 'check_callback') {
    $url = trim($_POST['url'] ?? '');
    if (empty($url)) {
        echo json_encode(['success'=>false,'message'=>'No URL provided']);
        exit;
    }
    // Do a GET request — callback.php responds with a JSON health-check for non-POST
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        echo json_encode(['success'=>false,'message'=>"Connection failed: $err — URL is not publicly reachable."]);
        exit;
    }
    $json = json_decode($raw, true);
    if ($code === 200 && isset($json['result'])) {
        echo json_encode(['success'=>true,'message'=>"✅ URL reachable (HTTP $code). Response: " . ($json['message'] ?? $raw)]);
    } else {
        echo json_encode(['success'=>false,'message'=>"⚠️ URL returned HTTP $code. Response: " . substr($raw, 0, 200)]);
    }
    exit;
}

echo json_encode(['success'=>false,'message'=>'Unknown action']);
