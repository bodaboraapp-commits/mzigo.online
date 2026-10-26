<?php
/** POST /api/track-subscribe.php — guest receiver notification opt-in for a parcel. */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_notifications.php';

require_post();
verify_csrf();
$data = body_json();
$tracking = mb_strtoupper(trim((string) ($data['tracking'] ?? '')));
if ($tracking === '') { fail('Enter a tracking number.', 422); }
$email = isset($data['email']) && is_scalar($data['email']) ? trim((string) $data['email']) : '';
$phone = isset($data['phone']) && is_scalar($data['phone']) ? trim((string) $data['phone']) : '';
if ($email === '' && $phone === '') { fail('Add an email or phone number for updates.', 422); }
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { fail('Enter a valid email address.', 422); }
if ($phone !== '' && !phone_valid($phone)) { fail('Enter a valid Kenyan phone number.', 422); }
if (empty($data['consent'])) { fail('Please agree to receive parcel updates.', 422); }
rate_limit('track_subscribe:' . client_ip(), 30, 3600);
$stmt = db()->prepare('SELECT * FROM parcels WHERE tracking = ? LIMIT 1');
$stmt->execute([$tracking]);
$parcel = $stmt->fetch();
if (!$parcel) { fail_error('not_found', 'No parcel matches that tracking number.', 404); }
$stmt = db()->prepare('INSERT INTO track_subscriptions (tracking, email, phone, consent, created_at) VALUES (?, ?, ?, 1, NOW())');
$stmt->execute([$tracking, $email ?: null, $phone ?: null]);
audit('track.subscribed', 'parcel', (string) $parcel['id'], ['tracking' => $tracking]);
json_out(['ok' => true, 'message' => 'You will receive updates for ' . $tracking . '.'], 201);
