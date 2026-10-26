-- Mzigo.online — migration 033: promise windows, parcel CSAT, dashboard instrument foundation.
USE mzigo_online;
ALTER TABLE parcels
  ADD COLUMN promised_delivery_at DATETIME NULL AFTER registered_at,
  ADD COLUMN csat_token VARCHAR(64) NULL AFTER insurance_response,
  ADD COLUMN csat_rating TINYINT UNSIGNED NULL AFTER csat_token,
  ADD COLUMN csat_comment VARCHAR(500) NULL AFTER csat_rating,
  ADD COLUMN csat_at DATETIME NULL AFTER csat_comment;

UPDATE parcels SET promised_delivery_at = DATE_ADD(registered_at, INTERVAL 48 HOUR) WHERE promised_delivery_at IS NULL;

INSERT INTO platform_settings (setting_key, setting_value)
VALUES ('delivery_promise_hours', '48')
ON DUPLICATE KEY UPDATE setting_value = setting_value;
