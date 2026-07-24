-- Migration: 2026-07-24-clients-status-enum
-- Expands the clients.status ENUM to cover every status value the application
-- actually writes. The original enum was ('active','inactive','suspended'), but
-- the code sets 'pending' (paid hotspot signup before payment confirmation),
-- 'expired', and 'grace' (expiry cron). On MySQL servers running in strict mode
-- (STRICT_TRANS_TABLES — the production default) an out-of-range enum value is
-- rejected with SQLSTATE[01000] "Warning 1265 Data truncated for column 'status'",
-- which surfaced to hotspot customers as:
--   "Payment could not be initiated: ... Data truncated for column 'status'"
-- and blocked the STK push entirely. On non-strict servers the value would be
-- silently coerced to '' (empty), corrupting the row.
--
-- Idempotent: only rewrites the column when 'pending' is not already a member.

SET @has_pending := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'clients'
      AND COLUMN_NAME  = 'status'
      AND COLUMN_TYPE LIKE '%''pending''%'
);
SET @sql := IF(@has_pending = 0,
    "ALTER TABLE clients MODIFY COLUMN status ENUM('active','inactive','suspended','pending','expired','grace') NOT NULL DEFAULT 'inactive'",
    'SELECT 1 -- clients.status already includes pending'
);
PREPARE _stmt FROM @sql;
EXECUTE _stmt;
DEALLOCATE PREPARE _stmt;
