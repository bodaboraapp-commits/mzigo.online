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
