-- 019: webhook retries and delivery queue metadata.
USE mzigo_online;

ALTER TABLE webhook_deliveries
  MODIFY status ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
  ADD COLUMN attempts INT NOT NULL DEFAULT 0 AFTER status,
  ADD COLUMN next_retry_at DATETIME NULL AFTER attempts,
  ADD COLUMN last_attempt_at DATETIME NULL AFTER next_retry_at,
  ADD KEY idx_webhook_retry (status, next_retry_at);
