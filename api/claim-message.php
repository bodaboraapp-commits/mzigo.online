<?php
/** POST /api/claim-message.php — message thread on a claim (customer, merchant or admin). */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_claims.php';
require __DIR__ . '/_notifications.php';

require_post();
verify_csrf();
$user = require_user();
$data = body_json();
$claimId = (int) param($data, 'claim_id', 1, 20, 'Claim');
$message = param($data, 'message', 2, 2000, 'Message');

$stmt = db()->prepare(
    'SELECT c.*, p.tracking, p.receiver_name, p.receiver_phone, p.tenant_id AS parcel_tenant
     FROM claims c LEFT JOIN parcels p ON p.id = c.parcel_id WHERE c.id = ? LIMIT 1'
);
$stmt->execute([$claimId]);
$claim = $stmt->fetch();
if (!$claim) {
    fail_error('not_found', 'Claim not found.', 404);
}
$tenant = current_tenant();
$isAdmin = ($user['role'] ?? '') === 'platform_admin';
$isMerchant = $tenant && in_array($tenant['role'], ['owner', 'admin', 'staff'], true) && (int) $tenant['id'] === (int) $claim['tenant_id'];
$isCustomer = ($user['role'] ?? '') === 'customer' && !empty($claim['user_id']) && (int) $claim['user_id'] === (int) $user['id'];
if (!$isAdmin && !$isMerchant && !$isCustomer) {
    fail_error('forbidden', 'You are not a participant on this claim.', 403);
}
$role = $isAdmin ? 'admin' : ($isCustomer ? 'customer' : 'merchant');
rate_limit('claim_message:' . $user['id'] . ':' . $claimId, 10, 60);

db()->prepare('INSERT INTO claim_messages (claim_id, sender_user_id, sender_role, message, created_at) VALUES (?, ?, ?, ?, NOW())')
    ->execute([$claimId, (int) $user['id'], $role, $message]);
$messageId = (int) db()->lastInsertId();
claim_event($claimId, 'message', $role . ': ' . mb_substr($message, 0, 200), (int) $user['id']);
$parcel = [
    'id' => $claim['parcel_id'],
    'tracking' => $claim['tracking'] ?? '',
    'receiver_name' => $claim['receiver_name'] ?? '',
];
notify_claim_message($claim, $parcel, $user, $role, $message);
audit('claim.message', 'claim', (string) $claimId, ['message_id' => $messageId, 'role' => $role]);
json_out(['ok' => true, 'source' => 'mysql', 'message_id' => $messageId, 'message' => 'Message sent.']);
