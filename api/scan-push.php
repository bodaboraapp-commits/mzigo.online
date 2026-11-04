<?php
/**
 * POST /api/scan-push.php — subscribe the scan PWA to point push notifications.
 *
 * Authentication: X-Point-Token (the same token used by /api/scan.php).
 * Body: { action: save|delete, token: string, platform?: string }
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_push.php';

header('Cache-Control: no-store');

function scan_push_point(): array
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
        rate_limit('scan_push_auth_fail:' . client_ip(), 30, 300);
        fail_error('point_token_invalid', 'That point access token is not valid.', 401);
    }
    return $point;
}

require_post();
$point = scan_push_point();
$data = body_json();
$action = (string) ($data['action'] ?? 'save');
$token = trim((string) ($data['token'] ?? ''));
if ($token === '' || strlen($token) > 512) {
    fail_error('invalid_token', 'Push token is missing or too long.', 422);
}
rate_limit('scan-push:' . (int) $point['id'], 30, 300);

try {
    point_push_tokens_ensure_schema();

    if ($action === 'delete') {
        db()->prepare('DELETE FROM point_push_tokens WHERE point_id = ? AND token = ?')
            ->execute([(int) $point['id'], $token]);
        json_out(['ok' => true, 'message' => 'Scan notifications disabled on this device.']);
    }
    if ($action !== 'save') {
        fail_error('unknown_action', 'Unknown scan notification action.', 400);
    }

    $platform = substr(trim((string) ($data['platform'] ?? 'web')), 0, 20);
    $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    db()->prepare(
        'INSERT INTO point_push_tokens (point_id, token, platform, user_agent)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE point_id = VALUES(point_id), platform = VALUES(platform),
           user_agent = VALUES(user_agent), last_seen_at = NOW()'
    )->execute([(int) $point['id'], $token, $platform ?: 'web', $ua]);

    // Confirm the device is subscribed. Delivery failures must not block saving.
    firebase_send_push(
        [$token],
        'Scanner notifications enabled',
        (string) ($point['name'] ?? 'This point') . ' will now receive MziGO scan alerts.',
        '/scan'
    );

    audit('point.push_token_saved', 'partner_point', (string) $point['id'], ['platform' => $platform]);
    json_out(['ok' => true, 'message' => 'Scan notifications enabled on this device.']);
} catch (Throwable $e) {
    error_log('Point push token save failed for point ' . (int) $point['id'] . ': ' . $e->getMessage());
    fail_error('push_unavailable', 'Scan notifications could not be enabled. Please try again.', 503);
}
