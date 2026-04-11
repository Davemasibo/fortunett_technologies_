-- Migration: Add missing columns to packages table
-- Run this on any VPS/production DB that was set up from the old schema

ALTER TABLE packages
    ADD COLUMN IF NOT EXISTS tenant_id    INT DEFAULT NULL AFTER id,
    ADD COLUMN IF NOT EXISTS rate_limit   VARCHAR(50) DEFAULT NULL AFTER data_limit,
    ADD COLUMN IF NOT EXISTS connection_type VARCHAR(20) DEFAULT 'pppoe' AFTER rate_limit,
    ADD COLUMN IF NOT EXISTS validity_value INT DEFAULT 30 AFTER connection_type,
    ADD COLUMN IF NOT EXISTS validity_unit  VARCHAR(20) DEFAULT 'days' AFTER validity_value,
    ADD COLUMN IF NOT EXISTS device_limit   INT DEFAULT 1 AFTER validity_unit;

-- Add index on tenant_id if not exists
ALTER TABLE packages ADD INDEX IF NOT EXISTS idx_pkg_tenant (tenant_id);

-- Ensure payment_method column in payments table accepts manual entry methods
ALTER TABLE payments MODIFY COLUMN payment_method VARCHAR(50) DEFAULT 'cash';
