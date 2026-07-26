-- Migration: 2026-07-26-platform-collections
--
-- Lets tenants pay their platform invoices into the shared paybill using a short
-- permanent code (FN<id>), and gives the super admin somewhere to actually see
-- the money.
--
-- Before this, platform_invoices could only be settled by hand, and the tables
-- that record who collected money on whose behalf (isp_payout_queue,
-- platform_commissions, ledger_entries) were written by the payment pipeline but
-- read by nothing at all.
--
-- Idempotent — safe to re-run.

-- ── tenants.platform_billing_code ─────────────────────────────────────────────
SET @has_col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'platform_billing_code'
);
SET @sql := IF(@has_col = 0,
    'ALTER TABLE tenants ADD COLUMN platform_billing_code VARCHAR(16) DEFAULT NULL',
    'SELECT 1 -- tenants.platform_billing_code already present'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- Backfill: FN + tenant id. Short, permanent, unique, and easy to read out over
-- the phone — which matters when someone is standing at an M-Pesa till.
UPDATE tenants
   SET platform_billing_code = CONCAT('FN', id)
 WHERE platform_billing_code IS NULL OR platform_billing_code = '';

SET @has_idx := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenants' AND INDEX_NAME = 'uniq_platform_billing_code'
);
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE tenants ADD UNIQUE KEY uniq_platform_billing_code (platform_billing_code)',
    'SELECT 1 -- unique index already present'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- ── platform_invoices.amount_paid ─────────────────────────────────────────────
-- Supports part-payment: a tenant paying KSH 500 against a KSH 1,200 invoice
-- should reduce the balance, not be rejected or silently over-credit.
SET @has_col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'platform_invoices' AND COLUMN_NAME = 'amount_paid'
);
SET @sql := IF(@has_col = 0,
    'ALTER TABLE platform_invoices ADD COLUMN amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER total_due',
    'SELECT 1 -- platform_invoices.amount_paid already present'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- Existing settled invoices are fully paid by definition
UPDATE platform_invoices SET amount_paid = total_due WHERE status = 'paid' AND amount_paid = 0;

-- ── platform_payments — money tenants send US ─────────────────────────────────
CREATE TABLE IF NOT EXISTS platform_payments (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id      INT NOT NULL,
    amount         DECIMAL(12,2) NOT NULL,
    allocated      DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'How much has been applied to invoices; remainder is credit',
    phone          VARCHAR(20)  DEFAULT NULL,
    mpesa_receipt  VARCHAR(32)  DEFAULT NULL,
    bill_ref       VARCHAR(64)  DEFAULT NULL COMMENT 'What the payer actually typed',
    source         VARCHAR(16)  NOT NULL DEFAULT 'c2b',
    raw_callback   TEXT         DEFAULT NULL,
    paid_at        DATETIME     NOT NULL,
    created_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_platform_receipt (mpesa_receipt),
    INDEX idx_pp_tenant (tenant_id),
    INDEX idx_pp_paid   (paid_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── platform_payment_allocations — which invoice each shilling settled ────────
CREATE TABLE IF NOT EXISTS platform_payment_allocations (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    payment_id  INT NOT NULL,
    invoice_id  INT NOT NULL,
    amount      DECIMAL(12,2) NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ppa_payment (payment_id),
    INDEX idx_ppa_invoice (invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
