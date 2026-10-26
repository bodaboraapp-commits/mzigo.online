<?php
/** GET /api/tenant-branding.php?slug=workspace-slug — public-safe tenant branding. */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_tenant_branding.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    fail_error('method_not_allowed', 'GET required.', 405);
}
$slug = strtolower(trim((string) ($_GET['slug'] ?? '')));
if ($slug === '' || !preg_match('/^[a-z0-9-]{2,80}$/', $slug)) {
    fail_error('invalid_slug', 'Pass a valid workspace slug.', 422);
}
rate_limit('tenant_branding:' . client_ip(), 120, 60);
$branding = tenant_branding_public_by_slug($slug);
if (!$branding) { fail_error('not_found', 'Workspace branding not found.', 404); }
json_out(['ok' => true, 'source' => 'mysql', 'branding' => $branding]);
