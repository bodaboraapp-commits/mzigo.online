<?php
/**
 * Mzigo.online — website CMS helpers (FAQs + brand assets).
 *
 * Shared by the public api/cms.php payload and the admin console endpoints.
 */
declare(strict_types=1);

function cms_faqs(bool $activeOnly = false): array
{
    $sql = 'SELECT id, question, answer, sort_order, is_active FROM faqs'
        . ($activeOnly ? ' WHERE is_active = 1' : '')
        . ' ORDER BY sort_order ASC, id ASC';
    $rows = db()->query($sql)->fetchAll();
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['sort_order'] = (int) $row['sort_order'];
        $row['is_active'] = (bool) (int) $row['is_active'];
    }
    return $rows;
}

function cms_social_catalog(): array
{
    return [
        'instagram' => ['label' => 'Instagram', 'placeholder' => '@mzigo.online or https://instagram.com/mzigo.online'],
        'facebook' => ['label' => 'Facebook', 'placeholder' => 'mzigo.online or https://facebook.com/mzigo.online'],
        'x' => ['label' => 'X (Twitter)', 'placeholder' => '@mzigo_online or https://x.com/mzigo_online'],
        'linkedin' => ['label' => 'LinkedIn', 'placeholder' => 'mzigo-online or full LinkedIn URL'],
        'tiktok' => ['label' => 'TikTok', 'placeholder' => '@mzigo.online or full TikTok URL'],
        'whatsapp' => ['label' => 'WhatsApp', 'placeholder' => '+254 800 211 211 or full wa.me URL'],
    ];
}

function cms_social_defaults(): array
{
    return [
        'instagram' => 'https://www.instagram.com/mzigo.online',
        'facebook' => 'https://www.facebook.com/mzigo.online',
        'x' => 'https://x.com/mzigo_online',
        'linkedin' => 'https://www.linkedin.com/company/mzigo-online',
    ];
}

function cms_social_normalize(string $network, string $value): string
{
    $value = trim($value);
    if ($value === '') { return ''; }
    if (mb_strlen($value) > 255) { fail_error('social_too_long', 'Social link is too long.', 422); }
    if (preg_match('#^https?://#i', $value)) {
        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            fail_error('invalid_social_url', 'Enter a valid http(s) social media URL.', 422);
        }
        return $value;
    }
    $handle = ltrim($value, '@');
    if ($network === 'whatsapp') {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) < 9) { fail_error('invalid_social_id', 'Enter a WhatsApp number or full wa.me URL.', 422); }
        return 'https://wa.me/' . $digits;
    }
    if (!preg_match('/^[A-Za-z0-9._-]{1,80}$/', $handle)) {
        fail_error('invalid_social_id', 'Enter a social handle, ID or full URL.', 422);
    }
    $handle = rawurlencode($handle);
    return match ($network) {
        'instagram' => 'https://www.instagram.com/' . $handle,
        'facebook' => 'https://www.facebook.com/' . $handle,
        'x' => 'https://x.com/' . $handle,
        'linkedin' => 'https://www.linkedin.com/company/' . $handle,
        'tiktok' => 'https://www.tiktok.com/@' . $handle,
        default => fail_error('invalid_social_network', 'Unknown social network.', 422),
    };
}

function cms_social_values(): array
{
    $defaults = cms_social_defaults();
    $out = [];
    foreach (cms_social_catalog() as $key => $meta) {
        $stored = platform_setting('social_' . $key, null);
        $out[$key] = $stored === null ? (string) ($defaults[$key] ?? '') : (string) $stored;
    }
    return $out;
}

function cms_social_links(): array
{
    $out = [];
    foreach (cms_social_values() as $key => $url) {
        if (trim($url) !== '') { $out[$key] = $url; }
    }
    return $out;
}

