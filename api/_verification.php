<?php
/** Email verification token issue/validation helpers. */
declare(strict_types=1);
require_once __DIR__ . '/_notifications.php';

function email_verification_issue(int $userId, string $email): array
{
    $email = trim($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['sent' => false, 'demo_link' => null];
    }
    db()->prepare('UPDATE email_verifications SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')->execute([$userId]);
    $token = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO email_verifications (user_id, email, token_hash, expires_at)
        VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))')
        ->execute([$userId, $email, hash('sha256', $token)]);
    $base = rtrim((string) (config()['app']['base_url'] ?? 'http://localhost:8090'), '/');
    $link = $base . '/verify-email?token=' . rawurlencode($token);
    $stmt = db()->prepare('SELECT id, name, email, phone, role, status, comm_channel FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch() ?: ['id' => $userId, 'name' => 'there', 'email' => $email, 'phone' => '', 'comm_channel' => null];
    $body = implode("\n", [
        'Hi ' . ($user['name'] ?? 'there') . ',',
        'Confirm the email address on your Mzigo.online account by opening this link:',
        $link,
        'The link expires in 24 hours.',
        'If you did not create this account, you can ignore this email.',
    ]);
    notification_send('email', $user, 'Verify your Mzigo.online email', $body, 'email_verification',
        ['id' => 0, 'tracking' => ''], 'log', 'user', (string) $userId, ['link' => $link]);
    $attempt = db()->prepare("SELECT status, error FROM notifications WHERE template = 'email_verification' AND related_id = ? ORDER BY id DESC LIMIT 1");
    $attempt->execute([(string) $userId]);
    $row = $attempt->fetch() ?: ['status' => 'sent', 'error' => null];
    return [
        'sent' => $row['status'] === 'sent',
        'status' => $row['status'],
        'error' => $row['error'],
        'demo_link' => (config()['app']['demo_mode'] ?? false) ? $link : null,
    ];
}
