<?php
/**
 * Meta WhatsApp Cloud API webhook.
 * GET  — verification challenge.
 * POST — signed inbound messages/statuses; enqueue and return 200 fast.
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_whatsapp.php';

$config = wa_config();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $mode = (string) ($_GET['hub_mode'] ?? '');
    $token = (string) ($_GET['hub_verify_token'] ?? '');
    $challenge = (string) ($_GET['hub_challenge'] ?? '');
    if ($mode === 'subscribe' && $token !== '' && hash_equals((string) $config['verify_token'], $token)) {
        header('Content-Type: text/plain');
        echo $challenge;
        exit;
    }
    http_response_code(403);
    echo 'Verification failed';
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

$raw = file_get_contents('php://input') ?: '';
if ((string) $config['app_secret'] === '') {
    if ($config['mode'] === 'live') {
        http_response_code(403);
        echo 'Webhook app secret is not configured';
        exit;
    }
} else {
    $signature = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
    if (!wa_signature_valid($raw, $signature, (string) $config['app_secret'])) {
        http_response_code(403);
        echo 'Invalid signature';
        exit;
    }
}

$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo 'Invalid payload';
    exit;
}

try {
    $count = wa_handle_webhook($body);
    error_log('WhatsApp webhook accepted ' . $count . ' message(s)');
} catch (Throwable $e) {
    error_log('WhatsApp webhook error: ' . $e->getMessage());
}
http_response_code(200);
json_out(['ok' => true]);
