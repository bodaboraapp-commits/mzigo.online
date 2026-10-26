-- Mzigo.online — SaaS migration 014: password reset tokens.
USE mzigo_online;

CREATE TABLE IF NOT EXISTS password_resets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  requested_ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_reset_token (token_hash),
  KEY idx_reset_user (user_id, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
