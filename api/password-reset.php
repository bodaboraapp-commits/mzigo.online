<?php
/**
 * Mzigo.online — password reset for admin/merchant accounts.
 *
 * GET  ?token=…   validate a reset token
 * POST request    { email }        generic response + email link (demo link in demo mode)
 * POST reset      { token, password, password_confirm }
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_notifications.php';

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$demoMode = config()['app']['demo_mode'] === true;

function reset_eligible_user(string $email): ?array
{
    $stmt = db()->prepare(
        'SELECT id, name, email, phone, role, status, comm_channel
         FROM users WHERE LOWER(email) = ? AND role IN ("platform_admin", "user") AND status = "active" LIMIT 1'
    );
    $stmt->execute([mb_strtolower(trim($email))]);
    $user = $stmt->fetch();
    return $user ?: null;
}

if ($method === 'GET') {
    $token = isset($_GET['token']) ? trim((string) $_GET['token']) : '';
    $valid = false;
    if ($token !== '') {
        $stmt = db()->prepare('SELECT expires_at, used_at FROM password_resets WHERE token_hash = ? LIMIT 1');
        $stmt->execute([hash('sha256', $token)]);
        $row = $stmt->fetch();
        $valid = $row && empty($row['used_at']) && strtotime((string) $row['expires_at']) > time();
    }
    json_out(['ok' => true, 'source' => 'mysql', 'valid' => $valid]);
}

require_post();
verify_csrf();
$data = body_json();
$action = (string) ($data['action'] ?? '');

if ($action === 'request') {
    $email = param($data, 'email', 3, 160, 'Email');
    rate_limit('password_reset_request:' . hash('sha256', mb_strtolower(trim($email))) . ':' . client_ip(), 5, 3600);
    $user = reset_eligible_user($email);
    $link = null;
    if ($user) {
        db()->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')->execute([(int) $user['id']]);
        $token = bin2hex(random_bytes(32));
        db()->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at, requested_ip)
            VALUES (?, ?, ?, ?)')
            ->execute([(int) $user['id'], hash('sha256', $token), date('Y-m-d H:i:s', time() + 3600), client_ip()]);
        $base = rtrim((string) (config()['app']['base_url'] ?? 'http://localhost:8090'), '/');
        $link = $base . '/reset-password?token=' . rawurlencode($token);
        $body = implode("\n", [
            'Hi ' . ($user['name'] ?? 'there') . ',',
            'Use the link below to reset your Mzigo.online password. It expires in 60 minutes and can be used once.',
            $link,
            'If you did not request this, you can ignore this email.',
        ]);
        notification_send(
            'email', $user, 'Reset your Mzigo.online password', $body, 'password_reset',
            ['id' => 0, 'tracking' => ''], 'log', 'user', (string) $user['id'], ['link' => $link]
        );
        audit('auth.password_reset_requested', 'user', (string) $user['id'], ['email' => $user['email']]);
    }
    json_out([
        'ok' => true, 'source' => 'mysql',
        'message' => 'If an eligible admin or merchant account exists, a reset link has been emailed.',
        'demo_link' => ($user && $demoMode) ? $link : null,
    ]);
}

if ($action === 'reset') {
    $token = trim((string) ($data['token'] ?? ''));
    if (strlen($token) < 16 || strlen($token) > 128) {
        fail_error('invalid_token', 'This reset link is invalid or has expired.', 422);
    }
    $password = param($data, 'password', 8, 72, 'Password');
    $confirm = (string) ($data['password_confirm'] ?? '');
    if ($password !== $confirm) {
        fail_error('password_mismatch', 'The passwords do not match.', 422);
    }
    rate_limit('password_reset_attempt:' . client_ip(), 20, 3600);
    $stmt = db()->prepare(
        'SELECT r.id, r.user_id, r.expires_at, r.used_at, u.email, u.name, u.role, u.status
         FROM password_resets r JOIN users u ON u.id = r.user_id WHERE r.token_hash = ? LIMIT 1'
    );
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch();
    if (!$row || !empty($row['used_at']) || strtotime((string) $row['expires_at']) < time()) {
        fail_error('invalid_token', 'This reset link is invalid or has expired.', 422);
    }
    if (!in_array($row['role'], ['platform_admin', 'user'], true) || $row['status'] !== 'active') {
        fail_error('invalid_token', 'This reset link is invalid or has expired.', 422);
    }
    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), (int) $row['user_id']]);
    db()->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?')->execute([(int) $row['id']]);
    db()->prepare('UPDATE sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL')->execute([(int) $row['user_id']]);
    audit('auth.password_reset_completed', 'user', (string) $row['user_id'], []);
    notification_send('email', [
        'id' => (int) $row['user_id'], 'name' => $row['name'] ?? 'there', 'email' => $row['email'],
        'phone' => '', 'comm_channel' => null,
    ], 'Your Mzigo.online password was changed',
        "Hi " . ($row['name'] ?? 'there') . ",\nYour Mzigo.online password was changed. If this was not you, reset it immediately.",
        'password_changed', ['id' => 0, 'tracking' => ''], 'log', 'user', (string) $row['user_id'], []);
    json_out(['ok' => true, 'source' => 'mysql', 'message' => 'Password updated. Sign in with your new password.']);
}

fail_error('unknown_action', 'Unknown password reset action.', 400);
