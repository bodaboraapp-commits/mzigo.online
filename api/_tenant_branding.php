<?php
/** Per-tenant branding and carrier/insurance rate cards. */
declare(strict_types=1);

function tenant_branding_defaults(): array
{
    return [
        'tenant_id' => 0, 'logo_url' => null, 'primary_color' => '#3c5223',
        'secondary_color' => '#4a6330', 'accent_color' => '#8ddc95',
        'support_email' => null, 'support_phone' => null, 'document_footer' => null,
    ];
}

function tenant_branding(int $tenantId): array
{
    $defaults = tenant_branding_defaults();
    $defaults['tenant_id'] = $tenantId;
    try {
        $stmt = db()->prepare('SELECT logo_url, primary_color, secondary_color, accent_color, support_email, support_phone, document_footer
            FROM tenant_branding WHERE tenant_id = ? LIMIT 1');
        $stmt->execute([$tenantId]);
        $row = $stmt->fetch();
        if ($row) {
            foreach ($defaults as $key => $value) {
                if ($key !== 'tenant_id' && array_key_exists($key, $row)) { $defaults[$key] = $row[$key]; }
            }
        }
    } catch (Throwable $e) {
        error_log('tenant branding lookup failed: ' . $e->getMessage());
    }
    return $defaults;
}

function tenant_branding_public_by_slug(string $slug): ?array
{
    $stmt = db()->prepare('SELECT id, slug, name, status FROM tenants WHERE slug = ? LIMIT 1');
    $stmt->execute([$slug]);
    $tenant = $stmt->fetch();
    if (!$tenant || !in_array($tenant['status'], ['active', 'trial'], true)) { return null; }
    $branding = tenant_branding((int) $tenant['id']);
    return [
        'slug' => $tenant['slug'], 'name' => $tenant['name'],
        'logo_url' => $branding['logo_url'], 'primary_color' => $branding['primary_color'],
        'secondary_color' => $branding['secondary_color'], 'accent_color' => $branding['accent_color'],
        'support_email' => $branding['support_email'], 'support_phone' => $branding['support_phone'],
    ];
}

function tenant_branding_color(string $value, string $fallback): string
{
    $value = strtoupper(trim($value));
    return preg_match('/^#[0-9A-F]{6}$/', $value) ? $value : $fallback;
}

function tenant_branding_save(int $tenantId, array $data, int $userId): array
{
    $current = tenant_branding($tenantId);
    $primary = tenant_branding_color((string) ($data['primary_color'] ?? ''), $current['primary_color']);
    $secondary = tenant_branding_color((string) ($data['secondary_color'] ?? ''), $current['secondary_color']);
    $accent = tenant_branding_color((string) ($data['accent_color'] ?? ''), $current['accent_color']);
    $email = mb_substr(trim((string) ($data['support_email'] ?? '')), 0, 160);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail_error('invalid_support_email', 'Enter a valid support email.', 422);
    }
    $phone = mb_substr(trim((string) ($data['support_phone'] ?? '')), 0, 30);
    $footer = mb_substr(trim((string) ($data['document_footer'] ?? '')), 0, 255);
    db()->prepare('INSERT INTO tenant_branding (tenant_id, primary_color, secondary_color, accent_color, support_email, support_phone, document_footer, updated_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE primary_color = VALUES(primary_color), secondary_color = VALUES(secondary_color),
          accent_color = VALUES(accent_color), support_email = VALUES(support_email), support_phone = VALUES(support_phone),
          document_footer = VALUES(document_footer), updated_by = VALUES(updated_by)')
        ->execute([$tenantId, $primary, $secondary, $accent, $email !== '' ? $email : null, $phone !== '' ? $phone : null,
            $footer !== '' ? $footer : null, $userId]);
    audit('tenant.branding_updated', 'tenant', (string) $tenantId, ['colors' => [$primary, $secondary, $accent]]);
    return tenant_branding($tenantId);
}

function tenant_branding_upload_logo(int $tenantId, array $file, int $userId): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        fail_error('upload_failed', 'Choose a logo image to upload.', 422);
    }
    if (($file['size'] ?? 0) <= 0 || (int) $file['size'] > 2 * 1024 * 1024) {
        fail_error('upload_too_large', 'Logo must be smaller than 2 MB.', 422);
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) { fail_error('upload_failed', 'The upload could not be verified.', 422); }
    $info = @getimagesize($tmp);
    $allowed = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'];
    if ($info === false || !isset($allowed[$info[2]])) {
        fail_error('upload_type', 'Logo must be a PNG, JPG or WebP image.', 422);
    }
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'tenant-branding';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        fail_error('storage_unavailable', 'Branding storage is unavailable.', 500);
    }
    $name = 'tenant-' . $tenantId . '-' . bin2hex(random_bytes(8)) . '.' . $allowed[$info[2]];
    if (!move_uploaded_file($tmp, $dir . DIRECTORY_SEPARATOR . $name)) {
        fail_error('upload_failed', 'Could not store the logo.', 500);
    }
    $url = '/assets/img/tenant-branding/' . $name;
    db()->prepare('INSERT INTO tenant_branding (tenant_id, logo_url, updated_by) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE logo_url = VALUES(logo_url), updated_by = VALUES(updated_by)')
        ->execute([$tenantId, $url, $userId]);
    audit('tenant.branding_logo_updated', 'tenant', (string) $tenantId, ['logo' => $url]);
    return tenant_branding($tenantId);
}

