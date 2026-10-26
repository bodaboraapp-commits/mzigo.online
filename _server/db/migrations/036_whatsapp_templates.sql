-- Mzigo.online — migration 036: WhatsApp Phase 2 outbound templates.
USE mzigo_online;

CREATE TABLE IF NOT EXISTS wa_templates (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event VARCHAR(40) NOT NULL,
  template_name VARCHAR(120) NOT NULL,
  language VARCHAR(10) NOT NULL DEFAULT 'en',
  active TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_wa_template_event (event)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO wa_templates (event, template_name, language, active, created_at)
VALUES
  ('registered', 'mzigo_parcel_registered', 'en', 0, NOW()),
  ('ready', 'mzigo_parcel_ready', 'en', 0, NOW()),
  ('payment_received', 'mzigo_payment_received', 'en', 0, NOW()),
  ('collected', 'mzigo_parcel_collected', 'en', 0, NOW()),
  ('status', 'mzigo_parcel_status', 'en', 0, NOW())
ON DUPLICATE KEY UPDATE event = VALUES(event);

CREATE TABLE IF NOT EXISTS wa_outbound_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  parcel_id VARCHAR(64) NULL,
  tracking VARCHAR(20) NULL,
  phone VARCHAR(20) NULL,
  event VARCHAR(40) NOT NULL,
  template_name VARCHAR(120) NULL,
  status ENUM('sent','skipped','failed') NOT NULL DEFAULT 'skipped',
  error VARCHAR(255) NULL,
  params_json TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_wa_outbound_event (event, created_at),
  KEY idx_wa_outbound_tracking (tracking, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
