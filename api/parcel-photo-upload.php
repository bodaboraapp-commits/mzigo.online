<?php
/**
 * POST /api/parcel-photo-upload.php — multipart product/package photo capture.
 * Fields: parcel_id, kind (product|package), file
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_malware.php';

require_post();
verify_csrf();
rate_limit('parcel_photo_upload:' . client_ip(), 30, 3600);

$parcelId = trim((string) ($_POST['parcel_id'] ?? ''));
$kind = strtolower(trim((string) ($_POST['kind'] ?? '')));
if (!preg_match('/^[a-f0-9-]{36}$/i', $parcelId)) {
    fail_error('invalid_parcel', 'Choose a valid parcel.', 422);
}
if (!in_array($kind, ['product', 'package'], true)) {
    fail_error('invalid_kind', 'Choose a product or package photo.', 422);
}

$stmt = db()->prepare('SELECT * FROM parcels WHERE id = ? LIMIT 1');
$stmt->execute([$parcelId]);
$parcel = $stmt->fetch();
if (!$parcel) {
    fail_error('not_found', 'Parcel not found.', 404);
}
if (!parcel_photo_can_manage($parcel)) {
    fail_error('forbidden', 'You cannot add photos to this order.', 403);
}
if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    fail_error('upload_failed', 'Choose a photo to upload.', 422);
}
$file = $_FILES['file'];
if ((int) $file['size'] > 5 * 1024 * 1024) {
    fail_error('file_too_large', 'Photos must be 5MB or smaller.', 422);
}
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string) $finfo->file((string) $file['tmp_name']);
$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
if (!isset($allowed[$mime])) {
    fail_error('invalid_file_type', 'Upload a JPG, PNG or WebP photo.', 422);
}
$scan = malware_scan_file((string) $file['tmp_name'], $mime, (string) $file['name'], (int) $file['size']);
if (($scan['result'] ?? 'clean') !== 'clean') {
    fail_error('unsafe_file', 'That photo could not be accepted.', 422);
}

$dir = parcel_photo_dir();
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    fail_error('storage_unavailable', 'Photo storage is unavailable.', 500);
}
$storage = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
if (!move_uploaded_file((string) $file['tmp_name'], $dir . DIRECTORY_SEPARATOR . $storage)) {
    fail_error('storage_unavailable', 'Could not save the photo.', 500);
}

$old = db()->prepare('SELECT storage_name FROM parcel_photos WHERE parcel_id = ? AND kind = ? LIMIT 1');
$old->execute([$parcelId, $kind]);
$oldStorage = (string) ($old->fetchColumn() ?: '');
$original = mb_substr(basename((string) $file['name']), 0, 255);
$user = current_user();
db()->prepare('INSERT INTO parcel_photos (parcel_id, kind, filename, mime, size_bytes, storage_name, scan_status, uploaded_by, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ON DUPLICATE KEY UPDATE filename = VALUES(filename), mime = VALUES(mime), size_bytes = VALUES(size_bytes),
    storage_name = VALUES(storage_name), scan_status = VALUES(scan_status), uploaded_by = VALUES(uploaded_by), created_at = NOW()')
    ->execute([$parcelId, $kind, $original, $mime, (int) $file['size'], $storage,
        (string) ($scan['result'] ?? 'clean'), $user['id'] ?? null]);
if ($oldStorage !== '' && $oldStorage !== $storage) {
    @unlink($dir . DIRECTORY_SEPARATOR . $oldStorage);
}
audit('parcel.photo_uploaded', 'parcel', $parcelId, [
    'kind' => $kind, 'mime' => $mime, 'bytes' => (int) $file['size'],
], $parcel['tenant_id'] !== null ? (int) $parcel['tenant_id'] : null);
json_out(['ok' => true, 'parcel_id' => $parcelId, 'kind' => $kind, 'photos' => parcel_photo_urls($parcelId)]);