function cms_branding(): array
{
    $logo = (string) (platform_setting('brand_logo_url', '') ?? '');
    $favicon = (string) (platform_setting('brand_favicon_url', '') ?? '');
    $hero = (string) (platform_setting('brand_hero_url', '') ?? '');
    $heroTitle = (string) (platform_setting('hero_title', '') ?? '');
    $heroSubtitle = (string) (platform_setting('hero_subtitle', '') ?? '');
    return [
        'logo_url' => $logo !== '' ? $logo : '/assets/img/mzigo-logo.png',
        'favicon_url' => $favicon !== '' ? $favicon : '/assets/img/favicon.ico',
        'hero_url' => $hero !== '' ? $hero : '/assets/img/delivery.jpg',
        'hero_title' => $heroTitle !== '' ? $heroTitle : 'Send or Track Mzigo',
        'hero_subtitle' => $heroSubtitle !== '' ? $heroSubtitle : 'Your trusted package delivery and tracking solution',
        'custom_logo' => $logo !== '',
        'custom_favicon' => $favicon !== '',
        'custom_hero' => $hero !== '',
        'custom_hero_copy' => $heroTitle !== '' || $heroSubtitle !== '',
        'social' => cms_social_links(),
        'social_values' => cms_social_values(),
        'social_catalog' => cms_social_catalog(),
        'version' => (int) ((platform_setting('brand_version', '0') ?? '0')),
        'max_bytes' => 2 * 1024 * 1024,
    ];
}

/** Fit an uploaded logo into a 150×55 transparent PNG (the bundled slot size). */
function cms_seo_settings(): array
{
    return [
        'search_console_token' => trim((string) (platform_setting('seo_search_console_token', '') ?? '')),
        'bing_token' => trim((string) (platform_setting('seo_bing_token', '') ?? '')),
        'cwv_api_key' => trim((string) (platform_setting('seo_cwv_api_key', '') ?? '')),
    ];
}

