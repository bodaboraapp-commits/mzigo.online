<?php
/**
 * Public newsletter & product-alert prompt API.
 *
 * GET  /api/newsletter.php?action=config
 * POST /api/newsletter.php  { action: subscribe, channel, email/phone, interests, consent, ... }
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_newsletter.php';
require __DIR__ . '/_notifications.php';

$action = strtolower(trim((string) ($_GET['action'] ?? $_POST['action'] ?? '')));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    if ($action === 'config') {
        json_out(['ok' => true, 'config' => newsletter_public_config()]);
    }
    fail_error('unknown_action', 'Unknown newsletter action.', 400);
}

require_post();
verify_csrf();
$data = body_json();
$action = strtolower(trim((string) ($data['action'] ?? '')));
if ($action !== 'subscribe') {
    fail_error('unknown_action', 'Unknown newsletter action.', 400);
}

rate_limit('newsletter_subscribe:' . client_ip(), 8, 600);
if (!empty($data['website'])) {
    // Honeypot: pretend success without creating a subscriber.
    json_out(['ok' => true, 'pending' => true, 'message' => 'Check your inbox to confirm your subscription.'], 201);
}
$result = newsletter_subscribe($data);
json_out($result, 201);
