-- 025: runner receiver OTP proof, matching attributes, incentives and SEO monitoring.
USE mzigo_online;

ALTER TABLE errands
  ADD COLUMN size VARCHAR(40) NULL AFTER description,
  ADD COLUMN distance_km DECIMAL(5,1) NULL AFTER size;

ALTER TABLE runner_jobs
  ADD COLUMN size VARCHAR(40) NULL AFTER description,
  ADD COLUMN distance_km DECIMAL(5,1) NULL AFTER size,
  ADD COLUMN delivery_otp_hash VARCHAR(255) NULL,
  ADD COLUMN delivery_otp_expires_at DATETIME NULL,
  ADD COLUMN delivery_otp_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN delivery_otp_sent_at DATETIME NULL,
  ADD COLUMN delivery_otp_required TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN delivery_otp_channel VARCHAR(40) NULL;

ALTER TABLE runner_payouts
  ADD COLUMN bonus_kes INT UNSIGNED NOT NULL DEFAULT 0 AFTER amount_kes;

CREATE TABLE IF NOT EXISTS web_vitals (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  metric VARCHAR(20) NOT NULL,
  value DECIMAL(12,3) NOT NULL,
  rating ENUM('good','needs-improvement','poor','unknown') NOT NULL DEFAULT 'unknown',
  page_path VARCHAR(255) NULL,
  device VARCHAR(20) NULL,
  connection_type VARCHAR(30) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_web_vitals_metric (metric, created_at),
  KEY idx_web_vitals_path (page_path, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO platform_settings (setting_key, setting_value) VALUES
  ('seo_search_console_token', ''),
  ('seo_bing_token', ''),
  ('seo_cwv_api_key', '')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
