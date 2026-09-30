-- Run after 2026-09-30-tenant-disbursements.sql.
-- Existing rows and their original transfer references are preserved.
-- The application also applies these upgrades through ensureDisbursementSchema().
SET @disbursement_ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payments' AND COLUMN_NAME='disbursed_amount'),
    'SELECT 1', 'ALTER TABLE payments ADD COLUMN disbursed_amount DECIMAL(12,2) NOT NULL DEFAULT 0');
PREPARE disbursement_upgrade FROM @disbursement_ddl;
EXECUTE disbursement_upgrade;
DEALLOCATE PREPARE disbursement_upgrade;

SET @disbursement_ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tenant_disbursements' AND COLUMN_NAME='platform_cost'),
    'SELECT 1', 'ALTER TABLE tenant_disbursements ADD COLUMN platform_cost DECIMAL(12,2) NOT NULL DEFAULT 0');
PREPARE disbursement_upgrade FROM @disbursement_ddl;
EXECUTE disbursement_upgrade;
DEALLOCATE PREPARE disbursement_upgrade;

SET @disbursement_ddl = IF(
    (SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tenant_disbursement_items' AND INDEX_NAME='PRIMARY') = 'payment_id',
    'ALTER TABLE tenant_disbursement_items DROP PRIMARY KEY, ADD PRIMARY KEY(disbursement_id,payment_id), ADD KEY payment_id(payment_id)', 'SELECT 1');
PREPARE disbursement_upgrade FROM @disbursement_ddl;
EXECUTE disbursement_upgrade;
DEALLOCATE PREPARE disbursement_upgrade;