function tenant_branding_remove_logo(int $tenantId, int $userId): array
{
    $current = tenant_branding($tenantId);
    $url = (string) ($current['logo_url'] ?? '');
    if (str_starts_with($url, '/assets/img/tenant-branding/')) {
        $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($url, '/'));
        $real = realpath($path);
        $base = realpath(dirname(__DIR__) . '/assets/img/tenant-branding');
        if ($real && $base && str_starts_with($real, $base)) { @unlink($real); }
    }
    db()->prepare('UPDATE tenant_branding SET logo_url = NULL, updated_by = ? WHERE tenant_id = ?')->execute([$userId, $tenantId]);
    audit('tenant.branding_logo_removed', 'tenant', (string) $tenantId, []);
    return tenant_branding($tenantId);
}

function tenant_rate_cards(int $tenantId): array
{
    $stmt = db()->prepare('SELECT id, carrier_slug, route_from, route_to, size, base_fee_kes, holding_fee_kes,
            insurance_pct, platform_fee_kes, is_active, updated_at
        FROM tenant_rate_cards WHERE tenant_id = ? ORDER BY is_active DESC, id DESC');
    $stmt->execute([$tenantId]);
    return $stmt->fetchAll();
}

function tenant_rate_card_save(int $tenantId, array $data, int $id = 0): array
{
    $carrier = trim((string) ($data['carrier_slug'] ?? ''));
    if ($carrier !== '') {
        $stmt = db()->prepare('SELECT 1 FROM partners WHERE slug = ? LIMIT 1');
        $stmt->execute([$carrier]);
        if ($stmt->fetchColumn() === false) { fail_error('invalid_carrier', 'Choose a valid carrier.', 422); }
    }
    $from = mb_substr(trim((string) ($data['route_from'] ?? '')), 0, 120);
    $to = mb_substr(trim((string) ($data['route_to'] ?? '')), 0, 120);
    $size = mb_substr(trim((string) ($data['size'] ?? '')), 0, 40);
    $base = isset($data['base_fee_kes']) && $data['base_fee_kes'] !== '' ? (int) $data['base_fee_kes'] : null;
    $holding = isset($data['holding_fee_kes']) && $data['holding_fee_kes'] !== '' ? (int) $data['holding_fee_kes'] : null;
    $platform = isset($data['platform_fee_kes']) && $data['platform_fee_kes'] !== '' ? (int) $data['platform_fee_kes'] : null;
    $insurance = isset($data['insurance_pct']) && $data['insurance_pct'] !== '' ? (float) $data['insurance_pct'] : null;
    foreach ([$base, $holding, $platform] as $fee) {
        if ($fee !== null && $fee < 0) { fail_error('invalid_fee', 'Fees cannot be negative.', 422); }
    }
    if ($insurance !== null && ($insurance < 0 || $insurance > 100)) {
        fail_error('invalid_insurance', 'Insurance percentage must be between 0 and 100.', 422);
    }
    $active = !empty($data['is_active']) ? 1 : 0;
    if ($id > 0) {
        $stmt = db()->prepare('UPDATE tenant_rate_cards SET carrier_slug = ?, route_from = ?, route_to = ?, size = ?,
            base_fee_kes = ?, holding_fee_kes = ?, insurance_pct = ?, platform_fee_kes = ?, is_active = ?
            WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$carrier !== '' ? $carrier : null, $from !== '' ? $from : null, $to !== '' ? $to : null,
            $size !== '' ? $size : null, $base, $holding, $insurance, $platform, $active, $id, $tenantId]);
    } else {
        db()->prepare('INSERT INTO tenant_rate_cards (tenant_id, carrier_slug, route_from, route_to, size, base_fee_kes,
            holding_fee_kes, insurance_pct, platform_fee_kes, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$tenantId, $carrier !== '' ? $carrier : null, $from !== '' ? $from : null,
                $to !== '' ? $to : null, $size !== '' ? $size : null, $base, $holding, $insurance, $platform, $active]);
    }
    audit('tenant.rate_card_saved', 'tenant', (string) $tenantId, ['carrier' => $carrier, 'from' => $from, 'to' => $to, 'size' => $size]);
    return tenant_rate_cards($tenantId);
}

