-- 023: tenant branding and per-tenant carrier/insurance rate cards.
USE mzigo_online;

CREATE TABLE IF NOT EXISTS tenant_branding (
  tenant_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  logo_url VARCHAR(255) NULL,
  primary_color VARCHAR(7) NOT NULL DEFAULT '#3c5223',
  secondary_color VARCHAR(7) NOT NULL DEFAULT '#4a6330',
  accent_color VARCHAR(7) NOT NULL DEFAULT '#8ddc95',
  support_email VARCHAR(160) NULL,
  support_phone VARCHAR(30) NULL,
  document_footer VARCHAR(255) NULL,
  updated_by BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tenant_rate_cards (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  carrier_slug VARCHAR(60) NULL,
  route_from VARCHAR(120) NULL,
  route_to VARCHAR(120) NULL,
  size VARCHAR(40) NULL,
  base_fee_kes INT UNSIGNED NULL,
  holding_fee_kes INT UNSIGNED NULL,
  insurance_pct DECIMAL(5,2) NULL,
  platform_fee_kes INT UNSIGNED NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_tenant_rate_cards (tenant_id, is_active),
  KEY idx_tenant_rate_match (tenant_id, carrier_slug, route_from, route_to, size)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
