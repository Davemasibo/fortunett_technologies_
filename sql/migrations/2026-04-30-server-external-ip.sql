-- Store the server's external/public IP in platform_settings.
-- Used by _uploadHotspotLoginPage() to add this IP to MikroTik walled-garden-ip
-- so hotspot clients can reach the captive portal before authenticating.

INSERT INTO `platform_settings` (`setting_key`, `setting_value`)
VALUES ('server_external_ip', '212.95.34.211')
ON DUPLICATE KEY UPDATE `setting_value` = '212.95.34.211';
