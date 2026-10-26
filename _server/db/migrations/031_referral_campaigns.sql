-- Mzigo.online — migration 031: campaign scheduling, partner poster attribution, A/B share tracking.
USE mzigo_online;

ALTER TABLE referral_clicks ADD COLUMN source VARCHAR(40) NULL AFTER user_agent;

CREATE TABLE IF NOT EXISTS referral_campaigns (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  message TEXT NOT NULL,
  schedule_at DATETIME NOT NULL,
  recurrence ENUM('once','weekly','monthly') NOT NULL DEFAULT 'once',
  status ENUM('scheduled','paused','sent') NOT NULL DEFAULT 'scheduled',
  sent_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_sent_at DATETIME NULL,
  next_run_at DATETIME NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_referral_campaign_due (status, next_run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
