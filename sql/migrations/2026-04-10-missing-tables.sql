-- Migration: Create missing tables for sms.php, emails.php, billing.php
-- Run with: mysql -u root fortunett_technologies < sql/migrations/2026-04-10-missing-tables.sql

SET FOREIGN_KEY_CHECKS=0;

-- ─── SMS outbox log ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sms_outbox` (
    `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`        INT UNSIGNED NOT NULL,
    `client_id`        INT UNSIGNED DEFAULT NULL,
    `recipient_phone`  VARCHAR(20) NOT NULL,
    `message`          TEXT NOT NULL,
    `status`           VARCHAR(20) DEFAULT 'sent',
    `provider_response` TEXT DEFAULT NULL,
    `sent_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_tenant` (`tenant_id`),
    INDEX `idx_client` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── SMS templates ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sms_templates` (
    `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`        INT UNSIGNED DEFAULT NULL,
    `template_key`     VARCHAR(80) NOT NULL,
    `template_name`    VARCHAR(120) NOT NULL,
    `template_content` TEXT NOT NULL,
    `is_global`        TINYINT(1) DEFAULT 0,
    `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_tenant_key` (`tenant_id`, `template_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed global SMS templates
INSERT IGNORE INTO `sms_templates` (`tenant_id`, `template_key`, `template_name`, `template_content`, `is_global`) VALUES
(NULL, 'payment_received',  'Payment Received',   'Dear {name}, your payment of KES {amount} has been received. Your account is active until {expiry}. Thank you!', 1),
(NULL, 'account_expiring',  'Account Expiring',   'Dear {name}, your internet subscription expires on {expiry}. Pay KES {amount} to renew via {paybill}. Thank you!', 1),
(NULL, 'account_expired',   'Account Expired',    'Dear {name}, your internet subscription expired on {expiry}. Please renew to restore service. Contact {support}.', 1),
(NULL, 'welcome',           'Welcome Message',    'Welcome {name}! Your ISP account has been created. Username: {username}. Contact {support} for support.', 1);

-- ─── Email outbox log ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `email_outbox` (
    `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`        INT UNSIGNED NOT NULL,
    `client_id`        INT UNSIGNED DEFAULT NULL,
    `recipient_email`  VARCHAR(255) NOT NULL,
    `subject`          VARCHAR(255) NOT NULL,
    `message_body`     LONGTEXT NOT NULL,
    `status`           VARCHAR(20) DEFAULT 'pending',
    `error_message`    TEXT DEFAULT NULL,
    `sent_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_tenant` (`tenant_id`),
    INDEX `idx_client` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── Email templates ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `email_templates` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`    INT UNSIGNED DEFAULT NULL,
    `template_key` VARCHAR(80) NOT NULL,
    `subject`      VARCHAR(255) NOT NULL,
    `body_content` LONGTEXT NOT NULL,
    `is_global`    TINYINT(1) DEFAULT 0,
    `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_tenant_key` (`tenant_id`, `template_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed global email templates
INSERT IGNORE INTO `email_templates` (`tenant_id`, `template_key`, `subject`, `body_content`, `is_global`) VALUES
(NULL, 'payment_received', 'Payment Received — {company}',
 '<p>Dear {name},</p><p>Your payment of <strong>KES {amount}</strong> has been received. Your account is active until <strong>{expiry}</strong>.</p><p>Thank you for using {company}!</p>', 1),
(NULL, 'account_expiring', 'Your Account is Expiring Soon — {company}',
 '<p>Dear {name},</p><p>Your internet subscription expires on <strong>{expiry}</strong>. Please renew to avoid interruption.</p><p>Pay KES {amount} via Paybill <strong>{paybill}</strong>.</p>', 1),
(NULL, 'welcome', 'Welcome to {company}!',
 '<p>Dear {name},</p><p>Welcome! Your account has been created.<br>Username: <strong>{username}</strong></p><p>Contact us at {support} for any help.</p>', 1);

-- ─── Tenant bills (for billing.php) ──────────────────────────────
CREATE TABLE IF NOT EXISTS `tenant_bills` (
    `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`         INT UNSIGNED NOT NULL,
    `billing_period`    DATE NOT NULL,
    `total_collections` DECIMAL(12,2) DEFAULT 0.00,
    `base_fee`          DECIMAL(12,2) DEFAULT 0.00,
    `commission_rate`   DECIMAL(5,2)  DEFAULT 3.00,
    `commission_amount` DECIMAL(12,2) DEFAULT 0.00,
    `status`            VARCHAR(20) DEFAULT 'pending',
    `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_tenant_period` (`tenant_id`, `billing_period`),
    INDEX `idx_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS=1;
