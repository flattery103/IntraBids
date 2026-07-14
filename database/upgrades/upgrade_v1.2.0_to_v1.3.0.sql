-- IntraBid v1.2.0 to v1.3.0
-- Adds application timezone setting and migration tracking.

CREATE TABLE IF NOT EXISTS schema_migrations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    migration VARCHAR(190) NOT NULL UNIQUE,
    executed_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('app_timezone', 'America/Chicago'),
('schema_version', '1.3.0');

UPDATE settings SET setting_value = '1.3.0' WHERE setting_key = 'schema_version';
