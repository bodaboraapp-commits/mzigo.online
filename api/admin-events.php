<?php
/** Mzigo.online — admin view of CTA/funnel analytics events. */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_platform_admin();

$days = 7;
$counts = db()->query(
    "SELECT event, COUNT(*) AS c, SUM(user_id IS NOT NULL) AS signed_in
       FROM analytics_events
      WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
      GROUP BY event ORDER BY c DESC"
)->fetchAll();
$recent = db()->query(
    "SELECT event, label, path, location, audience, ip, geo_country, geo_city, created_at
       FROM analytics_events
      WHERE event = 'cta_click'
      ORDER BY id DESC LIMIT 25"
)->fetchAll();
json_out([
    'ok' => true,
    'days' => $days,
    'counts' => array_map(fn ($r) => [
        'event' => (string) $r['event'],
        'count' => (int) $r['c'],
        'signed_in' => (int) $r['signed_in'],
    ], $counts),
    'recent' => array_map(fn ($r) => [
        'label' => (string) ($r['label'] ?? ''),
        'path' => (string) ($r['path'] ?? ''),
        'location' => (string) ($r['location'] ?? ''),
        'audience' => (string) ($r['audience'] ?? ''),
        'ip' => (string) ($r['ip'] ?? ''),
        'geo_country' => (string) ($r['geo_country'] ?? ''),
        'geo_city' => (string) ($r['geo_city'] ?? ''),
        'created_at' => $r['created_at'],
    ], $recent),
]);
