<?php
/** POST /api/claim-upload.php — multipart evidence upload for a claim. */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_claims.php';
require __DIR__ . '/_malware.php';

require_post();
verify_csrf();
$user = require_user();
rate_limit('claim_upload:' . $user['id'] . ':' . client_ip(), 20, 3600);
$claimId = (int) ($_POST['claim_id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM claims WHERE id = ? LIMIT 1');
$stmt->execute([$claimId]);
$claim = $stmt->fetch();
if (!$claim) {
    fail_error('not_found', 'Claim not found.', 404);
}
$tenant = current_tenant();
$isAdmin = ($user['role'] ?? '') === 'platform_admin';
$isManager = $tenant && in_array($tenant['role'], ['owner', 'admin'], true) && (int) $tenant['id'] === (int) $claim['tenant_id'];
$isCustomerClaimant = ($user['role'] ?? '') === 'customer' && !empty($claim['user_id']) && (int) $claim['user_id'] === (int) $user['id'];
if (!$isAdmin && !$isManager && !$isCustomerClaimant) {
    fail_error('forbidden', 'Only the workspace owner/admin or the customer who filed the claim can upload evidence.', 403);
}
$countStmt = db()->prepare('SELECT COUNT(*) FROM claim_attachments WHERE claim_id = ?');
$countStmt->execute([$claimId]);
if ((int) $countStmt->fetchColumn() >= 5) {
    fail_error('attachment_limit', 'You can upload up to 5 evidence files per claim.', 422);
}
if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    fail_error('upload_failed', 'Choose a file to upload.', 422);
}
$file = $_FILES['file'];
if ((int) $file['size'] > 5 * 1024 * 1024) {
    fail_error('file_too_large', 'Evidence files must be 5MB or smaller.', 422);
}
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string) $finfo->file($file['tmp_name']);
$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
if (!isset($allowed[$mime])) {
    fail_error('invalid_file_type', 'Only JPG, PNG or PDF evidence files are allowed.', 422);
}
$scan = malware_scan_file((string) $file['tmp_name'], $mime, (string) $file['name'], (int) $file['size']);
if (empty($scan['clean'])) {
    try {
        db()->prepare('INSERT INTO claim_attachment_scans (claim_id, uploaded_by, filename, mime, size_bytes, result, engine, note, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())')
            ->execute([$claimId, (int) $user['id'], mb_substr(basename((string) $file['name']), 0, 255), $mime,
                (int) $file['size'], (string) $scan['result'], (string) $scan['engine'], mb_substr((string) $scan['note'], 0, 255)]);
    } catch (Throwable $e) {
        error_log('attachment scan log failed: ' . $e->getMessage());
    }
    audit('claims.evidence_blocked', 'claim', (string) $claimId, ['engine' => $scan['engine'], 'result' => $scan['result']]);
    fail_error('unsafe_attachment', 'This file did not pass the malware scan and was not uploaded.', 422,
        [], ['file' => 'Unsafe or suspicious file.']);
}
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . '_server' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'claims';
if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
}
if (!is_dir($dir) || !is_writable($dir)) {
    fail_error('storage_unavailable', 'Evidence storage is unavailable.', 500);
}
$storage = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
if (!move_uploaded_file($file['tmp_name'], $dir . DIRECTORY_SEPARATOR . $storage)) {
    fail_error('upload_failed', 'Could not store the evidence file.', 500);
}
$original = mb_substr(basename((string) $file['name']), 0, 255);
db()->prepare('INSERT INTO claim_attachments (claim_id, tenant_id, filename, mime, size_bytes, storage_name, scan_status, scan_engine, scan_note, uploaded_by, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())')
    ->execute([$claimId, (int) $claim['tenant_id'], $original, $mime, (int) $file['size'], $storage,
        (string) $scan['result'], (string) $scan['engine'], mb_substr((string) $scan['note'], 0, 255), (int) $user['id']]);
$attachmentId = (int) db()->lastInsertId();
claim_event($claimId, 'evidence_uploaded', $original, (int) $user['id']);
audit('claims.evidence_uploaded', 'claim', (string) $claimId, ['file' => $original, 'bytes' => (int) $file['size']]);
json_out(['ok' => true, 'attachment_id' => $attachmentId, 'attachments' => claim_attachments($claimId)]);
