<?php
/**
 * Shared claim helpers: policy, risk scoring, blacklist, events, attachments and access checks.
 */
declare(strict_types=1);

if (!defined('CLAIM_PAYOUT_HOLD_HOURS')) {
    define('CLAIM_PAYOUT_HOLD_HOURS', 48);
    define('CLAIM_CAP_TIER_A', 10000);
    define('CLAIM_CAP_TIER_B', 50000);
    define('CLAIM_MAX_ACTIVE_PER_TENANT', 5);
    define('CLAIM_MAX_PER_RECEIVER_30D', 2);
    define('CLAIM_MAX_VALUE_30D', 50000);
    define('CLAIM_SLA_DAYS', 5);
    define('CLAIM_DUAL_APPROVAL_KES', 5000);
    define('CLAIM_OTP_TTL_MINUTES', 10);
    define('CLAIM_OTP_MAX_ATTEMPTS', 5);
}

/** Return an error message when the parcel/type is not eligible, otherwise null. */
function claim_eligibility(array $parcel, string $type): ?string
{
    $status = (string) $parcel['status'];
    $ageDays = (time() - strtotime((string) $parcel['registered_at'])) / 86400;
    if (in_array($status, ['CANCELLED', 'RETURNED', 'REFUSED'], true)) {
        return 'This parcel is closed and cannot be claimed.';
    }
    if ($ageDays > 30) {
        return 'Claims are only accepted within 30 days of registration.';
    }
    if ($type === 'lost') {
        if (in_array($status, ['COLLECTED_PAID'], true)) {
            return 'Collected parcels cannot be claimed as lost.';
        }
        if ($ageDays < 7) {
            return 'Loss claims can only be filed after 7 days in transit.';
        }
    } elseif ($type === 'damaged') {
        if (!in_array($status, ['READY_FOR_COLLECTION', 'ARRIVED_DEST_POINT', 'COLLECTED_PAID'], true)) {
            return 'Damage can only be reported once the parcel reaches its destination office.';
        }
        if ($status === 'COLLECTED_PAID' && !empty($parcel['paid_at'])) {
            if (strtotime((string) $parcel['paid_at']) < time() - 86400) {
                return 'Damage must be reported within 24 hours of collection.';
            }
        }
    } elseif ($type === 'delayed') {
        if ($ageDays < 3) {
            return 'Delay claims can only be filed after 3 days.';
        }
        if ($status === 'COLLECTED_PAID') {
            return 'Collected parcels cannot be claimed as delayed.';
        }
    }
    return null;
}

function claim_cap_for(array $parcel): int
{
    $cap = ($parcel['tier'] ?? 'A') === 'B' ? CLAIM_CAP_TIER_B : CLAIM_CAP_TIER_A;
    return min((int) $parcel['value_kes'], $cap);
}

/** Computed risk score + flags stored on the claim at creation. */
function claim_risk_assessment(array $parcel, string $type, string $claimantRole, ?int $estimated, int $tenantId, string $evidence): array
{
    $flags = [];
    $score = 0;
    if ($claimantRole === 'customer') {
        $flags[] = 'customer_reported';
        $score += 15;
    }
    $cap = claim_cap_for($parcel);
    if ($estimated !== null && $estimated >= 5000) {
        $flags[] = 'high_value';
        $score += 20;
    }
    if ($estimated !== null && $cap > 0 && $estimated >= $cap * 0.8) {
        $flags[] = 'near_cap';
        $score += 10;
    }
    $receiverCount = 0;
    if (!empty($parcel['receiver_phone_key'])) {
        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM claims c JOIN parcels p ON p.id = c.parcel_id
             WHERE p.receiver_phone_key = ? AND c.created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)'
        );
        $stmt->execute([(string) $parcel['receiver_phone_key']]);
        $receiverCount = (int) $stmt->fetchColumn();
        if ($receiverCount > 0) {
            $flags[] = 'repeat_receiver';
            $score += min(30, $receiverCount * 10);
        }
    }
    $activeStmt = db()->prepare('SELECT COUNT(*) FROM claims WHERE tenant_id = ? AND status IN ("open","under_review","approved")');
    $activeStmt->execute([$tenantId]);
    $activeClaims = (int) $activeStmt->fetchColumn();
    if ($activeClaims >= 3) {
        $flags[] = 'workspace_velocity';
        $score += 10;
    }
    if ($type === 'delayed' && trim($evidence) === '') {
        $flags[] = 'no_evidence';
        $score += 5;
    }
    if (($parcel['status'] ?? '') === 'COLLECTED_PAID') {
        $flags[] = 'post_collection';
        $score += 5;
    }
    $score = min(100, $score);
    $level = $score >= 60 ? 'high' : ($score >= 30 ? 'medium' : 'low');
    return ['score' => $score, 'level' => $level, 'flags' => array_values(array_unique($flags))];
}

/** Active blacklist entry for a claimant phone, user or workspace. */
function claim_blacklist_match(string $phoneKey, ?int $userId, int $tenantId): ?array
{
    $stmt = db()->prepare(
        'SELECT id, scope, value, reason, expires_at FROM claim_blacklist
         WHERE is_active = 1 AND (expires_at IS NULL OR expires_at > NOW())
           AND ((scope = "phone" AND value = ?) OR (scope = "user" AND value = ?) OR (scope = "tenant" AND value = ?))
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$phoneKey, (string) ($userId ?? 0), (string) $tenantId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function claim_event(int $claimId, string $event, ?string $note = null, ?int $actorId = null): void
{
    try {
        db()->prepare('INSERT INTO claim_events (claim_id, actor_user_id, event, note, created_at) VALUES (?, ?, ?, ?, NOW())')
            ->execute([$claimId, $actorId, $event, $note !== null ? mb_substr($note, 0, 500) : null]);
    } catch (Throwable $e) {
        error_log('claim_event failed: ' . $e->getMessage());
    }
}

function claim_attachments(int $claimId): array
{
    $stmt = db()->prepare('SELECT id, filename, mime, size_bytes, scan_status, scan_engine, scan_note, created_at FROM claim_attachments WHERE claim_id = ? ORDER BY id ASC');
    $stmt->execute([$claimId]);
    return $stmt->fetchAll();
}

function claim_event_timeline(int $claimId): array
{
    $stmt = db()->prepare(
        'SELECT e.event, e.note, e.created_at, u.name AS actor_name
         FROM claim_events e LEFT JOIN users u ON u.id = e.actor_user_id
         WHERE e.claim_id = ? ORDER BY e.id ASC'
    );
    $stmt->execute([$claimId]);
    return $stmt->fetchAll();
}

/** A tenant member may view claims of their workspace; platform admins any claim. */
function claim_viewable(array $claim): bool
{
    $user = current_user();
    if (!$user) {
        return false;
    }
    if (($user['role'] ?? '') === 'platform_admin') {
        return true;
    }
    if (($user['role'] ?? '') === 'customer' && !empty($claim['user_id'])) {
        return (int) $claim['user_id'] === (int) $user['id'];
    }
    $tenant = current_tenant();
    return $tenant !== null && (int) $tenant['id'] === (int) $claim['tenant_id'];
}
