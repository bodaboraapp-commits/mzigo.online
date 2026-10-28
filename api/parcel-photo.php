<?php
/**
 * GET /api/parcel-photo.php?parcel_id=ID&kind=product|package
 * Authorised product/package photo delivery.
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$parcelId = trim((string) ($_GET['parcel_id'] ?? ''));
$kind = strtolower(trim((string) ($_GET['kind'] ?? '')));
if (!preg_match('/^[a-f0-9-]{36}$/i', $parcelId) || !in_array($kind, ['product', 'package'], true)) {
    fail_error('not_found', 'Photo not found.', 404);
}
$stmt = db()->prepare('SELECT * FROM parcels WHERE id = ? LIMIT 1');
$stmt->execute([$parcelId]);
$parcel = $stmt->fetch();
if (!$parcel || !parcel_photo_can_view($parcel)) {
    fail_error('forbidden', 'You cannot view this photo.', 403);
}
$photoStmt = db()->prepare('SELECT mime, storage_name FROM parcel_photos WHERE parcel_id = ? AND kind = ? LIMIT 1');
$photoStmt->execute([$parcelId, $kind]);
$photo = $photoStmt->fetch();
if (!$photo) {
    fail_error('not_found', 'Photo not found.', 404);
}
$path = parcel_photo_dir() . DIRECTORY_SEPARATOR . basename((string) $photo['storage_name']);
if (!is_file($path)) {
    fail_error('not_found', 'Photo file is missing.', 404);
}
header('Content-Type: ' . (string) $photo['mime']);
header('Content-Length: ' . (string) filesize($path));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
