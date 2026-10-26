<?php
/**
 * Platform-admin workspace logo update.
 *
 * POST multipart { action: upload|remove, tenant_id, logo?, _csrf }
 * Used during merchant KYC approval so admins can correct or supply the logo
 * that will appear on workspace-generated documents.
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_tenant_branding.php';

$admin = require_platform_admin();
require_post();
verify_csrf();
$action = (string) ($_POST['action'] ?? '');
$tenantId = (int) ($_POST['tenant_id'] ?? 0);
if ($tenantId <= 0) {
    fail_error('invalid_tenant', 'Choose a workspace.', 422);
}
$stmt = db()->prepare('SELECT id, name FROM tenants WHERE id = ? LIMIT 1');
$stmt->execute([$tenantId]);
if (!$stmt->fetch()) {
    fail_error('tenant_not_found', 'Workspace not found.', 404);
}

if ($action === 'upload') {
    if (!isset($_FILES['logo'])) {
        fail_error('upload_failed', 'Choose a logo image to upload.', 422);
    }
    $branding = tenant_branding_upload_logo($tenantId, $_FILES['logo'], (int) $admin['id']);
    json_out(['ok' => true, 'source' => 'mysql', 'branding' => $branding]);
}
if ($action === 'remove') {
    $branding = tenant_branding_remove_logo($tenantId, (int) $admin['id']);
    json_out(['ok' => true, 'source' => 'mysql', 'branding' => $branding]);
}
fail_error('unknown_action', 'Unknown logo action.', 400);
