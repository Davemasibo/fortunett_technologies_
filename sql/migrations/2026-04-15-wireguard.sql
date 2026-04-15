-- WireGuard VPN columns for mikrotik_routers
-- Allows the VPS to reach routers behind NAT via a WireGuard tunnel.
-- Each router gets a unique VPN IP (10.200.200.{router_id}).

ALTER TABLE `mikrotik_routers`
    ADD COLUMN IF NOT EXISTS `vpn_ip`        VARCHAR(15)  DEFAULT NULL   COMMENT 'WireGuard VPN IP assigned to this router (10.200.200.x)',
    ADD COLUMN IF NOT EXISTS `wg_public_key` VARCHAR(64)  DEFAULT NULL   COMMENT 'Router WireGuard public key',
    ADD COLUMN IF NOT EXISTS `wg_private_key` VARCHAR(64) DEFAULT NULL   COMMENT 'Router WireGuard private key (generated on VPS, embedded in RSC)',
    ADD COLUMN IF NOT EXISTS `provision_id`  VARCHAR(36)  DEFAULT NULL   COMMENT 'UUID assigned at RSC generation, used to match auto_register call';

ALTER TABLE `mikrotik_routers`
    ADD UNIQUE INDEX IF NOT EXISTS `ux_routers_provision_id` (`provision_id`);
