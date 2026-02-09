-- =====================================================
-- Multi-Tenant Schema Migration
-- =====================================================
-- This migration adds multi-tenancy support to the ISP management system
-- Each admin user gets their own tenant with isolated data and subdomain

-- =====================================================
-- 1. Create Tenants Table
-- =====================================================
CREATE TABLE IF NOT EXISTS tenants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subdomain VARCHAR(50) UNIQUE NOT NULL,
    company_name VARCHAR(100) NOT NULL,
    admin_user_id INT,
    status ENUM('active', 'suspended', 'trial', 'expired') DEFAULT 'trial',
    provisioning_token VARCHAR(64) UNIQUE NOT NULL,
    trial_ends_at DATE,
    subscription_ends_at DATE,
    max_clients INT DEFAULT 100,
    max_routers INT DEFAULT 5,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_subdomain (subdomain),
    INDEX idx_provisioning_token (provisioning_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 2. Modify Users Table for Multi-Tenancy
-- =====================================================
-- Add tenant_id if it doesn't exist
ALTER TABLE users 
ADD COLUMN IF NOT EXISTS tenant_id INT DEFAULT NULL,
ADD COLUMN IF NOT EXISTS is_super_admin BOOLEAN DEFAULT FALSE,
ADD COLUMN IF NOT EXISTS account_prefix VARCHAR(10) DEFAULT NULL COMMENT 'Prefix for customer account numbers (e.g., "e" for ecco)',
ADD INDEX IF NOT EXISTS idx_tenant_id (tenant_id);

-- Add foreign key constraint
ALTER TABLE users
ADD CONSTRAINT fk_users_tenant 
FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;

-- =====================================================
-- 3. Modify Clients Table for Multi-Tenancy
-- =====================================================
-- Add tenant_id and account_number
ALTER TABLE clients
ADD COLUMN IF NOT EXISTS tenant_id INT DEFAULT NULL,
ADD COLUMN IF NOT EXISTS account_number VARCHAR(20) UNIQUE DEFAULT NULL COMMENT 'Auto-generated customer account number',
ADD INDEX IF NOT EXISTS idx_tenant_id (tenant_id),
ADD INDEX IF NOT EXISTS idx_account_number (account_number);

-- Add foreign key constraint
ALTER TABLE clients
ADD CONSTRAINT fk_clients_tenant 
FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;

-- =====================================================
-- 4. Modify MikroTik Routers Table for Multi-Tenancy
-- =====================================================
-- Check if mikrotik_routers table exists, if not create it
CREATE TABLE IF NOT EXISTS mikrotik_routers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    username VARCHAR(50) NOT NULL DEFAULT 'admin',
    password VARCHAR(255) NOT NULL,
    api_port INT DEFAULT 8728,
    mac_address VARCHAR(17),
    identity VARCHAR(100),
    status ENUM('active', 'inactive', 'pending') DEFAULT 'pending',
    last_seen TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add tenant_id to routers
ALTER TABLE mikrotik_routers
ADD COLUMN IF NOT EXISTS tenant_id INT DEFAULT NULL,
ADD INDEX IF NOT EXISTS idx_tenant_id (tenant_id);

-- Add foreign key constraint
ALTER TABLE mikrotik_routers
ADD CONSTRAINT fk_routers_tenant 
FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;

-- =====================================================
-- 5. Create Payment Gateways Table
-- =====================================================
CREATE TABLE IF NOT EXISTS payment_gateways (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    gateway_type ENUM('paybill_no_api', 'mpesa_api', 'bank_account', 'kopo_kopo', 'paypal') NOT NULL,
    gateway_name VARCHAR(100) NOT NULL COMMENT 'User-friendly name for this gateway',
    credentials TEXT NOT NULL COMMENT 'Encrypted JSON containing gateway credentials',
    is_active BOOLEAN DEFAULT TRUE,
    is_default BOOLEAN DEFAULT FALSE COMMENT 'Default gateway for this tenant',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_tenant_active (tenant_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 6. Modify Packages Table for Multi-Tenancy
-- =====================================================
ALTER TABLE packages
ADD COLUMN IF NOT EXISTS tenant_id INT DEFAULT NULL COMMENT 'NULL = global package, otherwise tenant-specific',
ADD INDEX IF NOT EXISTS idx_tenant_id (tenant_id);

-- Add foreign key constraint (allow NULL for global packages)
ALTER TABLE packages
ADD CONSTRAINT fk_packages_tenant 
FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;

-- =====================================================
-- 7. Modify Payments Table for Multi-Tenancy
-- =====================================================
ALTER TABLE payments
ADD COLUMN IF NOT EXISTS tenant_id INT DEFAULT NULL,
ADD COLUMN IF NOT EXISTS gateway_id INT DEFAULT NULL COMMENT 'Which payment gateway was used',
ADD INDEX IF NOT EXISTS idx_tenant_id (tenant_id);

-- Add foreign key constraints
ALTER TABLE payments
ADD CONSTRAINT fk_payments_tenant 
FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;

ALTER TABLE payments
ADD CONSTRAINT fk_payments_gateway 
FOREIGN KEY (gateway_id) REFERENCES payment_gateways(id) ON DELETE SET NULL;

-- =====================================================
-- 8. Create Tenant Settings Table
-- =====================================================
CREATE TABLE IF NOT EXISTS tenant_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    setting_key VARCHAR(50) NOT NULL,
    setting_value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY unique_tenant_setting (tenant_id, setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 9. Create Router Services Deployment Table
-- =====================================================
CREATE TABLE IF NOT EXISTS router_services (
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
    expires_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (router_id) REFERENCES mikrotik_routers(id) ON DELETE CASCADE,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE SET NULL,
    INDEX idx_tenant_client (tenant_id, client_id),
    INDEX idx_router_service (router_id, service_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 10. Create Migration Tracking Table
-- =====================================================
CREATE TABLE IF NOT EXISTS schema_migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    migration_name VARCHAR(100) UNIQUE NOT NULL,
    executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Record this migration
INSERT INTO schema_migrations (migration_name) 
VALUES ('multi_tenant_schema') 
ON DUPLICATE KEY UPDATE executed_at = CURRENT_TIMESTAMP;

-- =====================================================
-- 11. Generate Provisioning Tokens for Existing Data
-- =====================================================
-- Update any existing admins to have unique provisioning tokens
UPDATE users 
SET account_prefix = LEFT(username, 1)
WHERE role = 'admin' AND account_prefix IS NULL;
