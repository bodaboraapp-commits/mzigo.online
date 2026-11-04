-- MziGO.Online — scan point in-app alert feed.
CREATE TABLE IF NOT EXISTS point_notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    point_id BIGINT UNSIGNED NOT NULL,
    template VARCHAR(60) NOT NULL DEFAULT 'scan_activity',
    subject VARCHAR(190) NOT NULL,
    body TEXT NULL,
    data_json TEXT NULL,
    channel VARCHAR(40) NOT NULL DEFAULT 'in_app',
    status VARCHAR(20) NOT NULL DEFAULT 'sent',
    read_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_point_notifications_point (point_id, created_at),
    KEY idx_point_notifications_unread (point_id, read_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