function tenant_rate_card_delete(int $tenantId, int $id): array
{
    db()->prepare('DELETE FROM tenant_rate_cards WHERE id = ? AND tenant_id = ?')->execute([$id, $tenantId]);
    audit('tenant.rate_card_deleted', 'tenant', (string) $tenantId, ['id' => $id]);
    return tenant_rate_cards($tenantId);
}

function tenant_rate_quote(int $tenantId, string $carrierSlug, string $from, string $to, string $size, int $valueKes): array
{
    $quote = quote_for($size, $valueKes);
    if ($tenantId <= 0) { return $quote; }
    try {
        $stmt = db()->prepare('SELECT * FROM tenant_rate_cards
            WHERE tenant_id = ? AND is_active = 1
              AND (carrier_slug IS NULL OR carrier_slug = ?)
              AND (route_from IS NULL OR route_from = ?)
              AND (route_to IS NULL OR route_to = ?)
              AND (size IS NULL OR size = ?)
            ORDER BY (carrier_slug IS NOT NULL) DESC,
              ((route_from IS NOT NULL) + (route_to IS NOT NULL)) DESC,
              (size IS NOT NULL) DESC, id DESC LIMIT 1');
        $stmt->execute([$tenantId, $carrierSlug, $from, $to, $size]);
        $card = $stmt->fetch();
        if ($card) {
            if ($card['base_fee_kes'] !== null) { $quote['base'] = (int) $card['base_fee_kes']; }
            if ($card['holding_fee_kes'] !== null) { $quote['holding'] = (int) $card['holding_fee_kes']; }
            if ($card['platform_fee_kes'] !== null) { $quote['platform'] = (int) $card['platform_fee_kes']; }
            if ($card['insurance_pct'] !== null) {
                $quote['insurance'] = max(30, (int) (round(($valueKes * (float) $card['insurance_pct'] / 100) / 10) * 10));
            }
            $quote['total'] = (int) (ceil(($quote['base'] + $quote['holding'] + $quote['insurance'] + $quote['platform']) / 10) * 10);
            $quote['rate_card_id'] = (int) $card['id'];
        }
    } catch (Throwable $e) {
        error_log('tenant rate quote failed: ' . $e->getMessage());
    }
    return $quote;
}
