-- Migration: Add service_types and hotspot_shared_users to mikrotik_routers
-- Run with: mysql -u root fortunett_technologies < sql/migrations/2026-04-15-router-service-config.sql

ALTER TABLE `mikrotik_routers`
    ADD COLUMN IF NOT EXISTS `service_types` VARCHAR(20) DEFAULT NULL
        COMMENT 'Comma-separated active services: pppoe, hotspot, or pppoe,hotspot',
    ADD COLUMN IF NOT EXISTS `hotspot_shared_users` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0
        COMMENT '0 = unlimited concurrent sessions per user, 1 = one session at a time (no sharing)';
