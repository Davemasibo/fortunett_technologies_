<?php
/**
 * M-Pesa API Helper Class
 */
class MpesaAPI {
    private $consumer_key;
    private $consumer_secret;
    private $passkey;
    private $shortcode;
    private $store_number   = ''; // For Buy Goods: BusinessShortCode (store/head-office). Falls back to $shortcode if empty.
    private $shortcode_type = 'paybill'; // 'paybill' or 'till'
    private $env;
    private $base_url;
    
    private $pdo;
    private $tenant_id;
    private $last_error   = '';
    private $last_payload = null; // full request body sent to Safaricom (for debugging)

    public function __construct($pdo = null, $tenant_id = null) {
        require_once __DIR__ . '/../config/mpesa.php';
        require_once __DIR__ . '/../includes/credential_helper.php';
        
        $this->pdo = $pdo;
        $this->tenant_id = $tenant_id;
        
        // Default to globals (Single Tenant Mode)
        $this->consumer_key = defined('MPESA_CONSUMER_KEY') ? MPESA_CONSUMER_KEY : '';
        $this->consumer_secret = defined('MPESA_CONSUMER_SECRET') ? MPESA_CONSUMER_SECRET : '';
        $this->passkey = defined('MPESA_PASSKEY') ? MPESA_PASSKEY : '';
        $this->shortcode = defined('MPESA_SHORTCODE') ? MPESA_SHORTCODE : '';
        $this->env = $this->normalizeEnv(defined('MPESA_ENV') ? MPESA_ENV : 'sandbox');

        // Multi-Tenant Override
        if ($this->pdo && $this->tenant_id) {
            $this->loadTenantCredentials();
        }

        $this->base_url = ($this->env === 'production') ? 'https://api.safaricom.co.ke' : 'https://sandbox.safaricom.co.ke';
    }

    // Callback URL — set in constructor based on tenant credentials or auto-detected
    private $callback_url;
    private $resolved_callback_url = '';

