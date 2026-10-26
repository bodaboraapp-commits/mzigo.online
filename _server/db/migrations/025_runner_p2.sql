-- Mzigo.online — migration 025: P2 runner job lifecycle, proof photos, ratings, track subscriptions.
USE mzigo_online;

ALTER TABLE runner_jobs
  ADD COLUMN expires_at DATETIME NULL AFTER cancelled_at,
  ADD COLUMN proof_photo_path VARCHAR(255) NULL AFTER proof_note,
  ADD COLUMN rating TINYINT UNSIGNED NULL AFTER proof_photo_path,
  ADD COLUMN rating_note VARCHAR(255) NULL AFTER rating,
  ADD COLUMN rated_by BIGINT UNSIGNED NULL AFTER rating_note,
  ADD COLUMN rated_at DATETIME NULL AFTER rated_by,
  ADD KEY idx_runner_jobs_expiry (status, expires_at);

CREATE TABLE IF NOT EXISTS track_subscriptions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tracking VARCHAR(40) NOT NULL,
  email VARCHAR(160) NULL,
  phone VARCHAR(20) NULL,
  consent TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_notified_at DATETIME NULL,
  KEY idx_track_subs_tracking (tracking, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
