<?php
/** GET /api/claim-detail.php?claim_id=ID — claim, parcel scans, timeline, attachments. */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_claims.php';

$user = require_user();
$claimId = (int) ($_GET['claim_id'] ?? 0);
$stmt = db()->prepare(
    'SELECT c.*, t.name AS tenant_name,
            p.tracking, p.locker, p.carrier, p.from_city, p.to_city, p.status AS parcel_status,
            p.receiver_name, p.receiver_phone, p.value_kes, p.tier, p.registered_at, p.payment_status,
            fa.name AS first_approved_name, aa.name AS approved_name
     FROM claims c
     LEFT JOIN tenants t ON t.id = c.tenant_id
     LEFT JOIN parcels p ON p.id = c.parcel_id
     LEFT JOIN users fa ON fa.id = c.first_approved_by
     LEFT JOIN users aa ON aa.id = c.approved_by
     WHERE c.id = ? LIMIT 1'
);
$stmt->execute([$claimId]);
$claim = $stmt->fetch();
if (!$claim || !claim_viewable($claim)) {
    fail_error('not_found', 'Claim not found.', 404);
}
$scanStmt = db()->prepare('SELECT status, label, location, note, scanned_at FROM scans WHERE subject_type = "parcel" AND subject_id = ? ORDER BY scanned_at ASC, id ASC');
$scanStmt->execute([$claim['parcel_id']]);
$relatedStmt = db()->prepare(
    'SELECT c2.id, c2.ref, c2.status, c2.type, c2.claimant_role, c2.risk_score, c2.created_at, p2.tracking
     FROM claims c2 LEFT JOIN parcels p2 ON p2.id = c2.parcel_id
     WHERE c2.id <> ? AND (c2.tenant_id = ? OR p2.receiver_phone_key = (SELECT receiver_phone_key FROM parcels WHERE id = ?))
     ORDER BY c2.id DESC LIMIT 10'
);
$relatedStmt->execute([$claimId, (int) $claim['tenant_id'], $claim['parcel_id']]);
$related = $relatedStmt->fetchAll();
$msgStmt = db()->prepare(
    'SELECT m.id, m.sender_role, m.message, m.created_at, u.name AS sender_name
     FROM claim_messages m LEFT JOIN users u ON u.id = m.sender_user_id
     WHERE m.claim_id = ? ORDER BY m.id ASC LIMIT 200'
);
$msgStmt->execute([$claimId]);
$messages = $msgStmt->fetchAll();
$flags = [];
if (!empty($claim['risk_flags'])) {
    $decoded = json_decode((string) $claim['risk_flags'], true);
    if (is_array($decoded)) { $flags = $decoded; }
}
$claim['risk_flags'] = $flags;
$tenant = current_tenant();
$payoutStmt = db()->prepare('SELECT id, provider, mode, amount_kes, status, phone, provider_ref, external_ref, error, requested_at, completed_at FROM claim_payouts WHERE claim_id = ? ORDER BY id DESC LIMIT 20');
$payoutStmt->execute([$claimId]);
$recoveryStmt = db()->prepare('SELECT id, provider, mode, status, amount_kes, reference, external_ref, note, error, requested_at, completed_at FROM claim_recoveries WHERE claim_id = ? ORDER BY id DESC LIMIT 20');
$recoveryStmt->execute([$claimId]);
json_out([
    'ok' => true,
    'source' => 'mysql',
    'claim' => $claim,
    'events' => claim_event_timeline($claimId),
    'attachments' => claim_attachments($claimId),
    'scans' => $scanStmt->fetchAll(),
    'related_claims' => $related,
    'messages' => $messages,
    'payouts' => $payoutStmt->fetchAll(),
    'recoveries' => $recoveryStmt->fetchAll(),
    'can_manage' => in_array($user['role'] ?? '', ['platform_admin'], true) || ($tenant && in_array($tenant['role'], ['owner', 'admin'], true) && (int) $tenant['id'] === (int) $claim['tenant_id']),
]);
