<?php
declare(strict_types=1);
require __DIR__ . '/api/_bootstrap.php';
require __DIR__ . '/api/_newsletter.php';

$token = trim((string) ($_GET['token'] ?? ''));
$row = newsletter_confirm($token);
http_response_code($row ? 200 : 400);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
if ($row) {
    $cfg = newsletter_config();
    $lead = trim((string) ($cfg['lead_magnet_url'] ?? ''));
    $base = rtrim((string) (config()['app']['base_url'] ?? ''), '/');
    $leadUrl = $lead !== '' ? (preg_match('~^https?://~i', $lead) ? $lead : $base . '/' . ltrim($lead, '/')) : '';
    echo newsletter_html_page('Subscription confirmed', 'You are confirmed. We will send only the updates you asked for. You can unsubscribe at any time from any newsletter.', true,
        $leadUrl, (string) ($cfg['lead_magnet_label'] ?? 'Download your guide'));
} else {
    echo newsletter_html_page('Confirmation link invalid or expired', 'This confirmation link is invalid or has expired. You can request a new one from the Mzigo.online website.', false);
}
