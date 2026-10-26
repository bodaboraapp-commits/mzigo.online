-- Mzigo.online — migration 030: referral milestone rewards.
USE mzigo_online;
CREATE TABLE IF NOT EXISTS referral_milestones (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  program ENUM('runner','business','customer') NOT NULL,
  milestone INT UNSIGNED NOT NULL,
  amount_kes INT UNSIGNED NOT NULL,
  kind ENUM('mpesa','parcel_credit','bill_credit') NOT NULL DEFAULT 'parcel_credit',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_referral_milestone (user_id, program, milestone),
  CONSTRAINT fk_referral_milestone_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
