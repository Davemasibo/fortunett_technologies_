CREATE TABLE IF NOT EXISTS router_wan_config (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 tenant_id INT NOT NULL,
 identity VARCHAR(64) NOT NULL,
 router_id INT DEFAULT NULL,
 config_json TEXT NOT NULL,
 lan_bridge VARCHAR(64) NOT NULL,
 billing_url VARCHAR(255) NOT NULL,
 verified_at DATETIME DEFAULT NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY tenant_identity (tenant_id,identity),
 KEY tenant_router (tenant_id,router_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
