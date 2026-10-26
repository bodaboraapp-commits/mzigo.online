<?php
/** Email deliverability: bounce/complaint events and suppression list. */
declare(strict_types=1);
require_once __DIR__ . '/_crypto.php';

function email_suppression_get(string $email): ?array
{
    $email = strtolower(trim($email));
    if ($email === '') { return null; }
    try {
        $stmt = db()->prepare('SELECT email, reason, event_type, created_at FROM email_suppressions WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function email_suppress(string $email, string $reason, string $eventType): void
{
    $email = strtolower(trim($email));
    if ($email === '') { return; }
    db()->prepare('INSERT INTO email_suppressions (email, reason, event_type) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE reason = VALUES(reason), event_type = VALUES(event_type), updated_at = NOW()')
        ->execute([$email, mb_substr($reason, 0, 255), mb_substr($eventType, 0, 40)]);
}

function email_unsuppress(string $email): bool
{
    $stmt = db()->prepare('DELETE FROM email_suppressions WHERE email = ?');
    $stmt->execute([strtolower(trim($email))]);
    return $stmt->rowCount() > 0;
}

function email_event_log(string $provider, string $eventType, string $email, string $reason, string $payload = ''): int
{
    db()->prepare('INSERT INTO email_events (provider, event_type, email, reason, payload, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())')
        ->execute([mb_substr($provider, 0, 40), mb_substr($eventType, 0, 40), mb_substr($email, 0, 160),
            mb_substr($reason, 0, 255), $payload !== '' ? $payload : null]);
    return (int) db()->lastInsertId();
}

function email_deliverability_data(): array
{
    try {
        $suppressions = db()->query('SELECT email, reason, event_type, created_at, updated_at FROM email_suppressions ORDER BY updated_at DESC LIMIT 100')->fetchAll();
        $events = db()->query('SELECT provider, event_type, email, reason, created_at FROM email_events ORDER BY id DESC LIMIT 30')->fetchAll();
        return ['suppressions' => $suppressions, 'events' => $events, 'suppression_count' => count($suppressions)];
    } catch (Throwable $e) {
        return ['suppressions' => [], 'events' => [], 'suppression_count' => 0];
    }
}


function email_channel_config(): array
{
    try {
        $row = db()->query("SELECT provider, config FROM communication_channels WHERE code = 'email' LIMIT 1")->fetch();
        return $row ? channel_config_decode((string) ($row['config'] ?? '')) : [];
    } catch (Throwable $e) {
        return [];
    }
}

function email_event_apply(string $provider, string $eventType, string $email, string $reason, string $raw, bool $suppress): void
{
    $email = strtolower(trim($email));
    if ($email === '') { return; }
    if ($suppress) {
        email_suppress($email, $reason !== '' ? $reason : 'Bounce/complaint from email provider', $eventType);
    }
    try {
        email_event_log($provider, $eventType, $email, $reason, $raw);
    } catch (Throwable $e) {
        error_log('email event log failed: ' . $e->getMessage());
    }
}

function email_events_mailgun_verify(string $raw): ?array
{
    parse_str($raw, $form);
    $config = email_channel_config();
    $key = trim((string) ($config['webhook_signing_key'] ?? ''));
    if ($key === '') { return null; }
    $signature = trim((string) ($form['signature'] ?? ''));
    $timestamp = trim((string) ($form['timestamp'] ?? ''));
    $token = trim((string) ($form['token'] ?? ''));
    if ($signature === '' || $timestamp === '' || $token === '') { return null; }
    if (abs(time() - (int) $timestamp) > 900) { return null; }
    $expected = hash_hmac('sha256', $timestamp . $token, $key);
    if (!hash_equals($expected, $signature)) { return null; }
    return $form;
}

function email_events_sendgrid_verify(string $raw): bool
{
    $config = email_channel_config();
    $publicKey = trim((string) ($config['event_webhook_public_key'] ?? ''));
    if ($publicKey === '' || !function_exists('openssl_verify')) { return false; }
    $signature = (string) ($_SERVER['HTTP_X_TWILIO_EMAIL_EVENT_WEBHOOK_SIGNATURE'] ?? '');
    $timestamp = (string) ($_SERVER['HTTP_X_TWILIO_EMAIL_EVENT_WEBHOOK_TIMESTAMP'] ?? '');
    if ($signature === '' || $timestamp === '') { return false; }
    if (abs(time() - (int) $timestamp) > 900) { return false; }
    $decoded = base64_decode($signature, true);
    if ($decoded === false) { return false; }
    return openssl_verify($timestamp . $raw, $decoded, $publicKey, OPENSSL_ALGO_SHA256) === 1;
}

function email_event_suppress_type(string $event, string $bounceType = ''): bool
{
    $event = strtolower(trim($event));
    $bounceType = strtolower(trim($bounceType));
    return in_array($event, ['complaint', 'spam', 'spamreport', 'abuse', 'unsubscribe', 'unsubscribed', 'hard_bounce', 'hard-bounce', 'dropped'], true)
        || (in_array($event, ['bounce', 'permanent_fail', 'permanent-fail'], true) && $bounceType !== 'soft');
}

function email_event_secret(): string
{
    return (string) (config()['app']['config_key'] ?? '');
}
