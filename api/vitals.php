<?php
/** POST /api/vitals.php — privacy-safe real-user Core Web Vitals collector. */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

require_post();
rate_limit('web_vitals:' . client_ip(), 120, 60);
$data = body_json();
$metrics = isset($data['metrics']) && is_array($data['metrics']) ? $data['metrics'] : [$data];
$allowed = ['LCP', 'INP', 'CLS', 'FCP', 'TTFB'];
$stored = 0;
$pagePath = mb_substr(trim((string) ($data['page_path'] ?? '/')), 0, 255);
$device = mb_substr(trim((string) ($data['device'] ?? '')), 0, 20);
$connection = mb_substr(trim((string) ($data['connection'] ?? '')), 0, 30);
foreach ($metrics as $item) {
    if (!is_array($item)) { continue; }
    $metric = strtoupper(trim((string) ($item['metric'] ?? '')));
    if (!in_array($metric, $allowed, true)) { continue; }
    if (!isset($item['value']) || !is_numeric($item['value'])) { continue; }
    $value = max(0, min(1000000, (float) $item['value']));
    $rating = in_array(($item['rating'] ?? ''), ['good', 'needs-improvement', 'poor'], true)
        ? (string) $item['rating'] : 'unknown';
    $path = mb_substr(trim((string) ($item['page_path'] ?? $pagePath)), 0, 255) ?: '/';
    db()->prepare('INSERT INTO web_vitals (metric, value, rating, page_path, device, connection_type, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())')
        ->execute([$metric, $value, $rating, $path, $device ?: null, $connection ?: null]);
    $stored++;
}
json_out(['ok' => true, 'stored' => $stored]);
