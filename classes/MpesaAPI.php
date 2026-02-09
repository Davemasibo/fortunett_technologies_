<?php
/**
 * M-Pesa API Helper Class
 */
class MpesaAPI {
    private $consumer_key;
    private $consumer_secret;
    private $passkey;
    private $shortcode;
    private $env;
    private $base_url;
    
    private $pdo;
    private $tenant_id;

    public function __construct($pdo = null, $tenant_id = null) {
        require_once __DIR__ . '/../config/mpesa.php';
        
        $this->pdo = $pdo;
        $this->tenant_id = $tenant_id;
        
        // Default to globals (Single Tenant Mode)
        $this->consumer_key = defined('MPESA_CONSUMER_KEY') ? MPESA_CONSUMER_KEY : '';
        $this->consumer_secret = defined('MPESA_CONSUMER_SECRET') ? MPESA_CONSUMER_SECRET : '';
        $this->passkey = defined('MPESA_PASSKEY') ? MPESA_PASSKEY : '';
        $this->shortcode = defined('MPESA_SHORTCODE') ? MPESA_SHORTCODE : '';
        $this->env = defined('MPESA_ENV') ? MPESA_ENV : 'sandbox';
        
        // Multi-Tenant Override
        if ($this->pdo && $this->tenant_id) {
            $this->loadTenantCredentials();
        }

        $this->base_url = ($this->env === 'production') ? 'https://api.safaricom.co.ke' : 'https://sandbox.safaricom.co.ke';
    }

    private function loadTenantCredentials() {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM payment_gateways WHERE tenant_id = ? AND gateway_type = 'mpesa' AND is_active = 1 LIMIT 1");
            $stmt->execute([$this->tenant_id]);
            $gateway = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($gateway && !empty($gateway['credentials'])) {
                $creds = json_decode($gateway['credentials'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $this->consumer_key = $creds['consumer_key'] ?? $this->consumer_key;
                    $this->consumer_secret = $creds['consumer_secret'] ?? $this->consumer_secret;
                    $this->passkey = $creds['passkey'] ?? $this->passkey;
                    $this->shortcode = $creds['shortcode'] ?? $this->shortcode;
                    $this->env = $creds['env'] ?? $this->env;
                }
            }
        } catch (Exception $e) {
            // Fallback to globals on error
        }
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
        
        $response = curl_exec($curl);
        curl_close($curl);
        
        $json = json_decode($response);
        return $json->access_token ?? null;
    }
    
    /**
     * Initiate STK Push
     */
    public function stkPush($phone, $amount, $reference, $description = 'Payment') {
        $amount = (int)$amount;
        $phone = $this->formatPhone($phone);
        $timestamp = date('YmdHis');
        $password = base64_encode($this->shortcode . $this->passkey . $timestamp);
        
        // Use constant from config
        $callbackUrl = MPESA_CALLBACK_URL;
        
        $curl_post_data = [
            'BusinessShortCode' => $this->shortcode,
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => 'CustomerPayBillOnline',
            'Amount' => $amount,
            'PartyA' => $phone,
            'PartyB' => $this->shortcode,
            'PhoneNumber' => $phone,
            'CallBackURL' => $callbackUrl,
            'AccountReference' => $reference,
            'TransactionDesc' => $description
        ];
        
        $url = $this->base_url . '/mpesa/stkpush/v1/processrequest';
        $token = $this->getAccessToken();
        
        if (!$token) {
            throw new Exception("Failed to get M-Pesa access token");
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
        
        $response = curl_exec($curl);
        curl_close($curl);
        
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
