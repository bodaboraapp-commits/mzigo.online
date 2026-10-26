-- Mzigo.online — migration 027: growth (ratings, bonuses, referrals, coupons).
USE mzigo_online;

ALTER TABLE parcels
  ADD COLUMN coupon_code VARCHAR(40) NULL AFTER payment_ref,
  ADD COLUMN discount_kes INT UNSIGNED NOT NULL DEFAULT 0 AFTER coupon_code,
  ADD COLUMN paid_amount_kes INT UNSIGNED NULL AFTER discount_kes;

ALTER TABLE runner_jobs
  ADD COLUMN rating_token VARCHAR(64) NULL AFTER rated_at,
  ADD COLUMN bonus_kes INT UNSIGNED NOT NULL DEFAULT 0 AFTER rating_token,
  ADD UNIQUE KEY uniq_runner_job_rating_token (rating_token);

CREATE TABLE IF NOT EXISTS runner_ratings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  job_id BIGINT UNSIGNED NOT NULL,
  runner_id BIGINT UNSIGNED NOT NULL,
  customer_user_id BIGINT UNSIGNED NULL,
  rating TINYINT UNSIGNED NOT NULL,
  comment VARCHAR(500) NULL,
  source ENUM('customer','admin') NOT NULL DEFAULT 'customer',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_runner_rating_job (job_id),
  KEY idx_runner_ratings_runner (runner_id, created_at),
  CONSTRAINT fk_runner_ratings_job FOREIGN KEY (job_id) REFERENCES runner_jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS runner_bonus_ledger (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  runner_id BIGINT UNSIGNED NOT NULL,
  job_id BIGINT UNSIGNED NULL,
  rule_code VARCHAR(40) NOT NULL,
  label VARCHAR(120) NOT NULL,
  amount_kes INT UNSIGNED NOT NULL,
  status ENUM('pending','paid','void') NOT NULL DEFAULT 'pending',
  payout_id BIGINT UNSIGNED NULL,
  meta JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_bonus_rule_job (runner_id, rule_code, job_id),
  KEY idx_bonus_runner_status (runner_id, status),
  CONSTRAINT fk_bonus_runner FOREIGN KEY (runner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS referral_codes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  code VARCHAR(30) NOT NULL UNIQUE,
  program ENUM('runner','business','customer') NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_referral_user_program (user_id, program),
  CONSTRAINT fk_referral_codes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS referral_clicks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(30) NOT NULL,
  ip_hash CHAR(64) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_referral_clicks_code (code, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS referrals (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  referrer_user_id BIGINT UNSIGNED NOT NULL,
  referee_user_id BIGINT UNSIGNED NOT NULL,
  program ENUM('runner','business','customer') NOT NULL,
  code VARCHAR(30) NOT NULL,
  status ENUM('attributed','qualified','cancelled') NOT NULL DEFAULT 'attributed',
  attributed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  qualified_at DATETIME NULL,
  meta JSON NULL,
  UNIQUE KEY uniq_referee_program (referee_user_id, program),
  KEY idx_referrals_referrer (referrer_user_id, status),
  CONSTRAINT fk_referrals_referrer FOREIGN KEY (referrer_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_referrals_referee FOREIGN KEY (referee_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS referral_rewards (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  referral_id BIGINT UNSIGNED NOT NULL,
  beneficiary_user_id BIGINT UNSIGNED NOT NULL,
  role ENUM('referrer','referee') NOT NULL,
  amount_kes INT UNSIGNED NOT NULL,
  kind ENUM('mpesa','parcel_credit','bill_credit') NOT NULL DEFAULT 'parcel_credit',
  status ENUM('pending','paid','void') NOT NULL DEFAULT 'pending',
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at DATETIME NULL,
  UNIQUE KEY uniq_referral_role (referral_id, role),
  KEY idx_referral_rewards_beneficiary (beneficiary_user_id, status),
  CONSTRAINT fk_referral_rewards_referral FOREIGN KEY (referral_id) REFERENCES referrals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS credit_ledger (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  amount_kes INT UNSIGNED NOT NULL,
  kind ENUM('parcel_credit','bill_credit') NOT NULL,
  status ENUM('available','used','expired') NOT NULL DEFAULT 'available',
  reference VARCHAR(64) NULL,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  used_at DATETIME NULL,
  KEY idx_credit_user (user_id, kind, status),
  CONSTRAINT fk_credit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS coupons (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  type ENUM('percent','fixed') NOT NULL DEFAULT 'fixed',
  value INT UNSIGNED NOT NULL,
  applies_to ENUM('delivery','subscription','all') NOT NULL DEFAULT 'delivery',
  min_spend_kes INT UNSIGNED NOT NULL DEFAULT 0,
  max_discount_kes INT UNSIGNED NULL,
  usage_limit INT UNSIGNED NULL,
  per_user_limit INT UNSIGNED NOT NULL DEFAULT 1,
  starts_at DATETIME NULL,
  expires_at DATETIME NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS coupon_redemptions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  coupon_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  context ENUM('delivery','subscription') NOT NULL DEFAULT 'delivery',
  reference VARCHAR(64) NULL,
  original_kes INT UNSIGNED NOT NULL,
  discount_kes INT UNSIGNED NOT NULL,
  final_kes INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_coupon_redemptions_coupon (coupon_id, created_at),
  KEY idx_coupon_redemptions_user (user_id, coupon_id),
  CONSTRAINT fk_coupon_redemptions_coupon FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
