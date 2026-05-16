-- FreeRADIUS standard MySQL schema — added to the existing FortuNett database.
-- FreeRADIUS reads these tables via rlm_sql. FortuNett PHP writes to them.
--
-- Run on VPS:
--   mysql -u root fortunnet_technologies < sql/migrations/2026-05-16-freeradius-schema.sql
-- OR (if you already have the FreeRADIUS package installed):
--   mysql -u root fortunnet_technologies < /etc/freeradius/3.0/mods-config/sql/main/mysql/schema.sql

CREATE TABLE IF NOT EXISTS radcheck (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username   VARCHAR(64)  NOT NULL DEFAULT '',
    attribute  VARCHAR(64)  NOT NULL DEFAULT '',
    op         CHAR(2)      NOT NULL DEFAULT '==',
    value      VARCHAR(253) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY idx_username (username(32))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS radreply (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username   VARCHAR(64)  NOT NULL DEFAULT '',
    attribute  VARCHAR(64)  NOT NULL DEFAULT '',
    op         CHAR(2)      NOT NULL DEFAULT '=',
    value      VARCHAR(253) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY idx_username (username(32))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS radgroupcheck (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    groupname  VARCHAR(64)  NOT NULL DEFAULT '',
    attribute  VARCHAR(64)  NOT NULL DEFAULT '',
    op         CHAR(2)      NOT NULL DEFAULT '==',
    value      VARCHAR(253) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY idx_groupname (groupname(32))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS radgroupreply (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    groupname  VARCHAR(64)  NOT NULL DEFAULT '',
    attribute  VARCHAR(64)  NOT NULL DEFAULT '',
    op         CHAR(2)      NOT NULL DEFAULT '=',
    value      VARCHAR(253) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY idx_groupname (groupname(32))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS radusergroup (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username   VARCHAR(64)  NOT NULL DEFAULT '',
    groupname  VARCHAR(64)  NOT NULL DEFAULT '',
    priority   INT          NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY idx_username (username(32))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Session accounting — written by FreeRADIUS, read by FortuNett for usage stats
CREATE TABLE IF NOT EXISTS radacct (
    radacctid          BIGINT NOT NULL AUTO_INCREMENT,
    acctsessionid      VARCHAR(64)  NOT NULL DEFAULT '',
    acctuniqueid       VARCHAR(32)  NOT NULL DEFAULT '',
    username           VARCHAR(64)  NOT NULL DEFAULT '',
    realm              VARCHAR(64)           DEFAULT '',
    nasipaddress       VARCHAR(15)  NOT NULL DEFAULT '',
    nasportid          VARCHAR(15)           DEFAULT NULL,
    nasporttype        VARCHAR(32)           DEFAULT NULL,
    acctstarttime      DATETIME              DEFAULT NULL,
    acctupdatetime     DATETIME              DEFAULT NULL,
    acctstoptime       DATETIME              DEFAULT NULL,
    acctinterval       INT                   DEFAULT NULL,
    acctsessiontime    INT UNSIGNED          DEFAULT NULL,
    acctauthentic      VARCHAR(32)           DEFAULT NULL,
    connectinfo_start  VARCHAR(50)           DEFAULT NULL,
    connectinfo_stop   VARCHAR(50)           DEFAULT NULL,
    acctinputoctets    BIGINT                DEFAULT NULL,  -- bytes uploaded by client
    acctoutputoctets   BIGINT                DEFAULT NULL,  -- bytes downloaded by client
    calledstationid    VARCHAR(50)  NOT NULL DEFAULT '',
    callingstationid   VARCHAR(50)  NOT NULL DEFAULT '',
    acctterminatecause VARCHAR(32)  NOT NULL DEFAULT '',
    servicetype        VARCHAR(32)           DEFAULT NULL,
    framedprotocol     VARCHAR(32)           DEFAULT NULL,
    framedipaddress    VARCHAR(15)  NOT NULL DEFAULT '',
    framedipv6address  VARCHAR(45)  NOT NULL DEFAULT '',
    framedipv6prefix   VARCHAR(45)  NOT NULL DEFAULT '',
    framedinterfaceid  VARCHAR(44)  NOT NULL DEFAULT '',
    delegatedipv6prefix VARCHAR(45) NOT NULL DEFAULT '',
    PRIMARY KEY (radacctid),
    UNIQUE KEY acctuniqueid (acctuniqueid),
    KEY idx_username (username),
    KEY idx_framedipaddress (framedipaddress),
    KEY idx_acctsessionid (acctsessionid),
    KEY idx_acctsessiontime (acctsessiontime),
    KEY idx_acctstarttime (acctstarttime),
    KEY idx_acctstoptime (acctstoptime),
    KEY idx_nasipaddress (nasipaddress)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS radpostauth (
    id         INT NOT NULL AUTO_INCREMENT,
    username   VARCHAR(64)  NOT NULL DEFAULT '',
    pass       VARCHAR(64)  NOT NULL DEFAULT '',
    reply      VARCHAR(32)  NOT NULL DEFAULT '',
    authdate   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Store per-router RADIUS shared secret so PHP can reference it
CREATE TABLE IF NOT EXISTS router_radius_config (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    router_id       INT NOT NULL,
    tenant_id       INT NOT NULL,
    radius_host     VARCHAR(100) NOT NULL DEFAULT '127.0.0.1',
    radius_port     INT NOT NULL DEFAULT 1812,
    shared_secret   VARCHAR(128) NOT NULL,
    enabled         TINYINT(1) NOT NULL DEFAULT 0,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_router (router_id),
    KEY idx_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
