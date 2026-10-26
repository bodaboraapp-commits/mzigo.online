-- Marketing campaigns: non-transactional, consent-based broadcast messages.
CREATE TABLE IF NOT EXISTS marketing_campaigns (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    subject VARCHAR(160) NOT NULL,
    body TEXT NOT NULL,
    cta_label VARCHAR(80) NULL,
    cta_url VARCHAR(500) NULL,
    artwork_url VARCHAR(500) NULL,
    audience ENUM('newsletter','alerts','all') NOT NULL DEFAULT 'all',
    status ENUM('draft','scheduled','sending','sent','cancelled') NOT NULL DEFAULT 'draft',
    scheduled_at DATETIME NULL,
    sent_count INT UNSIGNED NOT NULL DEFAULT 0,
    failed_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    sent_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_marketing_status_schedule (status, scheduled_at),
    KEY idx_marketing_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketing_campaign_recipients (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    campaign_id BIGINT UNSIGNED NOT NULL,
    subscriber_id BIGINT UNSIGNED NOT NULL,
    channel VARCHAR(20) NOT NULL,
    recipient VARCHAR(190) NOT NULL,
    status ENUM('sent','failed','skipped') NOT NULL,
    error VARCHAR(255) NULL,
    notification_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_marketing_recipient (campaign_id, subscriber_id),
    KEY idx_marketing_recipient_status (campaign_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
