-- Mzigo.online — SaaS migration 008: per-channel configuration.
--
-- The admin console can now create notification channels and store the
-- provider/sender/config used to deliver them. `config` holds optional JSON
-- (API keys, webhook URLs, shortcodes) and is only returned to platform admins.

USE mzigo_online;

ALTER TABLE communication_channels
  ADD COLUMN provider VARCHAR(40) NOT NULL DEFAULT 'log' AFTER label,
  ADD COLUMN sender VARCHAR(160) NULL AFTER provider,
  ADD COLUMN config TEXT NULL AFTER sender,
  ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