/** Real-user Core Web Vitals summary for the admin dashboard. */
function cms_web_vitals_summary(): array
{
    try {
        $rows = db()->query('SELECT metric, value, rating, page_path, created_at FROM web_vitals
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY id DESC LIMIT 5000')->fetchAll();
    } catch (Throwable $e) {
        return ['metrics' => [], 'samples' => 0];
    }
    $byMetric = [];
    foreach ($rows as $row) {
        $byMetric[$row['metric']][] = (float) $row['value'];
    }
    $metrics = [];
    foreach (['LCP', 'INP', 'CLS', 'FCP', 'TTFB'] as $metric) {
        $values = $byMetric[$metric] ?? [];
        if (!$values) { continue; }
        sort($values);
        $index = (int) floor(0.75 * (count($values) - 1));
        $metrics[] = ['metric' => $metric, 'p75' => round($values[$index], 3), 'samples' => count($values)];
    }
    return ['metrics' => $metrics, 'samples' => count($rows)];
}

function cms_fit_logo(string $source, string $target): bool
{
    if (!function_exists('imagecreatetruecolor')) {
        return @copy($source, $target);
    }
    $data = @file_get_contents($source);
    if ($data === false) {
        return false;
    }
    $src = @imagecreatefromstring($data);
    if (!$src) {
        return false;
    }
    $srcW = imagesx($src);
    $srcH = imagesy($src);
    /* Logos are fitted into the same 150:55 slot as the bundled wordmark so
       switching logo never reflows the header or footer. */
    $canvasW = 150;
    $canvasH = 55;
    $scale = min($canvasW / max(1, $srcW), $canvasH / max(1, $srcH));
    $dstW = max(1, (int) round($srcW * $scale));
    $dstH = max(1, (int) round($srcH * $scale));
    $dstX = (int) round(($canvasW - $dstW) / 2);
    $dstY = (int) round(($canvasH - $dstH) / 2);

    $canvas = imagecreatetruecolor($canvasW, $canvasH);
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
    imagefilledrectangle($canvas, 0, 0, $canvasW - 1, $canvasH - 1, $transparent);
    imagealphablending($canvas, true);
    imagecopyresampled($canvas, $src, $dstX, $dstY, 0, 0, $dstW, $dstH, $srcW, $srcH);

    $ok = imagepng($canvas, $target, 6);
    imagedestroy($canvas);
    imagedestroy($src);
    return (bool) $ok;
}

/** Store/replace a brand asset from an uploaded file ($_FILES entry). */
function cms_store_upload(string $kind, array $file): array
{
    $slots = [
        'logo' => ['brand_logo_url', 'Logo'],
        'favicon' => ['brand_favicon_url', 'Favicon'],
        'hero' => ['brand_hero_url', 'Hero image'],
    ];
    if (!isset($slots[$kind])) {
        fail_error('invalid_kind', 'Unknown brand asset.', 422);
    }
    [$settingKey, $label] = $slots[$kind];

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $reason = [
            UPLOAD_ERR_INI_SIZE => 'The file is larger than the server allows.',
            UPLOAD_ERR_FORM_SIZE => 'The file is too large.',
            UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Try again.',
            UPLOAD_ERR_NO_FILE => 'Choose a file to upload.',
        ][$file['error'] ?? UPLOAD_ERR_NO_FILE] ?? 'The upload failed. Try again.';
        fail_error('upload_failed', $reason, 422);
    }
    if (($file['size'] ?? 0) <= 0 || (int) $file['size'] > 2 * 1024 * 1024) {
        fail_error('upload_too_large', $label . ' must be smaller than 2 MB.', 422);
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        fail_error('upload_failed', 'The upload could not be verified.', 422);
    }

    $name = strtolower((string) ($file['name'] ?? ''));
    $ext = pathinfo($name, PATHINFO_EXTENSION);
    $allowedByKind = [
        'logo' => ['png', 'jpg', 'jpeg', 'webp'],
        'favicon' => ['ico', 'png'],
        'hero' => ['png', 'jpg', 'jpeg', 'webp'],
    ];
    $allowed = $allowedByKind[$kind];
    if (!in_array($ext, $allowed, true)) {
        fail_error('upload_type', $label . ' must be one of: ' . implode(', ', $allowed) . '.', 422);
    }

    /* Content sniffing — every upload must be a real raster image. */
    $info = @getimagesize($tmp);
    if ($info === false || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
        fail_error('upload_type', 'That file is not a valid image.', 422);
    }

    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'branding';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        fail_error('upload_failed', 'The branding folder is not writable.', 500);
    }
    if (!is_writable($dir)) {
        fail_error('upload_failed', 'The branding folder is not writable.', 500);
    }

    /* Logos are fitted into the same 150:55 slot as the bundled wordmark (at
       2× size) so switching logo never reflows the header or footer. */
    if ($kind === 'logo') {
        $filename = 'logo-' . bin2hex(random_bytes(6)) . '.png';
        $target = $dir . DIRECTORY_SEPARATOR . $filename;
        if (!cms_fit_logo($tmp, $target)) {
            fail_error('upload_failed', 'Could not process the logo image.', 500);
        }
    } else {
        $filename = $kind . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
        $target = $dir . DIRECTORY_SEPARATOR . $filename;
        if (!@move_uploaded_file($tmp, $target)) {
            fail_error('upload_failed', 'Could not save the uploaded file.', 500);
        }
    }
    @chmod($target, 0644);

    /* Remove the previously uploaded file for this slot. */
    $previous = (string) (platform_setting($settingKey, '') ?? '');
    if (strncmp($previous, '/assets/img/branding/', 21) === 0) {
        $oldPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($previous, '/'));
        if (is_file($oldPath)) {
            @unlink($oldPath);
        }
    }

    $url = '/assets/img/branding/' . $filename;
    db()->prepare('INSERT INTO platform_settings (setting_key, setting_value) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')->execute([$settingKey, $url]);
    db()->prepare('INSERT INTO platform_settings (setting_key, setting_value) VALUES ("brand_version", ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
        ->execute([(string) time()]);

    return ['kind' => $kind, 'url' => $url, 'filename' => $filename];
}

/** Remove custom brand assets and fall back to the bundled defaults. */
function cms_reset_branding(): void
{
    foreach (['brand_logo_url', 'brand_favicon_url', 'brand_hero_url'] as $key) {
        $previous = (string) (platform_setting($key, '') ?? '');
        if (strncmp($previous, '/assets/img/branding/', 21) === 0) {
            $oldPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($previous, '/'));
            if (is_file($oldPath)) {
                @unlink($oldPath);
            }
        }
    }
    db()->prepare('UPDATE platform_settings SET setting_value = "" WHERE setting_key IN ("brand_logo_url","brand_favicon_url","brand_hero_url")')->execute();
    db()->prepare('INSERT INTO platform_settings (setting_key, setting_value) VALUES ("brand_version", ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
        ->execute([(string) time()]);
}
