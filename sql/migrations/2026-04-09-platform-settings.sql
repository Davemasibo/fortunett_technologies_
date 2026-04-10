-- Platform Settings — key-value store for super admin system configuration
-- Run this after the saas-upgrade migration.

CREATE TABLE IF NOT EXISTS `platform_settings` (
    `id`            INT NOT NULL AUTO_INCREMENT,
    `setting_key`   VARCHAR(100) NOT NULL,
    `setting_value` TEXT DEFAULT NULL,
    `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed defaults
INSERT IGNORE INTO `platform_settings` (`setting_key`, `setting_value`) VALUES
    ('platform_name',        'FortuNett Technologies'),
    ('platform_tagline',     'Multi-Tenant ISP Management'),
    ('platform_domain',      'fortunetttech.site'),
    ('support_email',        'support@fortunetttech.site'),
    ('support_phone',        ''),
    ('platform_logo_url',    ''),
    ('default_trial_days',   '30'),
    ('require_email_verify', '1'),
    ('auto_assign_plan_slug','starter'),
    ('signup_enabled',       '1'),
    ('signup_welcome_message','');

-- Ensure platform_email_config exists (also created by platform-comms migration)
CREATE TABLE IF NOT EXISTS `platform_email_config` (
    `id`            INT NOT NULL DEFAULT 1,
    `smtp_host`     VARCHAR(255) DEFAULT NULL,
    `smtp_port`     INT DEFAULT 587,
    `smtp_username` VARCHAR(255) DEFAULT NULL,
    `smtp_password` TEXT DEFAULT NULL,
    `from_email`    VARCHAR(255) DEFAULT NULL,
    `from_name`     VARCHAR(100) DEFAULT 'FortuNett Technologies',
    `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
    `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO `platform_email_config` (`id`) VALUES (1);

-- Ensure platform_sms_config exists
CREATE TABLE IF NOT EXISTS `platform_sms_config` (
    `id`        INT NOT NULL DEFAULT 1,
    `provider`  VARCHAR(50) DEFAULT 'talksasa',
    `api_key`   VARCHAR(255) DEFAULT NULL,
    `sender_id` VARCHAR(50) DEFAULT NULL,
    `api_url`   VARCHAR(255) DEFAULT 'https://api.talksasa.com/v1/sms/send',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO `platform_sms_config` (`id`) VALUES (1);
