<?php
/**
 * MziGO.Online — push token storage readiness.
 *
 * The push_tokens table was added after some installations were provisioned.
 * Repair the table in place so enabling notifications does not fail with a raw 500.
 */
declare(strict_types=1);

function push_tokens_ensure_schema(): void
{
    static $ready = false;
    if ($ready) return;

    $pdo = db();
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS push_tokens (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            token VARCHAR(512) NOT NULL,
            platform VARCHAR(20) NOT NULL DEFAULT "web",
            user_agent VARCHAR(255) NULL,
            marketing_opt_in TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_push_token (token),
            KEY idx_push_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM push_tokens') as $row) {
        $columns[(string) $row['Field']] = true;
    }
    $definitions = [
        'user_id' => 'BIGINT UNSIGNED NOT NULL',
        'token' => 'VARCHAR(512) NOT NULL',
        'platform' => 'VARCHAR(20) NOT NULL DEFAULT "web"',
        'user_agent' => 'VARCHAR(255) NULL',
        'marketing_opt_in' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'last_seen_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ];
    foreach ($definitions as $name => $definition) {
        if (!isset($columns[$name])) {
            $pdo->exec('ALTER TABLE push_tokens ADD COLUMN ' . $name . ' ' . $definition);
        }
    }

    $hasUnique = false;
    foreach ($pdo->query('SHOW INDEX FROM push_tokens') as $row) {
        if ((string) $row['Key_name'] === 'uniq_push_token') {
            $hasUnique = true;
            break;
        }
    }
    if (!$hasUnique) {
        try {
            $pdo->exec('ALTER TABLE push_tokens ADD UNIQUE KEY uniq_push_token (token)');
        } catch (Throwable $ignored) {
            // Existing duplicate tokens should not block enabling notifications.
        }
    }

    $ready = true;
}
