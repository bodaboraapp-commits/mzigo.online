<?php
/**
 * Mzigo.online — runner proof-of-delivery photo upload (RN-06).
 * POST /api/runner-proof.php multipart { job_id, file }
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_malware.php';
require_once __DIR__ . '/_runner_jobs.php';

require_post();
verify_csrf();
$user = require_user();
if (($user['role'] ?? '') !== 'freelancer') { fail_error('forbidden', 'Runner access required.', 403); }
$jobId = (int) ($_POST['job_id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM runner_jobs WHERE id = ? AND runner_id = ? LIMIT 1');
$stmt->execute([$jobId, (int) $user['id']]);
$job = $stmt->fetch();
if (!$job) { fail_error('not_found', 'Job not found.', 404); }
if (!in_array($job['status'], ['accepted', 'picked_up'], true)) {
    fail('Photos can only be uploaded for active jobs.', 422);
}
if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    fail_error('upload_failed', 'Choose a photo to upload.', 422);
}
$file = $_FILES['file'];
if ((int) $file['size'] > 5 * 1024 * 1024) { fail_error('file_too_large', 'Proof photos must be 5MB or smaller.', 422); }
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string) $finfo->file((string) $file['tmp_name']);
$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
if (!isset($allowed[$mime])) { fail_error('invalid_file', 'Upload a JPG or PNG photo.', 422); }
$scan = malware_scan_file((string) $file['tmp_name'], $mime, (string) $file['name'], (int) $file['size']);
if (($scan['result'] ?? 'clean') !== 'clean') { fail_error('unsafe_file', 'That photo could not be accepted.', 422); }
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . '_server' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'runner-proof';
if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
    fail_error('storage_unavailable', 'Proof storage is unavailable.', 500);
}
$storage = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
if (!move_uploaded_file((string) $file['tmp_name'], $dir . DIRECTORY_SEPARATOR . $storage)) {
    fail_error('storage_unavailable', 'Could not save the proof photo.', 500);
}
db()->prepare('UPDATE runner_jobs SET proof_photo_path = ? WHERE id = ?')->execute([$storage, $jobId]);
audit('runner.proof_uploaded', 'runner_job', (string) $jobId, ['runner_id' => (int) $user['id']]);
json_out(['ok' => true, 'job_id' => $jobId]);
