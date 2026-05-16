-- Phase 2: REST API Layer — refresh token store
-- Stateless JWT access tokens (15 min) are validated in PHP without DB.
-- Long-lived refresh tokens are stored here so they can be revoked.

CREATE TABLE IF NOT EXISTS api_tokens (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT          NOT NULL,
    tenant_id     INT          DEFAULT NULL,
    role          VARCHAR(20)  NOT NULL DEFAULT 'admin',
    token_hash    CHAR(64)     NOT NULL,           -- SHA-256(refresh_token_raw)
    device_name   VARCHAR(150) DEFAULT NULL,        -- "Admin App Android", "Customer App"
    expires_at    DATETIME     NOT NULL,
    last_used_at  DATETIME     DEFAULT NULL,
    created_at    DATETIME     DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_token_hash (token_hash),
    KEY idx_user_id   (user_id),
    KEY idx_expires   (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tidy expired tokens (run periodically via cron or on login)
-- DELETE FROM api_tokens WHERE expires_at < NOW();
