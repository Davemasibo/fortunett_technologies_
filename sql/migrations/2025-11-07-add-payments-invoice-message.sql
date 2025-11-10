
-- IMPORTANT: run this against your application database. Replace `<your_db>` below
-- or run the script with the database specified on the mysql CLI:
--   mysql -u <user> -p <your_db> < 2025-11-07-add-payments-invoice-message.sql


-- This script uses modern MySQL syntax (8.0+) to add columns only when missing
-- 1) Add `invoice` column if it doesn't exist
ALTER TABLE payments
    ADD COLUMN IF NOT EXISTS `invoice` VARCHAR(100) NULL AFTER `transaction_id`;

-- 2) Add `message` column if it doesn't exist
ALTER TABLE payments
    ADD COLUMN IF NOT EXISTS `message` TEXT NULL AFTER `payment_method`;

-- 3) Add an index for faster invoice lookups. If your MySQL version does not
-- support CREATE INDEX IF NOT EXISTS, run the following statement; it will
-- succeed if the index is not present and error if it already exists. If it
-- errors with "Duplicate key name", it's safe to ignore.
CREATE INDEX idx_payments_invoice ON payments(`invoice`(50));

-- Fallback for older MySQL versions (if ALTER ... ADD COLUMN IF NOT EXISTS fails):
-- Run these manually in your DB client after checking the schema:
-- ALTER TABLE payments ADD COLUMN `invoice` VARCHAR(100) NULL AFTER `transaction_id`;
-- ALTER TABLE payments ADD COLUMN `message` TEXT NULL AFTER `payment_method`;
-- CREATE INDEX idx_payments_invoice ON payments(`invoice`(50));

-- Done
