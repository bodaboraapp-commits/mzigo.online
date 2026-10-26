<?php
/** GET/POST /api/email-verification.php — verify or resend email verification. */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_verification.php';

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET') {
    $token = isset($_GET['token']) ? trim((string) $_GET['token']) : '';
    $valid = false;
    if ($token !== '') {
        $stmt = db()->prepare('SELECT expires_at, used_at FROM email_verifications WHERE token_hash = ? LIMIT 1');
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

if ($action === 'verify') {
    $token = trim((string) ($data['token'] ?? ''));
    if (strlen($token) < 16) {
        fail_error('invalid_token', 'This verification link is invalid or has expired.', 422);
    }
    $stmt = db()->prepare('SELECT * FROM email_verifications WHERE token_hash = ? LIMIT 1');
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch();
    if (!$row || !empty($row['used_at']) || strtotime((string) $row['expires_at']) < time()) {
        fail_error('invalid_token', 'This verification link is invalid or has expired.', 422);
    }
    db()->prepare('UPDATE users SET email_verified_at = NOW() WHERE id = ?')->execute([(int) $row['user_id']]);
    db()->prepare('UPDATE email_verifications SET used_at = NOW() WHERE id = ?')->execute([(int) $row['id']]);
    audit('auth.email_verified', 'user', (string) $row['user_id'], ['token_id' => (int) $row['id']]);
    $u = db()->prepare('SELECT name, email FROM users WHERE id = ? LIMIT 1');
    $u->execute([(int) $row['user_id']]);
    $verifiedUser = $u->fetch() ?: ['name' => 'there', 'email' => $row['email']];
    notification_send('email', [
        'id' => (int) $row['user_id'], 'name' => $verifiedUser['name'], 'email' => $verifiedUser['email'],
        'phone' => '', 'comm_channel' => null,
    ], 'Your Mzigo.online email is verified',
        "Hi " . $verifiedUser['name'] . ",\nYour email address is now verified.",
        'email_verified', ['id' => 0, 'tracking' => ''], 'log', 'user', (string) $row['user_id'], []);
    json_out(['ok' => true, 'source' => 'mysql', 'message' => 'Email verified. Your account is confirmed.']);
}

if ($action === 'resend') {
    $user = require_user();
    if (!empty($user['email_verified_at'])) {
        fail_error('already_verified', 'This email address is already verified.', 409);
    }
    if (empty($user['email'])) {
        fail_error('no_email', 'This account has no email address.', 422);
    }
    rate_limit('email_verify_resend:' . $user['id'], 3, 3600);
    $result = email_verification_issue((int) $user['id'], (string) $user['email']);
    audit('auth.email_verification_resent', 'user', (string) $user['id'], []);
    if (!$result['sent'] && empty($result['demo_link'])) {
        fail_error('email_send_failed', 'Could not send the verification email. Try again later.', 500);
    }
    json_out(['ok' => true, 'source' => 'mysql', 'message' => 'Verification email sent.',
        'demo_link' => $result['demo_link'] ?? null]);
}

fail_error('unknown_action', 'Unknown verification action.', 400);
