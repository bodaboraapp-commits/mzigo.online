<?php
declare(strict_types=1);
require __DIR__ . '/api/_bootstrap.php';
require __DIR__ . '/api/_newsletter.php';

$id = (int) ($_GET['id'] ?? 0);
$token = trim((string) ($_GET['token'] ?? ''));
$ok = newsletter_unsubscribe($id, $token);
http_response_code($ok ? 200 : 400);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
echo $ok
    ? newsletter_html_page('You are unsubscribed', 'You will no longer receive newsletter or product-alert emails. Essential service messages are unaffected.', true)
    : newsletter_html_page('Unsubscribe link invalid', 'This unsubscribe link is invalid or has already been used.', false);
