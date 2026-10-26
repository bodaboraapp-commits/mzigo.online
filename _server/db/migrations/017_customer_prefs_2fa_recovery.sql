-- 017: customer notification preferences and 2FA recovery codes.
USE mzigo_online;

CREATE TABLE IF NOT EXISTS user_notification_preferences (
  user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  email_enabled TINYINT(1) NOT NULL DEFAULT 1,
  parcel_updates TINYINT(1) NOT NULL DEFAULT 1,
  payment_receipts TINYINT(1) NOT NULL DEFAULT 1,
  claim_updates TINYINT(1) NOT NULL DEFAULT 1,
  marketing TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_2fa_recovery_codes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  code_hash VARCHAR(255) NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_2fa_recovery_user (user_id, used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
