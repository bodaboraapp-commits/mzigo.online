<?php
/**
 * Pricing page gate.
 *
 * The pricing page is always reachable, but in free mode the plan/pricing
 * sections are hidden (data-mz-plans-only) and the page shows a clear
 * "nothing is charged right now" state instead. Paid mode serves the full page.
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
header('Cache-Control: no-store');

$page = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'pricing.html';
if (!is_file($page)) {
    http_response_code(404);
    exit('Not found');
}
$html = file_get_contents($page);

if (pricing_mode() === 'free') {
    $html = preg_replace('/<body\b/', '<body data-pricing-free="1"', $html, 1);
    $html = str_replace(
        '</head>',
        '<style>body[data-pricing-free] [data-mz-plans-only]{display:none!important;}</style></head>',
        $html
    );
    $freeTitle = 'Mzigo.online Is Free to Use Right Now';
    $freeDesc = 'Mzigo.online is free to use while we onboard courier partners. Send, track and manage parcels across Kenya without a subscription; delivery fees are paid at collection.';
    $html = preg_replace('/<title>.*?<\/title>/s', '<title>' . $freeTitle . '</title>', $html, 1);
    $html = preg_replace('/(<meta name="description" content=").*?(">)/s', '${1}' . $freeDesc . '${2}', $html, 1);
    $html = preg_replace('/(<meta property="og:title" content=").*?(">)/s', '${1}' . $freeTitle . '${2}', $html, 1);
    $html = preg_replace('/(<meta property="og:description" content=").*?(">)/s', '${1}' . $freeDesc . '${2}', $html, 1);
    $html = preg_replace('/(<meta name="twitter:title" content=").*?(">)/s', '${1}' . $freeTitle . '${2}', $html, 1);
    $html = preg_replace('/(<meta name="twitter:description" content=").*?(">)/s', '${1}' . $freeDesc . '${2}', $html, 1);
    $html = str_replace('<span class="mz-cap">Pricing</span>', '<span class="mz-cap">Free to use</span>', $html);
    $html = str_replace('<h1>Simple pricing that grows with your business</h1>', '<h1>Mzigo.online is free to use right now</h1>', $html);
    $html = str_replace(
        '<p>Pick a plan by monthly parcels and team size. Every plan includes tracking, QR labels, saved senders and M-Pesa pay on collection.</p>',
        '<p>No subscription is charged while we onboard courier partners. Delivery fees are quoted per parcel and paid at collection.</p>',
        $html
    );
}

header('Content-Type: text/html; charset=utf-8');
echo $html;
