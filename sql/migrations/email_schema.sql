-- =====================================================
-- Email System Schema Migration
-- =====================================================

-- 1. Create Email Configurations Table (Tenant Specific)
CREATE TABLE IF NOT EXISTS email_configurations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    smtp_host VARCHAR(255),
    smtp_port INT DEFAULT 587,
    smtp_username VARCHAR(255),
    smtp_password VARCHAR(255), -- Will be stored as plain text for now, ideally encrypted
    from_email VARCHAR(255),
    from_name VARCHAR(255),
    encryption ENUM('tls', 'ssl', 'none') DEFAULT 'tls',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY unique_tenant_email_config (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Create Email Templates Table (Tenant Specific)
CREATE TABLE IF NOT EXISTS email_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    template_key VARCHAR(50) NOT NULL, -- e.g., 'welcome', 'invoice_new'
    subject VARCHAR(255) NOT NULL,
    body_content TEXT NOT NULL, -- HTML content
    variables TEXT COMMENT 'Comma separated list of available variables',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY unique_tenant_email_template (tenant_id, template_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Create Email Outbox/Logs Table (Tenant Specific)
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

-- Insert Default Templates for all existing tenants
INSERT INTO email_templates (tenant_id, template_key, subject, body_content, variables)
SELECT id, 'welcome', 'Welcome to {company_name}', '<p>Hello {name},</p><p>Welcome to our service. Your username is <strong>{username}</strong> and password is <strong>{password}</strong>.</p>', '{name},{username},{password},{company_name}' FROM tenants;

INSERT INTO email_templates (tenant_id, template_key, subject, body_content, variables)
SELECT id, 'invoice_reminder', 'Invoice Reminder', '<p>Dear {name},</p><p>This is a reminder that your payment of KES {amount} is due on {expiry_date}.</p><p>Please pay via Paybill: {paybill}, Account: {account_number}.</p>', '{name},{expiry_date},{amount},{account_number},{paybill}' FROM tenants;
