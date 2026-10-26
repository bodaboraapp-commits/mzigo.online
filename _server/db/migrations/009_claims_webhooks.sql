-- Mzigo.online — SaaS migration 009: claims, webhooks, API scopes.
USE mzigo_online;

ALTER TABLE transactions
  MODIFY COLUMN type ENUM('subscription','parcel_payment','manual','refund','claim_payout') NOT NULL DEFAULT 'subscription';

ALTER TABLE api_keys
  ADD COLUMN scopes VARCHAR(120) NOT NULL DEFAULT 'read,write' AFTER key_hash;

CREATE TABLE IF NOT EXISTS claims (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ref VARCHAR(24) NOT NULL,
  tenant_id BIGINT UNSIGNED NOT NULL,
  parcel_id VARCHAR(64) NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  type ENUM('lost','damaged','delayed') NOT NULL DEFAULT 'lost',
  description TEXT NOT NULL,
  estimated_value_kes INT NULL,
  status ENUM('open','under_review','approved','rejected','paid','withdrawn') NOT NULL DEFAULT 'open',
  resolution_note VARCHAR(255) NULL,
  payout_ref VARCHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  decided_at DATETIME NULL,
  UNIQUE KEY uniq_claim_ref (ref),
  KEY idx_claims_tenant (tenant_id, created_at),
  KEY idx_claims_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS webhooks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  url VARCHAR(500) NOT NULL,
  secret VARCHAR(80) NOT NULL,
  events VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_delivery_at DATETIME NULL,
  last_status VARCHAR(20) NULL,
  KEY idx_webhooks_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS webhook_deliveries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  webhook_id BIGINT UNSIGNED NOT NULL,
  event VARCHAR(60) NOT NULL,
  payload MEDIUMTEXT NOT NULL,
  response_code INT NULL,
  response_body VARCHAR(500) NULL,
  status ENUM('sent','failed') NOT NULL DEFAULT 'failed',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_wh_deliveries (webhook_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Align claim parcel ids with parcels.id for joins.
ALTER TABLE claims MODIFY parcel_id VARCHAR(64) COLLATE utf8mb4_unicode_ci NOT NULL;
