<?php
/** Mzigo.online — book an errand (route, contacts, item) into MySQL, tenant-scoped. */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_runner_jobs.php';

require_post();
verify_csrf();
$data = body_json();
$pdo = db();
$user = current_user();
$tenant = current_tenant();
$anon = (string) ($_SESSION['anon_id'] ?? '');
if (!$user && !$tenant) {
    rate_limit('public_errand:' . client_ip(), 10, 3600);
}

if ($tenant && in_array($tenant['status'], ['suspended', 'cancelled'], true)) {
    fail_error('tenant_' . $tenant['status'], 'This workspace is not active. Contact support.', 403);
}

$carrier = isset($data['carrier']) && is_scalar($data['carrier']) && trim((string) $data['carrier']) !== ''
    ? mb_substr(trim((string) $data['carrier']), 0, 120)
    : 'mzigo runners';

$pickup = param($data, 'pickup', 2, 160, 'Pickup point');
$dropoff = param($data, 'dropoff', 2, 160, 'Drop-off point');
$senderName = param($data, 'sender_name', 3, 120, 'Contact name');
$senderPhone = require_phone($data, 'sender_phone');
$receiverName = param($data, 'receiver_name', 3, 120, 'Recipient name');
$receiverPhone = require_phone($data, 'receiver_phone');
$description = param($data, 'description', 3, 255, 'Description');
$instructions = isset($data['instructions']) && is_scalar($data['instructions'])
    ? trim((string) $data['instructions'])
    : '';
if (mb_strlen($instructions) > 255) {
    $instructions = mb_substr($instructions, 0, 255);
}
$size = isset($data['size']) && is_scalar($data['size']) ? trim((string) $data['size']) : '';
if ($size !== '' && !in_array($size, MZ_PACKAGE_SIZES, true)) {
    fail('Choose a valid item size.', 422, ['size' => 'Invalid item size.']);
}
$distanceKm = null;
if (isset($data['distance_km']) && $data['distance_km'] !== '' && is_numeric($data['distance_km'])) {
    $distanceKm = round((float) $data['distance_km'], 1);
    if ($distanceKm < 0.1 || $distanceKm > 200) {
        fail('Estimated distance must be between 0.1 and 200 km.', 422, ['distance_km' => 'Out of range.']);
    }
}
$payment = strtoupper(param($data, 'payment', 1, 10, 'Payment method'));
if (!in_array($payment, MZ_PAYMENT_METHODS, true)) {
    fail('Choose CASH or M-PESA.', 422, ['payment' => 'Invalid payment method.']);
}

$fee = ['base' => 250, 'platform' => 50, 'total' => 300];
$id = uuid4();
$ref = unique_token('errands', 'ref', 'ERD-', 6);

$sql = 'INSERT INTO errands
    (id, ref, user_id, anon_id, tenant_id, carrier, pickup, dropoff, sender_name, sender_phone,
     recipient_name, recipient_phone, description, size, distance_km, instructions, payment_method,
     status, fee_base, fee_platform, fee_total, created_at)
    VALUES
    (:id, :ref, :user_id, :anon_id, :tenant_id, :carrier, :pickup, :dropoff, :sender_name, :sender_phone,
     :recipient_name, :recipient_phone, :description, :size, :distance_km, :instructions, :payment_method,
     :status, :fee_base, :fee_platform, :fee_total, NOW())';
$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':id'              => $id,
    ':ref'             => $ref,
    ':user_id'         => $user['id'] ?? null,
    ':anon_id'         => $anon,
    ':tenant_id'       => $tenant['id'] ?? null,
    ':carrier'         => $carrier,
    ':pickup'          => $pickup,
    ':dropoff'         => $dropoff,
    ':sender_name'     => $senderName,
    ':sender_phone'    => $senderPhone,
    ':recipient_name'  => $receiverName,
    ':recipient_phone' => $receiverPhone,
    ':description'     => $description,
    ':size'            => $size !== '' ? $size : null,
    ':distance_km'     => $distanceKm,
    ':instructions'    => $instructions !== '' ? $instructions : null,
    ':payment_method'  => $payment,
    ':status'          => 'CREATED',
    ':fee_base'        => $fee['base'],
    ':fee_platform'    => $fee['platform'],
    ':fee_total'       => $fee['total'],
]);

$scan = $pdo->prepare(
    'INSERT INTO scans (subject_type, subject_id, status, label, location, note, scanned_at)
     VALUES (\'errand\', ?, ?, ?, ?, ?, NOW())'
);
$scan->execute([
    $id, 'CREATED', 'Errand requested', 'Mzigo.online',
    'A runner will be assigned and you will be notified.',
]);

audit('errand.created', 'errand', $id, ['ref' => $ref, 'tenant_id' => $tenant['id'] ?? null]);

runner_job_create_from_errand([
    'id'              => $id,
    'ref'             => $ref,
    'pickup'          => $pickup,
    'dropoff'         => $dropoff,
    'fee_base'        => $fee['base'],
    'fee_total'       => $fee['total'],
    'description'     => $description,
    'size'            => $size,
    'distance_km'     => $distanceKm,
    'instructions'    => $instructions,
    'sender_name'     => $senderName,
    'sender_phone'    => $senderPhone,
    'recipient_name'  => $receiverName,
    'recipient_phone' => $receiverPhone,
]);

$stmt = $pdo->prepare('SELECT * FROM errands WHERE id = ?');
$stmt->execute([$id]);
$errand = errand_api($stmt->fetch());

json_out([
    'ok'     => true,
    'source' => 'mysql',
    'errand' => $errand,
    'qr'     => '/track-mzigo?num=' . rawurlencode($ref),
], 201);
