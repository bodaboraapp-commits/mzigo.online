-- Mzigo.online — migration 034: admin alert acknowledgement/escalation.
USE mzigo_online;
CREATE TABLE IF NOT EXISTS admin_alert_actions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  alert_key VARCHAR(60) NOT NULL,
  action ENUM('ack','escalate') NOT NULL,
  note VARCHAR(255) NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_alert_actions_key (alert_key, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
