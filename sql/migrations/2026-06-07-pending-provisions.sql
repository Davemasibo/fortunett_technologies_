-- Migration: 2026-06-07-pending-provisions
-- Queues failed MikroTik provisioning attempts so cron/retry_provisions.php
-- can retry them without repeating the full payment pipeline.

CREATE TABLE IF NOT EXISTS pending_provisions (
    id            INT           AUTO_INCREMENT PRIMARY KEY,
    tenant_id     INT           NOT NULL,
    client_id     INT           NOT NULL,
    package_id    INT           DEFAULT NULL,
    receipt       VARCHAR(50)   DEFAULT NULL,
    attempts      INT           NOT NULL DEFAULT 1,
    fail_reason   VARCHAR(500)  DEFAULT NULL,
    next_retry_at DATETIME      NOT NULL DEFAULT (NOW() + INTERVAL 5 MINUTE),
    created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_client_tenant (client_id, tenant_id),
    INDEX idx_retry (next_retry_at, attempts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
