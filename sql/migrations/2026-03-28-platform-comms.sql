-- Platform Email Config (singleton, id=1)
CREATE TABLE IF NOT EXISTS `platform_email_config` (
  `id`            int NOT NULL DEFAULT 1,
  `smtp_host`     varchar(255) DEFAULT NULL,
  `smtp_port`     int DEFAULT 587,
  `smtp_username` varchar(255) DEFAULT NULL,
  `smtp_password` text DEFAULT NULL,
  `from_email`    varchar(255) DEFAULT NULL,
  `from_name`     varchar(100) DEFAULT 'FortuNett Technologies',
  `is_active`     tinyint(1) NOT NULL DEFAULT 1,
  `updated_at`    timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default placeholder row
INSERT IGNORE INTO `platform_email_config` (`id`) VALUES (1);

-- Platform SMS Config (singleton, id=1)
CREATE TABLE IF NOT EXISTS `platform_sms_config` (
  `id`        int NOT NULL DEFAULT 1,
  `provider`  varchar(50) DEFAULT 'talksasa',
  `api_key`   varchar(255) DEFAULT NULL,
  `sender_id` varchar(50) DEFAULT NULL,
  `api_url`   varchar(255) DEFAULT 'https://api.talksasa.com/v1/sms/send',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default placeholder row
INSERT IGNORE INTO `platform_sms_config` (`id`) VALUES (1);
