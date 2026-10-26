<?php
/**
 * POST /api/email-events.php — provider bounce/complaint webhooks.
 *
 * Supported providers:
 *   - generic:  X-Mzigo-Email-Signature: sha256=HMAC(raw body, app config key)
 *   - mailgun:  form payload signed with timestamp+token HMAC using webhook_signing_key
 *   - sendgrid: Event Webhook ECDSA signature using event_webhook_public_key
 *
 * Hard bounces and complaints suppress the address; soft bounces are logged.
 * Query: ?provider=mailgun|sendgrid / generic body field `provider` also accepted.
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_email_deliverability.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    fail_error('method_not_allowed', 'POST required.', 405);
}
$raw = (string) file_get_contents('php://input');
$queryProvider = strtolower(trim((string) ($_GET['provider'] ?? '')));
$formProvider = strtolower(trim((string) ($_POST['provider'] ?? '')));
$provider = $queryProvider !== '' ? $queryProvider : $formProvider;
$contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
$sendgridHeader = (string) ($_SERVER['HTTP_X_TWILIO_EMAIL_EVENT_WEBHOOK_SIGNATURE'] ?? '');

/* ---------------------------------------------------------------- Mailgun */
if ($provider === 'mailgun' || ($provider === '' && $sendgridHeader === '' && str_contains($raw, 'signature=') && str_contains($raw, 'token='))) {
    $form = email_events_mailgun_verify($raw);
    if ($form === null) {
        fail_error('invalid_signature', 'Invalid Mailgun webhook signature or timestamp.', 403);
    }
    $event = strtolower(trim((string) ($form['event'] ?? 'unknown')));
    $email = strtolower(trim((string) ($form['recipient'] ?? '')));
    $severity = strtolower(trim((string) ($form['severity'] ?? '')));
    $reason = trim((string) ($form['reason'] ?? ($form['description'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail_error('invalid_email', 'A valid recipient email is required.', 422);
    }
    $eventType = $event === 'permanent_fail' ? 'hard_bounce' : ($event === 'temporary_fail' ? 'soft_bounce' : $event);
    $suppress = email_event_suppress_type($event, $severity);
    email_event_apply('mailgun', $eventType, $email, $reason, $raw, $suppress);
    json_out(['ok' => true, 'source' => 'mysql', 'provider' => 'mailgun', 'event' => $eventType,
        'email' => $email, 'suppressed' => $suppress]);
}

/* --------------------------------------------------------------- SendGrid */
if ($provider === 'sendgrid' || ($provider === '' && $sendgridHeader !== '')) {
    if (!email_events_sendgrid_verify($raw)) {
        fail_error('invalid_signature', 'Invalid SendGrid Event Webhook signature or timestamp.', 403);
    }
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        fail_error('invalid_payload', 'SendGrid events must be a JSON array.', 422);
    }
    $events = array_is_list($payload) ? $payload : [$payload];
    $processed = [];
    foreach ($events as $item) {
        if (!is_array($item)) { continue; }
        $email = strtolower(trim((string) ($item['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { continue; }
        $event = strtolower(trim((string) ($item['event'] ?? 'unknown')));
        $type = strtolower(trim((string) ($item['type'] ?? '')));
        $reason = trim((string) ($item['reason'] ?? ''));
        $eventType = $event === 'bounce' && $type === 'soft' ? 'soft_bounce' : $event;
        $suppress = email_event_suppress_type($event, $type);
        email_event_apply('sendgrid', $eventType, $email, $reason, $raw, $suppress);
        $processed[] = ['event' => $eventType, 'email' => $email, 'suppressed' => $suppress];
    }
    if (!$processed) {
        fail_error('no_events', 'No valid SendGrid recipient events found.', 422);
    }
    json_out(['ok' => true, 'source' => 'mysql', 'provider' => 'sendgrid',
        'processed' => count($processed), 'events' => $processed]);
}

/* ---------------------------------------------------------------- generic */
$secret = email_event_secret();
if ($secret === '') {
    fail_error('not_configured', 'Email event secret is not configured.', 503);
}
$timestamp = trim((string) ($_SERVER['HTTP_X_MZIGO_EMAIL_TIMESTAMP'] ?? ''));
if ($timestamp !== '' && abs(time() - (int) $timestamp) > 300) {
    fail_error('stale_event', 'Email event timestamp is outside the replay window.', 403);
}
$provided = (string) ($_SERVER['HTTP_X_MZIGO_EMAIL_SIGNATURE'] ?? '');
$expected = 'sha256=' . hash_hmac('sha256', $raw, $secret);
if ($provided === '' || !hash_equals($expected, $provided)) {
    fail_error('invalid_signature', 'Invalid email event signature.', 403);
}
$data = json_decode($raw, true);
if (!is_array($data)) {
    fail_error('invalid_payload', 'Email event payload must be JSON.', 422);
}
$email = strtolower(trim((string) ($data['email'] ?? $data['recipient'] ?? '')));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail_error('invalid_email', 'A valid recipient email is required.', 422);
}
$event = strtolower(trim((string) ($data['event'] ?? $data['type'] ?? 'unknown')));
$bounceType = strtolower(trim((string) ($data['bounce_type'] ?? '')));
$providerName = trim((string) ($data['provider'] ?? 'smtp'));
$reason = trim((string) ($data['reason'] ?? $data['description'] ?? ''));
$eventType = $event === 'bounce' ? ($bounceType === 'hard' ? 'hard_bounce' : ($bounceType === 'soft' ? 'soft_bounce' : 'bounce')) : $event;
$suppress = email_event_suppress_type($event, $bounceType);
email_event_apply($providerName, $eventType, $email, $reason, $raw, $suppress);
json_out(['ok' => true, 'source' => 'mysql', 'provider' => $providerName, 'event' => $eventType,
    'email' => $email, 'suppressed' => $suppress]);
