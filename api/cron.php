<?php
/**
 * Mzigo.online — subscription lifecycle job (CLI only).
 *
 *   php api\cron.php
 *
 * - Trialing workspaces whose trial has ended become `past_due` + `suspended`.
 * - Pending invoices past their due date mark the workspace `past_due`.
 * - Expired rate-limit buckets are pruned.
 *
 * Schedule daily in production (Task Scheduler / cron / container sidecar).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("CLI only\n");
}

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_notifications.php';
require __DIR__ . '/_claims.php';
require __DIR__ . '/_webhooks.php';
require_once __DIR__ . '/_growth.php';
require_once __DIR__ . '/_campaigns.php';

$pdo = db();
$now = date('Y-m-d H:i:s');
$platformFree = pricing_mode() === 'free';

$expiredTrials = $pdo->prepare(
    'SELECT s.tenant_id FROM subscriptions s
     WHERE s.status = "trialing" AND s.trial_ends_at IS NOT NULL AND s.trial_ends_at < ?'
);
$expiredTrials->execute([$now]);
$trialTenants = $expiredTrials->fetchAll(PDO::FETCH_COLUMN);

$n1 = 0;
foreach ($trialTenants as $tenantId) {
    if ($platformFree) {
        // The platform is free: expired trials simply become active, no suspension.
        $pdo->prepare('UPDATE subscriptions SET status = "active", trial_ends_at = NULL WHERE tenant_id = ?')->execute([$tenantId]);
        $pdo->prepare('UPDATE tenants SET status = "active" WHERE id = ? AND status = "trial"')->execute([$tenantId]);
        audit('cron.trial_converted_free', 'tenant', (string) $tenantId, [], (int) $tenantId);
    } else {
        $pdo->prepare('UPDATE subscriptions SET status = "past_due" WHERE tenant_id = ?')->execute([$tenantId]);
        $pdo->prepare('UPDATE tenants SET status = "suspended" WHERE id = ? AND status = "trial"')->execute([$tenantId]);
        $pdo->prepare('UPDATE sessions SET revoked_at = NOW()
            WHERE revoked_at IS NULL AND user_id IN (SELECT user_id FROM tenant_members WHERE tenant_id = ?)')
            ->execute([$tenantId]);
        audit('cron.trial_expired', 'tenant', (string) $tenantId, [], (int) $tenantId);
    }
    $n1++;
}

/* Scheduled cancellations and downgrades take effect at period end. */
$scheduled = $pdo->prepare(
    'SELECT s.tenant_id, s.cancel_at_period_end, s.pending_plan_id, s.pending_plan_at, s.current_period_end
     FROM subscriptions s
     WHERE s.current_period_end IS NOT NULL AND s.current_period_end <= ?
       AND s.status IN ("active","past_due","trialing")
       AND (s.cancel_at_period_end = 1 OR s.pending_plan_id IS NOT NULL)'
);
$scheduled->execute([$now]);
$n3 = 0;
foreach ($scheduled->fetchAll() as $sub) {
    $tenantId = (int) $sub['tenant_id'];
    if ((int) $sub['cancel_at_period_end'] === 1) {
        $pdo->prepare('UPDATE subscriptions SET status = "cancelled", cancel_at_period_end = 0, pending_plan_id = NULL, pending_plan_at = NULL WHERE tenant_id = ?')->execute([$tenantId]);
        $pdo->prepare('UPDATE tenants SET status = "cancelled" WHERE id = ?')->execute([$tenantId]);
        $pdo->prepare('UPDATE sessions SET revoked_at = NOW()
            WHERE revoked_at IS NULL AND user_id IN (SELECT user_id FROM tenant_members WHERE tenant_id = ?)')->execute([$tenantId]);
        audit('cron.subscription_cancelled', 'tenant', (string) $tenantId, [], $tenantId);
    } else {
        $pdo->prepare('UPDATE subscriptions SET plan_id = pending_plan_id, pending_plan_id = NULL, pending_plan_at = NULL,
            status = "active", current_period_start = NOW(), current_period_end = DATE_ADD(NOW(), INTERVAL 30 DAY)
            WHERE tenant_id = ?')->execute([$tenantId]);
        audit('cron.downgrade_applied', 'tenant', (string) $tenantId, ['plan_id' => (int) $sub['pending_plan_id']], $tenantId);
    }
    $n3++;
}

$overdue = $pdo->prepare(
    'SELECT tenant_id, COUNT(*) AS n FROM invoices
     WHERE status = "pending" AND due_at IS NOT NULL AND due_at < ?
     GROUP BY tenant_id'
);
$overdue->execute([$now]);
$n2 = 0;
foreach ($overdue->fetchAll() as $row) {
    if ($platformFree) {
        continue;
    }
    $pdo->prepare('UPDATE subscriptions SET status = "past_due" WHERE tenant_id = ?')->execute([$row['tenant_id']]);
    $pdo->prepare('UPDATE tenants SET status = "suspended" WHERE id = ? AND status <> "cancelled"')->execute([$row['tenant_id']]);
    $pdo->prepare('UPDATE sessions SET revoked_at = NOW()
        WHERE revoked_at IS NULL AND user_id IN (SELECT user_id FROM tenant_members WHERE tenant_id = ?)')
        ->execute([$row['tenant_id']]);
    audit('cron.invoice_overdue', 'tenant', (string) $row['tenant_id'], ['invoices' => (int) $row['n']], (int) $row['tenant_id']);
    $n2++;
}

/* Expire temporary claim blacklist entries. */
$expiredBlacklist = $pdo->exec('UPDATE claim_blacklist SET is_active = 0 WHERE is_active = 1 AND expires_at IS NOT NULL AND expires_at < NOW()');

/* Escalate claims that have missed their SLA once, and notify workspace owners. */
$slaStmt = $pdo->query(
    'SELECT c.id, c.ref, c.tenant_id, c.parcel_id, c.status, c.sla_due_at, p.tracking
     FROM claims c LEFT JOIN parcels p ON p.id = c.parcel_id
     WHERE c.status IN ("open", "under_review") AND c.sla_due_at IS NOT NULL AND c.sla_due_at < NOW()
       AND NOT EXISTS (SELECT 1 FROM claim_events e WHERE e.claim_id = c.id AND e.event = "sla_escalated")'
);
$n4 = 0;
foreach ($slaStmt->fetchAll() as $claim) {
    $claimId = (int) $claim['id'];
    claim_event($claimId, 'sla_escalated', 'SLA breached; owning workspace notified', null);
    $recipients = claim_tenant_recipients((int) $claim['tenant_id']);
    if ($recipients) {
        notify_claim_event([
            'id' => $claimId, 'ref' => $claim['ref'], 'tenant_id' => (int) $claim['tenant_id'],
        ], ['id' => $claim['parcel_id'], 'tracking' => $claim['tracking'] ?? ''], 'claim_sla_escalated',
            'Claim ' . $claim['ref'] . ' has passed its SLA',
            'Claim ' . $claim['ref'] . ' for parcel ' . ($claim['tracking'] ?? '') . ' passed its decision SLA. Please review it.',
            $recipients);
    }
    audit('cron.claim_sla_escalated', 'claim', (string) $claimId, ['ref' => $claim['ref']], (int) $claim['tenant_id']);
    $n4++;
}

/* Send due scheduled referral campaigns through configured email/SMS channels. */
$campaignResults = growth_campaign_run_due();
$campaignSent = array_sum(array_map(function ($r) { return (int) ($r['sent'] ?? 0); }, $campaignResults));
if ($campaignResults) {
    audit('cron.referral_campaigns', 'referral_campaign', 'due', ['runs' => $campaignResults]);
}

/* Send scheduled marketing campaigns to confirmed subscribers. */
$marketingResults = marketing_campaign_run_due();
$marketingSent = array_sum(array_map(function ($r) { return (int) ($r['sent'] ?? 0); }, $marketingResults));
if ($marketingResults) {
    audit('cron.marketing_campaigns', 'marketing_campaign', 'due', ['runs' => $marketingResults]);
}

/* Retry due webhook deliveries with exponential backoff. */
$webhookRetries = retry_webhook_deliveries(25);

$pruned = $pdo->exec('DELETE FROM rate_limits WHERE expires_at < NOW() - INTERVAL 1 DAY');

echo "Mzigo.online cron: {$n1} trial(s) expired, {$n2} workspace(s) suspended for overdue invoices, {$n3} scheduled billing change(s) applied, {$n4} claim SLA escalation(s), {$expiredBlacklist} blacklist entr(ies) expired, {$webhookRetries['attempted']} webhook retry attempt(s) ({$webhookRetries['sent']} sent), {$pruned} rate-limit bucket(s) pruned, {$campaignSent} referral campaign message(s) sent across " . count($campaignResults) . " campaign(s), {$marketingSent} marketing message(s) sent across " . count($marketingResults) . " campaign(s).\n";
