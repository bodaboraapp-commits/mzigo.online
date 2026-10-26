<?php
/** GET/POST /api/sessions.php — current user's sessions; revoke others. */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$user = require_user();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$currentId = session_id();

if ($method === 'GET') {
    $stmt = db()->prepare(
        'SELECT id, ip, user_agent, created_at, last_seen_at, expires_at
         FROM sessions WHERE user_id = ? AND revoked_at IS NULL AND expires_at > NOW()
         ORDER BY last_seen_at DESC LIMIT 50'
    );
    $stmt->execute([(int) $user['id']]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['current'] = hash_equals((string) $row['id'], (string) $currentId);
    }
    unset($row);
    json_out(['ok' => true, 'source' => 'mysql', 'sessions' => $rows, 'current_session' => $currentId]);
}

require_post();
verify_csrf();
$data = body_json();
$action = (string) ($data['action'] ?? '');
if ($action === 'revoke') {
    $sessionId = param($data, 'session_id', 4, 128, 'Session');
    if (hash_equals((string) $sessionId, (string) $currentId)) {
        fail_error('cannot_revoke_current', 'You cannot revoke the session you are using. Sign out instead.', 422);
    }
    $stmt = db()->prepare('UPDATE sessions SET revoked_at = NOW() WHERE id = ? AND user_id = ? AND revoked_at IS NULL');
    $stmt->execute([$sessionId, (int) $user['id']]);
    if ($stmt->rowCount() === 0) {
        fail_error('not_found', 'Session not found or already revoked.', 404);
    }
    audit('auth.session_revoked', 'session', substr($sessionId, 0, 16), []);
    json_out(['ok' => true, 'source' => 'mysql', 'message' => 'Session revoked.']);
}
if ($action === 'revoke_others') {
    $stmt = db()->prepare('UPDATE sessions SET revoked_at = NOW() WHERE user_id = ? AND id <> ? AND revoked_at IS NULL');
    $stmt->execute([(int) $user['id'], $currentId]);
    audit('auth.other_sessions_revoked', 'user', (string) $user['id'], ['count' => $stmt->rowCount()]);
    json_out(['ok' => true, 'source' => 'mysql', 'message' => 'Other sessions revoked.', 'revoked' => $stmt->rowCount()]);
}
fail_error('unknown_action', 'Unknown session action.', 400);
