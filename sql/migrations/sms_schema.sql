-- =====================================================
-- SMS System Schema Migration
-- =====================================================

-- 1. Create SMS Configurations Table (Tenant Specific)
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

-- 2. Create SMS Templates Table (Tenant Specific)
CREATE TABLE IF NOT EXISTS sms_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    template_key VARCHAR(50) NOT NULL, -- e.g., 'welcome', 'payment_reminder'
    template_name VARCHAR(100) NOT NULL,
    template_content TEXT NOT NULL,
    variables TEXT COMMENT 'Comma separated list of available variables',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY unique_tenant_template (tenant_id, template_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Create SMS Logs Table (Tenant Specific)
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

-- Insert Default Templates for all existing tenants
INSERT INTO sms_templates (tenant_id, template_key, template_name, template_content, variables)
SELECT id, 'welcome', 'Welcome Message', 'Hello {name}, welcome to our service. Your username is {username} and password is {password}.', '{name},{username},{password}' FROM tenants;

INSERT INTO sms_templates (tenant_id, template_key, template_name, template_content, variables)
SELECT id, 'payment_reminder', 'Payment Reminder', 'Dear {name}, your subscription expires on {expiry_date}. Please pay KES {amount} to Account: {account_number}.', '{name},{expiry_date},{amount},{account_number}' FROM tenants;
