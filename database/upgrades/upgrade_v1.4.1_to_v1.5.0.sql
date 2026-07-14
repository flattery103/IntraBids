INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('recently_ended_days', '7');

UPDATE settings SET setting_value = '1.5.0' WHERE setting_key = 'schema_version';
