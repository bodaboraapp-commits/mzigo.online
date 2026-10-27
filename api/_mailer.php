<?php
/**
 * Mzigo.online — email delivery adapters (SMTP, SendGrid, Mailgun, demo log).
 *
 * Uses the admin-configured email channel (Communication channels → Email).
 * Supported providers: smtp, sendgrid, mailgun, log. Falls back to
 * config['mail'] SMTP settings and to a signed log entry when no provider is
 * configured (demo/log mode).
 */
declare(strict_types=1);
require_once __DIR__ . '/_crypto.php';
require_once __DIR__ . '/_email_deliverability.php';

function mzigo_mail_settings(): array
{
    $defaults = config()['mail'] ?? [];
    $channel = null;
    try {
        $channel = db()->query("SELECT provider, sender, config FROM communication_channels WHERE code = 'email' LIMIT 1")->fetch();
    } catch (Throwable $e) {
        error_log('mail channel lookup failed: ' . $e->getMessage());
    }
    $cfg = [];
    if ($channel && !empty($channel['config'])) {
        $cfg = channel_config_decode((string) $channel['config']);
    }
    $smtp = $defaults['smtp'] ?? [];
    $provider = (string) ($channel['provider'] ?? ($defaults['driver'] ?? 'log'));
    $host = trim((string) ($cfg['host'] ?? $smtp['host'] ?? ''));
    $port = (int) ($cfg['port'] ?? $smtp['port'] ?? 587);
    $user = trim((string) ($cfg['username'] ?? $cfg['user'] ?? $smtp['user'] ?? ''));
    $pass = (string) ($cfg['password'] ?? $cfg['pass'] ?? $smtp['pass'] ?? '');
    $encryption = strtolower(trim((string) ($cfg['encryption'] ?? ($smtp['encryption'] ?? ''))));
    if ($encryption === 'none') { $encryption = ''; }
    if ($encryption === '' && $port === 465) { $encryption = 'ssl'; }
    if ($encryption === '' && $port === 587) { $encryption = 'tls'; }
    $from = trim((string) ($channel['sender'] ?? $defaults['from'] ?? 'no-reply@mzigo.online'));
    return [
        'provider' => $provider, 'host' => $host, 'port' => $port, 'user' => $user,
        'pass' => $pass, 'encryption' => $encryption, 'from' => $from, 'config' => $cfg,
    ];
}

function mzigo_mail_log(array $entry): void
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . '_server' . DIRECTORY_SEPARATOR . 'logs';
    if (is_dir($dir) && is_writable($dir)) {
        @file_put_contents($dir . DIRECTORY_SEPARATOR . 'mail.log', json_encode($entry) . "\n", FILE_APPEND);
    }
}

function mzigo_mail_http_json(string $url, array $headers, array $payload): array
{
    if (!function_exists('curl_init')) { return ['ok' => false, 'code' => 0, 'body' => 'cURL unavailable']; }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12,
        CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    return ['ok' => $code >= 200 && $code < 300, 'code' => $code, 'body' => $body, 'error' => $error];
}

function mzigo_sendgrid_send(string $to, string $subject, string $body, ?string $html, array $s): array
{
    $cfg = $s['config'] ?? [];
    $key = trim((string) ($cfg['api_key'] ?? ''));
    if ($key === '') { return ['sent' => false, 'provider' => 'sendgrid', 'error' => 'SendGrid API key is missing.', 'skipped' => false]; }
    $content = [['type' => 'text/plain', 'value' => $body]];
    if ($html !== null && trim($html) !== '') { $content[] = ['type' => 'text/html', 'value' => $html]; }
    $payload = [
        'personalizations' => [['to' => [['email' => $to]]]],
        'from' => ['email' => $s['from'], 'name' => 'Mzigo.online'],
        'subject' => $subject,
        'content' => $content,
    ];
    $res = mzigo_mail_http_json('https://api.sendgrid.com/v3/mail/send', [
        'Authorization: Bearer ' . $key, 'Content-Type: application/json',
    ], $payload);
    $ok = $res['ok'] && $res['code'] >= 200 && $res['code'] < 300;
    if ($ok) {
        mzigo_mail_log(['at' => date('c'), 'provider' => 'sendgrid', 'to' => $to, 'subject' => $subject, 'status' => 'sent']);
        return ['sent' => true, 'provider' => 'sendgrid', 'error' => null];
    }
    $msg = $res['error'] ?: ($res['body'] !== '' ? $res['body'] : 'SendGrid rejected the message.');
    mzigo_mail_log(['at' => date('c'), 'provider' => 'sendgrid', 'to' => $to, 'subject' => $subject, 'status' => 'failed', 'error' => $msg]);
    return ['sent' => false, 'provider' => 'sendgrid', 'error' => mb_substr($msg, 0, 240), 'skipped' => false];
}

