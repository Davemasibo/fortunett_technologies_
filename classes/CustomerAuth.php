<?php
/**
 * Customer Authentication Class
 * Handles customer portal login, sessions, and authentication
 */

class CustomerAuth {
    private $pdo;
    private $session_duration = 86400; // 24 hours
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Authenticate customer with username/password
     */
    public function login($username, $password) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM clients 
                WHERE (username = ? OR phone = ? OR email = ?) 
                AND status != 'suspended'
                LIMIT 1
            ");
            $stmt->execute([$username, $username, $username]);
            $client = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$client) {
                return ['success' => false, 'message' => 'Invalid credentials'];
            }
            
            // Verify password
            if (!empty($client['auth_password']) && password_verify($password, $client['auth_password'])) {
                return $this->createSession($client);
            } elseif (!empty($client['mikrotik_password']) && $password === $client['mikrotik_password']) {
                // Fallback to MikroTik password for backward compatibility
                return $this->createSession($client);
            }
            
            return ['success' => false, 'message' => 'Invalid credentials'];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Login failed: ' . $e->getMessage()];
        }
    }
    
    /**
     * Authenticate with voucher code
     */
    public function loginWithVoucher($voucherCode) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM vouchers 
                WHERE voucher_code = ? 
                AND status = 'active' 
                AND (expires_at IS NULL OR expires_at > NOW())
                LIMIT 1
            ");
            $stmt->execute([$voucherCode]);
            $voucher = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$voucher) {
                return ['success' => false, 'message' => 'Invalid or expired voucher'];
            }
            
            // Check if voucher already used
            if ($voucher['used_by_client_id']) {
                // Get existing client
                $stmt = $this->pdo->prepare("SELECT * FROM clients WHERE id = ?");
                $stmt->execute([$voucher['used_by_client_id']]);
                $client = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($client) {
                    return $this->createSession($client);
                }
            }
            
            // Create new client from voucher
            $client = $this->createClientFromVoucher($voucher);
            
            if ($client) {
                // Mark voucher as used
                $stmt = $this->pdo->prepare("
                    UPDATE vouchers 
                    SET status = 'used', used_by_client_id = ?, used_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$client['id'], $voucher['id']]);
                
                return $this->createSession($client);
            }
            
            return ['success' => false, 'message' => 'Failed to activate voucher'];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Voucher login failed: ' . $e->getMessage()];
        }
    }
    
    /**
     * Auto-login with payment token
     */
    public function autoLogin($token, $ipAddress = null, $macAddress = null) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM payment_auto_logins 
                WHERE login_token = ? 
                AND status = 'pending' 
                AND expires_at > NOW()
                LIMIT 1
            ");
            $stmt->execute([$token]);
            $autoLogin = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$autoLogin) {
                return ['success' => false, 'message' => 'Invalid or expired login token'];
            }
            
            // Get client
            $stmt = $this->pdo->prepare("SELECT * FROM clients WHERE id = ?");
            $stmt->execute([$autoLogin['client_id']]);
            $client = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$client) {
                return ['success' => false, 'message' => 'Client not found'];
            }
            
            // Mark token as used
            $stmt = $this->pdo->prepare("
                UPDATE payment_auto_logins 
                SET status = 'used', used_at = NOW(), ip_address = ?, mac_address = ? 
                WHERE id = ?
            ");
            $stmt->execute([$ipAddress, $macAddress, $autoLogin['id']]);
            
            return $this->createSession($client);
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Auto-login failed: ' . $e->getMessage()];
        }
    }
    
    /**
     * Create session for client
     */
    private function createSession($client) {
        try {
            // Enforce Device Limit
            $stmt = $this->pdo->prepare("SELECT * FROM packages WHERE id = ?");
            $stmt->execute([$client['package_id']]);
            $package = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($package) {
                // Count active sessions
                $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM customer_sessions WHERE client_id = ? AND expires_at > NOW()");
                $stmt->execute([$client['id']]);
                $activeSessions = (int)$stmt->fetchColumn();
                
                $deviceLimit = $package['device_limit'] ?? 1;
                
                if ($activeSessions >= $deviceLimit) {
                    return ['success' => false, 'message' => "Device limit reached. Your plan allows max $deviceLimit device(s)."];
                }
            }

            $sessionToken = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', time() + $this->session_duration);
            
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            $macAddress = null; // Can be extracted from MikroTik if needed
            
            $this->ensureAccountNumber($client);

            $stmt = $this->pdo->prepare("
                INSERT INTO customer_sessions 
                (client_id, session_token, ip_address, mac_address, user_agent, expires_at) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $client['id'], 
                $sessionToken, 
                $ipAddress, 
                $macAddress, 
                $userAgent, 
                $expiresAt
            ]);
            
            // Log activity
            $this->logActivity($client['id'], 'login', 'Customer logged in');
            
            return [
                'success' => true,
                'session_token' => $sessionToken,
                'client' => [
                    'id' => $client['id'],
                    'name' => $client['full_name'] ?? $client['name'],
                    'email' => $client['email'],
                    'phone' => $client['phone'],
                    'account_number' => $client['account_number'],
                    'package_id' => $client['package_id'],
                    'account_balance' => $client['account_balance'],
                    'expiry_date' => $client['expiry_date'],
                    'status' => $client['status']
                ]
            ];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Session creation failed: ' . $e->getMessage()];
        }
    }
    
    /**
     * Validate session token
     */
    public function validateSession($sessionToken) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT cs.*, c.* 
                FROM customer_sessions cs
                JOIN clients c ON cs.client_id = c.id
                WHERE cs.session_token = ? 
                AND cs.expires_at > NOW()
                LIMIT 1
            ");
            $stmt->execute([$sessionToken]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                // Update last activity
                $stmt = $this->pdo->prepare("
                    UPDATE customer_sessions 
                    SET last_activity = NOW() 
                    WHERE session_token = ?
                ");
                $stmt->execute([$sessionToken]);
                
                return [
                    'valid' => true,
                    'client' => $result
                ];
            }
            
            return ['valid' => false];
            
        } catch (Exception $e) {
            return ['valid' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Logout - destroy session
     */
    public function logout($sessionToken) {
        try {
            // Get client_id before deleting
            $stmt = $this->pdo->prepare("SELECT client_id FROM customer_sessions WHERE session_token = ?");
            $stmt->execute([$sessionToken]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($session) {
                $this->logActivity($session['client_id'], 'logout', 'Customer logged out');
            }
            
            $stmt = $this->pdo->prepare("DELETE FROM customer_sessions WHERE session_token = ?");
            $stmt->execute([$sessionToken]);
            
            return ['success' => true];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Create client from voucher
     */
    private function createClientFromVoucher($voucher) {
        try {
            // Get package details
            $stmt = $this->pdo->prepare("SELECT * FROM packages WHERE id = ?");
            $stmt->execute([$voucher['package_id']]);
            $package = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$package) {
                return null;
            }
            
            // Generate username
            $username = 'voucher_' . substr($voucher['voucher_code'], 0, 8);
            $expiryDate = date('Y-m-d H:i:s', strtotime('+' . $voucher['duration_days'] . ' days'));
            
            $stmt = $this->pdo->prepare("
                INSERT INTO clients 
                (full_name, name, username, mikrotik_username, package_id, subscription_plan, 
                 expiry_date, status, connection_type, package_price) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 'active', 'hotspot', ?)
            ");
            $stmt->execute([
                'Voucher User',
                'Voucher User',
                $username,
                $username,
                $voucher['package_id'],
                $package['name'],
                $expiryDate,
                $package['price']
            ]);
            
            $clientId = $this->pdo->lastInsertId();
            
            // Get created client
            $stmt = $this->pdo->prepare("SELECT * FROM clients WHERE id = ?");
            $stmt->execute([$clientId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * Log customer activity
     */
    public function logActivity($clientId, $activityType, $description, $ipAddress = null) {
        try {
            if ($ipAddress === null) {
                $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
            }
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            
            $stmt = $this->pdo->prepare("
                INSERT INTO customer_activity_log 
                (client_id, activity_type, description, ip_address, user_agent) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$clientId, $activityType, $description, $ipAddress, $userAgent]);
            
        } catch (Exception $e) {
            // Silent fail for logging
        }
    }
    
    /**
     * Create auto-login token after payment
     */
    public function createAutoLoginToken($clientId, $paymentId = null) {
        try {
            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', time() + 1800); // 30 minutes
            
            $stmt = $this->pdo->prepare("
                INSERT INTO payment_auto_logins 
                (client_id, payment_id, login_token, expires_at) 
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$clientId, $paymentId, $token, $expiresAt]);
            
            return $token;
            
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Ensure client has account number
     */
    private function ensureAccountNumber(&$client) {
        if (!empty($client['account_number'])) {
            return;
        }
        
        // Generate account number
        $initial = 'I';
        try {
            $stmt = $this->pdo->query("SELECT business_name FROM isp_profile LIMIT 1");
            $row = $stmt->fetch();
            if (!empty($row['business_name'])) {
                $initial = strtoupper(substr(trim($row['business_name']),0,1));
                if (!preg_match('/[A-Z]/', $initial)) $initial = 'I';
            }
        } catch (Exception $e) {}
        
        $num = str_pad((string)$client['id'], 5, '0', STR_PAD_LEFT);
        $accountNumber = $initial . $num;
        
        // Update DB
        try {
            $stmt = $this->pdo->prepare("UPDATE clients SET account_number = ? WHERE id = ?");
            $stmt->execute([$accountNumber, $client['id']]);
            $client['account_number'] = $accountNumber;
        } catch (Exception $e) {
            // Ignore errors
        }
    }
}
