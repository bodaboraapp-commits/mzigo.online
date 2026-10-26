-- Mzigo.online — SaaS migration 003
-- Fifth user class: Customer (the receiver who pays at collection).
-- Adds customer role + OTP fields, parcel payment state and a receiver phone key
-- so a customer can see parcels addressed to their phone.

USE mzigo_online;

ALTER TABLE users
  MODIFY COLUMN role ENUM('user','platform_admin','freelancer','customer') NOT NULL DEFAULT 'user',
  ADD COLUMN otp_hash VARCHAR(255) NULL AFTER pin_hash,
  ADD COLUMN otp_expires_at DATETIME NULL AFTER otp_hash;

ALTER TABLE parcels
  ADD COLUMN receiver_phone_key VARCHAR(15) NULL AFTER receiver_phone,
  ADD COLUMN payment_status ENUM('unpaid','paid') NOT NULL DEFAULT 'unpaid' AFTER status,
  ADD COLUMN paid_at DATETIME NULL AFTER payment_status,
  ADD COLUMN payment_ref VARCHAR(60) NULL AFTER paid_at,
  ADD COLUMN paid_by_user_id BIGINT UNSIGNED NULL AFTER payment_ref,
  ADD KEY idx_parcels_receiver_key (receiver_phone_key);

-- Normalise existing receiver phone numbers (last 9 digits) for customer lookups.
UPDATE parcels
SET receiver_phone_key = RIGHT(REGEXP_REPLACE(receiver_phone, '[^0-9]', ''), 9)
WHERE receiver_phone_key IS NULL;

-- Historically collected parcels were paid at collection.
UPDATE parcels
SET payment_status = 'paid',
    paid_at = registered_at,
    payment_ref = 'HISTORIC-COLLECTED'
WHERE status = 'COLLECTED_PAID' AND payment_status = 'unpaid';
