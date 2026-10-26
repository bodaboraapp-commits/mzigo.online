<?php
/**
 * Mzigo.online — public website CMS payload.
 *
 * GET -> active FAQs (landing-page accordion), current brand assets and the
 *        configured support address. Edited from the admin console (Website).
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_cms.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    fail('Use GET for the public CMS payload.', 405);
}

json_out([
    'ok' => true,
    'source' => 'mysql',
    'faqs' => cms_faqs(true),
    'branding' => cms_branding(),
    'support_email' => (string) (config()['app']['support_email'] ?? 'hello@mzigo.online'),
]);
