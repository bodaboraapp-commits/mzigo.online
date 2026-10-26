-- Mzigo.online — migration 021: runner (freelancer) onboarding profile.
-- Keeps job preferences, payout details and terms acceptance separate from the
-- users row so merchant/customer records stay untouched.
USE mzigo_online;

CREATE TABLE IF NOT EXISTS freelancer_profiles (
  user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  areas VARCHAR(255) NULL,
  vehicle_type VARCHAR(40) NULL,
  availability_days VARCHAR(120) NULL,
  availability_window VARCHAR(40) NULL,
  available_now TINYINT(1) NOT NULL DEFAULT 0,
  payout_phone VARCHAR(20) NULL,
  id_number VARCHAR(60) NULL,
  terms_version VARCHAR(20) NULL,
  terms_accepted_at DATETIME NULL,
  onboarding_completed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_freelancer_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
