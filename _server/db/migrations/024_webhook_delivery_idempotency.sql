-- 024: webhook event IDs, idempotency keys and merchant delivery history.
USE mzigo_online;

ALTER TABLE webhook_deliveries
  ADD COLUMN event_id CHAR(36) NULL AFTER webhook_id,
  ADD COLUMN idempotency_key VARCHAR(120) NULL AFTER event_id,
  ADD COLUMN error VARCHAR(255) NULL AFTER response_body,
  ADD COLUMN completed_at DATETIME NULL AFTER next_retry_at,
  ADD UNIQUE KEY uq_webhook_idempotency (webhook_id, idempotency_key),
  ADD KEY idx_webhook_event_id (event_id);
