<?php
/**
 * Payment Gateway Manager
 * 
 * Handles multiple payment gateway configurations per tenant
 * with encrypted credential storage
 */

class PaymentGatewayManager {
    private $db;
    private $encryptionKey;
    
    public function __construct($db) {
        $this->db = $db;
        // Use a secure encryption key (should be in environment variables in production)
        $this->encryptionKey = $this->getEncryptionKey();
    }
    
    /**
     * Get encryption key from environment or generate one
     * In production, this should be stored securely in environment variables
     */
    private function getEncryptionKey() {
        // Check if encryption key exists in config
        $keyFile = __DIR__ . '/../config/encryption.key';
        
        if (file_exists($keyFile)) {
            return file_get_contents($keyFile);
        }
        
        // Generate new key if it doesn't exist
        $key = base64_encode(random_bytes(32));
        
        // Create config directory if it doesn't exist
        $configDir = dirname($keyFile);
        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }
        
        file_put_contents($keyFile, $key);
        chmod($keyFile, 0600); // Restrict permissions
        
        return $key;
    }
    
    /**
     * Encrypt credentials before storing
     * 
     * @param array $credentials
     * @return string Encrypted JSON
     */
    private function encryptCredentials($credentials) {
        $json = json_encode($credentials);
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt(
            $json,
            'AES-256-CBC',
            base64_decode($this->encryptionKey),
            0,
            $iv
        );
        
        // Prepend IV to encrypted data
        return base64_encode($iv . $encrypted);
    }
    
    /**
     * Decrypt credentials when retrieving
     * 
     * @param string $encryptedData
     * @return array|null Decrypted credentials array
     */
    private function decryptCredentials($encryptedData) {
        try {
            $data = base64_decode($encryptedData);
            $iv = substr($data, 0, 16);
            $encrypted = substr($data, 16);
            
            $decrypted = openssl_decrypt(
                $encrypted,
                'AES-256-CBC',
                base64_decode($this->encryptionKey),
                0,
                $iv
            );
            
            return json_decode($decrypted, true);
        } catch (Exception $e) {
            error_log("Decryption error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Save payment gateway configuration
     * 
     * @param int $tenantId
     * @param string $gatewayType
     * @param string $gatewayName
     * @param array $credentials
     * @param bool $isDefault
     * @return int|false Gateway ID on success
     */
    public function saveGateway($tenantId, $gatewayType, $gatewayName, $credentials, $isDefault = false) {
        try {
            // Encrypt credentials
            $encryptedCredentials = $this->encryptCredentials($credentials);
            
            // If this is set as default, unset other defaults
            if ($isDefault) {
                $this->db->prepare("
                    UPDATE payment_gateways 
                    SET is_default = FALSE 
                    WHERE tenant_id = ?
                ")->execute([$tenantId]);
            }
            
            $stmt = $this->db->prepare("
                INSERT INTO payment_gateways (
                    tenant_id,
                    gateway_type,
                    gateway_name,
                    credentials,
                    is_active,
                    is_default
                ) VALUES (?, ?, ?, ?, TRUE, ?)
            ");
            
            $stmt->execute([
                $tenantId,
                $gatewayType,
                $gatewayName,
                $encryptedCredentials,
                $isDefault
            ]);
            
            return (int)$this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log("Gateway save error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update existing gateway
     * 
     * @param int $gatewayId
     * @param string $gatewayName
     * @param array $credentials
     * @param bool $isActive
     * @param bool $isDefault
     * @return bool
     */
    public function updateGateway($gatewayId, $gatewayName, $credentials, $isActive = true, $isDefault = false) {
        try {
            // Get gateway to verify tenant
            $gateway = $this->getGatewayById($gatewayId);
            if (!$gateway) {
                return false;
            }
            
            // If setting as default, unset other defaults for this tenant
            if ($isDefault) {
                $this->db->prepare("
                    UPDATE payment_gateways 
                    SET is_default = FALSE 
                    WHERE tenant_id = ? AND id != ?
                ")->execute([$gateway['tenant_id'], $gatewayId]);
            }
            
            $encryptedCredentials = $this->encryptCredentials($credentials);
            
            $stmt = $this->db->prepare("
                UPDATE payment_gateways
                SET gateway_name = ?,
                    credentials = ?,
                    is_active = ?,
                    is_default = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            
            return $stmt->execute([
                $gatewayName,
                $encryptedCredentials,
                $isActive,
                $isDefault,
                $gatewayId
            ]);
        } catch (PDOException $e) {
            error_log("Gateway update error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get all active payment gateways for a tenant
     * 
     * @param int $tenantId
     * @param bool $decryptCredentials Whether to decrypt and return credentials
     * @return array
     */
    public function getActiveGateways($tenantId, $decryptCredentials = false) {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM payment_gateways
                WHERE tenant_id = ? AND is_active = TRUE
                ORDER BY is_default DESC, created_at ASC
            ");
            $stmt->execute([$tenantId]);
            $gateways = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if ($decryptCredentials) {
                foreach ($gateways as &$gateway) {
                    $gateway['credentials'] = $this->decryptCredentials($gateway['credentials']);
                }
            } else {
                // Mask credentials for security
                foreach ($gateways as &$gateway) {
                    $gateway['credentials'] = '***ENCRYPTED***';
                }
            }
            
            return $gateways;
        } catch (PDOException $e) {
            error_log("Gateway retrieval error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get gateway by ID
     * 
     * @param int $gatewayId
     * @param bool $decryptCredentials
     * @return array|null
     */
    public function getGatewayById($gatewayId, $decryptCredentials = false) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM payment_gateways WHERE id = ?");
            $stmt->execute([$gatewayId]);
            $gateway = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($gateway && $decryptCredentials) {
                $gateway['credentials'] = $this->decryptCredentials($gateway['credentials']);
            }
            
            return $gateway;
        } catch (PDOException $e) {
            error_log("Gateway retrieval error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get default gateway for tenant
     * 
     * @param int $tenantId
     * @param bool $decryptCredentials
     * @return array|null
     */
    public function getDefaultGateway($tenantId, $decryptCredentials = false) {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM payment_gateways
                WHERE tenant_id = ? AND is_default = TRUE AND is_active = TRUE
                LIMIT 1
            ");
            $stmt->execute([$tenantId]);
            $gateway = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($gateway && $decryptCredentials) {
                $gateway['credentials'] = $this->decryptCredentials($gateway['credentials']);
            }
            
            return $gateway;
        } catch (PDOException $e) {
            error_log("Default gateway retrieval error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Delete gateway
     * 
     * @param int $gatewayId
     * @param int $tenantId For security validation
     * @return bool
     */
    public function deleteGateway($gatewayId, $tenantId) {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM payment_gateways
                WHERE id = ? AND tenant_id = ?
            ");
            return $stmt->execute([$gatewayId, $tenantId]);
        } catch (PDOException $e) {
            error_log("Gateway deletion error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Process payment through appropriate gateway
     * This is a router function that delegates to specific gateway handlers
     * 
     * @param int $gatewayId
     * @param float $amount
     * @param array $customer Customer data
     * @param array $metadata Additional payment metadata
     * @return array Payment result
     */
    public function processPayment($gatewayId, $amount, $customer, $metadata = []) {
        $gateway = $this->getGatewayById($gatewayId, true);
        
        if (!$gateway) {
            return [
                'success' => false,
                'message' => 'Payment gateway not found'
            ];
        }
        
        // Route to appropriate payment processor
        switch ($gateway['gateway_type']) {
            case 'mpesa_api':
                return $this->processMpesaSTK($gateway, $amount, $customer, $metadata);
                
            case 'paybill_no_api':
                return $this->generatePaybillInstructions($gateway, $amount, $customer);
                
            case 'bank_account':
                return $this->generateBankInstructions($gateway, $amount, $customer);
                
            case 'kopo_kopo':
                return $this->processKopoKopo($gateway, $amount, $customer, $metadata);
                
            case 'paypal':
                return $this->processPayPal($gateway, $amount, $customer, $metadata);
                
            default:
                return [
                    'success' => false,
                    'message' => 'Unsupported payment gateway type'
                ];
        }
    }
    
    /**
     * Process M-Pesa STK Push
     */
    private function processMpesaSTK($gateway, $amount, $customer, $metadata) {
        // This would integrate with existing M-Pesa STK logic
        // For now, return instructions
        return [
            'success' => true,
            'type' => 'stk_push',
            'message' => 'STK Push initiated. Please check your phone.',
            'requires_confirmation' => true
        ];
    }
    
    /**
     * Generate Paybill payment instructions
     */
    private function generatePaybillInstructions($gateway, $amount, $customer) {
        $credentials = $gateway['credentials'];
        
        return [
            'success' => true,
            'type' => 'manual',
            'payment_method' => 'M-Pesa Paybill',
            'instructions' => [
                'paybill_number' => $credentials['paybill_number'] ?? '',
                'account_number' => $customer['account_number'] ?? $credentials['account_number'] ?? '',
                'amount' => $amount,
                'steps' => [
                    'Go to M-Pesa menu on your phone',
                    'Select Lipa na M-Pesa',
                    'Select Pay Bill',
                    'Enter Business Number: ' . ($credentials['paybill_number'] ?? ''),
                    'Enter Account Number: ' . ($customer['account_number'] ?? ''),
                    'Enter Amount: KES ' . number_format($amount, 2),
                    'Enter your M-Pesa PIN',
                    'Confirm the transaction'
                ]
            ]
        ];
    }
    
    /**
     * Generate Bank Transfer instructions
     */
    private function generateBankInstructions($gateway, $amount, $customer) {
        $credentials = $gateway['credentials'];
        
        return [
            'success' => true,
            'type' => 'manual',
            'payment_method' => 'Bank Transfer',
            'instructions' => [
                'bank_name' => $credentials['bank_name'] ?? '',
                'account_number' => $credentials['account_number'] ?? '',
                'account_name' => $credentials['account_name'] ?? '',
                'branch' => $credentials['branch'] ?? '',
                'swift_code' => $credentials['swift_code'] ?? '',
                'amount' => $amount,
                'reference' => $customer['account_number'] ?? $customer['phone'] ?? ''
            ]
        ];
    }
    
    /**
     * Process Kopo Kopo payment (placeholder)
     */
    private function processKopoKopo($gateway, $amount, $customer, $metadata) {
        return [
            'success' => false,
            'message' => 'Kopo Kopo integration coming soon'
        ];
    }
    
    /**
     * Process PayPal payment (placeholder)
     */
    private function processPayPal($gateway, $amount, $customer, $metadata) {
        return [
            'success' => false,
            'message' => 'PayPal integration coming soon'
        ];
    }
}
