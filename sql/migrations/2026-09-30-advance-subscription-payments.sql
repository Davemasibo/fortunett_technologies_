-- Payment amount, purchased periods, and before/after balances.
CREATE TABLE IF NOT EXISTS payment_subscription_grants (
        tenant_id INT NOT NULL, activation_key VARCHAR(191) NOT NULL,
        client_id INT NOT NULL, receipt VARCHAR(191) NOT NULL, package_id INT NOT NULL,
        package_price DECIMAL(12,2) NOT NULL, periods INT NOT NULL,
        validity_value INT NOT NULL, validity_unit VARCHAR(20) NOT NULL,
        previous_expiry DATETIME NULL, expiry_date DATETIME NULL, previous_status VARCHAR(20) NOT NULL,
        previous_balance DECIMAL(12,2) NOT NULL, balance_after DECIMAL(12,2) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY(tenant_id, activation_key), KEY receipt(tenant_id, receipt)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Existing deployments: checkout prices are also added automatically by preparePaymentTerms().
CREATE TABLE IF NOT EXISTS payment_purchase_terms (
    checkout_id VARCHAR(191) PRIMARY KEY, tenant_id INT NOT NULL, client_id INT NOT NULL,
    package_id INT NOT NULL, validity_value INT NOT NULL, validity_unit VARCHAR(20) NOT NULL,
    package_price DECIMAL(12,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
