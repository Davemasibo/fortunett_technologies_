-- Customer Portal Database Schema Migration
-- Created: 2026-01-03
-- Description: Add tables and fields for customer portal functionality

USE fortunnet_technologies;

-- Add customer portal authentication fields to clients table
ALTER TABLE clients 
ADD COLUMN IF NOT EXISTS auth_password VARCHAR(255) COMMENT 'Hashed password for customer portal login',
ADD COLUMN IF NOT EXISTS package_id INT COMMENT 'Current package ID',
ADD COLUMN IF NOT EXISTS package_price DECIMAL(10,2) DEFAULT 0 COMMENT 'Current package price',
ADD COLUMN IF NOT EXISTS account_balance DECIMAL(10,2) DEFAULT 0 COMMENT 'Account balance',
ADD COLUMN IF NOT EXISTS expiry_date DATETIME COMMENT 'Subscription expiry date',
ADD COLUMN IF NOT EXISTS connection_type ENUM('pppoe', 'hotspot') DEFAULT 'hotspot',
ADD COLUMN IF NOT EXISTS username VARCHAR(100) COMMENT 'Customer portal username',
ADD COLUMN IF NOT EXISTS mikrotik_password VARCHAR(255) COMMENT 'MikroTik connection password';

-- Add foreign key for package_id if not exists
SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS 
    WHERE CONSTRAINT_SCHEMA = 'fortunnet_technologies' 
    AND TABLE_NAME = 'clients' 
    AND CONSTRAINT_NAME = 'fk_clients_package');

SET @sql = IF(@fk_exists = 0, 
    'ALTER TABLE clients ADD CONSTRAINT fk_clients_package FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE SET NULL',
    'SELECT "Foreign key already exists" AS message');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Create customer sessions table
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create vouchers table for credential-based access
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
    created_by_user_id INT COMMENT 'Admin user who created this voucher',
    FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE SET NULL,
    FOREIGN KEY (used_by_client_id) REFERENCES clients(id) ON DELETE SET NULL,
    INDEX idx_voucher_code (voucher_code),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create payment auto-login tracking
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create customer activity log
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Update existing clients to have default values
UPDATE clients 
SET account_balance = 0 
WHERE account_balance IS NULL;

UPDATE clients 
SET package_price = 0 
WHERE package_price IS NULL;

-- Set connection_type based on user_type if exists
UPDATE clients 
SET connection_type = user_type 
WHERE user_type IS NOT NULL AND connection_type IS NULL;

SELECT 'Customer Portal Schema Migration Completed Successfully!' as Status;
