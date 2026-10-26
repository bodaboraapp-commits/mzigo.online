-- Mzigo.online — SaaS migration 013: claim messages, notification read state.
USE mzigo_online;

ALTER TABLE notifications
  ADD COLUMN read_at DATETIME NULL AFTER sent_at;

CREATE TABLE IF NOT EXISTS claim_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  claim_id BIGINT UNSIGNED NOT NULL,
  sender_user_id BIGINT UNSIGNED NULL,
  sender_role ENUM('customer','merchant','admin') NOT NULL,
  message TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_claim_messages (claim_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
