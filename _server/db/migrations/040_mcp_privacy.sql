-- Mzigo.online — migration 040: MCP microservice privacy policy.
CREATE TABLE IF NOT EXISTS mcp_privacy_policies (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    mode ENUM('sandbox','proxy') NOT NULL DEFAULT 'sandbox',
    token VARCHAR(64) NULL,
    allowed_scopes VARCHAR(255) NOT NULL DEFAULT 'parcel.track,orders.list,workspace.analytics_summary',
    mask_fields VARCHAR(255) NOT NULL DEFAULT 'phone,email',
    max_rows INT UNSIGNED NOT NULL DEFAULT 50,
    rate_limit_per_min INT UNSIGNED NOT NULL DEFAULT 60,
    tenant_isolation TINYINT(1) NOT NULL DEFAULT 1,
    read_only TINYINT(1) NOT NULL DEFAULT 1,
    retention_days INT UNSIGNED NOT NULL DEFAULT 30,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO mcp_privacy_policies (id, enabled, mode, allowed_scopes, mask_fields, max_rows, rate_limit_per_min, tenant_isolation, read_only, retention_days)
VALUES (1, 0, 'sandbox', 'parcel.track,orders.list,workspace.analytics_summary', 'phone,email', 50, 60, 1, 1, 30)
ON DUPLICATE KEY UPDATE id = id;
