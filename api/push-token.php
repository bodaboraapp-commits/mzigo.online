<?php
/**
 * POST /api/push-token.php — save or remove a Firebase Cloud Messaging token.
 *
 * Body: { action: save|delete, token: string, marketing_opt_in?: bool }
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_push.php';

require_post();
verify_csrf();
$user = require_user();
$data = body_json();
$action = (string) ($data['action'] ?? 'save');
$token = trim((string) ($data['token'] ?? ''));
if ($token === '' || strlen($token) > 512) {
    fail_error('invalid_token', 'Push token is missing or too long.', 422);
}
rate_limit('push-token:' . (int) $user['id'], 30, 300);

try {
    push_tokens_ensure_schema();

    if ($action === 'delete') {
        db()->prepare('DELETE FROM push_tokens WHERE user_id = ? AND token = ?')->execute([(int) $user['id'], $token]);
        json_out(['ok' => true, 'message' => 'Push notifications disabled on this device.']);
    }
    if ($action !== 'save') {
        fail_error('unknown_action', 'Unknown push action.', 400);
    }

    $marketing = !empty($data['marketing_opt_in']) ? 1 : 0;
    $platform = substr(trim((string) ($data['platform'] ?? 'web')), 0, 20);
    $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

    db()->prepare(
        'INSERT INTO push_tokens (user_id, token, platform, user_agent, marketing_opt_in)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), platform = VALUES(platform),
           user_agent = VALUES(user_agent), marketing_opt_in = VALUES(marketing_opt_in),
           last_seen_at = NOW()'
    )->execute([(int) $user['id'], $token, $platform ?: 'web', $ua, $marketing]);

    audit('push.token_saved', 'user', (string) $user['id'], ['platform' => $platform, 'marketing' => $marketing]);
    json_out(['ok' => true, 'message' => 'Push notifications enabled on this device.']);
} catch (Throwable $e) {
    error_log('Push token save failed for user ' . (int) $user['id'] . ': ' . $e->getMessage());
    fail_error('push_unavailable', 'Push notifications could not be enabled. Please try again.', 503);
}
