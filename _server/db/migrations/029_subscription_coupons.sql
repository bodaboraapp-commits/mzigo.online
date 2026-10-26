-- Mzigo.online — migration 029: subscription coupon and credit fields on invoices.
USE mzigo_online;
ALTER TABLE invoices
  ADD COLUMN coupon_code VARCHAR(40) NULL AFTER reference,
  ADD COLUMN discount_kes INT UNSIGNED NOT NULL DEFAULT 0 AFTER coupon_code,
  ADD COLUMN credit_used_kes INT UNSIGNED NOT NULL DEFAULT 0 AFTER discount_kes,
  ADD COLUMN paid_amount_kes INT UNSIGNED NULL AFTER credit_used_kes;
