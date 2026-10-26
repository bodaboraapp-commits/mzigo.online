<?php
/**
 * Newsletter & product-alert prompt configuration and subscriber handling.
 *
 * Public subscribers are double opt-in. The prompt is intentionally disabled
 * until an admin enables it in Admin -> Website.
 */
declare(strict_types=1);

function newsletter_defaults(): array
{
    return [
        'enabled' => false,
        'pilot_percent' => 10,
        'channels' => ['email', 'sms', 'whatsapp'],
        'pages' => ['/', '/send-mzigo', '/send-errand'],
        'show_authenticated' => false,
        'triggers' => ['idle' => true, 'active' => true, 'abandoned' => true, 'exit' => true],
        'idle_seconds' => 20,
        'min_dwell_seconds' => 15,
        'active_seconds' => 45,
        'scroll_pct' => 60,
        'active_pageviews' => 3,
        'abandoned_seconds' => 30,
        'frequency_days' => 7,
        'dismiss_days' => 60,
        'title' => 'Get parcel tips and launch alerts',
        'body' => 'Join the Mzigo.online list for delivery tips, route updates and new services. No spam.',
        'cta' => 'Keep me posted',
        'dismiss' => 'Not now',
        'consent_label' => 'I agree to receive the Mzigo.online newsletter and product alerts. I can unsubscribe at any time.',
        'consent_version' => '2026-10-26',
        'artwork_url' => '',
        'lead_magnet_url' => '',
        'lead_magnet_label' => 'Get the free checklist',
    ];
}

function newsletter_channels(): array
{
    try {
        $stmt = db()->query('SELECT code, label, provider, is_active FROM communication_channels
            WHERE code IN ("email", "sms", "whatsapp") ORDER BY sort_order, code');
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $row) {
        $out[(string) $row['code']] = [
            'code' => (string) $row['code'],
            'label' => (string) $row['label'],
            'provider' => (string) $row['provider'],
            'active' => (int) $row['is_active'] === 1,
        ];
    }
    return $out;
}

function newsletter_config(): array
{
    $defaults = newsletter_defaults();
    $raw = platform_setting('newsletter_prompt_config', '');
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $defaults = array_replace_recursive($defaults, $decoded);
        }
    }
    return $defaults;
}

