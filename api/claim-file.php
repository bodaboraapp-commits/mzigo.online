<?php
/** GET /api/claim-file.php?id=ATTACHMENT_ID — authorised evidence download. */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_claims.php';

require_user();
$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT a.*, c.tenant_id, c.user_id FROM claim_attachments a JOIN claims c ON c.id = a.claim_id WHERE a.id = ? LIMIT 1');
$stmt->execute([$id]);
$file = $stmt->fetch();
if (!$file || !claim_viewable(['tenant_id' => $file['tenant_id'], 'user_id' => $file['user_id']])) {
    http_response_code(404);
    exit('Not found');
}
$path = dirname(__DIR__) . DIRECTORY_SEPARATOR . '_server' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'claims' . DIRECTORY_SEPARATOR . $file['storage_name'];
if (!is_file($path)) {
    http_response_code(404);
    exit('Not found');
}
header('Content-Type: ' . $file['mime']);
header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $file['filename']) . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
