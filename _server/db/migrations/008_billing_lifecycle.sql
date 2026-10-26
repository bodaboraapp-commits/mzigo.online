-- Mzigo.online — SaaS migration 008: billing lifecycle.
USE mzigo_online;

ALTER TABLE tenants
  ADD COLUMN billing_name VARCHAR(160) NULL AFTER phone,
  ADD COLUMN billing_email VARCHAR(160) NULL AFTER billing_name,
  ADD COLUMN billing_address VARCHAR(255) NULL AFTER billing_email,
  ADD COLUMN tax_id VARCHAR(40) NULL AFTER billing_address,
  ADD COLUMN tax_rate DECIMAL(5,2) NOT NULL DEFAULT 16.00 AFTER tax_id;

ALTER TABLE invoices
  MODIFY COLUMN status ENUM('pending','paid','void','refunded') NOT NULL DEFAULT 'pending',
  ADD COLUMN subtotal_kes INT NULL AFTER amount_kes,
  ADD COLUMN tax_kes INT NULL AFTER subtotal_kes,
  ADD COLUMN tax_rate DECIMAL(5,2) NULL AFTER tax_kes,
  ADD COLUMN billing_snapshot JSON NULL AFTER tax_rate,
  ADD COLUMN refunded_at DATETIME NULL AFTER paid_at,
  ADD COLUMN refund_ref VARCHAR(60) NULL AFTER refunded_at;

ALTER TABLE subscriptions
  ADD COLUMN cancel_at_period_end TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
  ADD COLUMN pending_plan_id BIGINT UNSIGNED NULL AFTER cancel_at_period_end,
  ADD COLUMN pending_plan_at DATETIME NULL AFTER pending_plan_id;

UPDATE tenants SET billing_name = name WHERE billing_name IS NULL;
UPDATE tenants SET billing_email = email WHERE billing_email IS NULL;
