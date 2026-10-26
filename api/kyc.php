<?php
/**
 * Mzigo.online — KYC document upload/status for runners and merchant workspaces.
 *
 * GET  /api/kyc.php    current status + uploaded documents
 * POST /api/kyc.php    multipart: doc_type, file
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_malware.php';
require_once __DIR__ . '/_payouts.php';

$user = require_user();
$role = (string) ($user['role'] ?? '');
$tenant = current_tenant();
$isRunner = $role === 'freelancer';
$isMerchant = $tenant && in_array((string) $tenant['role'], ['owner', 'admin'], true);

if (!$isRunner && !$isMerchant) {
    fail_error('forbidden', 'KYC documents are available to runners and workspace owners/admins.', 403);
}

function kyc_docs(int $userId, string $subjectType, int $subjectId): array
{
    $stmt = db()->prepare(
        "SELECT id, subject_type, subject_id, doc_type, original_name, status, note, reviewer_role, created_at, reviewed_at
           FROM kyc_documents WHERE user_id = ? AND subject_type = ? AND subject_id = ?
          ORDER BY created_at DESC LIMIT 12"
    );
    $stmt->execute([$userId, $subjectType, $subjectId]);
    return array_map(fn ($d) => [
        'id' => (int) $d['id'],
        'doc_type' => (string) $d['doc_type'],
        'original_name' => (string) ($d['original_name'] ?? ''),
        'status' => (string) $d['status'],
        'note' => (string) ($d['note'] ?? ''),
        'reviewer_role' => (string) ($d['reviewer_role'] ?? ''),
        'created_at' => $d['created_at'],
        'reviewed_at' => $d['reviewed_at'],
    ], $stmt->fetchAll());
}

function kyc_status_for(string $subjectType, int $subjectId, int $userId): array
{
    if ($subjectType === 'runner') {
        $stmt = db()->prepare('SELECT kyc_status, kyc_note, kyc_submitted_at, kyc_reviewed_at FROM freelancer_profiles WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch() ?: [];
        return [
            'status' => (string) ($row['kyc_status'] ?? 'not_submitted'),
            'note' => (string) ($row['kyc_note'] ?? ''),
            'submitted_at' => $row['kyc_submitted_at'] ?? null,
            'reviewed_at' => $row['kyc_reviewed_at'] ?? null,
        ];
    }
    $stmt = db()->prepare(
        "SELECT status, note, created_at, reviewed_at FROM kyc_documents
          WHERE subject_type = 'merchant' AND subject_id = ? ORDER BY created_at DESC LIMIT 1"
    );
    $stmt->execute([$subjectId]);
    $row = $stmt->fetch() ?: [];
    return [
        'status' => (string) ($row['status'] ?? 'not_submitted'),
        'note' => (string) ($row['note'] ?? ''),
        'submitted_at' => $row['created_at'] ?? null,
        'reviewed_at' => $row['reviewed_at'] ?? null,
    ];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $subjectType = $isRunner ? 'runner' : 'merchant';
    $subjectId = $isRunner ? (int) $user['id'] : (int) $tenant['id'];
    json_out([
        'ok' => true,
        'subject_type' => $subjectType,
        'subject_id' => $subjectId,
        'required' => $isRunner ? !empty(runner_launch_config()['kyc_required']) : true,
        'status' => kyc_status_for($subjectType, $subjectId, (int) $user['id']),
        'documents' => kyc_docs((int) $user['id'], $subjectType, $subjectId),
        'doc_types' => $isRunner
            ? ['national_id' => 'National ID', 'passport' => 'Passport', 'driver_license' => 'Driver licence']
            : ['business_permit' => 'Business permit', 'kra_pin' => 'KRA PIN certificate', 'registration_cert' => 'Business registration certificate'],
    ]);
}

require_post();
verify_csrf();
rate_limit('kyc_upload:' . $user['id'] . ':' . client_ip(), 10, 3600);
$docType = (string) ($_POST['doc_type'] ?? '');
$allowedTypes = $isRunner
    ? ['national_id', 'passport', 'driver_license']
    : ['business_permit', 'kra_pin', 'registration_cert'];
if (!in_array($docType, $allowedTypes, true)) {
    fail('Choose a valid document type.', 422, ['doc_type' => 'Invalid document type.']);
}
if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    fail_error('upload_failed', 'Choose a document to upload.', 422);
}
$file = $_FILES['file'];
if ((int) $file['size'] > 5 * 1024 * 1024) {
    fail_error('file_too_large', 'KYC files must be 5MB or smaller.', 422);
}
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string) $finfo->file((string) $file['tmp_name']);
$allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
if (!isset($allowedMimes[$mime])) {
    fail_error('invalid_file', 'Upload a JPG, PNG or PDF document.', 422);
}
$scan = malware_scan_file((string) $file['tmp_name'], $mime, (string) $file['name'], (int) $file['size']);
if (($scan['result'] ?? 'clean') !== 'clean') {
    fail_error('unsafe_file', 'That file could not be accepted. Please upload a different copy.', 422);
}
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . '_server' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'kyc';
if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
    fail_error('storage_unavailable', 'KYC storage is unavailable.', 500);
}
$storage = bin2hex(random_bytes(16)) . '.' . $allowedMimes[$mime];
if (!move_uploaded_file((string) $file['tmp_name'], $dir . DIRECTORY_SEPARATOR . $storage)) {
    fail_error('storage_unavailable', 'Could not save the KYC document.', 500);
}
$subjectType = $isRunner ? 'runner' : 'merchant';
$subjectId = $isRunner ? (int) $user['id'] : (int) $tenant['id'];
$original = mb_substr((string) $file['name'], 0, 160);
$stmt = db()->prepare(
    'INSERT INTO kyc_documents (subject_type, subject_id, user_id, doc_type, original_name, file_path, status, created_at)
     VALUES (?, ?, ?, ?, ?, ?, "pending", NOW())'
);
$stmt->execute([$subjectType, $subjectId, (int) $user['id'], $docType, $original, $storage]);
$docId = (int) db()->lastInsertId();

if ($isRunner) {
    $stmt = db()->prepare(
        "INSERT INTO freelancer_profiles (user_id, kyc_status, kyc_document_type, kyc_document_path, kyc_submitted_at)
         VALUES (?, 'pending', ?, ?, NOW())
         ON DUPLICATE KEY UPDATE kyc_status = 'pending', kyc_document_type = VALUES(kyc_document_type),
           kyc_document_path = VALUES(kyc_document_path), kyc_submitted_at = NOW(), kyc_note = NULL"
    );
    $stmt->execute([(int) $user['id'], $docType, $storage]);
}
audit('kyc.document_uploaded', $subjectType, (string) $subjectId, ['doc_id' => $docId, 'doc_type' => $docType]);
json_out([
    'ok' => true,
    'doc' => ['id' => $docId, 'doc_type' => $docType, 'status' => 'pending'],
    'status' => kyc_status_for($subjectType, $subjectId, (int) $user['id']),
    'documents' => kyc_docs((int) $user['id'], $subjectType, $subjectId),
], 201);
