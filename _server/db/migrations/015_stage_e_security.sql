-- Mzigo.online — SaaS migration 015: Stage E security.
USE mzigo_online;

ALTER TABLE users
  ADD COLUMN email_verified_at DATETIME NULL AFTER email;

UPDATE users SET email_verified_at = NOW() WHERE email IS NOT NULL AND email_verified_at IS NULL;

CREATE TABLE IF NOT EXISTS email_verifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  email VARCHAR(160) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_email_verify_token (token_hash),
  KEY idx_email_verify_user (user_id, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_2fa (
  user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  secret VARCHAR(64) NOT NULL,
  enabled_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS api_usage_daily (
  tenant_id BIGINT UNSIGNED NOT NULL,
  api_key_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  day DATE NOT NULL,
  requests INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (tenant_id, api_key_id, day),
  KEY idx_api_usage_day (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
