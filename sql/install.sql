-- =====================================================
-- FortuNett Technologies — Complete Install Script
-- Run as: mysql -u root fortunett_technologies < install.sql
-- DO NOT rename the database — it must be fortunett_technologies
-- =====================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = '';

-- Core tables (no FK deps)
CREATE TABLE IF NOT EXISTS `isp_profile` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    business_name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    phone VARCHAR(20),
    subscription_expiry DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `schema_migrations` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    migration_name VARCHAR(100) UNIQUE NOT NULL,
    executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `platform_subscription_plans` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(50) UNIQUE NOT NULL,
    pppoe_fee_per_user DECIMAL(10,2) NOT NULL DEFAULT 25.00,
    hotspot_commission_rate DECIMAL(5,4) NOT NULL DEFAULT 0.0300,
    base_monthly_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    max_clients INT DEFAULT NULL,
    max_routers INT DEFAULT NULL,
    trial_days INT NOT NULL DEFAULT 30,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `platform_subscription_plans` (name, slug, pppoe_fee_per_user, hotspot_commission_rate, base_monthly_fee, max_clients, max_routers, trial_days) VALUES
    ('Starter',    'starter',    25.00, 0.0300, 0.00,    200,  3,    30),
    ('Growth',     'growth',     20.00, 0.0250, 500.00,  1000, 10,   30),
    ('Enterprise', 'enterprise', 15.00, 0.0200, 2000.00, NULL, NULL, 30);

