-- =====================================================
-- Tenant Billing System Schema
-- =====================================================

CREATE TABLE IF NOT EXISTS tenant_bills (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    billing_period DATE NOT NULL, -- The first day of the month being billed (e.g., 2023-10-01)
    
    -- Calculation details
    total_collections DECIMAL(15, 2) DEFAULT 0.00 COMMENT 'Total revenue collected by tenant in this period',
    base_fee DECIMAL(10, 2) DEFAULT 500.00 COMMENT 'Fixed monthly fee',
    commission_rate DECIMAL(5, 2) DEFAULT 10.00 COMMENT 'Percentage charged on collections',
    commission_amount DECIMAL(15, 2) DEFAULT 0.00 COMMENT 'Calculated commission',
    
    total_due DECIMAL(15, 2) GENERATED ALWAYS AS (base_fee + commission_amount) STORED,
    
    status ENUM('pending', 'paid', 'overdue', 'cancelled') DEFAULT 'pending',
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    paid_at TIMESTAMP NULL,
    transaction_ref VARCHAR(100) NULL,
    
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY unique_tenant_period (tenant_id, billing_period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional: Create a view or procedure to generate bills, but we might do it in PHP for now.
