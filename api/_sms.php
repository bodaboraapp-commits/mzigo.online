<?php
/**
 * Mzigo.online — SMS delivery.
 *
 * Uses the active `sms` communication channel. Infobip is the first-class
 * provider; the existing log provider is a safe demo fallback. Credentials
 * are encrypted at rest by the communications channel config layer.
 */
declare(strict_types=1);
require_once __DIR__ . '/_crypto.php';

function sms_e164(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if ($digits === '') return '';
    if (str_starts_with($digits, '0')) $digits = '254' . substr($digits, 1);
    elseif (strlen($digits) === 9) $digits = '254' . $digits;
    return $digits;
}

function sms_channel(): ?array
{
    try {
        $stmt = db()->query("SELECT code, label, provider, sender, config, is_active FROM communication_channels WHERE code = 'sms' LIMIT 1");
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        return null;
    }
    if (!$row || (int) $row['is_active'] !== 1) return null;
    $config = channel_config_decode((string) ($row['config'] ?? ''));
    $provider = strtolower(trim((string) ($row['provider'] ?? 'log')));
    $sender = trim((string) ($row['sender'] ?? ''));
    if ($sender === '') $sender = trim((string) ($config['sender_id'] ?? ''));
    return [
        'code' => 'sms',
        'provider' => $provider,
        'sender' => $sender,
        'config' => $config,
        'is_active' => true,
    ];
}

function sms_channel_ready(): bool
{
    $channel = sms_channel();
    if (!$channel) return false;
    if ($channel['provider'] === 'infobip') {
        return trim((string) ($channel['config']['base_url'] ?? '')) !== ''
            && trim((string) ($channel['config']['api_key'] ?? '')) !== ''
            && $channel['sender'] !== '';
    }
    if ($channel['provider'] === 'twilio-sms') {
        $hasSender = trim((string) ($channel['config']['from_number'] ?? '')) !== ''
            || trim((string) ($channel['config']['messaging_service_sid'] ?? '')) !== '';
        return trim((string) ($channel['config']['account_sid'] ?? '')) !== ''
            && trim((string) ($channel['config']['auth_token'] ?? '')) !== ''
            && $hasSender;
    }
    return $channel['provider'] === 'log';
}

function sms_log(array $entry): void
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . '_server' . DIRECTORY_SEPARATOR . 'logs';
    if (is_dir($dir) && is_writable($dir)) {
        @file_put_contents($dir . DIRECTORY_SEPARATOR . 'sms.log', json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
    }
}

/**
 * Send a plain SMS through the active SMS channel.
 * Returns ok=false with skipped=true when the channel is disabled/log-only.
 */
