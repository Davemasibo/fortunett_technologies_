-- Keep the three independent management-health observations separate.
SET @has_col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mikrotik_routers' AND COLUMN_NAME='last_wan_heartbeat_at');
SET @ddl = IF(@has_col=0, 'ALTER TABLE mikrotik_routers ADD COLUMN last_wan_heartbeat_at DATETIME NULL DEFAULT NULL COMMENT ''Last authenticated WAN check-in from the router''', 'SELECT 1');
PREPARE router_health_wan FROM @ddl; EXECUTE router_health_wan; DEALLOCATE PREPARE router_health_wan;

SET @has_col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mikrotik_routers' AND COLUMN_NAME='last_wg_handshake_at');
SET @ddl = IF(@has_col=0, 'ALTER TABLE mikrotik_routers ADD COLUMN last_wg_handshake_at DATETIME NULL DEFAULT NULL COMMENT ''Most recent WireGuard handshake observed on the VPS''', 'SELECT 1');
PREPARE router_health_wg FROM @ddl; EXECUTE router_health_wg; DEALLOCATE PREPARE router_health_wg;

SET @has_col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mikrotik_routers' AND COLUMN_NAME='last_api_success_at');
SET @ddl = IF(@has_col=0, 'ALTER TABLE mikrotik_routers ADD COLUMN last_api_success_at DATETIME NULL DEFAULT NULL COMMENT ''Last successful RouterOS API login''', 'SELECT 1');
PREPARE router_health_api FROM @ddl; EXECUTE router_health_api; DEALLOCATE PREPARE router_health_api;