function mzigo_mailgun_send(string $to, string $subject, string $body, ?string $html, array $s): array
{
    $cfg = $s['config'] ?? [];
    $key = trim((string) ($cfg['api_key'] ?? ''));
    $domain = trim((string) ($cfg['domain'] ?? ''));
    if ($key === '' || $domain === '') { return ['sent' => false, 'provider' => 'mailgun', 'error' => 'Mailgun API key/domain is missing.', 'skipped' => false]; }
    $region = strtolower(trim((string) ($cfg['region'] ?? 'us')));
    $base = $region === 'eu' ? 'https://api.eu.mailgun.net' : 'https://api.mailgun.net';
    $url = $base . '/v3/' . rawurlencode($domain) . '/messages';
    $fields = ['from' => 'Mzigo.online <' . $s['from'] . '>', 'to' => $to, 'subject' => $subject, 'text' => $body];
    if ($html !== null && trim($html) !== '') { $fields['html'] = $html; }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12,
        CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_POSTFIELDS => $fields,
        CURLOPT_USERPWD => 'api:' . $key,
    ]);
    $resBody = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($code >= 200 && $code < 300) {
        mzigo_mail_log(['at' => date('c'), 'provider' => 'mailgun', 'to' => $to, 'subject' => $subject, 'status' => 'sent']);
        return ['sent' => true, 'provider' => 'mailgun', 'error' => null];
    }
    $msg = $error ?: ($resBody !== '' ? $resBody : 'Mailgun rejected the message.');
    mzigo_mail_log(['at' => date('c'), 'provider' => 'mailgun', 'to' => $to, 'subject' => $subject, 'status' => 'failed', 'error' => $msg]);
    return ['sent' => false, 'provider' => 'mailgun', 'error' => mb_substr($msg, 0, 240), 'skipped' => false];
}

function mzigo_smtp_read($fp): string
{
    $out = '';
    while (($line = fgets($fp, 515)) !== false) {
        $out .= $line;
        if (preg_match('/^\d{3} /', $line)) { break; }
        if (feof($fp)) { break; }
    }
    return $out;
}

function mzigo_smtp_cmd($fp, string $command, array $expect = ['2', '3']): string
{
    fwrite($fp, $command . "\r\n");
    $response = mzigo_smtp_read($fp);
    if ($response === '') {
        throw new RuntimeException('SMTP connection closed unexpectedly.');
    }
    $code = substr(trim(strtok($response, "\r\n") ?: ''), 0, 1);
    if (!in_array($code, $expect, true)) {
        throw new RuntimeException('SMTP error: ' . trim($response));
    }
    return $response;
}

