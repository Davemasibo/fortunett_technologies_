-- Add hotspot_server column to packages so per-package server selection is persisted.
-- Used when provisioning hotspot users to restrict them to a specific hotspot server
-- on the router (=server= param in /ip/hotspot/user/add). NULL / empty means 'all'.

ALTER TABLE packages
    ADD COLUMN IF NOT EXISTS hotspot_server VARCHAR(64) NULL DEFAULT NULL
    COMMENT 'MikroTik hotspot server name; NULL = all servers';
