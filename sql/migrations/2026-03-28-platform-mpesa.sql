-- Platform M-Pesa Integration Migration
-- 2026-03-28

-- 1. Platform credentials table (singleton row, id=1)
CREATE TABLE IF NOT EXISTS `platform_mpesa_config` (
    `id`                   INT NOT NULL DEFAULT 1,
    `consumer_key`         VARCHAR(255) NOT NULL DEFAULT '',
    `consumer_secret`      VARCHAR(255) NOT NULL DEFAULT '',
    `passkey`              VARCHAR(255) NOT NULL DEFAULT '',
    `shortcode`            VARCHAR(20)  NOT NULL DEFAULT '',
    `shortcode_type`       ENUM('paybill','till') NOT NULL DEFAULT 'paybill',
    `environment`          ENUM('sandbox','live','production') NOT NULL DEFAULT 'sandbox',
    `callback_url`         VARCHAR(512) NOT NULL DEFAULT '',
    `c2b_validation_url`   VARCHAR(512) NOT NULL DEFAULT '',
    `c2b_confirmation_url` VARCHAR(512) NOT NULL DEFAULT '',
    `notes`                TEXT DEFAULT NULL,
    `updated_at`           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ensure singleton row
INSERT IGNORE INTO `platform_mpesa_config` (`id`) VALUES (1);

-- 2. Tag payments collected via platform paybill
ALTER TABLE `payments`
    ADD COLUMN IF NOT EXISTS `collection_type`
    ENUM('direct','platform') NOT NULL DEFAULT 'direct'
    COMMENT 'direct = tenant own paybill; platform = routed via FortuNett shared paybill';

-- Back-fill existing rows (assume all historical = direct)
UPDATE `payments` SET `collection_type` = 'direct' WHERE `collection_type` IS NULL OR `collection_type` = '';