function newsletter_config_save(array $input): array
{
    $clean = array_replace_recursive(newsletter_config(), $input);
    $clean['enabled'] = !empty($clean['enabled']);
    $clean['show_authenticated'] = !empty($clean['show_authenticated']);
    $clean['pilot_percent'] = max(1, min(100, (int) ($clean['pilot_percent'] ?? 10)));
    $channels = [];
    $known = newsletter_channels();
    foreach ((array) ($clean['channels'] ?? []) as $code) {
        $code = strtolower(trim((string) $code));
        if (isset($known[$code])) { $channels[] = $code; }
    }
    if (!$channels) { $channels = ['email']; }
    $clean['channels'] = array_values(array_unique($channels));
    $pages = [];
    foreach ((array) ($clean['pages'] ?? []) as $page) {
        $page = trim((string) $page);
        if ($page === '') { continue; }
        $pages[] = mb_substr($page, 0, 120);
    }
    $clean['pages'] = $pages ?: ['/'];
    foreach (['idle_seconds' => [5, 300], 'min_dwell_seconds' => [3, 300], 'active_seconds' => [5, 600],
        'scroll_pct' => [10, 100], 'active_pageviews' => [1, 20], 'abandoned_seconds' => [5, 300],
        'frequency_days' => [1, 365], 'dismiss_days' => [1, 730]] as $key => $range) {
        $clean[$key] = max($range[0], min($range[1], (int) ($clean[$key] ?? $range[0])));
    }
    foreach (['idle', 'active', 'abandoned', 'exit'] as $trigger) {
        $clean['triggers'][$trigger] = !empty($clean['triggers'][$trigger]);
    }
    foreach (['title' => 120, 'body' => 600, 'cta' => 40, 'dismiss' => 40, 'consent_label' => 300,
        'consent_version' => 40] as $key => $max) {
        $clean[$key] = mb_substr(trim((string) ($clean[$key] ?? '')), 0, $max);
    }
    if ($clean['title'] === '') { $clean['title'] = newsletter_defaults()['title']; }
    if ($clean['body'] === '') { $clean['body'] = newsletter_defaults()['body']; }
    if ($clean['cta'] === '') { $clean['cta'] = newsletter_defaults()['cta']; }
    if ($clean['dismiss'] === '') { $clean['dismiss'] = newsletter_defaults()['dismiss']; }
    if ($clean['consent_label'] === '') { $clean['consent_label'] = newsletter_defaults()['consent_label']; }
    if ($clean['consent_version'] === '') { $clean['consent_version'] = newsletter_defaults()['consent_version']; }
    $artwork = trim((string) ($clean['artwork_url'] ?? ''));
    if ($artwork !== '' && !str_starts_with($artwork, '/assets/img/newsletter/')) {
        $artwork = (string) (newsletter_config()['artwork_url'] ?? '');
    }
    $clean['artwork_url'] = $artwork;
    $leadMagnet = trim((string) ($clean['lead_magnet_url'] ?? ''));
    $leadMagnet = mb_substr($leadMagnet, 0, 500);
    if ($leadMagnet !== '' && !preg_match('~^(https?://|/)~i', $leadMagnet)) { $leadMagnet = ''; }
    $clean['lead_magnet_url'] = $leadMagnet;
    $clean['lead_magnet_label'] = mb_substr(trim((string) ($clean['lead_magnet_label'] ?? '')), 0, 80);
    if ($clean['lead_magnet_label'] === '') { $clean['lead_magnet_label'] = newsletter_defaults()['lead_magnet_label']; }

    db()->prepare('INSERT INTO platform_settings (setting_key, setting_value) VALUES ("newsletter_prompt_config", ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
        ->execute([json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
    return $clean;
}

function newsletter_public_config(): array
{
    $cfg = newsletter_config();
    $selected = [];
    $all = newsletter_channels();
    foreach ((array) $cfg['channels'] as $code) {
        if (isset($all[$code]) && $all[$code]['active']) {
            $selected[] = ['code' => $code, 'label' => $all[$code]['label']];
        }
    }
    return [
        'enabled' => (bool) $cfg['enabled'],
        'pilot_percent' => (int) $cfg['pilot_percent'],
        'channels' => $selected,
        'pages' => array_values($cfg['pages']),
        'show_authenticated' => (bool) $cfg['show_authenticated'],
        'triggers' => $cfg['triggers'],
        'idle_seconds' => (int) $cfg['idle_seconds'],
        'min_dwell_seconds' => (int) $cfg['min_dwell_seconds'],
        'active_seconds' => (int) $cfg['active_seconds'],
        'scroll_pct' => (int) $cfg['scroll_pct'],
        'active_pageviews' => (int) $cfg['active_pageviews'],
        'abandoned_seconds' => (int) $cfg['abandoned_seconds'],
        'frequency_days' => (int) $cfg['frequency_days'],
        'dismiss_days' => (int) $cfg['dismiss_days'],
        'title' => (string) $cfg['title'],
        'body' => (string) $cfg['body'],
        'cta' => (string) $cfg['cta'],
        'dismiss' => (string) $cfg['dismiss'],
        'consent_label' => (string) $cfg['consent_label'],
        'consent_version' => (string) $cfg['consent_version'],
        'artwork_url' => (string) ($cfg['artwork_url'] ?? ''),
        'lead_magnet_url' => (string) ($cfg['lead_magnet_url'] ?? ''),
        'lead_magnet_label' => (string) ($cfg['lead_magnet_label'] ?? ''),
    ];
}

function newsletter_interests(array $input): string
{
    $allowed = ['newsletter', 'alerts'];
    $out = [];
    foreach ($input as $interest) {
        $interest = strtolower(trim((string) $interest));
        if (in_array($interest, $allowed, true) && !in_array($interest, $out, true)) { $out[] = $interest; }
    }
    return $out ? implode(',', $out) : 'newsletter,alerts';
}

function newsletter_token_hash(string $token): string
{
    return hash('sha256', $token);
}

function newsletter_unsub_token(int $id): string
{
    $key = (string) (config()['security']['app_key'] ?? 'mzigo');
    return substr(hash_hmac('sha256', 'mzigo-newsletter-unsub:' . $id, $key), 0, 40);
}

function newsletter_subscriber_by_token(string $token, bool $pendingOnly = true): ?array
{
    if ($token === '') { return null; }
    $stmt = db()->prepare('SELECT * FROM newsletter_subscribers WHERE token_hash = ? LIMIT 1');
    $stmt->execute([newsletter_token_hash($token)]);
    $row = $stmt->fetch();
    if (!$row) { return null; }
    if ($pendingOnly && ($row['status'] !== 'pending' || strtotime((string) $row['token_expires_at']) < time())) {
        return null;
    }
    return $row;
}

function newsletter_send_confirmation(array $row, string $confirmUrl, string $artworkUrl = '', string $leadMagnetUrl = '', string $leadMagnetLabel = ''): bool
{
    require_once __DIR__ . '/_notifications.php';
    $channels = newsletter_channels();
    $channel = (string) ($row['channel'] ?? 'email');
    if (!isset($channels[$channel]) || !$channels[$channel]['active']) { return false; }
    $user = [
        'id' => 0,
        'name' => 'Mzigo subscriber',
        'email' => (string) ($row['email'] ?? ''),
        'phone' => (string) ($row['phone'] ?? ''),
        'comm_channel' => null,
    ];
    $subject = 'Confirm your Mzigo.online updates';
    $body = "Hi,\n\nPlease confirm that you want to receive Mzigo.online news and alerts.\n\nConfirm: " . $confirmUrl . "\n\nThis link expires in 48 hours. If you did not request this, ignore this message.";
    try {
        notification_send($channel, $user, $subject, $body, 'newsletter_confirm', [], $channels[$channel]['provider'],
            'newsletter', (string) ($row['id'] ?? ''), ['confirm_url' => $confirmUrl, 'channel' => $channel,
                'artwork_url' => $artworkUrl, 'lead_magnet_url' => $leadMagnetUrl, 'lead_magnet_label' => $leadMagnetLabel]);
        return true;
    } catch (Throwable $e) {
        error_log('newsletter confirmation failed: ' . $e->getMessage());
        return false;
    }
}

function newsletter_save_artwork(array $file, int $userId): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        fail_error('upload_failed', 'Choose an image to upload.', 422);
    }
    if ((int) ($file['size'] ?? 0) <= 0 || (int) $file['size'] > 2 * 1024 * 1024) {
        fail_error('upload_too_large', 'Artwork must be smaller than 2 MB.', 422);
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) { fail_error('upload_failed', 'The upload could not be verified.', 422); }
    $info = @getimagesize($tmp);
    $allowed = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'];
    if ($info === false || !isset($allowed[$info[2]])) {
        fail_error('upload_type', 'Artwork must be a PNG, JPG or WebP image.', 422);
    }
    if (($info[0] ?? 0) < 300 || ($info[1] ?? 0) < 160 || ($info[0] ?? 0) > 3000 || ($info[1] ?? 0) > 3000) {
        fail_error('upload_dimensions', 'Artwork must be between 300 x 160 and 3000 x 3000 pixels.', 422);
    }
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'newsletter';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        fail_error('storage_unavailable', 'Artwork storage is unavailable.', 500);
    }
    $name = 'newsletter-' . date('YmdHis') . '-' . bin2hex(random_bytes(5)) . '.' . $allowed[$info[2]];
    if (!move_uploaded_file($tmp, $dir . DIRECTORY_SEPARATOR . $name)) {
        fail_error('upload_failed', 'Could not store the artwork.', 500);
    }
    $current = newsletter_config();
    $old = (string) ($current['artwork_url'] ?? '');
    $cfg = newsletter_config_save(array_replace($current, ['artwork_url' => '/assets/img/newsletter/' . $name]));
    if (str_starts_with($old, '/assets/img/newsletter/')) {
        $oldPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($old, '/'));
        $real = realpath($oldPath);
        $base = realpath(dirname(__DIR__) . '/assets/img/newsletter');
        if ($real && $base && str_starts_with($real, $base)) { @unlink($real); }
    }
    audit('admin.newsletter_artwork_uploaded', 'newsletter_prompt', 'artwork', ['file' => $name]);
    return $cfg;
}

