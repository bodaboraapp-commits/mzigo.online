<?php
/**
 * Mzigo.online — tracking lookup against MySQL.
 * Accepts a tracking number (TRK-…), a Locker ID (LK-…-…) or an errand ref (ERD-…).
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
function public_phone_mask(?string $phone): ?string
{
    $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
    if ($digits === '') { return null; }
    return str_repeat('*', max(0, strlen($digits) - 3)) . substr($digits, -3);
}

function public_tracking_redact(array $record, string $kind): array
{
    $record['sender_phone'] = public_phone_mask($record['sender_phone'] ?? null);
    $record['receiver_phone'] = public_phone_mask($record['receiver_phone'] ?? null);
    if ($kind === 'parcel') {
        unset($record['collect_code'], $record['payment_ref'], $record['description'], $record['value']);
    } else {
        unset($record['instructions'], $record['description']);
    }
    return $record;
}


$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
if ($q === '') {
    fail('Enter a tracking number, Locker ID or errand reference.', 422);
}
$q = strtoupper($q);
rate_limit('public_track:' . client_ip(), 60, 60);
$pdo = db();

$stmt = $pdo->prepare('SELECT * FROM parcels WHERE tracking = ? OR locker = ? LIMIT 1');
$stmt->execute([$q, $q]);
$row = $stmt->fetch();
if ($row) {
    $record = public_tracking_redact(parcel_api($row), 'parcel');
    json_out([
        'ok'     => true,
        'source' => 'mysql',
        'found'  => true,
        'kind'   => 'parcel',
        'query'  => $q,
        'record' => $record,
        'scans'  => $record['scans'],
    ]);
}

$stmt = $pdo->prepare('SELECT * FROM errands WHERE ref = :q LIMIT 1');
$stmt->execute([':q' => $q]);
$row = $stmt->fetch();
if ($row) {
    $record = public_tracking_redact(errand_api($row), 'errand');
    json_out([
        'ok'     => true,
        'source' => 'mysql',
        'found'  => true,
        'kind'   => 'errand',
        'query'  => $q,
        'record' => $record,
        'scans'  => $record['scans'],
    ]);
}

json_out(['ok' => true, 'source' => 'mysql', 'found' => false, 'query' => $q]);
