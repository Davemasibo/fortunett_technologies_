-- Migration: 2026-06-07-expiry-reminders
-- Adds columns to track whether pre-expiry SMS reminders have been sent,
-- preventing duplicate messages when the cron runs every 15 minutes.
-- Uses PREPARE/EXECUTE to avoid DELIMITER issues in any MySQL client context.

SET @col3d := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'clients'
      AND COLUMN_NAME  = 'expiry_reminder_3d_sent'
);
SET @sql3d := IF(@col3d = 0,
    'ALTER TABLE clients ADD COLUMN expiry_reminder_3d_sent TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT 1 -- expiry_reminder_3d_sent already exists'
);
PREPARE _stmt FROM @sql3d;
EXECUTE _stmt;
DEALLOCATE PREPARE _stmt;

SET @col1d := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'clients'
      AND COLUMN_NAME  = 'expiry_reminder_1d_sent'
);
SET @sql1d := IF(@col1d = 0,
    'ALTER TABLE clients ADD COLUMN expiry_reminder_1d_sent TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT 1 -- expiry_reminder_1d_sent already exists'
);
PREPARE _stmt FROM @sql1d;
EXECUTE _stmt;
DEALLOCATE PREPARE _stmt;
