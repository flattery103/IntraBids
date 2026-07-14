-- IntraBids v1.7.1 to v1.8.0
-- Adds per-administrator access approval links, user email preferences,
-- bidder privacy, primary auction images, password-reset enforcement,
-- and configurable audit/security-token retention.

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'must_reset_password') = 0, 'ALTER TABLE users ADD COLUMN must_reset_password TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active', 'SELECT 1');
PREPARE intrabids_stmt FROM @sql;
EXECUTE intrabids_stmt;
DEALLOCATE PREPARE intrabids_stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'auction_images' AND COLUMN_NAME = 'is_primary') = 0, 'ALTER TABLE auction_images ADD COLUMN is_primary TINYINT(1) NOT NULL DEFAULT 0 AFTER sort_order', 'SELECT 1');
PREPARE intrabids_stmt FROM @sql;
EXECUTE intrabids_stmt;
DEALLOCATE PREPARE intrabids_stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'auction_images' AND INDEX_NAME = 'idx_images_primary') = 0, 'ALTER TABLE auction_images ADD INDEX idx_images_primary (auction_id, is_primary)', 'SELECT 1');
PREPARE intrabids_stmt FROM @sql;
EXECUTE intrabids_stmt;
DEALLOCATE PREPARE intrabids_stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs' AND INDEX_NAME = 'idx_audit_action_created') = 0, 'ALTER TABLE audit_logs ADD INDEX idx_audit_action_created (action, created_at)', 'SELECT 1');
PREPARE intrabids_stmt FROM @sql;
EXECUTE intrabids_stmt;
DEALLOCATE PREPARE intrabids_stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs' AND INDEX_NAME = 'idx_audit_ip_created') = 0, 'ALTER TABLE audit_logs ADD INDEX idx_audit_ip_created (ip_address, created_at)', 'SELECT 1');
PREPARE intrabids_stmt FROM @sql;
EXECUTE intrabids_stmt;
DEALLOCATE PREPARE intrabids_stmt;

CREATE TABLE IF NOT EXISTS auction_access_approval_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id BIGINT UNSIGNED NOT NULL,
    admin_user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_access_approval_request FOREIGN KEY (request_id) REFERENCES auction_access_requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_access_approval_admin FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_access_approval_admin (request_id, admin_user_id),
    INDEX idx_access_approval_expiry (expires_at, used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_notification_preferences (
    user_id INT UNSIGNED PRIMARY KEY,
    email_outbid TINYINT(1) NOT NULL DEFAULT 1,
    email_auction_won TINYINT(1) NOT NULL DEFAULT 1,
    email_creator_ended TINYINT(1) NOT NULL DEFAULT 1,
    email_access_request_updates TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_notification_preferences_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO user_notification_preferences (user_id, updated_at)
SELECT id, NOW() FROM users;

UPDATE auction_images ai
JOIN (
    SELECT auction_id, CAST(SUBSTRING_INDEX(GROUP_CONCAT(id ORDER BY sort_order ASC, id ASC), ',', 1) AS UNSIGNED) AS primary_id
    FROM auction_images
    GROUP BY auction_id
    HAVING SUM(is_primary = 1) = 0
) selected ON selected.primary_id = ai.id
SET ai.is_primary = 1;

UPDATE auction_access_requests
SET approval_token_hash = NULL, approval_token_expires_at = NULL
WHERE status = 'pending';

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('bidder_name_privacy', 'full'),
('audit_log_retention_days', '365'),
('security_token_retention_days', '30'),
('last_security_cleanup_at', '');

UPDATE settings SET setting_value = '1.8.0' WHERE setting_key = 'schema_version';
