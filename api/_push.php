<?php
/**
 * MziGO.Online — push token storage readiness and point-scoped delivery.
 *
 * The push_tokens table was added after some installations were provisioned.
 * Repair the table in place so enabling notifications does not fail with a raw 500.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/_server/firebase.php';

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

function point_push_tokens_ensure_schema(): void
{
    static $ready = false;
    if ($ready) return;

    $pdo = db();
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS point_push_tokens (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            point_id BIGINT UNSIGNED NOT NULL,
            token VARCHAR(512) NOT NULL,
            platform VARCHAR(20) NOT NULL DEFAULT "web",
            user_agent VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_point_push_token (token),
            KEY idx_point_push_point (point_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM point_push_tokens') as $row) {
        $columns[(string) $row['Field']] = true;
    }
    $definitions = [
        'point_id' => 'BIGINT UNSIGNED NOT NULL',
        'token' => 'VARCHAR(512) NOT NULL',
        'platform' => 'VARCHAR(20) NOT NULL DEFAULT "web"',
        'user_agent' => 'VARCHAR(255) NULL',
        'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'last_seen_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ];
    foreach ($definitions as $name => $definition) {
        if (!isset($columns[$name])) {
            $pdo->exec('ALTER TABLE point_push_tokens ADD COLUMN ' . $name . ' ' . $definition);
        }
    }

    $ready = true;
}

/** Push to every device subscribed to a partner scan point. */
function firebase_push_point(int $pointId, string $title, string $body, string $link = '/scan', array $data = []): array
{
    if ($pointId <= 0) return ['sent' => 0, 'failed' => 0];
    try {
        point_push_tokens_ensure_schema();
        $stmt = db()->prepare('SELECT token FROM point_push_tokens WHERE point_id = ?');
        $stmt->execute([$pointId]);
        $tokens = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return firebase_send_push($tokens, $title, $body, $link, $data);
    } catch (Throwable $e) {
        error_log('firebase push point failed: ' . $e->getMessage());
        return ['sent' => 0, 'failed' => 0];
    }
}
