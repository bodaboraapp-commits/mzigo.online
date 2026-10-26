-- Mzigo.online — SaaS migration 007: platform pricing controls.
USE mzigo_online;

CREATE TABLE IF NOT EXISTS platform_settings (
  setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
  setting_value VARCHAR(255) NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO platform_settings (setting_key, setting_value) VALUES ('pricing_mode', 'paid');
