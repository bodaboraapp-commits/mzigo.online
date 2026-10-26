<?php
/**
 * Mzigo.online — register a parcel.
 *
 * Cookie/session endpoint (CSRF-protected). When the request belongs to a signed-in
 * workspace the parcel is scoped to that tenant and the monthly plan quota is
 * enforced; walk-in senders can still register anonymously.
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_notifications.php';
require __DIR__ . '/_webhooks.php';
require_once __DIR__ . '/_tenant_branding.php';
require_once __DIR__ . '/_growth.php';
require_once __DIR__ . '/_insurance.php';

require_post();
verify_csrf();
$data = body_json();
$pdo = db();
$user = current_user();
$tenant = current_tenant();
$anon = (string) ($_SESSION['anon_id'] ?? '');
if (!$user && !$tenant) {
    rate_limit('public_parcel:' . client_ip(), 10, 3600);
}

if ($tenant) {
    if (in_array($tenant['status'], ['suspended', 'cancelled'], true)) {
        fail_error('tenant_' . $tenant['status'], 'This workspace is not active. Contact support.', 403);
    }
    enforce_parcel_quota((int) $tenant['id']);
}

/* ---------------------------------------------------------------- carrier */
$carrierSlug = param($data, 'carrier_slug', 1, 60, 'Courier');
$stmt = $pdo->prepare('SELECT slug, name FROM partners WHERE slug = ? LIMIT 1');
$stmt->execute([$carrierSlug]);
$partner = $stmt->fetch();
if (!$partner) {
    fail('Choose a courier from the list.', 422, ['carrier_slug' => 'Unknown courier.']);
}

/* ------------------------------------------------------------ from & to */
$from = param($data, 'from', 2, 120, 'Origin');
$to = param($data, 'to', 2, 120, 'Destination');
$stmt = $pdo->prepare('SELECT name FROM destinations WHERE partner_slug = ? AND name = ? LIMIT 1');
$stmt->execute([$partner['slug'], $from]);
if ($stmt->fetchColumn() === false) {
    fail('That origin is not on this courier\'s route. Pick one from the list.', 422, ['from' => 'Unknown origin.']);
}
$stmt->execute([$partner['slug'], $to]);
if ($stmt->fetchColumn() === false) {
    fail('That destination is not on this courier\'s route. Pick one from the list.', 422, ['to' => 'Unknown destination.']);
}

/* --------------------------------------------------------------- people */
$senderName = param($data, 'sender_name', 3, 120, 'Sender name');
$senderPhone = require_phone($data, 'sender_phone');
$receiverName = param($data, 'receiver_name', 3, 120, 'Receiver name');
$receiverPhone = require_phone($data, 'receiver_phone');

/* --------------------------------------------------------------- parcel */
$description = param($data, 'description', 3, 255, 'Description');
$size = param($data, 'size', 1, 40, 'Package size');
if (!in_array($size, MZ_PACKAGE_SIZES, true)) {
    fail('Choose a package size from the list.', 422, ['size' => 'Invalid package size.']);
}
$valueRaw = $data['value'] ?? null;
if (!is_numeric($valueRaw)) {
    fail('Declare the parcel value in KES.', 422, ['value' => 'Enter a numeric value.']);
}
$value = (int) $valueRaw;
if ($value < 1 || $value > 1000000) {
    fail('Parcel value must be between KES 1 and 1,000,000.', 422, ['value' => 'Out of range.']);
}
$payment = strtoupper(param($data, 'payment', 1, 10, 'Payment method'));
if (!in_array($payment, MZ_PAYMENT_METHODS, true)) {
    fail('Choose CASH or M-PESA.', 422, ['payment' => 'Invalid payment method.']);
}

$tier = $value > 10000 ? 'B' : 'A';
$promiseHours = max(1, min(240, (int) (platform_setting('delivery_promise_hours', '48') ?? 48)));
$promisedDeliveryAt = date('Y-m-d H:i:s', strtotime('+' . $promiseHours . ' hours'));
$quote = tenant_rate_quote((int) ($tenant['id'] ?? 0), (string) $partner['slug'], $from, $to, $size, $value);
$id = uuid4();
$tracking = unique_token('parcels', 'tracking', 'TRK-', 8);
$locker = unique_locker();
$collectCode = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

