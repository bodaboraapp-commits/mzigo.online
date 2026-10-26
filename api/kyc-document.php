<?php
/**
 * Mzigo.online — secure KYC document download for the owner or a platform admin.
 * GET /api/kyc-document.php?id=123
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$user = require_user();
$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM kyc_documents WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$doc = $stmt->fetch();
if (!$doc) { fail_error('not_found', 'Document not found.', 404); }
$isAdmin = ($user['role'] ?? '') === 'platform_admin';
$isOwner = (int) $doc['user_id'] === (int) $user['id'];
if (!$isAdmin && !$isOwner) { fail_error('forbidden', 'Access denied.', 403); }
$path = dirname(__DIR__) . DIRECTORY_SEPARATOR . '_server' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'kyc' . DIRECTORY_SEPARATOR . $doc['file_path'];
if (!is_file($path)) { fail_error('not_found', 'Document file missing.', 404); }
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string) $finfo->file($path);
header('Content-Type: ' . ($mime ?: 'application/octet-stream'));
header('Content-Disposition: inline; filename="' . basename((string) ($doc['original_name'] ?: $doc['file_path'])) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
exit;
