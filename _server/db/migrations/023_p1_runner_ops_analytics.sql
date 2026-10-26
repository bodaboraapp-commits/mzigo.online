-- Mzigo.online — migration 023: P1 analytics, runner payouts.
USE mzigo_online;

CREATE TABLE IF NOT EXISTS analytics_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event VARCHAR(64) NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  path VARCHAR(255) NULL,
  label VARCHAR(160) NULL,
  location VARCHAR(120) NULL,
  audience VARCHAR(30) NULL,
  meta JSON NULL,
  ip_hash CHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_analytics_event_time (event, created_at),
  KEY idx_analytics_path_time (path, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS runner_payouts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ref VARCHAR(24) NOT NULL UNIQUE,
  runner_id BIGINT UNSIGNED NOT NULL,
  amount_kes INT UNSIGNED NOT NULL,
  phone VARCHAR(20) NOT NULL,
  status ENUM('pending','processing','paid','failed') NOT NULL DEFAULT 'paid',
  provider_ref VARCHAR(64) NULL,
  note VARCHAR(255) NULL,
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at DATETIME NULL,
  KEY idx_runner_payouts_runner (runner_id, requested_at),
  CONSTRAINT fk_runner_payouts_runner FOREIGN KEY (runner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE runner_jobs
  ADD COLUMN payout_id BIGINT UNSIGNED NULL AFTER proof_note,
  ADD KEY idx_runner_jobs_payout (payout_id),
  ADD CONSTRAINT fk_runner_jobs_payout FOREIGN KEY (payout_id) REFERENCES runner_payouts(id) ON DELETE SET NULL;
