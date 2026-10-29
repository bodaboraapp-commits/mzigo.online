<?php
declare(strict_types=1);

/**
 * Parcel product/package photos captured during order creation.
 */

function parcel_photo_dir(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . '_server' . DIRECTORY_SEPARATOR
        . 'uploads' . DIRECTORY_SEPARATOR . 'parcel-photos';
}

function order_photos_required(): bool
{
    return platform_setting('order_photos_required', '0') === '1';
}

function order_photos_required_save(bool $required): bool
{
    db()->prepare('INSERT INTO platform_settings (setting_key, setting_value) VALUES ("order_photos_required", ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')->execute([$required ? '1' : '0']);
    return $required;
}

function parcel_photo_can_manage(array $parcel): bool
{
    $user = current_user();
    if ($user && ($user['role'] ?? '') === 'platform_admin') {
        return true;
    }
    if ($user && !empty($parcel['user_id']) && (int) $parcel['user_id'] === (int) $user['id']) {
        return true;
    }
    $anon = (string) ($_SESSION['anon_id'] ?? '');
    if ($anon !== '' && !empty($parcel['anon_id']) && hash_equals((string) $parcel['anon_id'], $anon)) {
        return true;
    }
    $tenant = current_tenant();
    if ($tenant && !empty($parcel['tenant_id']) && (int) $tenant['id'] === (int) $parcel['tenant_id']) {
        return true;
    }
    return false;
}

function parcel_photo_can_view(array $parcel): bool
{
    if (parcel_photo_can_manage($parcel)) {
        return true;
    }
    $user = current_user();
    if (!$user) {
        return false;
    }
    $receiverKey = (string) ($parcel['receiver_phone_key'] ?? '');
    if ($receiverKey !== '' && hash_equals($receiverKey, phone_key((string) ($user['phone'] ?? '')))) {
        return true;
    }
    return false;
}

function parcel_photo_urls(string $parcelId): array
{
    $urls = ['product' => null, 'package' => null];
    try {
        $stmt = db()->prepare('SELECT kind, created_at FROM parcel_photos WHERE parcel_id = ?');
        $stmt->execute([$parcelId]);
        foreach ($stmt->fetchAll() as $row) {
            $kind = (string) $row['kind'];
            if (!array_key_exists($kind, $urls)) {
                continue;
            }
            $urls[$kind] = '/api/parcel-photo.php?parcel_id=' . rawurlencode($parcelId)
                . '&kind=' . rawurlencode($kind)
                . '&v=' . rawurlencode((string) $row['created_at']);
        }
    } catch (Throwable $e) {
        // The photo table may not be migrated yet on an older deployment.
    }
    return $urls;
}

/**
 * Save one base64 photo supplied inline with order registration.
 * Throws RuntimeException with a user-safe message on failure.
 */
function parcel_photo_save_data_uri(string $parcelId, string $kind, array $photo): array
{
    require_once __DIR__ . '/_malware.php';
    if (!in_array($kind, ['product', 'package'], true)) {
        throw new RuntimeException('Choose a valid photo type.');
    }
    $raw = trim((string) ($photo['data'] ?? ''));
    if (str_starts_with($raw, 'data:')) {
        $comma = strpos($raw, ',');
        $raw = $comma !== false ? substr($raw, $comma + 1) : '';
    }
    if ($raw === '' || strlen($raw) > 8 * 1024 * 1024) {
        throw new RuntimeException('The photo is missing or too large.');
    }
    $binary = base64_decode($raw, true);
    if ($binary === false || $binary === '') {
        throw new RuntimeException('The photo could not be read.');
    }
    if (strlen($binary) > 5 * 1024 * 1024) {
        throw new RuntimeException('Photos must be 5MB or smaller.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->buffer($binary);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Upload a JPG, PNG or WebP photo.');
    }
    $name = mb_substr(basename((string) ($photo['name'] ?? ($kind . '.' . $allowed[$mime]))), 0, 255);
    $tmp = tempnam(sys_get_temp_dir(), 'mzphoto');
    if ($tmp === false) {
        throw new RuntimeException('Photo storage is unavailable.');
    }
    try {
        if (file_put_contents($tmp, $binary) === false) {
            throw new RuntimeException('Photo storage is unavailable.');
        }
        $scan = malware_scan_file($tmp, $mime, $name, strlen($binary));
        if (($scan['result'] ?? 'clean') !== 'clean') {
            throw new RuntimeException('That photo could not be accepted.');
        }
    } finally {
        @unlink($tmp);
    }
    $dir = parcel_photo_dir();
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Photo storage is unavailable.');
    }
    $storage = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    if (file_put_contents($dir . DIRECTORY_SEPARATOR . $storage, $binary) === false) {
        throw new RuntimeException('Could not save the photo.');
    }
    $old = db()->prepare('SELECT storage_name FROM parcel_photos WHERE parcel_id = ? AND kind = ? LIMIT 1');
    $old->execute([$parcelId, $kind]);
    $oldStorage = (string) ($old->fetchColumn() ?: '');
    $user = current_user();
    try {
        db()->prepare('INSERT INTO parcel_photos (parcel_id, kind, filename, mime, size_bytes, storage_name, scan_status, uploaded_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE filename = VALUES(filename), mime = VALUES(mime), size_bytes = VALUES(size_bytes),
            storage_name = VALUES(storage_name), scan_status = VALUES(scan_status), uploaded_by = VALUES(uploaded_by), created_at = NOW()')
            ->execute([$parcelId, $kind, $name, $mime, strlen($binary), $storage,
                (string) ($scan['result'] ?? 'clean'), $user['id'] ?? null]);
    } catch (Throwable $e) {
        @unlink($dir . DIRECTORY_SEPARATOR . $storage);
        throw $e;
    }
    if ($oldStorage !== '' && $oldStorage !== $storage) {
        @unlink($dir . DIRECTORY_SEPARATOR . $oldStorage);
    }
    return ['kind' => $kind, 'filename' => $name, 'mime' => $mime, 'size_bytes' => strlen($binary), 'storage_name' => $storage];
}
