-- Confirmed manual payouts, with a payment-level audit trail.
CREATE TABLE IF NOT EXISTS tenant_disbursements (
        id INT AUTO_INCREMENT PRIMARY KEY, tenant_id INT NOT NULL,
        reference VARCHAR(100) NOT NULL, gross_amount DECIMAL(12,2) NOT NULL,
        cash_amount DECIMAL(12,2) NOT NULL, fees_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        platform_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
        disbursed_at DATETIME NOT NULL, recorded_by INT NOT NULL,
        notes VARCHAR(255) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY tenant_reference (tenant_id, reference)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tenant_disbursement_items (
        disbursement_id INT NOT NULL, payment_id INT NOT NULL,
        amount DECIMAL(12,2) NOT NULL, PRIMARY KEY(payment_id),
        KEY disbursement_id (disbursement_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
