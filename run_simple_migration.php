<?php
/**
 * Simple Step-by-Step Migration
 * Runs each SQL statement individually for better error handling
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'includes/db_master.php';

echo "===================================\n";
echo "Step-by-Step Migration\n";
echo "===================================\n\n";

$migrations = [
    // Create tenants table
    "CREATE TABLE IF NOT EXISTS tenants (
        id INT AUTO_INCREMENT PRIMARY KEY,
        subdomain VARCHAR(63) UNIQUE NOT NULL,
        company_name VARCHAR(255) NOT NULL,
        admin_user_id INT,
        provisioning_token VARCHAR(255),
        status ENUM('active', 'suspended', 'trial') DEFAULT 'active',
        trial_ends_at DATETIME NULL,
        max_clients INT DEFAULT 100,
        max_routers INT DEFAULT 10,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_subdomain (subdomain),
        INDEX idx_admin_user (admin_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    
    // Add columns to users table
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS tenant_id INT NULL AFTER id",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS is_super_admin BOOLEAN DEFAULT FALSE AFTER role",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS account_prefix VARCHAR(10) NULL AFTER username",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS email_verified BOOLEAN DEFAULT FALSE AFTER email",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS verification_token VARCHAR(255) NULL AFTER email_verified",
    
    // Add columns to clients table
    "ALTER TABLE clients ADD COLUMN IF NOT EXISTS tenant_id INT NULL AFTER id",
    "ALTER TABLE clients ADD COLUMN IF NOT EXISTS account_number VARCHAR(20) NULL AFTER tenant_id",
    "ALTER TABLE clients ADD COLUMN IF NOT EXISTS full_name VARCHAR(255) NULL AFTER account_number",
    "ALTER TABLE clients ADD COLUMN IF NOT EXISTS user_type ENUM('pppoe', 'hotspot') DEFAULT 'pppoe' AFTER status",
    "ALTER TABLE clients ADD COLUMN IF NOT EXISTS mikrotik_username VARCHAR(100) NULL AFTER user_type",
    
    // Add index for account_number
    "ALTER TABLE clients ADD UNIQUE INDEX IF NOT EXISTS idx_account_number (tenant_id, account_number)",
    
    // Add columns to mikrotik_routers table
    "ALTER TABLE mikrotik_routers ADD COLUMN IF NOT EXISTS tenant_id INT NULL AFTER id",
    "ALTER TABLE mikrotik_routers ADD COLUMN IF NOT EXISTS mac_address VARCHAR(17) NULL AFTER ip_address",
    "ALTER TABLE mikrotik_routers ADD COLUMN IF NOT EXISTS identity VARCHAR(255) NULL AFTER name",
    "ALTER TABLE mikrotik_routers ADD COLUMN IF NOT EXISTS last_seen TIMESTAMP NULL AFTER status",
    
    // Add columns to packages table
    "ALTER TABLE packages ADD COLUMN IF NOT EXISTS tenant_id INT NULL AFTER id",
    
    // Add columns to payments table (if exists)
    "ALTER TABLE payments ADD COLUMN IF NOT EXISTS tenant_id INT NULL AFTER id",
    
    // Create payment_gateways table
    "CREATE TABLE IF NOT EXISTS payment_gateways (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        gateway_type ENUM('paybill_no_api', 'mpesa_api', 'bank_account', 'kopo_kopo', 'paypal') NOT NULL,
        gateway_name VARCHAR(255) NOT NULL,
        credentials TEXT NOT NULL,
        is_active BOOLEAN DEFAULT TRUE,
        is_default BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_tenant (tenant_id),
        INDEX idx_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    
    // Create router_services table
    "CREATE TABLE IF NOT EXISTS router_services (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        router_id INT NOT NULL,
        client_id INT NOT NULL,
        service_type ENUM('pppoe', 'hotspot') NOT NULL,
        package_id INT,
        username VARCHAR(100) NOT NULL,
        password VARCHAR(100) NOT NULL,
        status ENUM('active', 'suspended', 'expired') DEFAULT 'active',
        deployed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_service (tenant_id, router_id, client_id, service_type),
        INDEX idx_tenant (tenant_id),
        INDEX idx_router (router_id),
        INDEX idx_client (client_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    
    // Create tenant_settings table
    "CREATE TABLE IF NOT EXISTS tenant_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        setting_key VARCHAR(100) NOT NULL,
        setting_value TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_setting (tenant_id, setting_key),
        INDEX idx_tenant (tenant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
];

$successCount = 0;
$errorCount = 0;
$skippedCount = 0;

foreach ($migrations as $index => $sql) {
    try {
        $pdo->exec($sql);
        $successCount++;
        
        // Extract operation type
        if (preg_match('/CREATE TABLE.*?(\w+)/i', $sql, $matches)) {
            echo "✓ Created table: {$matches[1]}\n";
        } elseif (preg_match('/ALTER TABLE (\w+) ADD COLUMN.*?(\w+)/i', $sql, $matches)) {
            echo "✓ Added column: {$matches[1]}.{$matches[2]}\n";
        } elseif (preg_match('/ALTER TABLE (\w+) ADD.*INDEX/i', $sql, $matches)) {
            echo "✓ Added index to: {$matches[1]}\n";
        } else {
            echo "✓ Statement " . ($index + 1) . " executed\n";
        }
        
    } catch (PDOException $e) {
        $errorMsg = $e->getMessage();
        
        // Skip if column/table already exists
        if (strpos($errorMsg, 'Duplicate column') !== false ||
            strpos($errorMsg, 'Duplicate key') !== false ||
            strpos($errorMsg, 'already exists') !== false) {
            $skippedCount++;
            if (preg_match('/column.*?(\w+)/i', $errorMsg, $matches)) {
                echo "⊘ Skipped (already exists): {$matches[1]}\n";
            }
        } else {
            $errorCount++;
            echo "✗ Error in statement " . ($index + 1) . ": " . $errorMsg . "\n";
        }
    }
}

echo "\n===================================\n";
echo "Migration Summary\n";
echo "===================================\n";
echo "Successful: $successCount\n";
echo "Skipped: $skippedCount\n";
echo "Errors: $errorCount\n";
echo "\n";

// Verify tables
echo "Verifying schema...\n\n";

$requiredTables = ['tenants', 'payment_gateways', 'router_services', 'tenant_settings'];
foreach ($requiredTables as $table) {
    $result = $pdo->query("SHOW TABLES LIKE '$table'");
    if ($result->rowCount() > 0) {
        echo "✓ Table '$table' exists\n";
    } else {
        echo "✗ Table '$table' is missing!\n";
    }
}

echo "\n";
echo "Migration complete!\n";
echo "You can now run: php verify_account_manual.php\n";
