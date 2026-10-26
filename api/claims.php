<?php
/**
 * Mzigo.online — parcel claims (lost, damaged, delayed) with Phase 1 controls.
 *
 * Controls enforced here:
 *  - eligibility windows by claim type and parcel status
 *  - maximum claim value by parcel tier (A: 10k, B: 50k, capped at declared value)
 *  - mandatory evidence reference for loss/damage
 *  - duplicate/velocity limits: active claims per workspace, per receiver phone
 *    in 30 days, and rolling claimed value per workspace
 *  - admin decisions require a resolution reason; approvals start a 48h payout
 *    hold before a payout can be released (ledger-only payout)
 *
 * GET  /api/claims.php            tenant claims + eligible/recent parcels
 * POST /api/claims.php {action:"create_claim"|"withdraw_claim", ...}
 * Owner/admin only for writes.
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_webhooks.php';
require __DIR__ . '/_claims.php';


$user = require_user();
$tenant = current_tenant();
if (!$tenant) {
    fail_error('no_tenant', 'This account has no business workspace yet.', 403);
}
$tenantId = (int) $tenant['id'];
$isManager = in_array($tenant['role'], ['owner', 'admin'], true);

function tenant_claims(int $tenantId): array
{
    $stmt = db()->prepare(
        'SELECT c.id, c.ref, c.parcel_id, c.type, c.description, c.evidence, c.estimated_value_kes, c.status,
                c.resolution_note, c.payout_ref, c.payout_hold_until, c.paid_at, c.created_at, c.decided_at,
                c.sla_due_at, c.appeal_count, c.appeal_deadline, c.reversed_at, c.reversal_ref,
                c.recovery_status, c.recovery_ref, c.recovery_amount_kes, c.recovery_note,
                c.payout_amount_kes, c.first_approved_by, c.first_approved_at, c.approved_by,
                c.claimant_role, c.risk_score, c.risk_flags,
                (SELECT COUNT(*) FROM claim_attachments a WHERE a.claim_id = c.id) AS attachment_count,
                p.tracking, p.status AS parcel_status, p.carrier, p.from_city, p.to_city, p.value_kes AS parcel_value
         FROM claims c LEFT JOIN parcels p ON p.id = c.parcel_id
         WHERE c.tenant_id = ? ORDER BY c.id DESC LIMIT 100'
    );
    $stmt->execute([$tenantId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['estimated_value_kes'] = $row['estimated_value_kes'] !== null ? (int) $row['estimated_value_kes'] : null;
        $row['parcel_value'] = $row['parcel_value'] !== null ? (int) $row['parcel_value'] : null;
    }
    return $rows;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $stmt = db()->prepare('SELECT id, tracking, status, value_kes, tier, carrier, from_city, to_city, registered_at, payment_status
        FROM parcels WHERE tenant_id = ? ORDER BY registered_at DESC LIMIT 100');
    $stmt->execute([$tenantId]);
    $parcels = $stmt->fetchAll();
    foreach ($parcels as &$p) {
        $p['value_kes'] = (int) $p['value_kes'];
        $p['claim_cap_kes'] = claim_cap_for($p);
    }
    json_out([
        'ok' => true, 'source' => 'mysql', 'role' => $tenant['role'], 'can_manage' => $isManager,
        'claims' => tenant_claims($tenantId),
        'parcels' => $parcels,
        'policy' => [
            'payout_hold_hours' => CLAIM_PAYOUT_HOLD_HOURS,
            'max_active_claims' => CLAIM_MAX_ACTIVE_PER_TENANT,
            'receiver_claim_limit_30d' => CLAIM_MAX_PER_RECEIVER_30D,
            'max_claimed_value_30d' => CLAIM_MAX_VALUE_30D,
            'dual_approval_kes' => CLAIM_DUAL_APPROVAL_KES,
            'sla_days' => CLAIM_SLA_DAYS,
            'otp_ttl_minutes' => CLAIM_OTP_TTL_MINUTES,
        ],
    ]);
}

require_post();
verify_csrf();
if (!$isManager) {
    fail_error('forbidden', 'Only workspace owners and admins can manage claims.', 403);
}
$data = body_json();
$action = (string) ($data['action'] ?? '');

switch ($action) {
    case 'create_claim':
        $parcelId = param($data, 'parcel_id', 5, 64, 'Parcel');
        $type = (string) ($data['type'] ?? 'lost');
        if (!in_array($type, ['lost', 'damaged', 'delayed'], true)) {
            fail('Claim type must be lost, damaged or delayed.', 422, ['type' => 'Invalid claim type.']);
        }
        $description = param($data, 'description', 10, 1000, 'Description');
        $evidence = isset($data['evidence']) && is_scalar($data['evidence']) ? trim((string) $data['evidence']) : '';
        if (in_array($type, ['lost', 'damaged'], true) && mb_strlen($evidence) < 10) {
            fail('Evidence is required for loss or damage claims (photo/document references, at least 10 characters).', 422, ['evidence' => 'Evidence reference is required.']);
        }
        if (mb_strlen($evidence) > 1000) {
            $evidence = mb_substr($evidence, 0, 1000);
        }

        $stmt = db()->prepare('SELECT id, tracking, tenant_id, status, value_kes, tier, registered_at, paid_at, receiver_phone_key,
            fee_insurance FROM parcels WHERE id = ? AND tenant_id = ? LIMIT 1');
        $stmt->execute([$parcelId, $tenantId]);
        $parcel = $stmt->fetch();
        if (!$parcel) {
            fail_error('not_found', 'Parcel not found in this workspace.', 404);
        }
        if (($eligibleError = claim_eligibility($parcel, $type)) !== null) {
            fail_error('claim_not_eligible', $eligibleError, 422);
        }
        $blacklist = claim_blacklist_match((string) $parcel['receiver_phone_key'], (int) $user['id'], $tenantId);
        if ($blacklist) {
            fail_error('claim_blacklisted', 'This phone, account or workspace is blocked from filing claims. Contact support.', 403);
        }

        $cap = claim_cap_for($parcel);
        $estimated = isset($data['estimated_value_kes']) && $data['estimated_value_kes'] !== '' ? (int) $data['estimated_value_kes'] : null;
        if ($estimated !== null) {
            if ($estimated < 0) {
                fail_error('invalid_value', 'Estimated value cannot be negative.', 422);
            }
            if ($estimated > $cap) {
                fail_error('claim_cap_exceeded', 'The maximum claim for this parcel is KES ' . number_format($cap) . '.', 422);
            }
        }

        $dup = db()->prepare('SELECT 1 FROM claims WHERE parcel_id = ? AND status IN ("open","under_review","approved") LIMIT 1');
        $dup->execute([$parcelId]);
        if ($dup->fetchColumn()) {
            fail_error('claim_exists', 'An active claim already exists for this parcel.', 409);
        }
        $recent = db()->prepare('SELECT COUNT(*) FROM claims WHERE parcel_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)');
        $recent->execute([$parcelId]);
        if ((int) $recent->fetchColumn() >= 2) {
            fail_error('claim_limit_parcel', 'This parcel has already had too many claims in the last 30 days.', 422);
        }

        $active = db()->prepare('SELECT COUNT(*) FROM claims WHERE tenant_id = ? AND status IN ("open","under_review","approved")');
        $active->execute([$tenantId]);
        if ((int) $active->fetchColumn() >= CLAIM_MAX_ACTIVE_PER_TENANT) {
            fail_error('claim_limit_workspace', 'This workspace has too many active claims. Resolve existing claims first.', 422);
        }
        $receiver = db()->prepare(
            'SELECT COUNT(*) FROM claims c JOIN parcels p ON p.id = c.parcel_id
             WHERE p.receiver_phone_key = ? AND c.created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)'
        );
        $receiver->execute([(string) $parcel['receiver_phone_key']]);
        if ((int) $receiver->fetchColumn() >= CLAIM_MAX_PER_RECEIVER_30D) {
            fail_error('claim_limit_receiver', 'Too many claims for this receiver phone in the last 30 days.', 422);
        }
        $valueWindow = db()->prepare(
            'SELECT COALESCE(SUM(COALESCE(estimated_value_kes, 0)),0) FROM claims
             WHERE tenant_id = ? AND status NOT IN ("rejected","withdrawn") AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)'
        );
        $valueWindow->execute([$tenantId]);
        if ((int) $valueWindow->fetchColumn() + (int) ($estimated ?? 0) > CLAIM_MAX_VALUE_30D) {
            fail_error('claim_value_limit', 'This workspace has reached its 30-day claim value limit.', 422);
        }

        $risk = claim_risk_assessment($parcel, $type, 'merchant', $estimated, $tenantId, $evidence);
        $ref = 'CLM-' . date('ymd') . '-' . strtoupper(random_code(4));
        $ins = db()->prepare('INSERT INTO claims (ref, tenant_id, parcel_id, user_id, type, description, evidence, estimated_value_kes,
            claimant_role, risk_score, risk_flags) VALUES (?, ?, ?, ?, ?, ?, ?, ?, "merchant", ?, ?)');
        $ins->execute([$ref, $tenantId, $parcelId, (int) $user['id'], $type, $description, $evidence !== '' ? $evidence : null, $estimated,
            $risk['score'], json_encode($risk['flags'])]);
        $claimId = (int) db()->lastInsertId();
        db()->prepare('UPDATE claims SET sla_due_at = DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id = ?')->execute([CLAIM_SLA_DAYS, $claimId]);
        claim_event($claimId, 'created', $description, (int) $user['id']);
        audit('claims.created', 'claim', (string) $claimId, [
            'ref' => $ref, 'parcel' => $parcel['tracking'], 'type' => $type,
            'estimated' => $estimated, 'has_evidence' => $evidence !== '',
        ], $tenantId);
        dispatch_webhooks($tenantId, 'claim.created', ['claim' => $ref, 'parcel' => $parcel['tracking'], 'type' => $type]);
        json_out(['ok' => true, 'source' => 'mysql', 'claims' => tenant_claims($tenantId), 'claim_id' => $claimId, 'ref' => $ref,
            'message' => 'Claim ' . $ref . ' submitted for review.']);
        // no break

    case 'withdraw_claim':
        $claimId = (int) param($data, 'claim_id', 1, 20, 'Claim');
        $stmt = db()->prepare('SELECT * FROM claims WHERE id = ? AND tenant_id = ? LIMIT 1');
        $stmt->execute([$claimId, $tenantId]);
        $claim = $stmt->fetch();
        if (!$claim) {
            fail_error('not_found', 'Claim not found.', 404);
        }
        if (!in_array($claim['status'], ['open', 'under_review'], true)) {
            fail_error('not_withdrawable', 'Only open or under-review claims can be withdrawn.', 422);
        }
        db()->prepare('UPDATE claims SET status = "withdrawn", decided_at = NOW() WHERE id = ?')->execute([$claimId]);
        claim_event($claimId, 'withdrawn', 'Claim withdrawn by workspace', (int) $user['id']);
        audit('claims.withdrawn', 'claim', (string) $claimId, ['ref' => $claim['ref']], $tenantId);
        json_out(['ok' => true, 'source' => 'mysql', 'claims' => tenant_claims($tenantId), 'message' => 'Claim withdrawn.']);
        // no break

    case 'appeal_claim':
        $claimId = (int) param($data, 'claim_id', 1, 20, 'Claim');
        $reason = param($data, 'reason', 5, 500, 'Appeal reason');
        $stmt = db()->prepare('SELECT * FROM claims WHERE id = ? AND tenant_id = ? LIMIT 1');
        $stmt->execute([$claimId, $tenantId]);
        $claim = $stmt->fetch();
        if (!$claim) { fail_error('not_found', 'Claim not found.', 404); }
        if ($claim['status'] !== 'rejected') {
            fail_error('not_appealable', 'Only rejected claims can be appealed.', 422);
        }
        if ((int) $claim['appeal_count'] >= 1) {
            fail_error('appeal_limit', 'This claim has already been appealed once.', 422);
        }
        if (!empty($claim['appeal_deadline']) && strtotime((string) $claim['appeal_deadline']) < time()) {
            fail_error('appeal_expired', 'The appeal window for this claim has closed.', 422);
        }
        db()->prepare('UPDATE claims SET status = "under_review", appeal_count = appeal_count + 1, decided_at = NULL WHERE id = ?')->execute([$claimId]);
        claim_event($claimId, 'appealed', $reason, (int) $user['id']);
        audit('claims.appealed', 'claim', (string) $claimId, ['ref' => $claim['ref'], 'reason' => $reason], $tenantId);
        dispatch_webhooks($tenantId, 'claim.updated', ['claim' => $claim['ref'], 'status' => 'under_review', 'event' => 'appealed']);
        json_out(['ok' => true, 'source' => 'mysql', 'claims' => tenant_claims($tenantId), 'message' => 'Appeal submitted for review.']);
        // no break

    default:
        fail_error('unknown_action', 'Unknown claim action.', 400);
}
