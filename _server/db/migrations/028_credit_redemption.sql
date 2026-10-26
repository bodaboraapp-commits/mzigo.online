-- Mzigo.online — migration 028: partial credit redemption tracking.
USE mzigo_online;
ALTER TABLE credit_ledger
  ADD COLUMN used_kes INT UNSIGNED NOT NULL DEFAULT 0 AFTER amount_kes,
  ADD COLUMN redeemed_reference VARCHAR(64) NULL AFTER status;
