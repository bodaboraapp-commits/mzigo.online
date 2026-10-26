-- Mzigo.online — SaaS migration 006: communication channels + notifications.
USE mzigo_online;

CREATE TABLE IF NOT EXISTS communication_channels (
  code VARCHAR(20) NOT NULL PRIMARY KEY,
  label VARCHAR(40) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO communication_channels (code, label, is_active, sort_order) VALUES
  ('sms', 'SMS', 1, 1), ('whatsapp', 'WhatsApp', 1, 2), ('email', 'Email', 1, 3)
ON DUPLICATE KEY UPDATE label = VALUES(label);

ALTER TABLE users
  ADD COLUMN comm_channel VARCHAR(20) NULL AFTER status;

CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  channel VARCHAR(20) NOT NULL,
  recipient_user_id BIGINT UNSIGNED NULL,
  recipient VARCHAR(160) NULL,
  subject VARCHAR(160) NULL,
  body TEXT NOT NULL,
  template VARCHAR(40) NOT NULL,
  related_type VARCHAR(20) NULL,
  related_id VARCHAR(64) NULL,
  status ENUM('queued','sent','skipped','failed') NOT NULL DEFAULT 'queued',
  provider VARCHAR(30) NOT NULL DEFAULT 'log',
  error VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL,
  KEY idx_notifications_user (recipient_user_id),
  KEY idx_notifications_related (related_type, related_id),
  KEY idx_notifications_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
