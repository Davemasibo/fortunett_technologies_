-- ============================================================
-- Migration: Fix missing columns for VPS production databases
-- Run this once on any server set up from an older schema
-- Safe to run multiple times (uses ADD COLUMN IF NOT EXISTS)
-- ============================================================

-- 1. packages table: add missing columns
ALTER TABLE packages
    ADD COLUMN IF NOT EXISTS tenant_id       INT DEFAULT NULL AFTER id,
    ADD COLUMN IF NOT EXISTS rate_limit      VARCHAR(50) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS connection_type VARCHAR(20) DEFAULT 'pppoe',
    ADD COLUMN IF NOT EXISTS validity_value  INT DEFAULT 30,
    ADD COLUMN IF NOT EXISTS validity_unit   VARCHAR(20) DEFAULT 'days',
    ADD COLUMN IF NOT EXISTS device_limit    INT DEFAULT 1;

-- Back-fill connection_type from type column where it's empty
UPDATE packages SET connection_type = type WHERE connection_type IS NULL OR connection_type = '';

-- Add index on tenant_id
ALTER TABLE packages ADD INDEX IF NOT EXISTS idx_pkg_tenant (tenant_id);

-- 2. tenants table: ensure admin_user_id column exists
ALTER TABLE tenants
    ADD COLUMN IF NOT EXISTS admin_user_id INT DEFAULT NULL;

-- 3. payments table: widen payment_method from ENUM to VARCHAR
--    so manual entries like 'M-Pesa', 'bank_transfer' etc. don't truncate
ALTER TABLE payments MODIFY COLUMN payment_method VARCHAR(50) DEFAULT 'cash';

-- 4. clients table: ensure tenant_id exists
ALTER TABLE clients ADD COLUMN IF NOT EXISTS tenant_id INT DEFAULT NULL;

-- 5. payments table: ensure tenant_id exists
ALTER TABLE payments ADD COLUMN IF NOT EXISTS tenant_id INT DEFAULT NULL;
