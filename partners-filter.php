<?php
/**
 * /partners/{slug} — SEO landing page for a single courier partner.
 *
 * Reuses the static partner directory shell, replaces the main content with a
 * partner-specific route list and sets canonical/meta/schema for the filter page.
 */
declare(strict_types=1);
$config = require __DIR__ . '/_server/config.php';
$slug = strtolower(trim((string) ($_GET['slug'] ?? '')));
if (!preg_match('/^[a-z0-9-]{2,60}$/', $slug)) {
    http_response_code(404);
    include __DIR__ . '/404.html';
    exit;
}
$pdo = new PDO($config['db']['dsn'], $config['db']['user'], $config['db']['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$stmt = $pdo->prepare('SELECT slug, name FROM partners WHERE slug = ? LIMIT 1');
$stmt->execute([$slug]);
$partner = $stmt->fetch();
if (!$partner) {
    http_response_code(404);
    include __DIR__ . '/404.html';
    exit;
}
$stmt = $pdo->prepare('SELECT name FROM destinations WHERE partner_slug = ? ORDER BY name ASC');
$stmt->execute([$slug]);
$destinations = array_map(fn ($r) => (string) $r['name'], $stmt->fetchAll());
$origin = in_array('Nairobi', $destinations, true) ? 'Nairobi' : ($destinations[0] ?? 'Kenya');
$name = (string) $partner['name'];
$title = 'Send a Parcel with ' . $name . ' | Routes & Tracking | Mzigo.online';
$desc = 'Compare ' . $name . ' parcel routes in Kenya. Register online, drop off at a partner office, track every scan and pay on collection by M-Pesa with Mzigo.online.';
$canonical = 'https://mzigo.online/partners/' . rawurlencode($slug);
$routeTokens = implode(', ', array_slice($destinations, 0, 12));
$routeCards = '';
foreach ($destinations as $destination) {
    if (mb_strlen($destination) < 2) { continue; }
    $routeCards .= '<a class="mz-partner-route" href="/send-mzigo?carrier=' . rawurlencode($slug) . '&amp;from=' . rawurlencode($origin) . '&amp;to=' . rawurlencode($destination) . '">' . htmlspecialchars($destination, ENT_QUOTES, 'UTF-8') . '<span aria-hidden="true">→</span></a>';
}
$content = '
<div class="flex flex-col gap-6">
  <div class="mz-saas-head">
    <span class="mz-cap">Courier partner</span>
    <h1>Send a parcel with ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</h1>
    <p>Book ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ' parcel delivery across Kenya through Mzigo.online. Compare routes, register online, drop off at a partner office and track every scan to collection.</p>
  </div>
  <div class="mz-saas-card">
    <div class="mz-card__title">Routes served by ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</div>
    <p class="mz-hint">Choose a destination to start your booking. ' . count($destinations) . ' destinations available.</p>
    <div class="mz-partner-routes">' . ($routeCards ?: '<p class="mz-hint">Routes are being updated. Browse all partners to find another route.</p>') . '</div>
  </div>
  <div class="mz-saas-grid mz-saas-grid--2">
    <div class="mz-saas-card">
      <div class="mz-card__title">Why send with Mzigo.online?</div>
      <ul class="mz-check-list">
        <li>Compare trusted courier partners and routes</li>
        <li>Parcel insurance based on the declared value</li>
        <li>Live scan tracking from drop-off to collection</li>
        <li>M-Pesa pay on collection for receivers</li>
      </ul>
      <div class="mz-row" style="margin-top:14px;gap:10px;justify-content:flex-start;">
        <a class="mz-btn" href="/send-mzigo?carrier=' . rawurlencode($slug) . '">Send with ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</a>
        <a class="mz-btn mz-btn--outline" href="/partners">All partners</a>
      </div>
    </div>
    <div class="mz-saas-card">
      <div class="mz-card__title">Need help choosing a route?</div>
      <p class="mz-hint">Talk to our team about the best partner for your origin, destination and parcel size. Nationwide coverage with live scan tracking and M-Pesa collection.</p>
      <a class="mz-link" href="/contact">Contact support →</a>
    </div>
  </div>
</div>';
$template = (string) file_get_contents(__DIR__ . '/partners.html');
$template = preg_replace('/<title>.*?<\/title>/is', '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>', $template, 1) ?: $template;
$doc = new DOMDocument('1.0', 'UTF-8');
libxml_use_internal_errors(true);
$doc->loadHTML('<?xml encoding="utf-8" ?>' . $template);
libxml_clear_errors();
$xp = new DOMXPath($doc);
foreach ($xp->query('//title') as $node) { $node->nodeValue = $title; }
$metaMap = [
    'description' => $desc,
    'og:title' => $title,
    'og:description' => $desc,
    'og:url' => $canonical,
    'twitter:title' => $title,
    'twitter:description' => $desc,
];
foreach ($xp->query('//meta') as $meta) {
    $key = $meta->getAttribute('name') ?: $meta->getAttribute('property');
    if (isset($metaMap[$key])) { $meta->setAttribute('content', $metaMap[$key]); }
}
foreach ($xp->query('//link[@rel="canonical"]') as $link) { $link->setAttribute('href', $canonical); }
$contentNode = $xp->query("//div[contains(@class,'bg-background') and contains(@class,'text-foreground') and contains(@class,'mx-auto')]")->item(0);
if ($contentNode) {
    while ($contentNode->firstChild) { $contentNode->removeChild($contentNode->firstChild); }
    $fragment = $doc->createDocumentFragment();
    $fragment->appendXML($content);
    $contentNode->appendChild($fragment);
}
$schema = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://mzigo.online/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Partners', 'item' => 'https://mzigo.online/partners'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => $name, 'item' => $canonical],
    ],
];
$script = $doc->createElement('script');
$script->setAttribute('type', 'application/ld+json');
$script->appendChild($doc->createTextNode(json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
$doc->getElementsByTagName('head')->item(0)->appendChild($script);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=300');
$output = str_replace('<?xml encoding="utf-8" ?>', '', $doc->saveHTML());
$output = preg_replace('/<title>.*?<\/title>/is', '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>', $output, 1) ?: $output;
echo $output;