CREATE TABLE IF NOT EXISTS `tenants` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subdomain VARCHAR(50) UNIQUE NOT NULL,
    company_name VARCHAR(100) NOT NULL,
    admin_user_id INT DEFAULT NULL,
    status ENUM('active','suspended','trial','expired') DEFAULT 'trial',
    provisioning_token VARCHAR(64) UNIQUE NOT NULL DEFAULT (SHA2(RAND(), 256)),
    trial_ends_at DATE DEFAULT NULL,
    subscription_ends_at DATE DEFAULT NULL,
    subscription_plan_id INT DEFAULT NULL,
    max_clients INT DEFAULT 100,
    max_routers INT DEFAULT 5,
    notes TEXT DEFAULT NULL,
    suspended_at TIMESTAMP NULL DEFAULT NULL,
    suspended_reason VARCHAR(255) DEFAULT NULL,
    contact_phone VARCHAR(30) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_subdomain (subdomain),
    INDEX idx_provisioning_token (provisioning_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `users` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(100),
    role ENUM('admin','operator','superadmin') DEFAULT 'operator',
    tenant_id INT DEFAULT NULL,
    is_super_admin TINYINT(1) DEFAULT 0,
    is_verified TINYINT(1) NOT NULL DEFAULT 0,
    email_verified TINYINT(1) NOT NULL DEFAULT 0,
    verification_token VARCHAR(80) DEFAULT NULL,
    account_prefix VARCHAR(10) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tenant_id (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `packages` (
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
    status ENUM('active','inactive') DEFAULT 'active',
    tenant_id INT DEFAULT NULL,
    validity_value INT DEFAULT 30,
    validity_unit VARCHAR(20) DEFAULT 'days',
    device_limit INT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tenant_id (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `clients` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    phone VARCHAR(20),
    address TEXT,
    company VARCHAR(255),
    mikrotik_username VARCHAR(50) DEFAULT NULL,
    status ENUM('active','inactive','suspended') DEFAULT 'inactive',
    user_type ENUM('pppoe','hotspot') DEFAULT 'pppoe',
    connection_type ENUM('pppoe','hotspot') DEFAULT 'hotspot',
    subscription_plan VARCHAR(50),
    data_limit BIGINT DEFAULT 0,
    download_speed INT DEFAULT 0,
    upload_speed INT DEFAULT 0,
    monthly_fee DECIMAL(10,2) DEFAULT 0,
    last_payment_date DATE DEFAULT NULL,
    next_payment_date DATE DEFAULT NULL,
    tenant_id INT DEFAULT NULL,
    account_number VARCHAR(20) DEFAULT NULL,
    auth_password VARCHAR(255) DEFAULT NULL,
    mikrotik_password VARCHAR(255) DEFAULT NULL,
    username VARCHAR(100) DEFAULT NULL,
    package_id INT DEFAULT NULL,
    package_price DECIMAL(10,2) DEFAULT 0,
    account_balance DECIMAL(10,2) DEFAULT 0,
    expiry_date DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_account_number (account_number),
    INDEX idx_tenant_id (tenant_id),
    INDEX idx_account_number (account_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `mikrotik_routers` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    username VARCHAR(50) NOT NULL DEFAULT 'admin',
    password VARCHAR(255) NOT NULL,
    api_port INT DEFAULT 8728,
    mac_address VARCHAR(17),
    identity VARCHAR(100),
    status ENUM('active','inactive','pending') DEFAULT 'pending',
    last_seen TIMESTAMP NULL,
    tenant_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tenant_id (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `payment_gateways` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    gateway_type ENUM('paybill_no_api','mpesa_api','bank_account','kopo_kopo','paypal') NOT NULL,
    gateway_name VARCHAR(100) NOT NULL,
    credentials TEXT NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    is_default TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tenant_active (tenant_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `payments` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT DEFAULT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_method ENUM('mpesa','cash','bank_transfer','card') DEFAULT 'cash',
    payment_date DATE NOT NULL,
    transaction_id VARCHAR(100),
    invoice VARCHAR(100) DEFAULT NULL,
    message TEXT DEFAULT NULL,
    status ENUM('completed','pending','failed') DEFAULT 'completed',
    notes TEXT,
    tenant_id INT DEFAULT NULL,
    gateway_id INT DEFAULT NULL,
    collection_type VARCHAR(20) NOT NULL DEFAULT 'direct',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_payments_invoice (invoice(50)),
    INDEX idx_tenant_id (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `mpesa_transactions` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    merchant_request_id VARCHAR(100),
    checkout_request_id VARCHAR(100),
    result_code INT,
    result_desc TEXT,
    amount DECIMAL(10,2),
    mpesa_receipt_number VARCHAR(50),
    transaction_date VARCHAR(20),
    phone_number VARCHAR(20),
    client_id INT DEFAULT NULL,
    payment_id INT DEFAULT NULL,
    status ENUM('pending','completed','failed','cancelled') DEFAULT 'pending',
    tenant_id INT DEFAULT NULL,
    raw_callback TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_checkout_request (checkout_request_id),
    INDEX idx_tenant_id (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `messages` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT DEFAULT NULL,
    tenant_id INT DEFAULT NULL,
    message_type ENUM('reminder','payment','credential','general','alert') DEFAULT 'general',
    subject VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('unread','read','archived') DEFAULT 'unread',
    priority ENUM('low','normal','high','urgent') DEFAULT 'normal',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at TIMESTAMP NULL,
    INDEX idx_tenant_id (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `tenant_settings` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    setting_key VARCHAR(50) NOT NULL,
    setting_value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_tenant_setting (tenant_id, setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `customer_sessions` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    tenant_id INT DEFAULT NULL,
    session_token VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_session_token (session_token),
    INDEX idx_client_id (client_id),
    INDEX idx_tenant_id (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `vouchers` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    voucher_code VARCHAR(50) UNIQUE NOT NULL,
    package_id INT DEFAULT NULL,
    tenant_id INT DEFAULT NULL,
    duration_days INT DEFAULT 30,
    price DECIMAL(10,2) DEFAULT 0,
    status ENUM('active','used','expired') DEFAULT 'active',
    used_by_client_id INT DEFAULT NULL,
    used_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    created_by_user_id INT DEFAULT NULL,
    INDEX idx_voucher_code (voucher_code),
    INDEX idx_tenant_id (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `payment_auto_logins` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    tenant_id INT DEFAULT NULL,
    payment_id INT DEFAULT NULL,
    login_token VARCHAR(255) UNIQUE NOT NULL,
    ip_address VARCHAR(45),
    mac_address VARCHAR(17),
    status ENUM('pending','used','expired') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    used_at TIMESTAMP NULL,
    INDEX idx_login_token (login_token),
    INDEX idx_tenant_id (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `customer_activity_log` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    tenant_id INT DEFAULT NULL,
    activity_type ENUM('login','logout','payment','plan_change','profile_update','password_change') NOT NULL,
    description TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_client_id (client_id),
    INDEX idx_tenant_id (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `router_services` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    router_id INT NOT NULL,
    client_id INT NOT NULL,
    service_type ENUM('pppoe','hotspot') NOT NULL,
    package_id INT DEFAULT NULL,
    username VARCHAR(100) NOT NULL,
    password VARCHAR(100) NOT NULL,
    status ENUM('active','suspended','expired') DEFAULT 'active',
    deployed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `sms_configurations` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    provider VARCHAR(50) DEFAULT 'talksasa',
    api_url VARCHAR(255),
    api_key VARCHAR(255),
    sender_id VARCHAR(50),
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_tenant_provider (tenant_id, provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `email_configurations` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    smtp_host VARCHAR(255),
    smtp_port INT DEFAULT 587,
    smtp_username VARCHAR(255),
    smtp_password VARCHAR(255),
    from_email VARCHAR(255),
    from_name VARCHAR(255),
    encryption ENUM('tls','ssl','none') DEFAULT 'tls',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_tenant_email_config (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `platform_invoices` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_number VARCHAR(30) UNIQUE NOT NULL,
    tenant_id INT NOT NULL,
    billing_period DATE NOT NULL,
    plan_id INT DEFAULT NULL,
    pppoe_user_count INT NOT NULL DEFAULT 0,
    pppoe_fee_per_user DECIMAL(10,2) NOT NULL DEFAULT 25.00,
    pppoe_subtotal DECIMAL(15,2) GENERATED ALWAYS AS (pppoe_user_count * pppoe_fee_per_user) STORED,
    hotspot_collections DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    hotspot_commission_rate DECIMAL(5,4) NOT NULL DEFAULT 0.0300,
    hotspot_commission DECIMAL(15,2) GENERATED ALWAYS AS (hotspot_collections * hotspot_commission_rate) STORED,
    base_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_due DECIMAL(15,2) GENERATED ALWAYS AS (pppoe_user_count * pppoe_fee_per_user + hotspot_collections * hotspot_commission_rate + base_fee) STORED,
    status ENUM('pending','paid','overdue','waived','cancelled') NOT NULL DEFAULT 'pending',
    due_date DATE NOT NULL,
    paid_at TIMESTAMP NULL,
    payment_method VARCHAR(50) DEFAULT NULL,
    transaction_ref VARCHAR(100) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_tenant_period (tenant_id, billing_period),
    INDEX idx_status (status),
    INDEX idx_due_date (due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `platform_mpesa_config` (
    id INT NOT NULL DEFAULT 1,
    consumer_key VARCHAR(255) NOT NULL DEFAULT '',
    consumer_secret VARCHAR(255) NOT NULL DEFAULT '',
    passkey VARCHAR(255) NOT NULL DEFAULT '',
    shortcode VARCHAR(20) NOT NULL DEFAULT '',
    shortcode_type ENUM('paybill','till') NOT NULL DEFAULT 'paybill',
    environment ENUM('sandbox','live','production') NOT NULL DEFAULT 'sandbox',
    callback_url VARCHAR(512) NOT NULL DEFAULT '',
    c2b_validation_url VARCHAR(512) NOT NULL DEFAULT '',
    c2b_confirmation_url VARCHAR(512) NOT NULL DEFAULT '',
    notes TEXT DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `platform_mpesa_config` (id) VALUES (1);

CREATE TABLE IF NOT EXISTS `platform_email_config` (
    id INT NOT NULL DEFAULT 1,
    smtp_host VARCHAR(255) DEFAULT NULL,
    smtp_port INT DEFAULT 587,
    smtp_username VARCHAR(255) DEFAULT NULL,
    smtp_password TEXT DEFAULT NULL,
    from_email VARCHAR(255) DEFAULT NULL,
    from_name VARCHAR(100) DEFAULT 'FortuNett Technologies',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `platform_email_config` (id) VALUES (1);

CREATE TABLE IF NOT EXISTS `platform_sms_config` (
    id INT NOT NULL DEFAULT 1,
    provider VARCHAR(50) DEFAULT 'talksasa',
    api_key VARCHAR(255) DEFAULT NULL,
    sender_id VARCHAR(50) DEFAULT NULL,
    api_url VARCHAR(255) DEFAULT 'https://api.talksasa.com/v1/sms/send',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `platform_sms_config` (id) VALUES (1);

CREATE TABLE IF NOT EXISTS `platform_settings` (
    id INT NOT NULL AUTO_INCREMENT,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY setting_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `platform_settings` (setting_key, setting_value) VALUES
    ('platform_name',         'FortuNett Technologies'),
    ('platform_tagline',      'Multi-Tenant ISP Management'),
    ('platform_domain',       'fortunetttech.site'),
    ('support_email',         'support@fortunetttech.site'),
    ('support_phone',         ''),
    ('platform_logo_url',     ''),
    ('default_trial_days',    '14'),
    ('require_email_verify',  '1'),
    ('auto_assign_plan_slug', 'starter'),
    ('signup_enabled',        '1'),
    ('signup_welcome_message','');

-- Seed data
INSERT IGNORE INTO `isp_profile` (business_name, email, phone, subscription_expiry)
VALUES ('FortuNett Technologies', 'admin@fortunetttech.site', '+254700000000', DATE_ADD(CURDATE(), INTERVAL 365 DAY));

INSERT IGNORE INTO `schema_migrations` (migration_name) VALUES
    ('install-v2.0'),
    ('2026-03-26-saas-upgrade'),
    ('2026-03-28-platform-comms'),
    ('2026-03-28-platform-mpesa'),
    ('2026-04-09-platform-settings');

SET FOREIGN_KEY_CHECKS = 1;
