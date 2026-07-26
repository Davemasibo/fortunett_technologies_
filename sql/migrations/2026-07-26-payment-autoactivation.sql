-- Migration: 2026-07-26-payment-autoactivation
--
-- Fixes the "customer pays but is never auto-activated" bug.
--
-- Both C2B confirmation handlers write a payments row with
-- payment_method = 'mpesa_paybill' and an mpesa_transactions row containing
-- tenant_id, mpesa_receipt_number and raw_callback. None of those existed:
--
--   * payments.payment_method was enum('mpesa','cash','bank_transfer','card').
--     On a strict-mode server the INSERT threw; on a lax one it silently stored
--     an empty string.
--   * mpesa_transactions had no tenant_id / mpesa_receipt_number / raw_callback.
--     A missing column is a hard 1054 error on EVERY configuration, and that
--     INSERT sits *before* process_payment_success() in the handler — so the
--     handler aborted and the client was never activated, even though the money
--     had already been taken. The operator then had to activate by hand.
--
-- tenant_id on mpesa_transactions is also SELECTed by
-- api/payment/hotspot_payment_status.php, so without it the captive portal
-- polls forever after an STK push and never sees 'completed'.
--
-- Idempotent — safe to re-run. Equivalent to: php tools/repair_status_enums.php

-- ── payments.payment_method ───────────────────────────────────────────────────
SET @has_paybill := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'payments'
      AND COLUMN_NAME  = 'payment_method'
      AND COLUMN_TYPE LIKE '%''mpesa_paybill''%'
);
SET @sql := IF(@has_paybill = 0,
    "ALTER TABLE payments MODIFY COLUMN payment_method ENUM('mpesa','cash','bank_transfer','card','mpesa_paybill','mpesa_stk','mpesa_c2b','voucher','manual') NOT NULL DEFAULT 'mpesa'",
    'SELECT 1 -- payments.payment_method already includes mpesa_paybill'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- ── mpesa_transactions.tenant_id ──────────────────────────────────────────────
SET @has_col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mpesa_transactions' AND COLUMN_NAME = 'tenant_id'
);
SET @sql := IF(@has_col = 0,
    'ALTER TABLE mpesa_transactions ADD COLUMN tenant_id INT DEFAULT NULL, ADD INDEX idx_mt_tenant (tenant_id)',
    'SELECT 1 -- mpesa_transactions.tenant_id already present'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- ── mpesa_transactions.mpesa_receipt_number ───────────────────────────────────
SET @has_col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mpesa_transactions' AND COLUMN_NAME = 'mpesa_receipt_number'
);
SET @sql := IF(@has_col = 0,
    'ALTER TABLE mpesa_transactions ADD COLUMN mpesa_receipt_number VARCHAR(32) DEFAULT NULL',
    'SELECT 1 -- mpesa_transactions.mpesa_receipt_number already present'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- ── mpesa_transactions.raw_callback ───────────────────────────────────────────
SET @has_col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mpesa_transactions' AND COLUMN_NAME = 'raw_callback'
);
SET @sql := IF(@has_col = 0,
    'ALTER TABLE mpesa_transactions ADD COLUMN raw_callback TEXT DEFAULT NULL',
    'SELECT 1 -- mpesa_transactions.raw_callback already present'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- ── Back-fill tenant_id on existing rows from the client they belong to ───────
UPDATE mpesa_transactions mt
  JOIN clients c ON c.id = mt.client_id
   SET mt.tenant_id = c.tenant_id
 WHERE mt.tenant_id IS NULL;
