<?php
/**
 * Account Number Generator
 * 
 * Automatically generates sequential account numbers for customers
 * based on the admin username prefix (e.g., admin "ecco" -> e001, e002, etc.)
 */

class AccountNumberGenerator {
    private $db;
    
    public function __construct($db) {
        $this->db = $db;
    }
    
    /**
     * Generate account number for a new customer
     * 
     * @param int $tenantId Tenant ID
     * @return string Generated account number (e.g., "e001")
     */
    public function generateAccountNumber($tenantId) {
        try {
            // Start transaction for thread safety
            $this->db->beginTransaction();
            
            // Get the prefix for this tenant
            $prefix = $this->getPrefix($tenantId);
            
            if (!$prefix) {
                $this->db->rollBack();
                throw new Exception("Unable to determine account number prefix for tenant");
            }
            
            // Get next sequence number
            $nextNumber = $this->getNextSequence($tenantId, $prefix);
            
            // Format the account number (prefix + zero-padded number)
            $accountNumber = strtoupper($prefix) . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
            
            $this->db->commit();
            
            return $accountNumber;
            
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log("Account number generation error: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get prefix for tenant based on admin username
     * 
     * @param int $tenantId
     * @return string|null Prefix (1-3 characters)
     */
    private function getPrefix($tenantId) {
        try {
            // First check if user has explicit account_prefix set
            $stmt = $this->db->prepare("
                SELECT u.account_prefix, u.username
                FROM users u
                JOIN tenants t ON t.admin_user_id = u.id
                WHERE t.id = ?
                LIMIT 1
            ");
            $stmt->execute([$tenantId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$result) {
                return null;
            }
            
            // Use explicit prefix if set
            if (!empty($result['account_prefix'])) {
                return strtolower($result['account_prefix']);
            }
            
            // Otherwise, extract from username
            $username = $result['username'];
            
            // Get first 1-3 characters from username
            // Try to get first letter(s) before numbers or special chars
            preg_match('/^([a-zA-Z]{1,3})/', $username, $matches);
            
            if (isset($matches[1])) {
                $prefix = strtolower($matches[1]);
                
                // Update the account_prefix in users table for future use
                $updateStmt = $this->db->prepare("
                    UPDATE users u
                    JOIN tenants t ON t.admin_user_id = u.id
                    SET u.account_prefix = ?
                    WHERE t.id = ?
                ");
                $updateStmt->execute([$prefix, $tenantId]);
                
                return $prefix;
            }
            
            // Fallback: use 't' for tenant if no letters found
            return 't';
            
        } catch (PDOException $e) {
            error_log("Prefix retrieval error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get next sequence number for the prefix
     * 
     * @param int $tenantId
     * @param string $prefix
     * @return int Next sequence number
     */
    private function getNextSequence($tenantId, $prefix) {
        try {
            // Lock table to prevent race conditions
            $this->db->exec("LOCK TABLES clients WRITE");
            
            // Find the highest account number with this prefix for this tenant
            $stmt = $this->db->prepare("
                SELECT account_number
                FROM clients
                WHERE tenant_id = ? 
                AND account_number LIKE ?
                ORDER BY account_number DESC
                LIMIT 1
            ");
            
            $likePattern = strtoupper($prefix) . '%';
            $stmt->execute([$tenantId, $likePattern]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Unlock tables
            $this->db->exec("UNLOCK TABLES");
            
            if ($result && $result['account_number']) {
                // Extract the numeric part
                $accountNumber = $result['account_number'];
                $numericPart = preg_replace('/[^0-9]/', '', $accountNumber);
                
                if ($numericPart) {
                    return (int)$numericPart + 1;
                }
            }
            
            // If no existing accounts, start at 1
            return 1;
            
        } catch (PDOException $e) {
            // Make sure to unlock tables on error
            try {
                $this->db->exec("UNLOCK TABLES");
            } catch (Exception $unlockError) {
                // Ignore unlock errors
            }
            
            error_log("Sequence retrieval error: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Validate account number format
     * 
     * @param string $accountNumber
     * @return bool
     */
    public static function validateFormat($accountNumber) {
        // Pattern: 1-3 letters followed by 3 digits
        // Examples: e001, ab001, xyz999
        return preg_match('/^[A-Z]{1,3}[0-9]{3}$/i', $accountNumber) === 1;
    }
    
    /**
     * Check if account number is unique
     * 
     * @param string $accountNumber
     * @param int|null $excludeClientId Optional client ID to exclude (for updates)
     * @return bool
     */
    public function isUnique($accountNumber, $excludeClientId = null) {
        try {
            $sql = "SELECT COUNT(*) as count FROM clients WHERE account_number = ?";
            $params = [$accountNumber];
            
            if ($excludeClientId) {
                $sql .= " AND id != ?";
                $params[] = $excludeClientId;
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result['count'] == 0;
        } catch (PDOException $e) {
            error_log("Uniqueness check error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Bulk generate account numbers for existing clients without them
     * Useful for migration
     * 
     * @param int $tenantId
     * @return int Number of account numbers generated
     */
    public function backfillAccountNumbers($tenantId) {
        try {
            $count = 0;
            
            // Get all clients without account numbers for this tenant
            $stmt = $this->db->prepare("
                SELECT id FROM clients
                WHERE tenant_id = ? AND (account_number IS NULL OR account_number = '')
                ORDER BY id ASC
            ");
            $stmt->execute([$tenantId]);
            $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($clients as $client) {
                $accountNumber = $this->generateAccountNumber($tenantId);
                
                $updateStmt = $this->db->prepare("
                    UPDATE clients SET account_number = ? WHERE id = ?
                ");
                $updateStmt->execute([$accountNumber, $client['id']]);
                $count++;
            }
            
            return $count;
            
        } catch (Exception $e) {
            error_log("Backfill error: " . $e->getMessage());
            return 0;
        }
    }
}
