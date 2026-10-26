-- Mzigo.online — SaaS migration 005: user lifecycle status.
USE mzigo_online;

ALTER TABLE users
  ADD COLUMN status ENUM('active','suspended','deleted') NOT NULL DEFAULT 'active' AFTER role,
  ADD KEY idx_users_status (status);

UPDATE users SET status = 'active' WHERE status IS NULL;
