CREATE TABLE IF NOT EXISTS google_identities (
    google_sub VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    user_id INT NOT NULL,
    email VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY google_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
