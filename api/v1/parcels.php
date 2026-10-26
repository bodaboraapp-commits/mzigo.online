<?php
/**
 * Mzigo.online — public API v1: parcels.
 *
 * POST /api/v1/parcels.php  (X-API-Key)  create a parcel in the key's workspace
 * GET  /api/v1/parcels.php  (X-API-Key)  ?tracking=TRK-… | list recent parcels
 */
declare(strict_types=1);
require __DIR__ . '/../_bootstrap.php';
require __DIR__ . '/../_notifications.php';
require __DIR__ . '/../_webhooks.php';
require_once __DIR__ . '/../_tenant_branding.php';

$key = api_key_context();
$tenantId = (int) $key['tenant_id'];
record_api_usage($tenantId, (int) ($key['key_id'] ?? 0));
$keyScopes = array_map('trim', explode(',', (string) ($key['scopes'] ?? 'read,write')));
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'GET' && !in_array('read', $keyScopes, true)) {
    fail_error('scope_required', 'This API key does not have the read scope.', 403);
}
if ($method !== 'GET' && !in_array('write', $keyScopes, true)) {
    fail_error('scope_required', 'This API key does not have the write scope.', 403);
}

if ($method === 'GET') {
    $tracking = isset($_GET['tracking']) ? strtoupper(trim((string) $_GET['tracking'])) : '';
    if ($tracking !== '') {
        $stmt = db()->prepare('SELECT * FROM parcels WHERE tenant_id = ? AND (tracking = ? OR locker = ?) LIMIT 1');
        $stmt->execute([$tenantId, $tracking, $tracking]);
        $row = $stmt->fetch();
        if (!$row) {
            fail_error('not_found', 'No parcel with that tracking number in this workspace.', 404);
        }
        json_out(['ok' => true, 'source' => 'mysql', 'parcel' => parcel_api($row)]);
    }
    $stmt = db()->prepare('SELECT * FROM parcels WHERE tenant_id = ? ORDER BY registered_at DESC LIMIT 50');
    $stmt->execute([$tenantId]);
    json_out([
        'ok'       => true,
        'source'   => 'mysql',
        'workspace' => $key['tenant_name'],
        'parcels'  => array_map('parcel_api', $stmt->fetchAll()),
    ]);
}

/* ------------------------------------------------------------------ create */
$data = body_json();
if (in_array($tenantId, [0], true)) {
    fail_error('forbidden', 'Invalid API key context.', 403);
}

$stmt = db()->prepare('SELECT status FROM tenants WHERE id = ? LIMIT 1');
$stmt->execute([$tenantId]);
$status = (string) $stmt->fetchColumn();
if (in_array($status, ['suspended', 'cancelled'], true)) {
    fail_error('tenant_suspended', 'This workspace is not active.', 403);
}
enforce_parcel_quota($tenantId);

$carrierSlug = param($data, 'carrier_slug', 1, 60, 'Courier');
$stmt = db()->prepare('SELECT slug, name FROM partners WHERE slug = ? LIMIT 1');
$stmt->execute([$carrierSlug]);
$partner = $stmt->fetch();
if (!$partner) {
    fail('Unknown courier.', 422, ['carrier_slug' => 'Unknown courier.']);
}

$from = param($data, 'from', 2, 120, 'Origin');
$to = param($data, 'to', 2, 120, 'Destination');
$stmt = db()->prepare('SELECT name FROM destinations WHERE partner_slug = ? AND name = ? LIMIT 1');
$stmt->execute([$partner['slug'], $from]);
if ($stmt->fetchColumn() === false) {
    fail('That origin is not on this courier\'s route.', 422, ['from' => 'Unknown origin.']);
}
$stmt->execute([$partner['slug'], $to]);
if ($stmt->fetchColumn() === false) {
    fail('That destination is not on this courier\'s route.', 422, ['to' => 'Unknown destination.']);
}

$senderName = param($data, 'sender_name', 3, 120, 'Sender name');
$senderPhone = require_phone($data, 'sender_phone');
$receiverName = param($data, 'receiver_name', 3, 120, 'Receiver name');
$receiverPhone = require_phone($data, 'receiver_phone');
$description = param($data, 'description', 3, 255, 'Description');
$size = param($data, 'size', 1, 40, 'Package size');
if (!in_array($size, MZ_PACKAGE_SIZES, true)) {
    fail('Invalid package size.', 422, ['size' => 'Invalid package size.']);
}
$valueRaw = $data['value'] ?? null;
if (!is_numeric($valueRaw)) {
    fail('Declare the parcel value in KES.', 422, ['value' => 'Numeric value required.']);
}
$value = (int) $valueRaw;
if ($value < 1 || $value > 1000000) {
    fail('Parcel value must be between KES 1 and 1,000,000.', 422);
}
$payment = strtoupper(param($data, 'payment', 1, 10, 'Payment method'));
if (!in_array($payment, MZ_PAYMENT_METHODS, true)) {
    fail('Choose CASH or M-PESA.', 422);
}

$quote = tenant_rate_quote($tenantId, (string) $partner['slug'], $from, $to, $size, $value);
$tier = $value > 10000 ? 'B' : 'A';
$id = uuid4();
$tracking = unique_token('parcels', 'tracking', 'TRK-', 8);
$locker = unique_locker();
$collectCode = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

$pdo = db();
$stmt = $pdo->prepare(
    'INSERT INTO parcels
     (id, tracking, locker, user_id, anon_id, tenant_id, carrier, carrier_slug, from_city, to_city,
      sender_name, sender_phone, receiver_name, receiver_phone, receiver_phone_key, description, size, value_kes,
      tier, payment_method, collect_code, status,
      fee_base, fee_holding, fee_insurance, fee_platform, fee_total, registered_at)
     VALUES (?, ?, ?, NULL, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "CREATED", ?, ?, ?, ?, ?, NOW())'
);
$stmt->execute([
    $id, $tracking, $locker, $tenantId, $partner['name'], $partner['slug'], $from, $to,
    $senderName, $senderPhone, $receiverName, $receiverPhone, phone_key($receiverPhone), $description, $size, $value,
    $tier, $payment, $collectCode,
    $quote['base'], $quote['holding'], $quote['insurance'], $quote['platform'], $quote['total'],
]);

$pdo->prepare(
    'INSERT INTO scans (subject_type, subject_id, status, label, location, note, scanned_at)
     VALUES ("parcel", ?, "CREATED", "Parcel registered", "Mzigo.online", ?, NOW())'
)->execute([$id, 'Created through the Mzigo.online API. Drop off at the courier office to start tracking.']);

$pdo->prepare('INSERT IGNORE INTO senders (tenant_id, phone, name) VALUES (?, ?, ?)')
    ->execute([$tenantId, $senderPhone, $senderName]);

audit('api.parcel_created', 'parcel', $id, [
    'tracking' => $tracking, 'api_key_id' => $key['key_id'],
], $tenantId);

$fetch = $pdo->prepare('SELECT * FROM parcels WHERE id = ?');
$fetch->execute([$id]);
$parcelRow = $fetch->fetch();
dispatch_webhooks($tenantId, 'parcel.created', ['parcel' => parcel_api($parcelRow), 'source' => 'api']);

json_out([
    'ok'     => true,
    'source' => 'mysql',
    'parcel' => parcel_api($parcelRow),
    'notifications' => notify_parcel_created($parcelRow),
    'usage'  => tenant_usage($tenantId),
], 201);