    private function loadTenantCredentials() {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM payment_gateways
                 WHERE tenant_id = ? AND gateway_type = 'mpesa_api' AND is_active = 1
                 ORDER BY is_default DESC LIMIT 1"
            );
            $stmt->execute([$this->tenant_id]);
            $gateway = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($gateway && !empty($gateway['credentials'])) {
                $creds = decrypt_gateway_credentials($gateway['credentials']);
                if (!empty($creds)) {
                    $this->consumer_key    = $creds['consumer_key']    ?? $this->consumer_key;
                    $this->consumer_secret = $creds['consumer_secret'] ?? $this->consumer_secret;
                    $this->passkey         = $creds['passkey']         ?? $this->passkey;
                    $this->shortcode       = $creds['shortcode']       ?? $this->shortcode;
                    $this->shortcode_type  = $creds['shortcode_type']  ?? $this->shortcode_type;
                    $this->store_number    = $creds['store_number']    ?? $this->store_number;
                    $this->env             = $this->normalizeEnv($creds['environment'] ?? ($creds['env'] ?? $this->env));
                    // Per-tenant callback URL (overrides global config)
                    if (!empty($creds['callback_url'])) {
                        $this->callback_url = rtrim($creds['callback_url'], '/');
                    }
                    $this->base_url = ($this->env === 'production')
                        ? 'https://api.safaricom.co.ke'
                        : 'https://sandbox.safaricom.co.ke';
                }
            }
        } catch (Exception $e) {
            error_log("MpesaAPI loadTenantCredentials error: " . $e->getMessage());
        }
    }

    /** Build the callback URL, using tenant override → global config → auto-detect */
    private function resolveCallbackUrl(): string {
        if (!empty($this->callback_url) && !$this->isLocalUrl($this->callback_url)) {
            $this->resolved_callback_url = $this->callback_url;
            return $this->callback_url;
        }
        if (defined('MPESA_CALLBACK_URL') && !empty(MPESA_CALLBACK_URL) && !$this->isLocalUrl(MPESA_CALLBACK_URL)) {
            $this->resolved_callback_url = MPESA_CALLBACK_URL;
            return MPESA_CALLBACK_URL;
        }
        // Auto-detect from current server host
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $url = $scheme . '://' . $host . '/api/payment/callback.php';
        $this->resolved_callback_url = $url;
        return $url;
    }

    /** Return the callback URL that was sent to Safaricom in the last stkPush call */
    public function getLastCallbackUrl(): string { return $this->resolved_callback_url; }

    /** Returns true if the URL points to localhost/127.x (Safaricom cannot reach it) */
    private function isLocalUrl(string $url): bool {
        return (bool) preg_match('/https?:\/\/(localhost|127\.\d+\.\d+\.\d+|::1)/i', $url);
    }

    /** Normalize environment string — 'live' and 'production' both mean production */
    private function normalizeEnv(string $env): string {
        return in_array(strtolower($env), ['production', 'live'], true) ? 'production' : 'sandbox';
    }

    /** Return the active environment (sandbox/production) */
    public function getEnvironment(): string { return $this->env; }
    /** Returns the full JSON body sent in the last stkPush call (for sharing with Safaricom support) */
    public function getLastPayload(): ?array { return $this->last_payload; }

    /** Return the last error detail from Safaricom (populated after a failed getAccessToken call) */
    public function getLastError(): string { return $this->last_error; }

    /** Return current shortcode for display */
    public function getShortcode(): string { return $this->shortcode; }

    /** Check whether all required credentials are present */
    public function hasValidCredentials(): bool {
        return !empty($this->consumer_key)
            && !empty($this->consumer_secret)
            && !empty($this->passkey)
            && !empty($this->shortcode);
    }

    /**
     * Override credentials from a plain array (used for platform-level fallback).
     * Call this AFTER construction to switch to a different credential set.
     */
    public function loadFromArray(array $creds): void {
        // Only override if the incoming value is non-empty (don't blank out existing credentials)
        if (!empty($creds['consumer_key']))    $this->consumer_key    = $creds['consumer_key'];
        if (!empty($creds['consumer_secret'])) $this->consumer_secret = $creds['consumer_secret'];
        if (!empty($creds['passkey']))         $this->passkey         = $creds['passkey'];
        if (!empty($creds['shortcode']))       $this->shortcode       = $creds['shortcode'];
        if (!empty($creds['shortcode_type']))  $this->shortcode_type  = $creds['shortcode_type'];
        if (isset($creds['store_number']))     $this->store_number    = $creds['store_number']; // may be empty string to clear
        $envRaw = $creds['environment'] ?? ($creds['env'] ?? null);
        if ($envRaw !== null) {
            $this->env = $this->normalizeEnv($envRaw);
        }
        if (!empty($creds['callback_url'])) {
            $this->callback_url = rtrim($creds['callback_url'], '/');
        }
        $this->base_url = ($this->env === 'production')
            ? 'https://api.safaricom.co.ke'
            : 'https://sandbox.safaricom.co.ke';
    }
    
    /**
     * Generate Access Token
     */
    public function getAccessToken() {
        $url = $this->base_url . '/oauth/v1/generate?grant_type=client_credentials';
        $credentials = base64_encode($this->consumer_key . ':' . $this->consumer_secret);
        
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Authorization: Basic ' . $credentials]);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($curl, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($curl);
        $curlError = curl_error($curl);
        $httpCode  = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($curlError) {
            error_log("MpesaAPI getAccessToken curl error: {$curlError} | env={$this->env} url={$url}");
            return null;
        }

        $json = json_decode($response);
        if (empty($json->access_token)) {
            $sfError = $json->errorMessage ?? $json->error_description ?? $json->error ?? null;
            $this->last_error = $sfError
                ? $sfError
                : 'HTTP ' . $httpCode . ' — ' . substr($response, 0, 200);
            error_log("MpesaAPI getAccessToken failed: HTTP {$httpCode} | env={$this->env} | response=" . substr($response, 0, 300));
        }
        return $json->access_token ?? null;
    }
    
    /**
     * Initiate STK Push
     */
    public function stkPush($phone, $amount, $reference, $description = 'Payment') {
        $amount = (int)$amount;
        $phone = $this->formatPhone($phone);
        $timestamp = date('YmdHis');

        $transactionType = ($this->shortcode_type === 'till')
            ? 'CustomerBuyGoodsOnline'
            : 'CustomerPayBillOnline';

        // For Till (Buy Goods):
        //   BusinessShortCode = store/head-office number (Safaricom uses this for password validation)
        //   PartyB            = till number (what customers actually pay to — shows on their phone)
        //   Password hash     = base64(store_number + passkey + timestamp)
        // For Paybill: all three are the same shortcode.
        $isTill            = ($this->shortcode_type === 'till');
        $businessShortCode = ($isTill && !empty($this->store_number))
            ? $this->store_number
            : $this->shortcode;
        // PartyB is always the till/paybill number the customer pays to
        $partyB = $isTill ? $this->shortcode : $businessShortCode;

        $password    = base64_encode($businessShortCode . $this->passkey . $timestamp);
        $callbackUrl = $this->resolveCallbackUrl();

        $curl_post_data = [
            'BusinessShortCode' => $businessShortCode,
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'TransactionType'   => $transactionType,
            'Amount'            => $amount,
            'PartyA'            => $phone,
            'PartyB'            => $partyB,
            'PhoneNumber'       => $phone,
            'CallBackURL'       => $callbackUrl,
            'AccountReference'  => $reference,
            'TransactionDesc'   => $description,
        ];
        
        $url = $this->base_url . '/mpesa/stkpush/v1/processrequest';

        // Store full payload (including real Password hash) for Safaricom support sharing
        $this->last_payload = $curl_post_data;
        $logDir = defined('LOG_DIR') ? LOG_DIR : __DIR__ . '/../logs';
        if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
        @file_put_contents(
            $logDir . '/stk_push_payload.log',
            date('Y-m-d H:i:s') . ' ' . json_encode($curl_post_data) . "\n",
            FILE_APPEND | LOCK_EX
        );

        $token = $this->getAccessToken();

        if (!$token) {
            $detail = $this->last_error ? ' (' . $this->last_error . ')' : '';
            throw new Exception("Failed to get M-Pesa access token" . $detail);
        }
        
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ]);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($curl_post_data));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($curl, CURLOPT_TIMEOUT, 30);

        $response  = curl_exec($curl);
        $curlError = curl_error($curl);
        curl_close($curl);

        if ($curlError) {
            error_log("MpesaAPI stkPush curl error: {$curlError}");
        }

        return json_decode($response);
    }
    
    /**
     * Register C2B validation and confirmation URLs with Safaricom.
     *
     * Call this once after saving M-Pesa API credentials. Safaricom remembers
     * the URLs per shortcode — you only need to re-register if the URLs change.
     *
     * ResponseType:
     *   'Completed' — accept all transactions even if validation URL is down
     *   'Cancelled' — reject the transaction if validation URL is unreachable
     *
     * @return array ['success' => bool, 'error' => string|null, 'response' => array|null]
     */
    public function registerC2B(string $validationUrl, string $confirmationUrl, string $responseType = 'Completed'): array {
        $token = $this->getAccessToken();
        if (!$token) {
            $detail = $this->last_error ? ' (' . $this->last_error . ')' : '';
            return ['success' => false, 'error' => 'Failed to get access token' . $detail];
        }

        // For Buy Goods, RegisterURL is keyed on the ORGANISATION shortcode (the
        // store / head-office number), not the till the customer pays to. Sending
        // the till number here fails, which is part of why till-based ISPs never
        // got auto-activation working. Same precedence as stkPush() uses for
        // BusinessShortCode.
        $isTill    = ($this->shortcode_type === 'till');
        $regShortcode = ($isTill && !empty($this->store_number))
            ? $this->store_number
            : $this->shortcode;

        $url  = $this->base_url . '/mpesa/c2b/v1/registerurl';
        $body = [
            'ShortCode'       => $regShortcode,
            'ResponseType'    => $responseType,
            'ConfirmationURL' => $confirmationUrl,
            'ValidationURL'   => $validationUrl,
        ];

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ]);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($curl, CURLOPT_TIMEOUT, 30);

        $response  = curl_exec($curl);
        $curlError = curl_error($curl);
        $httpCode  = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($curlError) {
            return ['success' => false, 'error' => 'cURL error: ' . $curlError];
        }

        $json = json_decode($response, true) ?? [];

        // Safaricom returns ResponseCode "0" on success
        if (isset($json['ResponseCode']) && $json['ResponseCode'] === '0') {
            return ['success' => true, 'response' => $json];
        }

        $errorMsg = $json['errorMessage'] ?? $json['ResponseDescription'] ?? ('HTTP ' . $httpCode . ': ' . substr($response, 0, 300));
        return ['success' => false, 'error' => $errorMsg, 'raw' => $json];
    }

    /**
     * Query STK Push transaction status from Daraja.
     *
     * Returns an array with keys:
     *   result_code  (int)    — 0 = success, 1032 = cancelled, 1019 = timeout/expired
     *   result_desc  (string)
     *   raw          (array)  — full decoded Daraja response
     *   error        (string|null) — set on network/auth failure before Daraja responded
     *
     * Result codes to treat as "still pending": 1025 (processing).
     * Codes 1019 (expired) and 1037 (timeout) mean the request is gone — mark failed.
     */
    public function stkQuery(string $checkoutRequestId): array {
        $token = $this->getAccessToken();
        if (!$token) {
            return ['result_code' => -1, 'result_desc' => 'Token error', 'error' => $this->last_error ?: 'No access token', 'raw' => []];
        }

        $timestamp         = date('YmdHis');
        $isTill            = ($this->shortcode_type === 'till');
        $businessShortCode = ($isTill && !empty($this->store_number)) ? $this->store_number : $this->shortcode;
        $password          = base64_encode($businessShortCode . $this->passkey . $timestamp);

        $body = [
            'BusinessShortCode'  => $businessShortCode,
            'Password'           => $password,
            'Timestamp'          => $timestamp,
            'CheckoutRequestID'  => $checkoutRequestId,
        ];

        $url  = $this->base_url . '/mpesa/stkpushquery/v1/query';
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ]);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($curl, CURLOPT_TIMEOUT, 30);

        $response  = curl_exec($curl);
        $curlError = curl_error($curl);
        curl_close($curl);

        if ($curlError) {
            return ['result_code' => -1, 'result_desc' => 'cURL error', 'error' => $curlError, 'raw' => []];
        }

        $json = json_decode($response, true) ?? [];
        $code = isset($json['ResultCode']) ? (int)$json['ResultCode'] : -1;
        $desc = $json['ResultDesc'] ?? ($json['errorMessage'] ?? 'Unknown');

        return ['result_code' => $code, 'result_desc' => $desc, 'error' => null, 'raw' => $json];
    }

    /**
     * Format phone number to 254...
     */
    // ─────────────────────────────────────────────────────────────────────────
    //  B2C — paying money OUT
    // ─────────────────────────────────────────────────────────────────────────
    //
    // Everything above moves money towards us. This moves it away, which makes
    // it the only part of this class where a bug spends real money, so it is
    // deliberately harder to fire: it refuses without an initiator identity and
    // an explicit result URL, and it never retries on its own. The caller owns
    // idempotency (see cron/disburse_payouts.php, which writes a batch row and
    // marks the queue 'processing' BEFORE calling this).

    private $initiator_name = '';
    private $security_credential = '';
    private $b2c_shortcode = '';

    /**
     * Initiator identity for B2C.
     *
     * $securityCredential is the value Safaricom's portal gives you when you
     * encrypt the initiator password against their production certificate —
     * paste it as-is. Use encryptSecurityCredential() only if you hold the
     * plaintext password and the .cer file instead.
     */
    public function setInitiator(string $name, string $securityCredential, string $b2cShortcode = ''): void
    {
        $this->initiator_name      = trim($name);
        $this->security_credential = trim($securityCredential);
        $this->b2c_shortcode       = trim($b2cShortcode) ?: $this->shortcode;
    }

    /**
     * RSA-encrypt an initiator password against Safaricom's public certificate.
     *
     * Only needed when you have the raw password rather than the portal's
     * pre-encrypted string. The cert differs between sandbox and production —
     * using the wrong one produces a credential Safaricom rejects with a
     * message that does not mention certificates at all.
     */
    public static function encryptSecurityCredential(string $initiatorPassword, string $certPath): ?string
    {
        if (!is_readable($certPath)) {
            error_log("MpesaAPI::encryptSecurityCredential: cannot read cert $certPath");
            return null;
        }
        $cert = file_get_contents($certPath);
        $key  = openssl_pkey_get_public($cert);
        if (!$key) {
            error_log('MpesaAPI::encryptSecurityCredential: cert is not a valid public key');
            return null;
        }
        $encrypted = '';
        if (!openssl_public_encrypt($initiatorPassword, $encrypted, $key, OPENSSL_PKCS1_PADDING)) {
            error_log('MpesaAPI::encryptSecurityCredential: openssl_public_encrypt failed');
            return null;
        }
        return base64_encode($encrypted);
    }

    /** Is this instance able to send money out? */
    public function canSendB2C(): array
    {
        $missing = [];
        if ($this->initiator_name === '')      $missing[] = 'initiator_name';
        if ($this->security_credential === '') $missing[] = 'security_credential';
        if ($this->b2c_shortcode === '')       $missing[] = 'b2c_shortcode';
        if (empty($this->consumer_key))        $missing[] = 'consumer_key';
        if (empty($this->consumer_secret))     $missing[] = 'consumer_secret';

        return ['ok' => empty($missing), 'missing' => $missing];
    }

    /**
     * Send money to a customer/tenant M-Pesa number.
     *
     * Returns immediately with Safaricom's ACCEPTANCE of the request, not its
     * outcome — the money has NOT moved when this returns success. The real
     * result arrives asynchronously at $resultUrl. Treating the synchronous
     * response as proof of payment is how a payout gets marked settled and then
     * silently fails.
     *
     * @param string $originatorConversationId Caller's own idempotency key.
     * @param string $commandId  BusinessPayment (no charge prompt) |
     *                           SalaryPayment | PromotionPayment
     * @return array{success:bool,accepted:bool,conversation_id:?string,
     *               originator_conversation_id:string,error:?string,raw:mixed}
     */
    public function b2cPayment(
        string $phone,
        float  $amount,
        string $remarks,
        string $originatorConversationId,
        string $resultUrl,
        string $timeoutUrl,
        string $commandId = 'BusinessPayment'
    ): array {
        $fail = function (string $msg) use ($originatorConversationId) {
            $this->last_error = $msg;
            error_log("MpesaAPI b2cPayment [$originatorConversationId]: $msg");
            return [
                'success' => false, 'accepted' => false, 'conversation_id' => null,
                'originator_conversation_id' => $originatorConversationId,
                'error' => $msg, 'raw' => null,
            ];
        };

        $ready = $this->canSendB2C();
        if (!$ready['ok']) {
            return $fail('B2C is not configured — missing: ' . implode(', ', $ready['missing']));
        }

        // Safaricom rejects fractional amounts; rounding UP would pay out money
        // we never collected, so truncate and let the remainder ride to the
        // next payout rather than inventing a shilling.
        $whole = (int)floor($amount);
        if ($whole < 1) {
            return $fail('amount rounds to less than KES 1 — nothing to send');
        }

        $msisdn = $this->formatPhone($phone);
        if (!preg_match('/^2547\d{8}$|^2541\d{8}$/', $msisdn)) {
            return $fail("payout number '$phone' is not a valid Kenyan mobile number");
        }

        if ($resultUrl === '' || $timeoutUrl === '' || $this->isLocalUrl($resultUrl)) {
            return $fail('a publicly reachable ResultURL and QueueTimeOutURL are required');
        }

        $token = $this->getAccessToken();
        if (!$token) {
            return $fail('could not obtain an access token: ' . $this->last_error);
        }

        $payload = [
            'OriginatorConversationID' => $originatorConversationId,
            'InitiatorName'            => $this->initiator_name,
            'SecurityCredential'       => $this->security_credential,
            'CommandID'                => $commandId,
            'Amount'                   => $whole,
            'PartyA'                   => $this->b2c_shortcode,
            'PartyB'                   => $msisdn,
            'Remarks'                  => substr($remarks, 0, 100),
            'QueueTimeOutURL'          => $timeoutUrl,
            'ResultURL'                => $resultUrl,
            'Occasion'                 => '',
        ];
        $this->last_payload = $payload;

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $this->base_url . '/mpesa/b2c/v3/paymentrequest');
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ]);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($curl, CURLOPT_TIMEOUT, 40);

        $response  = curl_exec($curl);
        $curlError = curl_error($curl);
        $httpCode  = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        // A timeout is NOT a failure to send. Safaricom may have accepted the
        // request and be about to move the money, so this must never be treated
        // as "safe to retry" — the caller leaves the batch in 'processing' for a
        // human, which is why 'error' is set but 'accepted' is left null-ish.
        if ($curlError) {
            $this->last_error = $curlError;
            error_log("MpesaAPI b2cPayment [$originatorConversationId] curl error: $curlError");
            return [
                'success' => false, 'accepted' => null, 'conversation_id' => null,
                'originator_conversation_id' => $originatorConversationId,
                'error' => 'network error, outcome UNKNOWN: ' . $curlError, 'raw' => null,
            ];
        }

        $json = json_decode($response, true);
        $code = $json['ResponseCode'] ?? null;

        if ($code === '0' || $code === 0) {
            return [
                'success' => true, 'accepted' => true,
                'conversation_id' => $json['ConversationID'] ?? null,
                'originator_conversation_id' => $json['OriginatorConversationID'] ?? $originatorConversationId,
                'error' => null, 'raw' => $json,
            ];
        }

        $msg = $json['errorMessage'] ?? $json['ResponseDescription'] ?? ('HTTP ' . $httpCode . ' — ' . substr((string)$response, 0, 200));
        $this->last_error = $msg;
        error_log("MpesaAPI b2cPayment [$originatorConversationId] rejected: $msg");
        return [
            'success' => false, 'accepted' => false, 'conversation_id' => null,
            'originator_conversation_id' => $originatorConversationId,
            'error' => $msg, 'raw' => $json,
        ];
    }

    private function formatPhone($phone) {
        $phone = preg_replace('/[^0-9]/', '', $phone); // Remove non-numeric
        if (substr($phone, 0, 1) == '0') {
            return '254' . substr($phone, 1);
        }
        if (substr($phone, 0, 3) == '254') {
            return $phone;
        }
        return '254' . $phone; // Assume local if strange
    }
}
