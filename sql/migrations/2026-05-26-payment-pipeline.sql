-- ============================================================
-- Payment Pipeline Tables
-- Run once: mysql fortunett_technologies < 2026-05-26-payment-pipeline.sql
-- ============================================================

-- Client-facing invoices (one per payment/renewal)
CREATE TABLE IF NOT EXISTS client_invoices (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id      INT           NOT NULL,
    client_id      INT           NOT NULL,
    payment_id     INT           DEFAULT NULL,
    invoice_number VARCHAR(40)   NOT NULL,
    amount         DECIMAL(10,2) NOT NULL,
    description    VARCHAR(255)  DEFAULT NULL,
    status         ENUM('pending','paid','void') NOT NULL DEFAULT 'pending',
    due_date       DATE          DEFAULT NULL,
    paid_at        TIMESTAMP     NULL DEFAULT NULL,
    created_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE  KEY uq_invoice_number (invoice_number),
    INDEX   idx_client      (client_id, tenant_id),
    INDEX   idx_payment     (payment_id),
    INDEX   idx_status      (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Double-entry financial ledger (one debit + one credit per payment)
CREATE TABLE IF NOT EXISTS ledger_entries (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id    INT           NOT NULL,
    client_id    INT           DEFAULT NULL,
    payment_id   INT           DEFAULT NULL,
    entry_type   ENUM('debit','credit') NOT NULL,
    account      VARCHAR(50)   NOT NULL COMMENT 'revenue | receivable | commission | payout',
    amount       DECIMAL(10,2) NOT NULL,
    description  VARCHAR(255)  DEFAULT NULL,
    reference    VARCHAR(100)  DEFAULT NULL COMMENT 'M-Pesa receipt',
    created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tenant    (tenant_id),
    INDEX idx_payment   (payment_id),
    INDEX idx_reference (reference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-payment platform commission (hotspot: % of amount; PPPoE: monthly billed separately)
CREATE TABLE IF NOT EXISTS platform_commissions (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id         INT           NOT NULL,
    client_id         INT           NOT NULL,
    payment_id        INT           NOT NULL,
    connection_type   ENUM('hotspot','pppoe') NOT NULL DEFAULT 'hotspot',
    gross_amount      DECIMAL(10,2) NOT NULL,
    commission_rate   DECIMAL(6,5)  NOT NULL DEFAULT 0.03000,
    commission_amount DECIMAL(10,2) NOT NULL,
    net_amount        DECIMAL(10,2) NOT NULL,
    receipt           VARCHAR(50)   DEFAULT NULL,
    collected         TINYINT(1)    NOT NULL DEFAULT 0,
    created_at        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_payment   (payment_id),
    INDEX      idx_tenant   (tenant_id),
    INDEX      idx_collected (collected)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ISP payout queue — for payments received on the platform paybill that must be disbursed
CREATE TABLE IF NOT EXISTS isp_payout_queue (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id         INT           NOT NULL,
    payment_id        INT           NOT NULL,
    gross_amount      DECIMAL(10,2) NOT NULL,
    commission_amount DECIMAL(10,2) NOT NULL,
    net_amount        DECIMAL(10,2) NOT NULL,
    receipt           VARCHAR(50)   DEFAULT NULL,
    status            ENUM('pending','processing','paid','cancelled') NOT NULL DEFAULT 'pending',
    scheduled_for     DATE          DEFAULT NULL,
    processed_at      TIMESTAMP     NULL DEFAULT NULL,
    notes             TEXT          DEFAULT NULL,
    created_at        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_payment  (payment_id),
    INDEX      idx_tenant  (tenant_id),
    INDEX      idx_status  (status),
    INDEX      idx_schedule (scheduled_for)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
