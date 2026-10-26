<?php
/**
 * Mzigo.online — public contact form endpoint.
 *
 * POST { name, email, phone?, subject, message, company? }
 * Stores the submission in contact_messages for the platform admin inbox and
 * returns the configured support address so the /contact page can show it.
 *
 * The endpoint is public but CSRF-protected (the site bootstraps a token for
 * anonymous visitors too) and rate-limited per IP to keep spam out.
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET') {
    json_out([
        'ok' => true,
        'source' => 'mysql',
        'support_email' => (string) (config()['app']['support_email'] ?? 'hello@mzigo.online'),
        'app_name' => (string) (config()['app']['name'] ?? 'Mzigo.online'),
    ]);
}

require_post();
verify_csrf();

$data = body_json();

/* Bot trap: a hidden field real visitors never fill in. Answer "ok" so bots
   do not learn that their submission was dropped. */
if (trim((string) ($data['company'] ?? '')) !== '') {
    json_out(['ok' => true, 'source' => 'mysql']);
}

rate_limit('contact:' . client_ip(), 5, 300);

$name = param($data, 'name', 2, 120, 'Your name');
$email = param($data, 'email', 5, 160, 'Email');
if (!email_valid($email)) {
    fail('Enter a valid email address, e.g. you@business.co.ke.', 422, ['email' => 'Invalid email address.']);
}
$phoneRaw = isset($data['phone']) && is_scalar($data['phone']) ? trim((string) $data['phone']) : '';
$phone = null;
if ($phoneRaw !== '') {
    if (mb_strlen($phoneRaw) > 20) {
        fail('Phone number is too long (max 20 characters).', 422, ['phone' => 'Too long.']);
    }
    $phone = $phoneRaw;
}
$subject = param($data, 'subject', 2, 160, 'Topic');
$message = param($data, 'message', 10, 4000, 'Message');

$pdo = db();
$pdo->prepare(
    'INSERT INTO contact_messages (name, email, phone, subject, message, status, ip, user_agent)
     VALUES (?, ?, ?, ?, ?, "new", ?, ?)'
)->execute([
    $name, $email, $phone, $subject, $message,
    client_ip(), substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
]);
$id = (int) $pdo->lastInsertId();

audit('contact.submitted', 'contact', (string) $id, ['subject' => $subject, 'email' => $email]);

json_out([
    'ok' => true,
    'source' => 'mysql',
    'id' => $id,
    'support_email' => (string) (config()['app']['support_email'] ?? 'hello@mzigo.online'),
]);
