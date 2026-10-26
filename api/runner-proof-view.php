<?php
/** GET /api/runner-proof-view.php?id=jobId — proof photo for admin or owning runner. */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
$user = require_user();
$jobId = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM runner_jobs WHERE id = ? LIMIT 1');
$stmt->execute([$jobId]);
$job = $stmt->fetch();
if (!$job || empty($job['proof_photo_path'])) { fail_error('not_found', 'Proof photo not found.', 404); }
$isAdmin = ($user['role'] ?? '') === 'platform_admin';
$isRunner = (int) ($job['runner_id'] ?? 0) === (int) $user['id'];
if (!$isAdmin && !$isRunner) { fail_error('forbidden', 'Access denied.', 403); }
$path = dirname(__DIR__) . DIRECTORY_SEPARATOR . '_server' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'runner-proof' . DIRECTORY_SEPARATOR . $job['proof_photo_path'];
if (!is_file($path)) { fail_error('not_found', 'Proof photo missing.', 404); }
$finfo = new finfo(FILEINFO_MIME_TYPE);
header('Content-Type: ' . ((string) $finfo->file($path) ?: 'application/octet-stream'));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
exit;
