-- Adds the optional home page alert banner settings.
-- Category drag sorting and safe category deletion use the existing category schema.

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('home_alert_enabled', '0'),
('home_alert_text', '');

UPDATE settings SET setting_value = '1.6.0' WHERE setting_key = 'schema_version';
