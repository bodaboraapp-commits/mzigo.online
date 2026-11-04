<?php
/**
 * GET  /api/scan-notifications.php — point-level alerts + active comms channels.
 * POST /api/scan-notifications.php — mark alerts read.
 *
 * Authentication: X-Point-Token (same token as /api/scan.php).
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_notifications.php';
require __DIR__ . '/_push.php';

header('Cache-Control: no-store');

function scan_notifications_point(): array
{
    $raw = trim((string) ($_SERVER['HTTP_X_POINT_TOKEN'] ?? ''));
    if ($raw === '' || strlen($raw) < 24) {
        fail_error('point_token_required', 'Enter the point access token.', 401);
    }
    $prefix = substr($raw, 0, 12);
    $stmt = db()->prepare('SELECT * FROM partner_points WHERE access_token_prefix = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$prefix]);
    $point = $stmt->fetch();
    if (!$point || !hash_equals((string) $point['access_token_hash'], hash('sha256', $raw))) {
        rate_limit('scan_notifications_auth_fail:' . client_ip(), 30, 300);
        fail_error('point_token_invalid', 'That point access token is not valid.', 401);
    }
    return $point;
}

function scan_notifications_payload(array $point): array
{
    point_notifications_ensure_schema();
    $stmt = db()->prepare('SELECT id, template, subject, body, channel, status, read_at, created_at
        FROM point_notifications WHERE point_id = ? ORDER BY id DESC LIMIT 12');
    $stmt->execute([(int) $point['id']]);
    $rows = $stmt->fetchAll();
    $unread = db()->prepare('SELECT COUNT(*) FROM point_notifications WHERE point_id = ? AND read_at IS NULL');
    $unread->execute([(int) $point['id']]);
    $channels = array_values(array_map(function ($c) {
        return ['code' => (string) $c['code'], 'label' => (string) $c['label']];
    }, notification_channels(true)));
    return [
        'ok' => true,
        'source' => 'mysql',
        'notifications' => $rows,
        'unread' => (int) $unread->fetchColumn(),
        'channels' => $channels,
        'push' => point_push_channel_enabled(),
    ];
}

$point = scan_notifications_point();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET') {
    json_out(scan_notifications_payload($point));
}

require_post();
$data = body_json();
$action = (string) ($data['action'] ?? 'mark_all_read');
rate_limit('scan-notifications:' . (int) $point['id'], 60, 60);

if ($action === 'mark_read') {
    $id = (int) ($data['notification_id'] ?? 0);
    if ($id <= 0) {
        fail_error('invalid_notification', 'Choose a notification to mark read.', 422);
    }
    db()->prepare('UPDATE point_notifications SET read_at = NOW() WHERE id = ? AND point_id = ?')
        ->execute([$id, (int) $point['id']]);
} elseif ($action === 'mark_all_read') {
    db()->prepare('UPDATE point_notifications SET read_at = NOW() WHERE point_id = ? AND read_at IS NULL')
        ->execute([(int) $point['id']]);
} else {
    fail_error('unknown_action', 'Unknown notification action.', 400);
}

json_out(scan_notifications_payload($point));
