CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('user','admin') NOT NULL DEFAULT 'user',
    can_create_auctions TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_users_role (role),
    INDEX idx_users_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_categories_active (is_active),
    INDEX idx_categories_sort (sort_order, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auctions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NOT NULL,
    created_by INT UNSIGNED NOT NULL,
    title VARCHAR(180) NOT NULL,
    description TEXT NOT NULL,
    item_condition VARCHAR(80) NULL,
    starting_bid DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    bid_increment DECIMAL(10,2) NOT NULL DEFAULT 1.00,
    current_high_bid DECIMAL(10,2) NULL,
    current_high_bidder_id INT UNSIGNED NULL,
    start_time DATETIME NOT NULL,
    end_time DATETIME NOT NULL,
    status ENUM('draft','scheduled','active','ended','awarded','cancelled') NOT NULL DEFAULT 'draft',
    pickup_location VARCHAR(255) NULL,
    pickup_instructions TEXT NULL,
    winning_bid_id INT UNSIGNED NULL,
    winning_user_id INT UNSIGNED NULL,
    awarded_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_auctions_category FOREIGN KEY (category_id) REFERENCES categories(id),
    CONSTRAINT fk_auctions_creator FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_auctions_high_bidder FOREIGN KEY (current_high_bidder_id) REFERENCES users(id),
    CONSTRAINT fk_auctions_winner FOREIGN KEY (winning_user_id) REFERENCES users(id),
    INDEX idx_auctions_status_time (status, start_time, end_time),
    INDEX idx_auctions_category (category_id),
    INDEX idx_auctions_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bids (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    auction_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    bid_amount DECIMAL(10,2) NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_bids_auction FOREIGN KEY (auction_id) REFERENCES auctions(id) ON DELETE CASCADE,
    CONSTRAINT fk_bids_user FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_bids_auction_amount (auction_id, bid_amount),
    INDEX idx_bids_user (user_id),
    INDEX idx_bids_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS auction_images (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    auction_id INT UNSIGNED NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_images_auction FOREIGN KEY (auction_id) REFERENCES auctions(id) ON DELETE CASCADE,
    INDEX idx_images_auction (auction_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    action VARCHAR(80) NOT NULL,
    entity_type VARCHAR(80) NULL,
    entity_id INT UNSIGNED NULL,
    old_value JSON NULL,
    new_value JSON NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_audit_user (user_id),
    INDEX idx_audit_entity (entity_type, entity_id),
    INDEX idx_audit_created (created_at),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(120) NOT NULL UNIQUE,
    setting_value TEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO categories (name, description, is_active, sort_order, created_at, updated_at) VALUES
('Electronics', 'Computers, monitors, phones, accessories, and related items.', 1, 10, NOW(), NOW()),
('Furniture', 'Desks, chairs, cabinets, tables, and other furniture.', 1, 20, NOW(), NOW()),
('Office Equipment', 'Printers, phones, supplies, tools, and general office equipment.', 1, 30, NOW(), NOW()),
('Miscellaneous', 'Items that do not fit another category.', 1, 999, NOW(), NOW());

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('site_name', 'IntraBid'),
('app_timezone', 'America/Chicago'),
('site_logo_path', ''),
('home_alert_enabled', '0'),
('home_alert_text', ''),
('site_email', ''),
('site_email_name', 'IntraBid'),
('smtp_enabled', '0'),
('smtp_host', ''),
('smtp_port', '587'),
('smtp_encryption', 'tls'),
('smtp_username', ''),
('smtp_password', ''),
('registration_enabled', '1'),
('allowed_email_domain', ''),
('default_bid_increment', '1.00'),
('anti_sniping_enabled', '0'),
('anti_sniping_minutes', '2'),
('recently_ended_days', '7'),
('allow_creator_to_bid', '0'),
('show_winner_publicly', '1');
