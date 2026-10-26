-- Mzigo.online — SaaS migration 012: customer claims, risk scoring, blacklist & OTP.
USE mzigo_online;

ALTER TABLE claims
  ADD COLUMN claimant_role ENUM('merchant','customer') NOT NULL DEFAULT 'merchant' AFTER type,
  ADD COLUMN risk_score TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER claimant_role,
  ADD COLUMN risk_flags TEXT NULL AFTER risk_score;

CREATE TABLE IF NOT EXISTS claim_otps (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  parcel_id VARCHAR(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  phone_key VARCHAR(32) NOT NULL,
  code_hash VARCHAR(255) NOT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at DATETIME NOT NULL,
  consumed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_claim_otp (parcel_id, user_id, consumed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS claim_blacklist (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  scope ENUM('phone','user','tenant') NOT NULL,
  value VARCHAR(190) NOT NULL,
  reason VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  expires_at DATETIME NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_blacklist_scope_value (scope, value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
