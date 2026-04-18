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
                $creds = json_decode($gateway['credentials'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
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
        $url = $scheme . '://' . $host . '/api/mpesa/callback.php';
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
     * Format phone number to 254...
     */
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
