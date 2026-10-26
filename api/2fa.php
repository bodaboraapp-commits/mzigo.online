<?php
/** GET/POST /api/2fa.php — TOTP two-factor setup and management. */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_totp.php';
require __DIR__ . '/_recovery.php';

$user = require_user();
if (!in_array($user['role'], ['platform_admin', 'user'], true)) {
    fail_error('forbidden', 'Two-factor authentication is available for admin and business accounts.', 403);
}
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

function twofa_status(int $userId): array
{
    $stmt = db()->prepare('SELECT secret, enabled_at FROM user_2fa WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return [
        'enabled' => $row && !empty($row['enabled_at']),
        'pending' => $row && empty($row['enabled_at']),
        'enabled_at' => $row['enabled_at'] ?? null,
        'recovery_remaining' => recovery_codes_remaining($userId),
    ];
}

if ($method === 'GET') {
    json_out(['ok' => true, 'source' => 'mysql', 'twofa' => twofa_status((int) $user['id'])]);
}

require_post();
verify_csrf();
$data = body_json();
$action = (string) ($data['action'] ?? '');

if ($action === 'setup') {
    rate_limit('2fa_setup:' . $user['id'], 5, 3600);
    $secret = totp_base32_encode(random_bytes(20));
    db()->prepare('INSERT INTO user_2fa (user_id, secret, enabled_at) VALUES (?, ?, NULL)
        ON DUPLICATE KEY UPDATE secret = VALUES(secret), enabled_at = NULL')
        ->execute([(int) $user['id'], $secret]);
    audit('auth.2fa_setup_started', 'user', (string) $user['id'], []);
    $issuer = 'Mzigo.online';
    $label = rawurlencode($issuer . ':' . ($user['email'] ?? $user['phone'] ?? ('user-' . $user['id'])));
    json_out(['ok' => true, 'source' => 'mysql', 'secret' => $secret,
        'otpauth' => 'otpauth://totp/' . $label . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&digits=6&period=30',
        'message' => 'Add the secret to your authenticator app, then confirm with a 6-digit code.']);
}

if ($action === 'enable') {
    $code = trim((string) ($data['code'] ?? ''));
    $stmt = db()->prepare('SELECT secret FROM user_2fa WHERE user_id = ? AND enabled_at IS NULL LIMIT 1');
    $stmt->execute([(int) $user['id']]);
    $secret = $stmt->fetchColumn();
    if (!$secret) {
        fail_error('not_setup', 'Start 2FA setup first.', 422);
    }
    if (!totp_verify((string) $secret, $code)) {
        fail_error('invalid_code', 'That authenticator code is not valid.', 422);
    }
    db()->prepare('UPDATE user_2fa SET enabled_at = NOW() WHERE user_id = ?')->execute([(int) $user['id']]);
    $recoveryCodes = recovery_codes_generate((int) $user['id']);
    audit('auth.2fa_enabled', 'user', (string) $user['id'], ['recovery_codes' => count($recoveryCodes)]);
    json_out(['ok' => true, 'source' => 'mysql', 'twofa' => twofa_status((int) $user['id']),
        'recovery_codes' => $recoveryCodes,
        'message' => 'Two-factor authentication enabled. Save your recovery codes now.']);
}

if ($action === 'disable') {
    $password = (string) ($data['password'] ?? '');
    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(int) $user['id']]);
    $hash = (string) $stmt->fetchColumn();
    if ($hash === '' || !password_verify($password, $hash)) {
        fail_error('invalid_password', 'Enter your current password to disable 2FA.', 422);
    }
    db()->prepare('DELETE FROM user_2fa WHERE user_id = ?')->execute([(int) $user['id']]);
    db()->prepare('DELETE FROM user_2fa_recovery_codes WHERE user_id = ?')->execute([(int) $user['id']]);
    audit('auth.2fa_disabled', 'user', (string) $user['id'], []);
    json_out(['ok' => true, 'source' => 'mysql', 'twofa' => twofa_status((int) $user['id']), 'message' => 'Two-factor authentication disabled.']);
}

if ($action === 'regenerate_recovery_codes') {
    $password = (string) ($data['password'] ?? '');
    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(int) $user['id']]);
    $hash = (string) $stmt->fetchColumn();
    if ($hash === '' || !password_verify($password, $hash)) {
        fail_error('invalid_password', 'Enter your current password to generate recovery codes.', 422);
    }
    if (!twofa_status((int) $user['id'])['enabled']) {
        fail_error('not_enabled', 'Enable two-factor authentication first.', 422);
    }
    $codes = recovery_codes_generate((int) $user['id']);
    audit('auth.2fa_recovery_regenerated', 'user', (string) $user['id'], ['count' => count($codes)]);
    json_out(['ok' => true, 'source' => 'mysql', 'recovery_codes' => $codes,
        'twofa' => twofa_status((int) $user['id']), 'message' => 'New recovery codes generated. Save them now.']);
}

fail_error('unknown_action', 'Unknown 2FA action.', 400);
