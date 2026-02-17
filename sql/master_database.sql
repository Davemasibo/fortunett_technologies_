-- =====================================================
-- Fortunnet Technologies - Master Database Schema
-- Combined and Unified for easy migration
-- =====================================================

-- 1. Create Database and Basic Schema
CREATE DATABASE IF NOT EXISTS fortunnet_technologies;
USE fortunnet_technologies;

CREATE TABLE IF NOT EXISTS isp_profile (
    id INT AUTO_INCREMENT PRIMARY KEY,
    business_name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    phone VARCHAR(20),
    subscription_expiry DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(100),
    role ENUM('admin', 'operator') DEFAULT 'operator',
    tenant_id INT DEFAULT NULL,
    is_super_admin BOOLEAN DEFAULT FALSE,
    account_prefix VARCHAR(10) DEFAULT NULL COMMENT 'Prefix for customer account numbers',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tenant_id (tenant_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    phone VARCHAR(20),
    address TEXT,
    company VARCHAR(255),
    mikrotik_username VARCHAR(50) UNIQUE,
    status ENUM('active', 'inactive', 'suspended') DEFAULT 'inactive',
    user_type ENUM('pppoe','hotspot') DEFAULT 'pppoe',
    subscription_plan VARCHAR(50),
    data_limit BIGINT DEFAULT 0,
    download_speed INT DEFAULT 0,
    upload_speed INT DEFAULT 0,
    monthly_fee DECIMAL(10,2) DEFAULT 0,
    last_payment_date DATE,
    next_payment_date DATE,
    tenant_id INT DEFAULT NULL,
    account_number VARCHAR(20) UNIQUE DEFAULT NULL COMMENT 'Auto-generated customer account number',
    auth_password VARCHAR(255) COMMENT 'Hashed password for customer portal login',
    package_id INT COMMENT 'Current package ID',
    package_price DECIMAL(10,2) DEFAULT 0 COMMENT 'Current package price',
    account_balance DECIMAL(10,2) DEFAULT 0 COMMENT 'Account balance',
    expiry_date DATETIME COMMENT 'Subscription expiry date',
    connection_type ENUM('pppoe', 'hotspot') DEFAULT 'hotspot',
    username VARCHAR(100) COMMENT 'Customer portal username',
    mikrotik_password VARCHAR(255) COMMENT 'MikroTik connection password',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tenant_id (tenant_id),
    INDEX idx_account_number (account_number),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
    tenant_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tenant_id (tenant_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS packages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('hotspot','pppoe') DEFAULT 'hotspot',
    name VARCHAR(255) NOT NULL,
    description TEXT,
    price DECIMAL(10,2) NOT NULL,
    duration VARCHAR(50),
    features TEXT,
    download_speed INT DEFAULT 0,
    upload_speed INT DEFAULT 0,
    data_limit BIGINT DEFAULT 0,
    status ENUM('active', 'inactive') DEFAULT 'active',
    tenant_id INT DEFAULT NULL COMMENT 'NULL = global package, otherwise tenant-specific',
    validity_value INT DEFAULT 30,
    validity_unit VARCHAR(20) DEFAULT 'days',
    device_limit INT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tenant_id (tenant_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add foreign key to clients after packages is created
ALTER TABLE clients ADD CONSTRAINT fk_clients_package FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE SET NULL;

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

CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT,
    amount DECIMAL(10,2) NOT NULL,
    payment_method ENUM('mpesa', 'cash', 'bank_transfer', 'card') DEFAULT 'cash',
    payment_date DATE NOT NULL,
    transaction_id VARCHAR(100),
    invoice VARCHAR(100) NULL,
    message TEXT NULL,
    status ENUM('completed', 'pending', 'failed') DEFAULT 'completed',
    notes TEXT,
    tenant_id INT DEFAULT NULL,
    gateway_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (gateway_id) REFERENCES payment_gateways(id) ON DELETE SET NULL,
    INDEX idx_payments_invoice (invoice(50)),
    INDEX idx_tenant_id (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT,
    message_type ENUM('reminder', 'payment', 'credential', 'general', 'alert') DEFAULT 'general',
    subject VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('unread', 'read', 'archived') DEFAULT 'unread',
    priority ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'normal',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at TIMESTAMP NULL,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tenant_bills (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    billing_period DATE NOT NULL,
    total_collections DECIMAL(15, 2) DEFAULT 0.00,
    base_fee DECIMAL(10, 2) DEFAULT 500.00,
    commission_rate DECIMAL(5, 2) DEFAULT 10.00,
    commission_amount DECIMAL(15, 2) DEFAULT 0.00,
    total_due DECIMAL(15, 2) GENERATED ALWAYS AS (base_fee + commission_amount) STORED,
    status ENUM('pending', 'paid', 'overdue', 'cancelled') DEFAULT 'pending',
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    paid_at TIMESTAMP NULL,
    transaction_ref VARCHAR(100) NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY unique_tenant_period (tenant_id, billing_period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS schema_migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    migration_name VARCHAR(100) UNIQUE NOT NULL,
    executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_configurations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    smtp_host VARCHAR(255),
    smtp_port INT DEFAULT 587,
    smtp_username VARCHAR(255),
    smtp_password VARCHAR(255),
    from_email VARCHAR(255),
    from_name VARCHAR(255),
    encryption ENUM('tls', 'ssl', 'none') DEFAULT 'tls',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY unique_tenant_email_config (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    template_key VARCHAR(50) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    body_content TEXT NOT NULL,
    variables TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY unique_tenant_email_template (tenant_id, template_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_outbox (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    client_id INT NULL,
    recipient_email VARCHAR(255) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    message_body TEXT,
    status ENUM('pending', 'sent', 'failed') DEFAULT 'pending',
    error_message TEXT,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    INDEX idx_tenant_email_status (tenant_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sms_configurations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    provider VARCHAR(50) DEFAULT 'talksasa',
    api_url VARCHAR(255),
    api_key VARCHAR(255),
    partner_id VARCHAR(100) NULL,
    shortcode VARCHAR(50) NULL,
    sender_id VARCHAR(50),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY unique_tenant_provider (tenant_id, provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sms_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    template_key VARCHAR(50) NOT NULL,
    template_name VARCHAR(100) NOT NULL,
    template_content TEXT NOT NULL,
    variables TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY unique_tenant_template (tenant_id, template_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sms_outbox (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    client_id INT NULL,
    recipient_phone VARCHAR(20) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('pending', 'sent', 'failed', 'delivered') DEFAULT 'pending',
    provider_response TEXT,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE set NULL,
    INDEX idx_tenant_status (tenant_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    session_token VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45),
    mac_address VARCHAR(17),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    INDEX idx_session_token (session_token),
    INDEX idx_client_id (client_id),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vouchers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    voucher_code VARCHAR(50) UNIQUE NOT NULL,
    package_id INT,
    duration_days INT DEFAULT 30,
    price DECIMAL(10,2) DEFAULT 0,
    status ENUM('active', 'used', 'expired') DEFAULT 'active',
    used_by_client_id INT NULL,
    used_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    created_by_user_id INT,
    FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE SET NULL,
    FOREIGN KEY (used_by_client_id) REFERENCES clients(id) ON DELETE SET NULL,
    INDEX idx_voucher_code (voucher_code),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_auto_logins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    payment_id INT,
    login_token VARCHAR(255) UNIQUE NOT NULL,
    ip_address VARCHAR(45),
    mac_address VARCHAR(17),
    status ENUM('pending', 'used', 'expired') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    used_at TIMESTAMP NULL,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    INDEX idx_login_token (login_token),
    INDEX idx_status (status),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    activity_type ENUM('login', 'logout', 'payment', 'plan_change', 'profile_update', 'password_change') NOT NULL,
    description TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    INDEX idx_client_id (client_id),
    INDEX idx_activity_type (activity_type),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 2. Insert Default Data
-- =====================================================

INSERT IGNORE INTO isp_profile (business_name, email, phone, subscription_expiry) 
VALUES ('Fortunnet Technologies', 'admin@fortunnet.com', '+254700000000', DATE_ADD(CURDATE(), INTERVAL 30 DAY));

-- Super Admin Setup
INSERT IGNORE INTO users (username, password_hash, email, role, is_super_admin) 
VALUES ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@fortunnet.com', 'admin', TRUE);

-- Sample Data (Optional)
-- INSERT INTO packages ...
-- INSERT INTO clients ...

-- =====================================================
-- 3. Run Migrations & Records
-- =====================================================
INSERT INTO schema_migrations (migration_name) VALUES ('initial_master_v1.0');
