-- Mzigo.online — migration 022: runner job console.
-- Jobs are created from errand bookings and offered to onboarded runners.
-- Earnings are derived from delivered jobs; payout ledger ships in Phase 3.
USE mzigo_online;

CREATE TABLE IF NOT EXISTS runner_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ref VARCHAR(24) NOT NULL UNIQUE,
  errand_id CHAR(36) NULL,
  pickup VARCHAR(160) NOT NULL,
  dropoff VARCHAR(160) NOT NULL,
  area VARCHAR(120) NULL,
  fee_kes INT UNSIGNED NOT NULL DEFAULT 0,
  customer_fee_kes INT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('available','accepted','picked_up','delivered','cancelled') NOT NULL DEFAULT 'available',
  runner_id BIGINT UNSIGNED NULL,
  description VARCHAR(255) NULL,
  instructions VARCHAR(255) NULL,
  contact_name VARCHAR(120) NULL,
  contact_phone VARCHAR(20) NULL,
  receiver_name VARCHAR(120) NULL,
  receiver_phone VARCHAR(20) NULL,
  proof_note VARCHAR(255) NULL,
  accepted_at DATETIME NULL,
  picked_up_at DATETIME NULL,
  delivered_at DATETIME NULL,
  cancelled_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_runner_jobs_status (status, created_at),
  KEY idx_runner_jobs_runner (runner_id, status),
  CONSTRAINT fk_runner_jobs_runner FOREIGN KEY (runner_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS runner_job_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  job_id BIGINT UNSIGNED NOT NULL,
  runner_id BIGINT UNSIGNED NULL,
  event VARCHAR(40) NOT NULL,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_runner_job_events_job (job_id, created_at),
  CONSTRAINT fk_runner_job_events_job FOREIGN KEY (job_id) REFERENCES runner_jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS runner_job_declines (
  job_id BIGINT UNSIGNED NOT NULL,
  runner_id BIGINT UNSIGNED NOT NULL,
  reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (job_id, runner_id),
  CONSTRAINT fk_runner_declines_job FOREIGN KEY (job_id) REFERENCES runner_jobs(id) ON DELETE CASCADE,
  CONSTRAINT fk_runner_declines_runner FOREIGN KEY (runner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS runner_notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(160) NOT NULL,
  body TEXT NOT NULL,
  job_id BIGINT UNSIGNED NULL,
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_runner_notifications_user (user_id, read_at, created_at),
  CONSTRAINT fk_runner_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
