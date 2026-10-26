-- 022: claim payout/recovery rails, provider configuration and callbacks.
USE mzigo_online;

CREATE TABLE IF NOT EXISTS claim_rails_config (
  id TINYINT UNSIGNED NOT NULL DEFAULT 1 PRIMARY KEY,
  payout_mode ENUM('demo','sandbox','live') NOT NULL DEFAULT 'demo',
  payout_provider VARCHAR(24) NOT NULL DEFAULT 'demo',
  payout_config MEDIUMTEXT NULL,
  recovery_mode ENUM('demo','sandbox','live') NOT NULL DEFAULT 'demo',
  recovery_provider VARCHAR(24) NOT NULL DEFAULT 'demo',
  recovery_config MEDIUMTEXT NULL,
  callback_secret MEDIUMTEXT NULL,
  updated_by BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO claim_rails_config (id, payout_mode, payout_provider, recovery_mode, recovery_provider, updated_at)
VALUES (1, 'demo', 'demo', 'demo', 'demo', NOW())
ON DUPLICATE KEY UPDATE id = id;

CREATE TABLE IF NOT EXISTS claim_payouts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  claim_id BIGINT UNSIGNED NOT NULL,
  tenant_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(24) NOT NULL,
  mode ENUM('demo','sandbox','live') NOT NULL,
  amount_kes INT UNSIGNED NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'KES',
  status ENUM('created','processing','sent','failed','reversed') NOT NULL DEFAULT 'created',
  phone VARCHAR(24) NULL,
  provider_ref VARCHAR(80) NULL,
  external_ref VARCHAR(120) NULL,
  request_payload MEDIUMTEXT NULL,
  response_payload MEDIUMTEXT NULL,
  error VARCHAR(255) NULL,
  idempotency_key VARCHAR(80) NULL,
  requested_by BIGINT UNSIGNED NULL,
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_claim_payout_idempotency (idempotency_key),
  UNIQUE KEY uq_claim_payout_external (external_ref),
  KEY idx_claim_payouts_claim (claim_id, created_at),
  KEY idx_claim_payouts_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS claim_recoveries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  claim_id BIGINT UNSIGNED NOT NULL,
  tenant_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(24) NOT NULL,
  mode ENUM('demo','sandbox','live') NOT NULL,
  status ENUM('requested','processing','recovered','rejected','cancelled') NOT NULL DEFAULT 'requested',
  amount_kes INT UNSIGNED NULL,
  reference VARCHAR(80) NULL,
  external_ref VARCHAR(120) NULL,
  request_payload MEDIUMTEXT NULL,
  response_payload MEDIUMTEXT NULL,
  error VARCHAR(255) NULL,
  note VARCHAR(255) NULL,
  requested_by BIGINT UNSIGNED NULL,
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_claim_recovery_external (external_ref),
  KEY idx_claim_recoveries_claim (claim_id, created_at),
  KEY idx_claim_recoveries_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS claim_rail_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(24) NOT NULL,
  external_ref VARCHAR(120) NULL,
  event_type VARCHAR(40) NOT NULL,
  payload MEDIUMTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_claim_rail_events_ref (external_ref, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE claims
  MODIFY status ENUM('open','under_review','approved','rejected','paid','withdrawn','reversed','payout_processing') NOT NULL DEFAULT 'open';
