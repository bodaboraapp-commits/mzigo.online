-- Mzigo.online — SaaS migration 004
-- DB-backed sessions (managed + logged) and a central transactions ledger.

USE mzigo_online;

CREATE TABLE IF NOT EXISTS sessions (
  id VARCHAR(128) NOT NULL PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  tenant_id BIGINT UNSIGNED NULL,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  data MEDIUMTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  KEY idx_sessions_user (user_id),
  KEY idx_sessions_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS transactions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  reference VARCHAR(64) NOT NULL,
  tenant_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NULL,
  type ENUM('subscription','parcel_payment','manual','refund') NOT NULL DEFAULT 'subscription',
  amount_kes INT NOT NULL,
  method VARCHAR(30) NOT NULL DEFAULT 'DEMO_MPESA',
  status ENUM('pending','succeeded','failed') NOT NULL DEFAULT 'pending',
  subject_type VARCHAR(20) NULL,
  subject_id VARCHAR(64) NULL,
  metadata JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  UNIQUE KEY uniq_tx_reference (reference),
  KEY idx_tx_tenant (tenant_id, created_at),
  KEY idx_tx_status (status),
  KEY idx_tx_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Align the ledger reference collation with invoices/parcels for backfills.
ALTER TABLE transactions
  MODIFY reference VARCHAR(64) COLLATE utf8mb4_unicode_ci NOT NULL;

-- Backfill subscription payments already recorded on invoices.
INSERT INTO transactions
  (reference, tenant_id, user_id, type, amount_kes, method, status, subject_type, subject_id, metadata, created_at, completed_at)
SELECT i.number, i.tenant_id, NULL, 'subscription', i.amount_kes,
       COALESCE(i.method, 'DEMO_MPESA'), 'succeeded', 'invoice', CAST(i.id AS CHAR),
       JSON_OBJECT('backfill', TRUE, 'number', i.number),
       COALESCE(i.paid_at, i.issued_at), COALESCE(i.paid_at, i.issued_at)
FROM invoices i
WHERE i.status = 'paid'
  AND NOT EXISTS (SELECT 1 FROM transactions t WHERE t.reference = i.number);

-- Backfill pay-at-collection parcel payments.
INSERT INTO transactions
  (reference, tenant_id, user_id, type, amount_kes, method, status, subject_type, subject_id, metadata, created_at, completed_at)
SELECT CONCAT('PRC-', p.tracking), p.tenant_id, p.paid_by_user_id, 'parcel_payment', p.fee_total,
       'DEMO_MPESA', 'succeeded', 'parcel', p.id,
       JSON_OBJECT('backfill', TRUE, 'tracking', p.tracking),
       COALESCE(p.paid_at, p.registered_at), COALESCE(p.paid_at, p.registered_at)
FROM parcels p
WHERE p.payment_status = 'paid'
  AND NOT EXISTS (SELECT 1 FROM transactions t WHERE t.reference = CONCAT('PRC-', p.tracking));
