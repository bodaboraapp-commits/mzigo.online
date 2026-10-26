-- Mzigo.online — migration 024: platform payout/KYC/runner launch configuration.
USE mzigo_online;

ALTER TABLE platform_settings
  MODIFY COLUMN setting_value TEXT NOT NULL;

ALTER TABLE freelancer_profiles
  ADD COLUMN kyc_status ENUM('not_submitted','pending','approved','rejected') NOT NULL DEFAULT 'not_submitted' AFTER id_number,
  ADD COLUMN kyc_document_type VARCHAR(40) NULL AFTER kyc_status,
  ADD COLUMN kyc_document_path VARCHAR(255) NULL AFTER kyc_document_type,
  ADD COLUMN kyc_submitted_at DATETIME NULL AFTER kyc_document_path,
  ADD COLUMN kyc_reviewed_at DATETIME NULL AFTER kyc_submitted_at,
  ADD COLUMN kyc_reviewed_by BIGINT UNSIGNED NULL AFTER kyc_reviewed_at,
  ADD COLUMN kyc_note VARCHAR(255) NULL AFTER kyc_reviewed_by,
  ADD COLUMN job_alerts TINYINT(1) NOT NULL DEFAULT 1 AFTER kyc_note,
  ADD COLUMN max_distance_km SMALLINT UNSIGNED NULL AFTER job_alerts,
  ADD COLUMN preferred_sizes VARCHAR(120) NULL AFTER max_distance_km;

CREATE TABLE IF NOT EXISTS kyc_documents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  subject_type ENUM('runner','merchant') NOT NULL,
  subject_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  doc_type VARCHAR(40) NOT NULL,
  original_name VARCHAR(160) NULL,
  file_path VARCHAR(255) NOT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  reviewer_id BIGINT UNSIGNED NULL,
  reviewer_role VARCHAR(30) NULL,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at DATETIME NULL,
  KEY idx_kyc_subject (subject_type, subject_id, status),
  KEY idx_kyc_status (status, created_at),
  CONSTRAINT fk_kyc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
