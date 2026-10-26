-- 020: attachment malware scan results and blocked upload audit.
USE mzigo_online;

ALTER TABLE claim_attachments
  ADD COLUMN scan_status VARCHAR(20) NOT NULL DEFAULT 'unscanned' AFTER storage_name,
  ADD COLUMN scan_engine VARCHAR(40) NULL AFTER scan_status,
  ADD COLUMN scan_note VARCHAR(255) NULL AFTER scan_engine;

CREATE TABLE IF NOT EXISTS claim_attachment_scans (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  claim_id BIGINT UNSIGNED NOT NULL,
  uploaded_by BIGINT UNSIGNED NULL,
  filename VARCHAR(255) NOT NULL,
  mime VARCHAR(80) NOT NULL,
  size_bytes INT NOT NULL DEFAULT 0,
  result VARCHAR(20) NOT NULL,
  engine VARCHAR(40) NULL,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_attachment_scans_claim (claim_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