$sql = 'INSERT INTO parcels
    (id, tracking, locker, user_id, anon_id, tenant_id, carrier, carrier_slug, from_city, to_city,
     sender_name, sender_phone, receiver_name, receiver_phone, receiver_phone_key, description, size, value_kes,
     tier, payment_method, collect_code, status,
     fee_base, fee_holding, fee_insurance, fee_platform, fee_total, registered_at, promised_delivery_at)
    VALUES
    (:id, :tracking, :locker, :user_id, :anon_id, :tenant_id, :carrier, :carrier_slug, :from_city, :to_city,
     :sender_name, :sender_phone, :receiver_name, :receiver_phone, :receiver_phone_key, :description, :size, :value_kes,
     :tier, :payment_method, :collect_code, :status,
     :fee_base, :fee_holding, :fee_insurance, :fee_platform, :fee_total, NOW(), :promised_delivery_at)';
$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':id'             => $id,
    ':tracking'       => $tracking,
    ':locker'         => $locker,
    ':user_id'        => $user['id'] ?? null,
    ':anon_id'        => $anon,
    ':tenant_id'      => $tenant['id'] ?? null,
    ':carrier'        => $partner['name'],
    ':carrier_slug'   => $partner['slug'],
    ':from_city'      => $from,
    ':to_city'        => $to,
    ':sender_name'    => $senderName,
    ':sender_phone'   => $senderPhone,
    ':receiver_name'  => $receiverName,
    ':receiver_phone' => $receiverPhone,
    ':receiver_phone_key' => phone_key($receiverPhone),
    ':description'    => $description,
    ':size'           => $size,
    ':value_kes'      => $value,
    ':tier'           => $tier,
    ':payment_method' => $payment,
    ':collect_code'   => $collectCode,
    ':status'         => 'CREATED',
    ':fee_base'       => $quote['base'],
    ':fee_holding'    => $quote['holding'],
    ':fee_insurance'  => $quote['insurance'],
    ':fee_platform'   => $quote['platform'],
    ':fee_total'      => $quote['total'],
    ':promised_delivery_at' => $promisedDeliveryAt,
]);

$scan = $pdo->prepare(
    'INSERT INTO scans (subject_type, subject_id, status, label, location, note, scanned_at)
     VALUES (\'parcel\', ?, ?, ?, ?, ?, NOW())'
);
$scan->execute([
    $id, 'CREATED', 'Parcel registered', 'Mzigo.online',
    'QR label and Locker ID issued to sender. Drop off at the courier office to start tracking.',
]);

$save = $pdo->prepare('INSERT IGNORE INTO senders (tenant_id, phone, name) VALUES (?, ?, ?)');
$save->execute([$tenant['id'] ?? null, $senderPhone, $senderName]);

$insuranceIssue = insurance_issue_for_parcel($id);
if (!$insuranceIssue['ok'] && !empty(insurance_config()['require_policy'])) {
    $pdo->prepare("DELETE FROM scans WHERE subject_type = 'parcel' AND subject_id = ?")->execute([$id]);
    $pdo->prepare('DELETE FROM parcels WHERE id = ?')->execute([$id]);
    fail('The parcel could not be insured right now. Please try again or contact support.', 502, ['insurance' => $insuranceIssue['error'] ?? 'Insurer unavailable.']);
}

audit('parcel.created', 'parcel', $id, [
    'tracking' => $tracking,
    'tenant_id' => $tenant['id'] ?? null,
    'value_kes' => $value,
    'fee_total' => $quote['total'],
    'rate_card_id' => $quote['rate_card_id'] ?? null,
]);

$stmt = $pdo->prepare('SELECT * FROM parcels WHERE id = ?');
$stmt->execute([$id]);
$parcelRow = $stmt->fetch();
$parcel = parcel_api($parcelRow);
$notified = notify_parcel_created($parcelRow);
if ($user) { growth_qualify_referral((int) $user['id'], 'business'); }
if ($tenant) {
    dispatch_webhooks((int) $tenant['id'], 'parcel.created', ['parcel' => $parcel, 'source' => 'dashboard']);
}

json_out([
    'ok'     => true,
    'source' => 'mysql',
    'parcel' => $parcel,
    'qr'     => '/track-mzigo?num=' . rawurlencode($tracking),
    'notifications' => $notified,
    'usage'  => $tenant ? tenant_usage((int) $tenant['id']) : null,
], 201);
