-- 021: partner scan points and idempotent scan ingestion.
USE mzigo_online;

CREATE TABLE IF NOT EXISTS partner_points (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NULL,
  partner_slug VARCHAR(60) NOT NULL,
  name VARCHAR(120) NOT NULL,
  code VARCHAR(24) NOT NULL,
  point_type ENUM('dropoff','handover','arrival','collection','multi') NOT NULL DEFAULT 'multi',
  location VARCHAR(160) NULL,
  access_token_prefix CHAR(12) NOT NULL,
  access_token_hash CHAR(64) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_scan_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_partner_point_code (code),
  UNIQUE KEY uq_partner_point_token_prefix (access_token_prefix),
  KEY idx_partner_points_carrier (partner_slug),
  KEY idx_partner_points_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS scan_ingestions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  point_id BIGINT UNSIGNED NOT NULL,
  idempotency_key VARCHAR(80) NOT NULL,
  tracking VARCHAR(20) NOT NULL,
  action VARCHAR(20) NOT NULL,
  parcel_id CHAR(36) NULL,
  response_json MEDIUMTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_scan_ingestion_key (point_id, idempotency_key),
  KEY idx_scan_ingestions_tracking (tracking, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE scans
  ADD COLUMN source VARCHAR(20) NOT NULL DEFAULT 'system' AFTER scanned_at,
  ADD COLUMN point_id BIGINT UNSIGNED NULL AFTER source,
  ADD COLUMN actor_user_id BIGINT UNSIGNED NULL AFTER point_id,
  ADD COLUMN idempotency_key VARCHAR(80) NULL AFTER actor_user_id,
  ADD KEY idx_scans_point (point_id, scanned_at);
