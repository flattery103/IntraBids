-- IntraBids v1.6.1 to v1.7.0
-- Adds password reset tokens and auction posting access requests.

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    requested_ip VARCHAR(45) NULL,
    CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_password_reset_user (user_id),
    INDEX idx_password_reset_expiry (expires_at, used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auction_access_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    status ENUM('pending','approved','denied') NOT NULL DEFAULT 'pending',
    requested_at DATETIME NOT NULL,
    resolved_at DATETIME NULL,
    resolved_by INT UNSIGNED NULL,
    resolution_method VARCHAR(40) NULL,
    approval_token_hash CHAR(64) NULL UNIQUE,
    approval_token_expires_at DATETIME NULL,
    CONSTRAINT fk_access_request_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_access_request_resolver FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_access_request_status (status, requested_at),
    INDEX idx_access_request_user (user_id, requested_at),
    INDEX idx_access_request_expiry (approval_token_expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('access_requests_enabled', '0'),
('access_request_email_approval_enabled', '0');

UPDATE settings SET setting_value = '1.7.0' WHERE setting_key = 'schema_version';
