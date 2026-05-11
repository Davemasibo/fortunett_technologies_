-- Fix payment_date column: DATE → DATETIME so time is preserved when recording manual payments
-- Safe to run multiple times (IF condition in the ALTER handles already-migrated schemas).

ALTER TABLE payments
    MODIFY COLUMN payment_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        COMMENT 'Full timestamp of the payment (was DATE — upgraded to preserve time)';
