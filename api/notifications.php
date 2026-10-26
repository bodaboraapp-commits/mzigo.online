<?php
/** GET/POST /api/notifications.php — current user's notification inbox and read state. */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$user = require_user();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET') {
    $stmt = db()->prepare(
        'SELECT id, channel, subject, body, template, related_type, related_id, status, created_at, read_at
         FROM notifications WHERE recipient_user_id = ? ORDER BY id DESC LIMIT 50'
    );
    $stmt->execute([(int) $user['id']]);
    $rows = $stmt->fetchAll();
    $unreadStmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE recipient_user_id = ? AND read_at IS NULL');
    $unreadStmt->execute([(int) $user['id']]);
    json_out(['ok' => true, 'source' => 'mysql', 'notifications' => $rows,
        'unread' => (int) $unreadStmt->fetchColumn()]);
}

require_post();
verify_csrf();
$data = body_json();
$action = (string) ($data['action'] ?? '');
if ($action === 'mark_read') {
    $id = (int) ($data['notification_id'] ?? 0);
    $stmt = db()->prepare('UPDATE notifications SET read_at = NOW() WHERE id = ? AND recipient_user_id = ?');
    $stmt->execute([$id, (int) $user['id']]);
} elseif ($action === 'mark_all_read') {
    db()->prepare('UPDATE notifications SET read_at = NOW() WHERE recipient_user_id = ? AND read_at IS NULL')
        ->execute([(int) $user['id']]);
} else {
    fail_error('unknown_action', 'Unknown notification action.', 400);
}
$unreadStmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE recipient_user_id = ? AND read_at IS NULL');
$unreadStmt->execute([(int) $user['id']]);
json_out(['ok' => true, 'source' => 'mysql', 'unread' => (int) $unreadStmt->fetchColumn()]);
