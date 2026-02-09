<?php
/**
 * Tenant Management Class
 * 
 * Handles multi-tenant context detection, validation, and data isolation.
 * This class is critical for security - it ensures users can only access
 * data belonging to their tenant.
 */

class TenantManager {
    private $db;
    private static $currentTenant = null;
    private static $instance = null;
    
    private function __construct($db) {
        $this->db = $db;
    }
    
    /**
     * Get singleton instance
     */
    public static function getInstance($db) {
        if (self::$instance === null) {
            self::$instance = new self($db);
        }
        return self::$instance;
    }
    
    /**
     * Detect tenant from subdomain
     * Extracts subdomain from HTTP_HOST and looks up tenant
     * 
     * @return array|null Tenant data or null if not found
     */
    public function detectTenantFromSubdomain() {
        $httpHost = $_SERVER['HTTP_HOST'] ?? '';
        
        // Remove port if present
        $host = explode(':', $httpHost)[0];
        
        // Check if this is a subdomain
        // Pattern: subdomain.fortunetttech.site
        $parts = explode('.', $host);
        
        // If we have at least 3 parts (subdomain.domain.tld), extract subdomain
        if (count($parts) >= 3) {
            $subdomain = $parts[0];
            
            // Skip 'www' subdomain
            if ($subdomain === 'www') {
                return null;
            }
            
            return $this->getTenantBySubdomain($subdomain);
        }
        
        return null;
    }
    
    /**
     * Get tenant by subdomain
     * 
     * @param string $subdomain
     * @return array|null
     */
    public function getTenantBySubdomain($subdomain) {
        try {
            $stmt = $this->db->prepare("
                SELECT t.*, u.username as admin_username, u.email as admin_email
                FROM tenants t
                LEFT JOIN users u ON t.admin_user_id = u.id
                WHERE t.subdomain = ? AND t.status = 'active'
                LIMIT 1
            ");
            $stmt->execute([$subdomain]);
            $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($tenant) {
                self::$currentTenant = $tenant;
            }
            
            return $tenant;
        } catch (PDOException $e) {
            error_log("Tenant lookup error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get tenant by ID
     * 
     * @param int $tenantId
     * @return array|null
     */
    public function getTenantById($tenantId) {
        try {
            $stmt = $this->db->prepare("
                SELECT t.*, u.username as admin_username, u.email as admin_email
                FROM tenants t
                LEFT JOIN users u ON t.admin_user_id = u.id
                WHERE t.id = ?
                LIMIT 1
            ");
            $stmt->execute([$tenantId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Tenant lookup error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get current tenant context
     * 
     * @return array|null
     */
    public function getCurrentTenant() {
        return self::$currentTenant;
    }
    
    /**
     * Set current tenant context
     * Should be called after authentication
     * 
     * @param int $tenantId
     * @return bool
     */
    public function setTenantContext($tenantId) {
        $tenant = $this->getTenantById($tenantId);
        if ($tenant) {
            self::$currentTenant = $tenant;
            $_SESSION['tenant_id'] = $tenantId;
            $_SESSION['tenant_subdomain'] = $tenant['subdomain'];
            return true;
        }
        return false;
    }
    
    /**
     * Validate that a user belongs to a specific tenant
     * SECURITY CRITICAL: Always call this when authenticating
     * 
     * @param int $userId
     * @param int $tenantId
     * @return bool
     */
    public function validateUserBelongsToTenant($userId, $tenantId) {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count
                FROM users
                WHERE id = ? AND tenant_id = ?
            ");
            $stmt->execute([$userId, $tenantId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['count'] > 0;
        } catch (PDOException $e) {
            error_log("Tenant validation error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create new tenant
     * 
     * @param string $subdomain
     * @param string $companyName
     * @param int $adminUserId
     * @return int|false Tenant ID on success, false on failure
     */
    public function createTenant($subdomain, $companyName, $adminUserId = null) {
        try {
            // Generate unique provisioning token
            $provisioningToken = bin2hex(random_bytes(32));
            
            // Calculate trial end date (30 days from now)
            $trialEndsAt = date('Y-m-d', strtotime('+30 days'));
            
            $stmt = $this->db->prepare("
                INSERT INTO tenants (
                    subdomain, 
                    company_name, 
                    admin_user_id, 
                    provisioning_token, 
                    trial_ends_at,
                    status
                ) VALUES (?, ?, ?, ?, ?, 'trial')
            ");
            
            $stmt->execute([
                $subdomain,
                $companyName,
                $adminUserId,
                $provisioningToken,
                $trialEndsAt
            ]);
            
            return (int)$this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log("Tenant creation error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if subdomain is available
     * 
     * @param string $subdomain
     * @return bool
     */
    public function isSubdomainAvailable($subdomain) {
        try {
            // Reserved subdomains
            $reserved = ['www', 'mail', 'ftp', 'admin', 'api', 'app', 'dashboard', 'portal', 'support'];
            
            if (in_array(strtolower($subdomain), $reserved)) {
                return false;
            }
            
            // Check if subdomain already exists
            $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM tenants WHERE subdomain = ?");
            $stmt->execute([$subdomain]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result['count'] == 0;
        } catch (PDOException $e) {
            error_log("Subdomain check error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get tenant provisioning token
     * Used for MikroTik auto-registration
     * 
     * @param int $tenantId
     * @return string|null
     */
    public function getProvisioningToken($tenantId) {
        try {
            $stmt = $this->db->prepare("SELECT provisioning_token FROM tenants WHERE id = ?");
            $stmt->execute([$tenantId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? $result['provisioning_token'] : null;
        } catch (PDOException $e) {
            error_log("Provisioning token error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Validate provisioning token and return tenant ID
     * 
     * @param string $token
     * @return int|null Tenant ID or null if invalid
     */
    public function validateProvisioningToken($token) {
        try {
            $stmt = $this->db->prepare("
                SELECT id FROM tenants 
                WHERE provisioning_token = ? AND status IN ('active', 'trial')
            ");
            $stmt->execute([$token]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? (int)$result['id'] : null;
        } catch (PDOException $e) {
            error_log("Token validation error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Sanitize subdomain - remove invalid characters
     * 
     * @param string $subdomain
     * @return string
     */
    public static function sanitizeSubdomain($subdomain) {
        // Convert to lowercase
        $subdomain = strtolower($subdomain);
        
        // Remove any characters that aren't alphanumeric or hyphen
        $subdomain = preg_replace('/[^a-z0-9-]/', '', $subdomain);
        
        // Remove leading/trailing hyphens
        $subdomain = trim($subdomain, '-');
        
        // Limit length to 50 characters
        $subdomain = substr($subdomain, 0, 50);
        
        return $subdomain;
    }
    
    /**
     * Get tenant statistics
     * 
     * @param int $tenantId
     * @return array
     */
    public function getTenantStats($tenantId) {
        try {
            $stats = [];
            
            // Count clients
            $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM clients WHERE tenant_id = ?");
            $stmt->execute([$tenantId]);
            $stats['total_clients'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            // Count routers
            $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM mikrotik_routers WHERE tenant_id = ?");
            $stmt->execute([$tenantId]);
            $stats['total_routers'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            // Count payment gateways
            $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM payment_gateways WHERE tenant_id = ?");
            $stmt->execute([$tenantId]);
            $stats['total_gateways'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            return $stats;
        } catch (PDOException $e) {
            error_log("Tenant stats error: " . $e->getMessage());
            return [];
        }
    }
}
