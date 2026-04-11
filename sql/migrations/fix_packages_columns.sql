-- ============================================================
-- Migration: Fix missing columns for production databases
-- MySQL 5.7 compatible (no ADD COLUMN IF NOT EXISTS)
-- Run once: mysql -u root -p dbname < this_file.sql
-- ============================================================

DROP PROCEDURE IF EXISTS SafeAddCol;
DELIMITER //
CREATE PROCEDURE SafeAddCol(IN tbl VARCHAR(64), IN col VARCHAR(64), IN col_def TEXT)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = tbl
          AND COLUMN_NAME  = col
    ) THEN
        SET @_sql = CONCAT('ALTER TABLE `', tbl, '` ADD COLUMN `', col, '` ', col_def);
        PREPARE _s FROM @_sql;
        EXECUTE _s;
        DEALLOCATE PREPARE _s;
    END IF;
END //
DELIMITER ;

-- 1. packages table: missing columns
CALL SafeAddCol('packages', 'tenant_id',       'INT DEFAULT NULL');
CALL SafeAddCol('packages', 'rate_limit',      'VARCHAR(50) DEFAULT NULL');
CALL SafeAddCol('packages', 'connection_type', 'VARCHAR(20) DEFAULT ''pppoe''');
CALL SafeAddCol('packages', 'validity_value',  'INT DEFAULT 30');
CALL SafeAddCol('packages', 'validity_unit',   'VARCHAR(20) DEFAULT ''days''');
CALL SafeAddCol('packages', 'device_limit',    'INT DEFAULT 1');

-- Back-fill connection_type from type column
UPDATE packages SET connection_type = type WHERE (connection_type IS NULL OR connection_type = '') AND type IS NOT NULL;

-- 2. tenants table: admin_user_id
CALL SafeAddCol('tenants', 'admin_user_id', 'INT DEFAULT NULL');

-- 3. clients table: tenant_id
CALL SafeAddCol('clients', 'tenant_id', 'INT DEFAULT NULL');

-- 4. payments table: tenant_id
CALL SafeAddCol('payments', 'tenant_id', 'INT DEFAULT NULL');

-- 5. Widen payment_method from ENUM to VARCHAR so manual entries don't truncate
ALTER TABLE payments MODIFY COLUMN payment_method VARCHAR(50) DEFAULT 'cash';

DROP PROCEDURE IF EXISTS SafeAddCol;

SELECT 'Migration complete' AS status;