function sms_send(string $phone, string $body): array
{
    $channel = sms_channel();
    $to = sms_e164($phone);
    $masked = $to !== '' ? '+' . substr($to, 0, 5) . '…' . substr($to, -2) : '';
    if ($to === '') {
        return ['ok' => false, 'skipped' => true, 'provider' => $channel['provider'] ?? 'log', 'error' => 'No recipient phone number.'];
    }
    if (!$channel) {
        sms_log(['at' => date('c'), 'to' => $masked, 'status' => 'skipped', 'error' => 'No active SMS channel.']);
        return ['ok' => false, 'skipped' => true, 'provider' => 'log', 'error' => 'SMS channel is not active.'];
    }
    if ($channel['provider'] === 'log') {
        sms_log(['at' => date('c'), 'to' => $masked, 'status' => 'skipped', 'provider' => 'log', 'body_preview' => mb_substr($body, 0, 80)]);
        return ['ok' => false, 'skipped' => true, 'provider' => 'log', 'error' => 'SMS provider is set to demo log.'];
    }
    if ($channel['provider'] === 'twilio-sms') {
        $config = $channel['config'];
        $sid = trim((string) ($config['account_sid'] ?? ''));
        $token = trim((string) ($config['auth_token'] ?? ''));
        $from = trim((string) ($config['from_number'] ?? ''));
        $service = trim((string) ($config['messaging_service_sid'] ?? ''));
        if ($sid === '' || $token === '' || ($from === '' && $service === '')) {
            return ['ok' => false, 'provider' => 'twilio-sms', 'error' => 'Twilio Account SID, Auth token and From number/Messaging Service are missing.'];
        }
        $twilioTo = '+' . ltrim($to, '+');
        $fields = ['To' => $twilioTo, 'Body' => mb_substr($body, 0, 1600)];
        if ($service !== '') $fields['MessagingServiceSid'] = $service;
        if ($from !== '') $fields['From'] = '+' . ltrim($from, '+');
        $ch = curl_init('https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($sid) . '/Messages.json');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_USERPWD => $sid . ':' . $token,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        $decoded = json_decode((string) $raw, true);
        if ($raw === false || $code < 200 || $code >= 300) {
            $error = $curlError !== '' ? $curlError : (string) ($decoded['message'] ?? ('HTTP ' . $code));
            sms_log(['at' => date('c'), 'to' => $masked, 'status' => 'failed', 'provider' => 'twilio-sms', 'http' => $code, 'error' => $error]);
            return ['ok' => false, 'provider' => 'twilio-sms', 'error' => $error];
        }
        $status = (string) ($decoded['status'] ?? 'accepted');
        sms_log(['at' => date('c'), 'to' => $masked, 'status' => 'sent', 'provider' => 'twilio-sms', 'message_id' => (string) ($decoded['sid'] ?? ''), 'twilio_status' => $status]);
        return ['ok' => true, 'provider' => 'twilio-sms', 'message_id' => (string) ($decoded['sid'] ?? ''), 'status' => $status];
    }

    if ($channel['provider'] !== 'infobip') {
        return ['ok' => false, 'provider' => $channel['provider'], 'error' => 'SMS provider "' . $channel['provider'] . '" is not implemented.'];
    }

    $config = $channel['config'];
    $base = trim((string) ($config['base_url'] ?? ''));
    if ($base !== '' && !preg_match('#^https?://#i', $base)) {
        $base = 'https://' . $base;
    }
    $base = rtrim($base, '/');
    $key = trim((string) ($config['api_key'] ?? ''));
    $sender = $channel['sender'];
    if ($base === '' || $key === '' || $sender === '') {
        return ['ok' => false, 'provider' => 'infobip', 'error' => 'Infobip base URL, API key or sender ID is missing.'];
    }
    if (!preg_match('#^https://#i', $base)) {
        return ['ok' => false, 'provider' => 'infobip', 'error' => 'Infobip base URL must start with https://.'];
    }

    $payload = [
        'messages' => [[
            'from' => $sender,
            'destinations' => [['to' => $to]],
            'text' => mb_substr($body, 0, 4096),
        ]],
    ];
    $ch = curl_init($base . '/sms/2/text/advanced');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Authorization: App ' . $key,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    $decoded = json_decode((string) $raw, true);
    $message = $decoded['messages'][0] ?? [];
    $messageId = (string) ($message['messageId'] ?? '');
    $statusName = strtoupper((string) ($message['status']['name'] ?? ''));
    $groupName = strtoupper((string) ($message['status']['groupName'] ?? ''));
    if ($raw === false || $code < 200 || $code >= 300 || in_array($groupName, ['REJECTED', 'UNDELIVERABLE', 'EXPIRED'], true)) {
        $error = $curlError !== '' ? $curlError : (string) ($message['status']['description'] ?? $decoded['requestError']['serviceException']['text'] ?? ('HTTP ' . $code));
        sms_log(['at' => date('c'), 'to' => $masked, 'status' => 'failed', 'provider' => 'infobip', 'http' => $code, 'error' => $error]);
        return ['ok' => false, 'provider' => 'infobip', 'error' => $error];
    }
    sms_log(['at' => date('c'), 'to' => $masked, 'status' => 'sent', 'provider' => 'infobip', 'message_id' => $messageId, 'status_group' => $groupName]);
    return ['ok' => true, 'provider' => 'infobip', 'message_id' => $messageId, 'status' => $statusName ?: 'accepted'];
}

function sms_send_code(string $phone, string $code, string $purpose = 'customer'): array
{
    $purpose = $purpose === 'runner' ? 'runner' : 'customer';
    $body = $purpose === 'runner'
        ? 'Mzigo.online: your runner PIN is ' . $code . '. Never share it with anyone.'
        : 'Mzigo.online: your sign-in code is ' . $code . '. It expires in 10 minutes. Never share it.';
    return sms_send($phone, $body);
}
