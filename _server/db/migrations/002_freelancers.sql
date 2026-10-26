-- Mzigo.online — SaaS migration 002
-- Fourth user class: Freelancer (independent gig worker) authenticates with a
-- phone number and a system-generated 4-digit PIN. Admin, merchant owners and
-- employees (tenant staff) keep their existing email/phone + password auth.

USE mzigo_online;

ALTER TABLE users
  MODIFY COLUMN role ENUM('user','platform_admin','freelancer') NOT NULL DEFAULT 'user',
  ADD COLUMN pin_hash VARCHAR(255) NULL AFTER password_hash;