function newsletter_remove_artwork(int $userId): array
{
    $cfg = newsletter_config();
    $old = (string) ($cfg['artwork_url'] ?? '');
    if (str_starts_with($old, '/assets/img/newsletter/')) {
        $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($old, '/'));
        $real = realpath($path);
        $base = realpath(dirname(__DIR__) . '/assets/img/newsletter');
        if ($real && $base && str_starts_with($real, $base)) { @unlink($real); }
    }
    $cfg = newsletter_config_save(array_replace($cfg, ['artwork_url' => '']));
    audit('admin.newsletter_artwork_removed', 'newsletter_prompt', 'artwork');
    return $cfg;
}

function newsletter_subscribe(array $data): array
{
    $cfg = newsletter_config();
    if (!$cfg['enabled']) {
        fail_error('disabled', 'Newsletter signup is not available right now.', 422);
    }
    $channel = strtolower(trim((string) ($data['channel'] ?? 'email')));
    $channels = newsletter_channels();
    if (!isset($channels[$channel]) || !$channels[$channel]['active'] || !in_array($channel, (array) $cfg['channels'], true)) {
        fail_error('invalid_channel', 'Choose a valid updates channel.', 422);
    }
    if (empty($data['consent'])) { fail('Please agree to receive these updates.', 422); }
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $phone = trim((string) ($data['phone'] ?? ''));
    if ($channel === 'email') {
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { fail('Enter a valid email address.', 422); }
    } else {
        if ($phone === '' || !phone_valid($phone)) { fail('Enter a valid Kenyan phone number.', 422); }
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { fail('Enter a valid email address.', 422); }
    if ($phone !== '' && !phone_valid($phone)) { fail('Enter a valid Kenyan phone number.', 422); }
    $interests = newsletter_interests((array) ($data['interests'] ?? []));
    $source = mb_substr(trim((string) ($data['source'] ?? 'landing-prompt')), 0, 40);
    $pagePath = mb_substr(trim((string) ($data['page_path'] ?? '')), 0, 255);
    $triggerSource = mb_substr(trim((string) ($data['trigger_source'] ?? '')), 0, 30);
    $consentVersion = mb_substr((string) $cfg['consent_version'], 0, 40);

    $stmt = db()->prepare('SELECT * FROM newsletter_subscribers
        WHERE (email IS NOT NULL AND email = ?) OR (phone IS NOT NULL AND phone = ?) LIMIT 1');
    $stmt->execute([$email !== '' ? $email : '__never__', $phone !== '' ? $phone : '__never__']);
    $existing = $stmt->fetch();
    if ($existing && $existing['status'] === 'confirmed') {
        return ['ok' => true, 'pending' => false, 'message' => 'You are already on the list. Thank you.'];
    }

    $token = bin2hex(random_bytes(32));
    $hash = newsletter_token_hash($token);
    $expires = date('Y-m-d H:i:s', time() + 172800);
    $ipHash = hash('sha256', client_ip() . '|' . (string) (config()['security']['app_key'] ?? 'mzigo'));
    $agent = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    if ($existing) {
        $stmt = db()->prepare('UPDATE newsletter_subscribers SET email = ?, phone = ?, channel = ?, interests = ?,
            status = "pending", source = ?, page_path = ?, trigger_source = ?, consent_version = ?,
            token_hash = ?, token_expires_at = ?, confirmed_at = NULL, unsubscribed_at = NULL,
            ip_hash = ?, user_agent = ? WHERE id = ?');
        $stmt->execute([$email ?: null, $phone ?: null, $channel, $interests, $source, $pagePath, $triggerSource,
            $consentVersion, $hash, $expires, $ipHash, $agent, (int) $existing['id']]);
        $id = (int) $existing['id'];
    } else {
        $stmt = db()->prepare('INSERT INTO newsletter_subscribers
            (email, phone, channel, interests, status, source, page_path, trigger_source, consent_version,
             token_hash, token_expires_at, ip_hash, user_agent, created_at)
            VALUES (?, ?, ?, ?, "pending", ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
        $stmt->execute([$email ?: null, $phone ?: null, $channel, $interests, $source, $pagePath, $triggerSource,
            $consentVersion, $hash, $expires, $ipHash, $agent]);
        $id = (int) db()->lastInsertId();
    }
    $row = ['id' => $id, 'email' => $email, 'phone' => $phone, 'channel' => $channel];
    $base = rtrim((string) (config()['app']['base_url'] ?? 'http://localhost:8090'), '/');
    $confirmUrl = $base . '/newsletter-confirm.php?token=' . rawurlencode($token);
    $artwork = (string) ($cfg['artwork_url'] ?? '');
    $leadMagnet = (string) ($cfg['lead_magnet_url'] ?? '');
    $leadMagnetLabel = (string) ($cfg['lead_magnet_label'] ?? '');
    $sent = newsletter_send_confirmation($row, $confirmUrl, $artwork !== '' ? $base . $artwork : '', $leadMagnet, $leadMagnetLabel);
    return [
        'ok' => true,
        'pending' => true,
        'sent' => $sent,
        'message' => $sent
            ? 'Check your ' . ($channel === 'email' ? 'inbox' : $channels[$channel]['label'] . ' messages') . ' to confirm your subscription.'
            : 'You are on the list. We could not send the confirmation right now; please try again later.',
    ];
}

function newsletter_confirm(string $token): ?array
{
    $row = newsletter_subscriber_by_token($token, true);
    if (!$row) { return null; }
    db()->prepare('UPDATE newsletter_subscribers SET status = "confirmed", confirmed_at = NOW(), token_hash = NULL,
        token_expires_at = NULL, unsubscribed_at = NULL WHERE id = ?')->execute([(int) $row['id']]);
    if (!empty($row['user_id'])) {
        $stmt = db()->prepare('INSERT INTO user_notification_preferences (user_id, email_enabled, parcel_updates, payment_receipts, claim_updates, marketing)
            VALUES (?, 1, 1, 1, 1, 1) ON DUPLICATE KEY UPDATE marketing = 1');
        $stmt->execute([(int) $row['user_id']]);
    }
    audit('newsletter.confirmed', 'newsletter_subscriber', (string) $row['id'], ['channel' => $row['channel']]);
    return $row;
}

function newsletter_unsubscribe(int $id, string $token): bool
{
    if ($id <= 0 || !hash_equals(newsletter_unsub_token($id), $token)) { return false; }
    $stmt = db()->prepare('UPDATE newsletter_subscribers SET status = "unsubscribed", unsubscribed_at = NOW(),
        token_hash = NULL, token_expires_at = NULL WHERE id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() < 1) { return false; }
    $row = db()->query('SELECT user_id FROM newsletter_subscribers WHERE id = ' . $id)->fetch();
    if ($row && !empty($row['user_id'])) {
        db()->prepare('UPDATE user_notification_preferences SET marketing = 0 WHERE user_id = ?')->execute([(int) $row['user_id']]);
    }
    audit('newsletter.unsubscribed', 'newsletter_subscriber', (string) $id);
    return true;
}

function newsletter_admin_payload(): array
{
    $cfg = newsletter_config();
    $counts = [];
    try {
        foreach (db()->query('SELECT status, COUNT(*) n FROM newsletter_subscribers GROUP BY status')->fetchAll() as $row) {
            $counts[$row['status']] = (int) $row['n'];
        }
        $recent = db()->query('SELECT id, email, phone, channel, interests, status, source, trigger_source,
            consent_version, confirmed_at, unsubscribed_at, created_at
            FROM newsletter_subscribers ORDER BY id DESC LIMIT 50')->fetchAll();
    } catch (Throwable $e) {
        error_log('newsletter admin payload failed: ' . $e->getMessage());
        $recent = [];
    }
    return [
        'config' => $cfg,
        'channels' => array_values(newsletter_channels()),
        'counts' => ['pending' => (int) ($counts['pending'] ?? 0), 'confirmed' => (int) ($counts['confirmed'] ?? 0),
            'unsubscribed' => (int) ($counts['unsubscribed'] ?? 0), 'total' => array_sum($counts)],
        'recent' => $recent,
    ];
}

function newsletter_export_csv(): void
{
    $stmt = db()->query('SELECT id, email, phone, channel, interests, status, source, page_path, trigger_source,
        consent_version, confirmed_at, unsubscribed_at, created_at FROM newsletter_subscribers ORDER BY id DESC');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="mzigo-newsletter-subscribers-' . date('Ymd-Hi') . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Email', 'Phone', 'Channel', 'Interests', 'Status', 'Source', 'Page', 'Trigger', 'Consent version', 'Confirmed at', 'Unsubscribed at', 'Created at']);
    foreach ($stmt->fetchAll() as $row) {
        fputcsv($out, [$row['id'], $row['email'], $row['phone'], $row['channel'], $row['interests'], $row['status'],
            $row['source'], $row['page_path'], $row['trigger_source'], $row['consent_version'],
            $row['confirmed_at'], $row['unsubscribed_at'], $row['created_at']]);
    }
    fclose($out);
}

function newsletter_html_page(string $title, string $message, bool $ok = true, string $ctaUrl = '', string $ctaLabel = ''): string
{
    $color = $ok ? '#3c5223' : '#9f2d20';
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow"><title>' . $safeTitle . ' | Mzigo.online</title>'
        . '<style>body{font-family:Outfit,Segoe UI,sans-serif;background:#eef5e6;color:#26331a;margin:0;padding:32px;}'
        . '.card{max-width:560px;margin:24px auto;background:#fff;border:1px solid #dfe8d3;border-radius:18px;padding:28px;}'
        . 'h1{font-size:24px;margin:0 0 12px;color:' . $color . ';}p{font-size:14px;line-height:1.65;color:#46523a;}'
        . 'a{color:#3c5223;font-weight:700;} .brand{font-weight:800;font-size:18px;color:#3c5223;margin-bottom:16px;}.cta{display:inline-block;background:#3c5223;color:#fff;padding:11px 18px;border-radius:999px;text-decoration:none;}'
        . '</style></head><body><div class="card"><div class="brand">Mzigo.online</div><h1>' . $safeTitle . '</h1><p>'
        . $safeMessage . '</p>' .
        ($ctaUrl !== '' ? '<p><a class="cta" href="' . htmlspecialchars($ctaUrl, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($ctaLabel !== '' ? $ctaLabel : 'Continue', ENT_QUOTES, 'UTF-8') . '</a></p>' : '') .
        '<p><a href="/">Back to Mzigo.online</a></p></div></body></html>';
}
