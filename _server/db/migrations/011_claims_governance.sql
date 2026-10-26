-- Mzigo.online — SaaS migration 011: claims Phase 2 governance.
USE mzigo_online;

ALTER TABLE claims
  MODIFY COLUMN status ENUM('open','under_review','approved','rejected','paid','withdrawn','reversed') NOT NULL DEFAULT 'open',
  ADD COLUMN payout_amount_kes INT NULL AFTER payout_ref,
  ADD COLUMN first_approved_by BIGINT UNSIGNED NULL AFTER payout_amount_kes,
  ADD COLUMN first_approved_at DATETIME NULL AFTER first_approved_by,
  ADD COLUMN approved_by BIGINT UNSIGNED NULL AFTER first_approved_at,
  ADD COLUMN sla_due_at DATETIME NULL AFTER approved_by,
  ADD COLUMN appeal_count TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER sla_due_at,
  ADD COLUMN appeal_deadline DATETIME NULL AFTER appeal_count,
  ADD COLUMN reversed_at DATETIME NULL AFTER appeal_deadline,
  ADD COLUMN reversal_ref VARCHAR(64) NULL AFTER reversed_at,
  ADD COLUMN recovery_status ENUM('none','requested','recovered','rejected') NOT NULL DEFAULT 'none' AFTER reversal_ref,
  ADD COLUMN recovery_ref VARCHAR(64) NULL AFTER recovery_status,
  ADD COLUMN recovery_amount_kes INT NULL AFTER recovery_ref,
  ADD COLUMN recovery_note VARCHAR(255) NULL AFTER recovery_amount_kes;

UPDATE claims SET sla_due_at = DATE_ADD(created_at, INTERVAL 5 DAY) WHERE sla_due_at IS NULL;

CREATE TABLE IF NOT EXISTS claim_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  claim_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  event VARCHAR(40) NOT NULL,
  note VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_claim_events (claim_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS claim_attachments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  claim_id BIGINT UNSIGNED NOT NULL,
  tenant_id BIGINT UNSIGNED NOT NULL,
  filename VARCHAR(255) NOT NULL,
  mime VARCHAR(80) NOT NULL,
  size_bytes INT NOT NULL,
  storage_name VARCHAR(120) NOT NULL,
  uploaded_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_claim_attachments (claim_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
