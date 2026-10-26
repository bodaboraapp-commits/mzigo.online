-- Mzigo.online — SaaS migration 010: claims phase 1 hardening.
USE mzigo_online;

ALTER TABLE claims
  ADD COLUMN evidence TEXT NULL AFTER description,
  ADD COLUMN payout_hold_until DATETIME NULL AFTER payout_ref,
  ADD COLUMN paid_at DATETIME NULL AFTER payout_hold_until;