/** @return array{sent:bool,provider:string,error:?string,skipped?:bool} */
function mzigo_mail_send(string $to, string $subject, string $body, ?string $html = null): array
{
    $to = trim($to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['sent' => false, 'provider' => 'log', 'error' => 'Invalid recipient email.', 'skipped' => true];
    }
    $suppression = email_suppression_get($to);
    if ($suppression) {
        mzigo_mail_log(['at' => date('c'), 'provider' => 'suppressed', 'to' => $to, 'subject' => $subject, 'status' => 'skipped', 'error' => 'Address suppressed after bounce/complaint']);
        return ['sent' => false, 'provider' => 'suppressed',
            'error' => 'Address is suppressed after a bounce or complaint.', 'skipped' => true];
    }
    $s = mzigo_mail_settings();
    if ($s['provider'] === 'sendgrid') {
        return mzigo_sendgrid_send($to, $subject, $body, $html, $s);
    }
    if ($s['provider'] === 'mailgun') {
        return mzigo_mailgun_send($to, $subject, $body, $html, $s);
    }
    if ($s['provider'] === 'mail') {
        $from = trim((string) ($s['from'] ?? ''));
        if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return ['sent' => false, 'provider' => 'mail', 'error' => 'Email sender address is invalid.', 'skipped' => false];
        }
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $headers = [
            'From: Mzigo.online <' . $from . '>',
            'Reply-To: ' . $from,
            'MIME-Version: 1.0',
        ];
        if ($html !== null && trim($html) !== '') {
            $boundary = 'mzigo-' . bin2hex(random_bytes(10));
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
            $message = '--' . $boundary . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $body .
                "\r\n--" . $boundary . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $html .
                "\r\n--" . $boundary . '--';
        } else {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: 8bit';
            $message = $body;
        }
        $ok = @mail($to, $encodedSubject, $message, implode("\r\n", $headers), '-f' . $from);
        mzigo_mail_log(['at' => date('c'), 'provider' => 'mail', 'to' => $to, 'subject' => $subject, 'status' => $ok ? 'sent' : 'failed']);
        return $ok
            ? ['sent' => true, 'provider' => 'mail', 'error' => null]
            : ['sent' => false, 'provider' => 'mail', 'error' => 'PHP mail() transport rejected the message.', 'skipped' => false];
    }
    if ($s['provider'] === 'log' || ($s['provider'] === 'smtp' && $s['host'] === '')) {
        mzigo_mail_log(['at' => date('c'), 'provider' => 'log', 'to' => $to, 'subject' => $subject, 'body' => $body, 'html' => $html]);
        return ['sent' => true, 'provider' => 'log', 'error' => null];
    }
    if ($s['provider'] !== 'smtp') {
        mzigo_mail_log(['at' => date('c'), 'provider' => $s['provider'], 'to' => $to, 'subject' => $subject, 'status' => 'failed', 'error' => 'Unsupported email provider.']);
        return ['sent' => false, 'provider' => $s['provider'], 'error' => 'Unsupported email provider.', 'skipped' => false];
    }
    $fp = null;
    try {
        $transport = $s['encryption'] === 'ssl' ? 'ssl://' : 'tcp://';
        $fp = @stream_socket_client($transport . $s['host'] . ':' . $s['port'], $errno, $errstr, 15, STREAM_CLIENT_CONNECT);
        if (!$fp) {
            throw new RuntimeException('SMTP connection failed: ' . $errstr);
        }
        stream_set_timeout($fp, 15);
        $greeting = mzigo_smtp_read($fp);
        if (!preg_match('/^220/', $greeting)) {
            throw new RuntimeException('SMTP refused connection.');
        }
        $hostname = gethostname() ?: 'localhost';
        mzigo_smtp_cmd($fp, 'EHLO ' . $hostname);
        if ($s['encryption'] === 'tls' || $s['encryption'] === 'starttls') {
            mzigo_smtp_cmd($fp, 'STARTTLS', ['2']);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('SMTP STARTTLS negotiation failed.');
            }
            mzigo_smtp_cmd($fp, 'EHLO ' . $hostname);
        }
        if ($s['user'] !== '') {
            mzigo_smtp_cmd($fp, 'AUTH LOGIN', ['3']);
            mzigo_smtp_cmd($fp, base64_encode($s['user']), ['3']);
            mzigo_smtp_cmd($fp, base64_encode($s['pass']), ['2']);
        }
        mzigo_smtp_cmd($fp, 'MAIL FROM:<' . $s['from'] . '>');
        mzigo_smtp_cmd($fp, 'RCPT TO:<' . $to . '>');
        mzigo_smtp_cmd($fp, 'DATA', ['3']);
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $headers = [
            'From: Mzigo.online <' . $s['from'] . '>',
            'To: <' . $to . '>',
            'Subject: ' . $encodedSubject,
            'Date: ' . date('r'),
            'MIME-Version: 1.0',
            'X-Mailer: Mzigo.online',
        ];
        $normalized = str_replace(["\r\n", "\r"], "\n", $body);
        if ($html !== null && trim($html) !== '') {
            $boundary = 'mzigo-' . bin2hex(random_bytes(10));
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
            $normalizedHtml = str_replace(["\r\n", "\r"], "\n", $html);
            $parts = '--' . $boundary . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" .
                implode("\r\n", explode("\n", $normalized)) .
                "\r\n--" . $boundary . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" .
                implode("\r\n", explode("\n", $normalizedHtml)) .
                "\r\n--" . $boundary . '--';
        } else {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: 8bit';
            $parts = implode("\r\n", explode("\n", $normalized));
        }
        $lines = explode("\r\n", $parts);
        foreach ($lines as &$line) {
            if (str_starts_with($line, '.')) { $line = '.' . $line; }
        }
        unset($line);
        $message = implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $lines) . "\r\n.";
        fwrite($fp, $message . "\r\n");
        $response = mzigo_smtp_read($fp);
        if (!preg_match('/^250/', $response)) {
            throw new RuntimeException('SMTP send rejected: ' . trim($response));
        }
        @fwrite($fp, "QUIT\r\n");
        @fclose($fp);
        mzigo_mail_log(['at' => date('c'), 'provider' => 'smtp', 'to' => $to, 'subject' => $subject, 'status' => 'sent']);
        return ['sent' => true, 'provider' => 'smtp', 'error' => null];
    } catch (Throwable $e) {
        if (is_resource($fp)) { @fclose($fp); }
        mzigo_mail_log(['at' => date('c'), 'provider' => 'smtp', 'to' => $to, 'subject' => $subject, 'status' => 'failed', 'error' => $e->getMessage()]);
        error_log('SMTP send failed: ' . $e->getMessage());
        return ['sent' => false, 'provider' => 'smtp', 'error' => mb_substr($e->getMessage(), 0, 240), 'skipped' => false];
    }
}
