<?php
/**
 * Mzigo.online — KYC review queue for platform admins.
 *
 * GET  /api/admin-kyc.php   pending + recent documents
 * POST /api/admin-kyc.php   { action: review, doc_id, decision: approved|rejected, note }
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_runner_jobs.php';

$admin = require_platform_admin();

function admin_kyc_rows(string $status = 'pending'): array
{
    $stmt = db()->prepare(
        "SELECT d.*, u.name AS user_name, u.phone AS user_phone, u.email AS user_email,
                t.name AS tenant_name,
                (SELECT tb.logo_url FROM tenant_branding tb WHERE tb.tenant_id = d.subject_id LIMIT 1) AS tenant_logo_url
           FROM kyc_documents d
           JOIN users u ON u.id = d.user_id
           LEFT JOIN tenants t ON d.subject_type = 'merchant' AND t.id = d.subject_id
          WHERE d.status = ?
          ORDER BY d.created_at ASC LIMIT 100"
    );
    $stmt->execute([$status]);
    return array_map(fn ($d) => [
        'id' => (int) $d['id'],
        'subject_type' => (string) $d['subject_type'],
        'subject_id' => (int) $d['subject_id'],
        'user_name' => (string) $d['user_name'],
        'user_phone' => (string) ($d['user_phone'] ?? ''),
        'tenant_name' => (string) ($d['tenant_name'] ?? ''),
        'tenant_logo_url' => (string) ($d['tenant_logo_url'] ?? ''),
        'doc_type' => (string) $d['doc_type'],
        'original_name' => (string) ($d['original_name'] ?? ''),
        'status' => (string) $d['status'],
        'note' => (string) ($d['note'] ?? ''),
        'created_at' => $d['created_at'],
        'reviewed_at' => $d['reviewed_at'],
        'reviewer_role' => $d['reviewer_role'] ?? '',
    ], $stmt->fetchAll());
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_out(['ok' => true, 'pending' => admin_kyc_rows('pending'), 'recent' => admin_kyc_rows('approved') + []]);
}

require_post();
verify_csrf();
$data = body_json();
$action = (string) ($data['action'] ?? '');
if ($action !== 'review') {
    fail_error('unknown_action', 'Unknown KYC action.', 400);
}
$docId = (int) ($data['doc_id'] ?? 0);
$decision = (string) ($data['decision'] ?? '');
if (!in_array($decision, ['approved', 'rejected'], true)) {
    fail('Choose approved or rejected.', 422);
}
$note = isset($data['note']) && is_scalar($data['note']) ? mb_substr(trim((string) $data['note']), 0, 255) : null;
$stmt = db()->prepare('SELECT * FROM kyc_documents WHERE id = ? LIMIT 1');
$stmt->execute([$docId]);
$doc = $stmt->fetch();
if (!$doc) { fail_error('not_found', 'KYC document not found.', 404); }
$stmt = db()->prepare(
    'UPDATE kyc_documents SET status = ?, reviewer_id = ?, reviewer_role = "admin", note = ?, reviewed_at = NOW() WHERE id = ?'
);
$stmt->execute([$decision, (int) $admin['id'], $note, $docId]);

if ($doc['subject_type'] === 'runner') {
    $pdo = db();
    $pdo->prepare(
        'UPDATE freelancer_profiles SET kyc_status = ?, kyc_note = ?, kyc_reviewed_at = NOW(), kyc_reviewed_by = ? WHERE user_id = ?'
    )->execute([$decision, $note, (int) $admin['id'], (int) $doc['user_id']]);
    runner_notify(
        (int) $doc['user_id'],
        $decision === 'approved' ? 'ID verified' : 'ID verification needs attention',
        $decision === 'approved'
            ? 'Your KYC document was approved. You can now accept jobs.'
            : ('Your KYC document was rejected' . ($note ? ': ' . $note : '.') . ' Upload a new document from your runner profile.')
    );
} else {
    runner_notify(
        (int) $doc['user_id'],
        $decision === 'approved' ? 'Business verification approved' : 'Business verification needs attention',
        $decision === 'approved'
            ? 'Your business KYC document was approved.'
            : ('Your business KYC document was rejected' . ($note ? ': ' . $note : '.') . ' Upload a new document from your dashboard.')
    );
}
audit('admin.kyc_reviewed', $doc['subject_type'], (string) $doc['subject_id'], ['doc_id' => $docId, 'decision' => $decision]);
json_out(['ok' => true, 'pending' => admin_kyc_rows('pending')]);
