-- Mzigo.online — migration 032: real insurer policy issuance.
USE mzigo_online;
ALTER TABLE parcels
  ADD COLUMN insurance_policy_no VARCHAR(100) NULL AFTER payment_ref,
  ADD COLUMN insurance_status ENUM('pending','issued','failed') NOT NULL DEFAULT 'pending' AFTER insurance_policy_no,
  ADD COLUMN insurance_provider VARCHAR(100) NULL AFTER insurance_status,
  ADD COLUMN insurance_issued_at DATETIME NULL AFTER insurance_provider,
  ADD COLUMN insurance_response MEDIUMTEXT NULL AFTER insurance_issued_at;
