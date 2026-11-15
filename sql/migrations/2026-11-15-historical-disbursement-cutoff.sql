-- Preserve both the transfer timestamp and the collection cutoff used to
-- calculate each manual payout, so backdated payouts can be replayed safely.
SET @has_col = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tenant_disbursements'
      AND COLUMN_NAME = 'collections_through'
);
SET @ddl = IF(
    @has_col = 0,
    'ALTER TABLE tenant_disbursements ADD COLUMN collections_through DATETIME DEFAULT NULL AFTER disbursed_at',
    'SELECT 1'
);
PREPARE disbursement_cutoff_upgrade FROM @ddl;
EXECUTE disbursement_cutoff_upgrade;
DEALLOCATE PREPARE disbursement_cutoff_upgrade;

UPDATE tenant_disbursements
SET collections_through = disbursed_at
WHERE collections_through IS NULL;
