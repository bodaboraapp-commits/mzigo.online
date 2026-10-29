<?php
/**
 * Mzigo.online — public CTA/funnel analytics intake.
 *
 * POST /api/events.php  { event, path, label, location, audience, meta }
 *
 * Fire-and-forget from the browser (sendBeacon/fetch keepalive). Rate limited;
 * stores the visitor IP for admin review, Cloudflare geo country/city when
 * available, an IP hash, and the signed-in user id when present.
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

require_post();

$data = body_json();
$event = (string) ($data['event'] ?? '');
$allowed = [
    'cta_click', 'page_view', 'earn_page_view', 'runner_join_start', 'runner_phone_submitted',
    'runner_pin_verified', 'runner_onboarding_complete', 'availability_on', 'job_view',
    'job_accepted', 'job_declined', 'job_completed', 'payout_requested', 'track_search',
    'receiver_signin', 'customer_pay', 'support_click', 'collection_code_copy',
    'referral_variant_exposure', 'referral_share',
    'newsletter_prompt_impression', 'newsletter_prompt_dismiss', 'newsletter_prompt_submit',
];
if (!in_array($event, $allowed, true)) {
    fail_error('invalid_event', 'Unknown analytics event.', 422);
}

rate_limit('events:' . client_ip(), 240, 60);

$user = current_user();
$path = mb_substr((string) ($data['path'] ?? ($_SERVER['REQUEST_URI'] ?? '')), 0, 255);
$label = isset($data['label']) && is_scalar($data['label']) ? mb_substr(trim((string) $data['label']), 0, 160) : null;
$location = isset($data['location']) && is_scalar($data['location']) ? mb_substr(trim((string) $data['location']), 0, 120) : null;
$audience = isset($data['audience']) && is_scalar($data['audience']) ? mb_substr(trim((string) $data['audience']), 0, 30) : null;
$meta = null;
if (isset($data['meta']) && is_array($data['meta'])) {
    $meta = json_encode($data['meta'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (strlen($meta) > 2000) {
        $meta = substr($meta, 0, 2000);
    }
}

// Prefer the real visitor address when the request passes through Cloudflare.
$ip = (string) client_ip();
$cfIp = trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
if ($cfIp !== '' && filter_var($cfIp, FILTER_VALIDATE_IP)) {
    $ip = $cfIp;
}
$geoCountry = strtoupper(trim((string) ($_SERVER['HTTP_CF_IPCOUNTRY'] ?? '')));
$geoCountry = preg_match('/^[A-Z]{2}$/', $geoCountry) ? $geoCountry : null;
$geoCity = mb_substr(trim((string) ($_SERVER['HTTP_CF_IPCITY'] ?? '')), 0, 80);
$geoCity = $geoCity !== '' ? $geoCity : null;
$ipHash = hash('sha256', $ip . '|' . (string) (config()['security']['app_key'] ?? 'mzigo'));

try {
    $stmt = db()->prepare(
        'INSERT INTO analytics_events (event, user_id, path, label, location, audience, meta, ip, geo_country, geo_city, ip_hash, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
    );
    $stmt->execute([
        $event,
        $user ? (int) $user['id'] : null,
        $path,
        $label,
        $location,
        $audience,
        $meta,
        $ip !== '' ? $ip : null,
        $geoCountry,
        $geoCity,
        $ipHash,
    ]);
} catch (Throwable $e) {
    error_log('analytics event failed: ' . $e->getMessage());
}

json_out(['ok' => true], 202);
