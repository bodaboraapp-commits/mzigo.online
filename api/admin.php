<?php
/**
 * Mzigo.online — platform admin console API (platform_admin role only).
 *
 * GET  -> metrics, tenants, invoices, plans, recent audit
 * POST verify_csrf:
 *   suspend_tenant / activate_tenant { tenant_id }
 *   change_plan                      { tenant_id, plan_code }
 *   mark_invoice_paid / void_invoice { invoice_id }
 *   save_channel                     { original_code, code, label, provider, sender, config, is_active }
 *   contact_status                   { contact_id, status, admin_note }
 *   contact_delete                   { contact_id }
 *   faq_save / faq_delete            { faq_id?, question, answer, sort_order, is_active }
 *   branding_upload                  multipart { kind: logo|favicon|hero, file }
 *   save_hero_copy                   { hero_title, hero_subtitle }
 *   branding_reset                   {}
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_notifications.php';
require __DIR__ . '/_scan_actions.php';
require_once __DIR__ . '/_newsletter.php';
require __DIR__ . '/_webhooks.php';
require __DIR__ . '/_claims.php';
require_once __DIR__ . '/_crypto.php';
require_once __DIR__ . '/_comms_catalog.php';
require_once __DIR__ . '/_template_store.php';
require_once __DIR__ . '/_email_deliverability.php';
require_once __DIR__ . '/_api_usage.php';
require_once __DIR__ . '/_claim_rails.php';
require __DIR__ . '/_cms.php';
require_once __DIR__ . '/_payouts.php';
require_once __DIR__ . '/_contact_threads.php';
require_once __DIR__ . '/_insurance.php';

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$admin = require_platform_admin();

function admin_metrics(): array
{
    $pdo = db();
    $count = function (string $sql): int {
        return (int) db()->query($sql)->fetchColumn();
    };
    return [
        'tenants_total'    => $count('SELECT COUNT(*) FROM tenants'),
        'tenants_active'   => $count('SELECT COUNT(*) FROM tenants WHERE status = "active"'),
        'tenants_trial'    => $count('SELECT COUNT(*) FROM tenants WHERE status = "trial"'),
        'tenants_suspended' => $count('SELECT COUNT(*) FROM tenants WHERE status = "suspended"'),
        'users_total'      => $count('SELECT COUNT(*) FROM users'),
        'parcels_total'    => $count('SELECT COUNT(*) FROM parcels'),
        'parcels_month'    => $count('SELECT COUNT(*) FROM parcels WHERE registered_at >= DATE_FORMAT(NOW(), "%Y-%m-01")'),
        'errands_total'    => $count('SELECT COUNT(*) FROM errands'),
        'mrr_kes'          => $count('SELECT COALESCE(SUM(p.monthly_price_kes),0) FROM subscriptions s JOIN plans p ON p.id = s.plan_id JOIN tenants t ON t.id = s.tenant_id WHERE s.status = "active" AND t.status = "active"'),
        'revenue_paid_kes' => $count('SELECT COALESCE(SUM(amount_kes),0) FROM invoices WHERE status = "paid"'),
        'invoices_pending' => $count('SELECT COUNT(*) FROM invoices WHERE status = "pending"'),
        'open_claims'      => 0,
    ];
}

function admin_tenants(): array
{
    $rows = db()->query(
        'SELECT t.id, t.slug, t.name, t.email, t.phone, t.status, t.created_at,
                p.code AS plan_code, p.name AS plan_name, s.status AS subscription_status,
                s.trial_ends_at,
                (SELECT COUNT(*) FROM parcels WHERE tenant_id = t.id AND registered_at >= DATE_FORMAT(NOW(), "%Y-%m-01")) AS parcels_month,
                (SELECT COALESCE(SUM(amount_kes),0) FROM invoices WHERE tenant_id = t.id AND status = "paid") AS paid_kes,
                (SELECT u.email FROM tenant_members m JOIN users u ON u.id = m.user_id WHERE m.tenant_id = t.id AND m.role = "owner" LIMIT 1) AS owner_email
         FROM tenants t
         LEFT JOIN subscriptions s ON s.tenant_id = t.id
         LEFT JOIN plans p ON p.id = s.plan_id
         ORDER BY t.created_at DESC'
    )->fetchAll();
    foreach ($rows as &$row) {
        $row['parcels_month'] = (int) $row['parcels_month'];
        $row['paid_kes'] = (int) $row['paid_kes'];
    }
    return $rows;
}

function admin_invoices(): array
{
    $rows = db()->query(
        'SELECT i.id, i.number, i.amount_kes, i.subtotal_kes, i.tax_kes, i.status, i.description, i.issued_at, i.paid_at,
                i.refunded_at, i.refund_ref, i.reference, t.id AS tenant_id, t.name AS tenant_name, p.code AS plan_code
         FROM invoices i JOIN tenants t ON t.id = i.tenant_id JOIN plans p ON p.id = i.plan_id
         ORDER BY i.issued_at DESC, i.id DESC LIMIT 40'
    )->fetchAll();
    foreach ($rows as &$row) {
        $row['amount_kes'] = (int) $row['amount_kes'];
    }
    return $rows;
}

function admin_audit(): array
{
    $rows = db()->query(
        'SELECT a.id, a.action, a.entity, a.entity_id, a.meta, a.ip, a.created_at,
                t.name AS tenant_name, u.name AS actor_name
         FROM audit_log a
         LEFT JOIN tenants t ON t.id = a.tenant_id
         LEFT JOIN users u ON u.id = a.actor_user_id
         ORDER BY a.id DESC LIMIT 40'
    )->fetchAll();
    foreach ($rows as &$row) {
        $row['meta'] = $row['meta'] ? json_decode((string) $row['meta'], true) : null;
    }
    return $rows;
}

function admin_plans(): array
{
    $rows = db()->query(
        'SELECT id, code, name, monthly_price_kes, parcel_limit, staff_limit, features, is_active, sort_order
         FROM plans ORDER BY sort_order ASC, monthly_price_kes ASC'
    )->fetchAll();
    foreach ($rows as &$row) {
        $row['monthly_price_kes'] = (int) $row['monthly_price_kes'];
        $row['parcel_limit'] = (int) $row['parcel_limit'];
        $row['staff_limit'] = (int) $row['staff_limit'];
        $row['features'] = $row['features'] ? json_decode((string) $row['features'], true) : [];
        $row['is_active'] = (bool) $row['is_active'];
    }
    return $rows;
}

function admin_sessions(): array
{
    $rows = db()->query(
        'SELECT s.id, s.user_id, s.tenant_id, s.ip, s.user_agent, s.created_at, s.last_seen_at, s.expires_at, s.revoked_at,
                u.name AS user_name, u.role AS user_role, t.name AS tenant_name
         FROM sessions s
         LEFT JOIN users u ON u.id = s.user_id
         LEFT JOIN tenants t ON t.id = s.tenant_id
         ORDER BY s.last_seen_at DESC LIMIT 25'
    )->fetchAll();
    foreach ($rows as &$row) {
        $row['current'] = ((string) $row['id'] === session_id());
        $row['id_short'] = substr((string) $row['id'], 0, 12);
        $row['user_agent'] = substr((string) ($row['user_agent'] ?? ''), 0, 90);
    }
    $active = (int) db()->query('SELECT COUNT(*) FROM sessions WHERE revoked_at IS NULL AND expires_at > NOW()')->fetchColumn();
    return ['active' => $active, 'current_session' => session_id(), 'rows' => $rows];
}

function admin_users(): array
{
    $stmt = db()->query(
        'SELECT u.id, u.name, u.email, u.email_verified_at, u.phone, u.role, u.status, u.created_at, u.last_login_at,
                (SELECT t.name FROM tenant_members m JOIN tenants t ON t.id = m.tenant_id
                 WHERE m.user_id = u.id ORDER BY m.tenant_id LIMIT 1) AS tenant_name,
                (SELECT m.role FROM tenant_members m WHERE m.user_id = u.id ORDER BY m.tenant_id LIMIT 1) AS member_role
         FROM users u ORDER BY u.id DESC LIMIT 200'
    );
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
    }
    return ['active' => (int) db()->query('SELECT COUNT(*) FROM users WHERE status = "active"')->fetchColumn(), 'rows' => $rows];
}

function admin_revoke_user_sessions(int $userId): void
{
    db()->prepare('UPDATE sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL')->execute([$userId]);
}

function admin_credential_for(string $role): array
{
    if ($role === 'freelancer') {
        return ['label' => 'PIN', 'value' => '1234', 'hash' => password_hash('1234', PASSWORD_DEFAULT)];
    }
    if ($role === 'customer') {
        return ['label' => 'demo code', 'value' => '1234', 'hash' => null];
    }
    $password = 'Mzigo-' . bin2hex(random_bytes(4)) . '!';
    return ['label' => 'temporary password', 'value' => $password, 'hash' => password_hash($password, PASSWORD_DEFAULT)];
}

function admin_claim_analytics(): array
{
    $pdo = db();
    $row = $pdo->query(
        'SELECT COUNT(*) AS total,
                SUM(status IN ("open","under_review","approved")) AS open_claims,
                SUM(status = "paid") AS paid_claims,
                SUM(status = "rejected") AS rejected_claims,
                COALESCE(SUM(payout_amount_kes), 0) AS paid_kes,
                COALESCE(SUM(recovery_amount_kes), 0) AS recovered_kes,
                SUM(risk_score >= 60) AS high_risk,
                ROUND(AVG(CASE WHEN decided_at IS NOT NULL THEN TIMESTAMPDIFF(HOUR, created_at, decided_at) END), 1) AS avg_decision_hours
         FROM claims'
    )->fetch();
    $monthly = $pdo->query(
        'SELECT DATE_FORMAT(created_at, "%Y-%m") AS month, COUNT(*) AS claims,
                COALESCE(SUM(payout_amount_kes), 0) AS paid_kes
         FROM claims GROUP BY DATE_FORMAT(created_at, "%Y-%m") ORDER BY month DESC LIMIT 6'
    )->fetchAll();
    $blacklist = $pdo->query('SELECT COUNT(*) FROM claim_blacklist WHERE is_active = 1 AND (expires_at IS NULL OR expires_at > NOW())')->fetchColumn();
    return [
        'total' => (int) $row['total'],
        'open' => (int) $row['open_claims'],
        'paid' => (int) $row['paid_claims'],
        'rejected' => (int) $row['rejected_claims'],
        'paid_kes' => (int) $row['paid_kes'],
        'recovered_kes' => (int) $row['recovered_kes'],
        'high_risk' => (int) $row['high_risk'],
        'avg_decision_hours' => $row['avg_decision_hours'] !== null ? (float) $row['avg_decision_hours'] : null,
        'blacklist_active' => (int) $blacklist,
        'monthly' => $monthly,
    ];
}

function admin_claim_blacklist(): array
{
    return db()->query(
        'SELECT b.id, b.scope, b.value, b.reason, b.is_active, b.expires_at, b.created_at,
                u.name AS created_by_name
         FROM claim_blacklist b LEFT JOIN users u ON u.id = b.created_by
         ORDER BY b.is_active DESC, b.id DESC LIMIT 100'
    )->fetchAll();
}

/** Read-only report of parcels not associated with a workspace. */
function admin_unassigned_parcels(): array
{
    $rows = db()->query('SELECT id, tracking, locker, carrier, from_city, to_city, sender_name, sender_phone,
            receiver_name, receiver_phone, size, value_kes, fee_total, status, payment_status, registered_at
        FROM parcels WHERE tenant_id IS NULL ORDER BY registered_at DESC LIMIT 100')->fetchAll();
    foreach ($rows as &$row) {
        $row['insurance_ref'] = insurance_reference_for_parcel($row);
        $row['public_url'] = '/track-mzigo?num=' . rawurlencode((string) $row['tracking']);
    }
    return $rows;
}

function admin_claims(): array
{
    $rows = db()->query(
        'SELECT c.id, c.ref, c.type, c.claimant_role, c.risk_score, c.risk_flags, c.description, c.evidence, c.estimated_value_kes, c.status, c.resolution_note,
                c.payout_ref, c.payout_amount_kes, c.payout_hold_until, c.paid_at, c.created_at, c.decided_at,
                c.sla_due_at, c.appeal_count, c.appeal_deadline, c.reversed_at, c.reversal_ref,
                c.recovery_status, c.recovery_ref, c.recovery_amount_kes, c.recovery_note,
                c.first_approved_by, c.first_approved_at, c.approved_by,
                t.name AS tenant_name, p.tracking, p.status AS parcel_status,
                p.value_kes AS parcel_value, p.tier AS parcel_tier,
                fa.name AS first_approved_name, aa.name AS approved_name,
                (SELECT COUNT(*) FROM claim_attachments a WHERE a.claim_id = c.id) AS attachment_count
         FROM claims c
         LEFT JOIN tenants t ON t.id = c.tenant_id
         LEFT JOIN parcels p ON p.id = c.parcel_id
         LEFT JOIN users fa ON fa.id = c.first_approved_by
         LEFT JOIN users aa ON aa.id = c.approved_by
         ORDER BY c.id DESC LIMIT 50'
    )->fetchAll();
    foreach ($rows as &$row) {
        $row['estimated_value_kes'] = $row['estimated_value_kes'] !== null ? (int) $row['estimated_value_kes'] : null;
        $row['payout_amount_kes'] = $row['payout_amount_kes'] !== null ? (int) $row['payout_amount_kes'] : null;
        $row['recovery_amount_kes'] = $row['recovery_amount_kes'] !== null ? (int) $row['recovery_amount_kes'] : null;
    }
    return $rows;
}

function admin_contact_messages(): array
{
    $rows = db()->query(
        'SELECT id, name, email, phone, subject, message, status, admin_note, created_at, updated_at
         FROM contact_messages ORDER BY id DESC LIMIT 100'
    )->fetchAll();
    $counts = ['new' => 0, 'read' => 0, 'replied' => 0, 'archived' => 0, 'total' => 0];
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['notes'] = contact_thread_notes((int) $row['id']);
        $row['replies'] = contact_thread_replies((int) $row['id']);
        $counts['total']++;
        if (isset($counts[$row['status']])) {
            $counts[$row['status']]++;
        }
    }
    unset($row);
    return [
        'rows' => $rows,
        'counts' => $counts,
        'channels' => contact_reply_channels(),
        'support_email' => (string) (config()['app']['support_email'] ?? 'hello@mzigo.online'),
    ];
}

function admin_transactions(): array
{
    $rows = db()->query(
        'SELECT t.id, t.reference, t.type, t.amount_kes, t.method, t.status, t.subject_type, t.subject_id,
                t.created_at, t.completed_at, tn.name AS tenant_name
         FROM transactions t LEFT JOIN tenants tn ON tn.id = t.tenant_id
         ORDER BY t.created_at DESC, t.id DESC LIMIT 30'
    )->fetchAll();
    foreach ($rows as &$row) {
        $row['amount_kes'] = (int) $row['amount_kes'];
    }
    return $rows;
}
function admin_partner_points(): array
{
    $sql = 'SELECT pp.id, pp.tenant_id, pp.partner_slug, pp.name, pp.code, pp.point_type, pp.location, pp.is_active, pp.manual_entry_enabled,
                   pp.last_scan_at, pp.created_at, p.name AS carrier_name, t.name AS workspace_name
            FROM partner_points pp LEFT JOIN partners p ON p.slug = pp.partner_slug
            LEFT JOIN tenants t ON t.id = pp.tenant_id
            ORDER BY pp.is_active DESC, pp.name ASC';
    return db()->query($sql)->fetchAll();
}

function admin_point_carriers(): array
{
    return db()->query('SELECT slug, name FROM partners ORDER BY name ASC')->fetchAll();
}

function admin_point_token(): array
{
    $raw = 'mzp_' . bin2hex(random_bytes(24));
    return ['raw' => $raw, 'prefix' => substr($raw, 0, 12), 'hash' => hash('sha256', $raw)];
}

if ($method === 'GET') {
    json_out([
        'ok'       => true,
        'source'   => 'mysql',
        'csrf'     => csrf_token(),
        'admin'    => ['id' => (int) $admin['id'], 'name' => $admin['name'], 'email' => $admin['email']],
        'metrics'  => admin_metrics(),
        'tenants'  => admin_tenants(),
        'invoices' => admin_invoices(),
        'plans'    => admin_plans(),
        'audit'    => admin_audit(),
        'transactions' => admin_transactions(),
        'sessions' => admin_sessions(),
        'users' => admin_users(),
        'claims' => admin_claims(),
        'claims_analytics' => admin_claim_analytics(),
        'claim_blacklist' => admin_claim_blacklist(),
        'contact_messages' => admin_contact_messages(),
        'comms_templates' => comms_template_table(),
        'api_usage' => platform_api_usage(),
        'unassigned_parcels' => admin_unassigned_parcels(),
        'partner_points' => admin_partner_points(),
        'scan_actions' => scan_action_config(),
        'claim_rails' => claim_rails_admin_view(),
        'point_carriers' => admin_point_carriers(),
        'email_deliverability' => email_deliverability_data(),
        'faqs' => cms_faqs(false),
        'branding' => cms_branding(),
        'newsletter_prompt' => newsletter_admin_payload(),
        'seo' => cms_seo_settings(),
        'web_vitals' => cms_web_vitals_summary(),
        'pricing_mode' => pricing_mode(),
        'payout_config' => payout_config(),
        'runner_launch_config' => runner_launch_config(),
        'pending_runner_payouts' => db()->query("SELECT p.*, u.name AS runner_name, u.phone AS runner_phone FROM runner_payouts p JOIN users u ON u.id = p.runner_id WHERE p.status = 'pending' ORDER BY p.requested_at ASC LIMIT 50")->fetchAll(),
        'show_demo_accounts' => platform_setting('show_demo_accounts', '1') === '1',
        'twofa' => (function () use ($admin) {
            $stmt = db()->prepare('SELECT enabled_at FROM user_2fa WHERE user_id = ? LIMIT 1');
            $stmt->execute([(int) $admin['id']]);
            $row = $stmt->fetch();
            return ['enabled' => $row && !empty($row['enabled_at']), 'enabled_at' => $row['enabled_at'] ?? null];
        })(),
        'channels' => notification_channels(false, true),
        'channel_providers' => channel_provider_catalog(),
        'channel_sender_hints' => channel_sender_hints(),
        'notifications' => db()->query('SELECT id, channel, recipient, subject, template, status, error, created_at
            FROM notifications ORDER BY id DESC LIMIT 30')->fetchAll(),
    ]);
}

require_post();
verify_csrf();
$data = body_json();
$action = (string) ($data['action'] ?? '');
$pdo = db();

switch ($action) {
    case 'suspend_tenant':
    case 'activate_tenant':
        $tenantId = (int) param($data, 'tenant_id', 1, 20, 'Tenant');
        $status = $action === 'suspend_tenant' ? 'suspended' : 'active';
        $stmt = $pdo->prepare('UPDATE tenants SET status = ? WHERE id = ?');
        $stmt->execute([$status, $tenantId]);
        if ($stmt->rowCount() === 0) {
            fail_error('not_found', 'Tenant not found.', 404);
        }
        if ($status === 'active') {
            $pdo->prepare('UPDATE subscriptions SET status = "active" WHERE tenant_id = ? AND status IN ("past_due","cancelled")')->execute([$tenantId]);
        } else {
            // Suspension ends the workspace members' active sessions.
            $pdo->prepare('UPDATE sessions SET revoked_at = NOW()
                WHERE revoked_at IS NULL AND user_id IN (SELECT user_id FROM tenant_members WHERE tenant_id = ?)')
                ->execute([$tenantId]);
        }
        audit('admin.tenant_' . ($status === 'suspended' ? 'suspended' : 'activated'), 'tenant', (string) $tenantId, [], $tenantId);
        json_out([
            'ok' => true, 'source' => 'mysql', 'csrf' => csrf_token(),
            'tenants' => admin_tenants(), 'metrics' => admin_metrics(),
        ]);
        // no break

    case 'change_plan':
        $tenantId = (int) param($data, 'tenant_id', 1, 20, 'Tenant');
        $code = param($data, 'plan_code', 2, 40, 'Plan');
        $stmt = $pdo->prepare('SELECT id, code FROM plans WHERE code = ? LIMIT 1');
        $stmt->execute([$code]);
        $plan = $stmt->fetch();
        if (!$plan) {
            fail('Unknown plan.', 422);
        }
        $pdo->prepare('UPDATE subscriptions SET plan_id = ? WHERE tenant_id = ?')->execute([(int) $plan['id'], $tenantId]);
        audit('admin.plan_changed', 'tenant', (string) $tenantId, ['plan' => $code], $tenantId);
        json_out([
            'ok' => true, 'source' => 'mysql', 'csrf' => csrf_token(),
            'tenants' => admin_tenants(), 'metrics' => admin_metrics(),
        ]);
        // no break

    case 'set_pricing_mode':
        $mode = (string) ($data['mode'] ?? '');
        if (!in_array($mode, ['paid', 'free'], true)) {
            fail_error('invalid_mode', 'Pricing mode must be paid or free.', 422);
        }
        db()->prepare('INSERT INTO platform_settings (setting_key, setting_value) VALUES ("pricing_mode", ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')->execute([$mode]);
        audit('admin.pricing_mode_changed', 'platform', 'pricing', ['mode' => $mode]);
        json_out(['ok' => true, 'source' => 'mysql', 'pricing_mode' => $mode, 'plans' => admin_plans()]);
        // no break

    case 'update_plan':
        $planId = (int) param($data, 'plan_id', 1, 20, 'Plan');
        $name = param($data, 'name', 2, 60, 'Plan name');
        $price = (int) param($data, 'monthly_price_kes', 1, 9, 'Monthly price');
        $parcelLimit = (int) param($data, 'parcel_limit', 1, 9, 'Parcel limit');
        $staffLimit = (int) param($data, 'staff_limit', 1, 9, 'Team limit');
        $features = $data['features'] ?? [];
        if (!is_array($features)) {
            $features = array_filter(array_map('trim', explode("
", (string) $features)));
        }
        $features = array_values(array_filter(array_map(fn ($f) => trim((string) $f), $features), fn ($f) => $f !== ''));
        if ($price < 0 || $parcelLimit < 0 || $staffLimit < 0) {
            fail_error('invalid_values', 'Amounts and limits cannot be negative.', 422);
        }
        $stmt = $pdo->prepare('UPDATE plans SET name = ?, monthly_price_kes = ?, parcel_limit = ?, staff_limit = ?,
            features = ?, is_active = ? WHERE id = ?');
        $stmt->execute([
            $name, $price, $parcelLimit, $staffLimit,
            json_encode(array_slice($features, 0, 12), JSON_UNESCAPED_SLASHES),
            !empty($data['is_active']) ? 1 : 0, $planId,
        ]);
        if ($stmt->rowCount() === 0) {
            fail_error('not_found', 'Plan not found or unchanged.', 404);
        }
        audit('admin.plan_edited', 'plan', (string) $planId, ['name' => $name, 'price' => $price, 'active' => !empty($data['is_active'])]);
        json_out(['ok' => true, 'source' => 'mysql', 'plans' => admin_plans()]);
        // no break

    case 'save_channel':
        $originalCode = isset($data['original_code']) && is_scalar($data['original_code'])
            ? strtolower(trim((string) $data['original_code'])) : '';
        $code = strtolower(param($data, 'code', 2, 20, 'Channel code'));
        if (!preg_match('/^[a-z0-9_]+$/', $code)) {
            fail_error('invalid_code', 'Channel code can only use lowercase letters, numbers and underscores.', 422,
                [], ['code' => 'Use lowercase letters, numbers or underscores.']);
        }
        $label = param($data, 'label', 1, 40, 'Channel label');
        $catalog = channel_provider_catalog();
        $providerRaw = isset($data['provider']) && is_scalar($data['provider']) ? trim((string) $data['provider']) : '';
        $provider = $providerRaw !== '' ? $providerRaw : 'log';
        if (!isset($catalog[$provider])) {
            fail_error('unknown_provider', 'Choose one of the supported messaging providers.', 422,
                [], ['provider' => 'Unsupported provider.']);
        }
        $providerDef = $catalog[$provider];
        $existingConfig = [];
        if ($originalCode !== '') {
            $existingStmt = $pdo->prepare('SELECT config FROM communication_channels WHERE code = ? LIMIT 1');
            $existingStmt->execute([$originalCode]);
            $existingConfig = channel_config_decode((string) ($existingStmt->fetchColumn() ?: ''));
        }
        $senderRaw = isset($data['sender']) && is_scalar($data['sender']) ? trim((string) $data['sender']) : '';
        if (mb_strlen($senderRaw) > 160) {
            fail_error('invalid_sender', 'Sender is too long (max 160 characters).', 422, [], ['sender' => 'Too long.']);
        }
        /* Provider settings arrive as an object from the channel form. A JSON
           string is still accepted for API clients calling the endpoint directly. */
        $configInput = $data['config'] ?? null;
        if (is_string($configInput) && trim($configInput) !== '') {
            $decoded = json_decode(trim($configInput), true);
            $configInput = is_array($decoded) ? $decoded : false;
            if ($configInput === false) {
                fail_error('invalid_config', 'Provider settings must be a JSON object.', 422,
                    [], ['config' => 'Invalid JSON.']);
            }
        }
        $allowedKeys = array_column($providerDef['fields'], 'key');
        $cleanConfig = [];
        if (is_array($configInput)) {
            foreach ($configInput as $key => $value) {
                $key = trim((string) $key);
                if ($key === '' || !in_array($key, $allowedKeys, true) || !is_scalar($value)) {
                    continue; // ignore unknown keys from older payloads
                }
                $value = trim((string) $value);
                if ($value === '' || $value === '••••••••') {
                    if (isset($existingConfig[$key])) {
                        $cleanConfig[$key] = (string) $existingConfig[$key];
                    }
                    continue;
                }
                if (mb_strlen($value) > 4000) {
                    fail_error('invalid_config', 'Provider setting "' . $key . '" is too long (max 4000 characters).', 422,
                        [], ['config' => 'A provider setting is too long.']);
                }
                $cleanConfig[$key] = $value;
            }
        }
        foreach ($providerDef['fields'] as $field) {
            if (!empty($field['required']) && ($cleanConfig[$field['key']] ?? '') === '') {
                fail_error('missing_config', $field['label'] . ' is required for ' . $providerDef['label'] . '.', 422,
                    [], ['config' => $field['label'] . ' is required.']);
            }
        }
        try {
            $config = !empty($cleanConfig) ? channel_config_encode($cleanConfig) : null;
        } catch (Throwable $e) {
            error_log('Channel credential encryption failed: ' . $e->getMessage());
            fail_error('encryption_not_configured', 'Provider credentials cannot be saved because APP_CONFIG_KEY is missing or invalid in the production .env. Add a strong APP_CONFIG_KEY, then save again.', 500);
        }
        $active = !empty($data['is_active']) ? 1 : 0;
        if ($originalCode === '') {
            $exists = $pdo->prepare('SELECT 1 FROM communication_channels WHERE code = ? LIMIT 1');
            $exists->execute([$code]);
            if ($exists->fetchColumn()) {
                fail_error('duplicate_code', 'A channel with that code already exists.', 409,
                    [], ['code' => 'Already in use.']);
            }
            $nextOrder = (int) $pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM communication_channels')->fetchColumn();
            $pdo->prepare('INSERT INTO communication_channels (code, label, provider, sender, config, is_active, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$code, $label, $provider, $senderRaw !== '' ? $senderRaw : null, $config, $active, $nextOrder]);
            $auditAction = 'admin.channel_created';
        } else {
            if ($originalCode !== $code) {
                fail_error('immutable_code', 'The channel code cannot be changed. Create a new channel instead.', 422,
                    [], ['code' => 'The code cannot be changed.']);
            }
            $stmt = $pdo->prepare('UPDATE communication_channels SET label = ?, provider = ?, sender = ?, config = ?, is_active = ?
                WHERE code = ?');
            $stmt->execute([$label, $provider, $senderRaw !== '' ? $senderRaw : null, $config, $active, $originalCode]);
            if ($stmt->rowCount() === 0) {
                // 0 rows also means "no change", so verify the row really exists.
                $check = $pdo->prepare('SELECT 1 FROM communication_channels WHERE code = ? LIMIT 1');
                $check->execute([$originalCode]);
                if (!$check->fetchColumn()) {
                    fail_error('not_found', 'Channel not found.', 404);
                }
            }
            $auditAction = 'admin.channel_updated';
        }
        audit($auditAction, 'channel', $code, [
            'provider' => $provider, 'active' => (bool) $active,
            'has_config' => $config !== null, 'has_sender' => $senderRaw !== '',
        ]);
        json_out(['ok' => true, 'source' => 'mysql', 'channels' => notification_channels(false, true)]);
        // no break

    case 'create_user':
        $name = param($data, 'name', 2, 120, 'Name');
        $phone = require_phone($data);
        $email = isset($data['email']) && trim((string) $data['email']) !== '' ? param($data, 'email', 5, 160, 'Email') : null;
        $role = param($data, 'role', 5, 30, 'Role');
        $allowedRoles = ['merchant', 'employee', 'freelancer', 'customer'];
        if (!in_array($role, $allowedRoles, true)) {
            fail_error('invalid_role', 'Role must be merchant, employee, freelancer or customer.', 422);
        }
        $tenantId = isset($data['tenant_id']) && (int) $data['tenant_id'] > 0 ? (int) $data['tenant_id'] : null;
        if (in_array($role, ['merchant', 'employee'], true) && !$tenantId) {
            fail_error('tenant_required', 'Choose a workspace for a merchant or employee.', 422);
        }
        if ($tenantId) {
            $t = $pdo->prepare('SELECT id, name FROM tenants WHERE id = ? LIMIT 1');
            $t->execute([$tenantId]);
            if (!$t->fetch()) {
                fail_error('tenant_not_found', 'Workspace not found.', 404);
            }
        }
        $exists = $pdo->prepare('SELECT id FROM users WHERE phone = ? LIMIT 1');
        $exists->execute([$phone]);
        if ($exists->fetch()) {
            fail_error('duplicate_phone', 'That phone number is already registered.', 409);
        }
        $credential = admin_credential_for($role);
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO users (name, email, phone, role, status, password_hash, pin_hash)
                VALUES (?, ?, ?, ?, "active", ?, ?)');
            $stmt->execute([
                $name, $email, $phone, $role,
                in_array($role, ['freelancer', 'customer'], true) ? null : $credential['hash'],
                $role === 'freelancer' ? $credential['hash'] : null,
            ]);
            $userId = (int) $pdo->lastInsertId();
            if ($tenantId) {
                $memberRole = 'staff';
                if ($role === 'merchant') {
                    $owner = $pdo->prepare('SELECT 1 FROM tenant_members WHERE tenant_id = ? AND role IN ("owner","admin") LIMIT 1');
                    $owner->execute([$tenantId]);
                    $memberRole = $owner->fetchColumn() ? 'admin' : 'owner';
                }
                $pdo->prepare('INSERT INTO tenant_members (tenant_id, user_id, role) VALUES (?, ?, ?)')
                    ->execute([$tenantId, $userId, $memberRole]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('create_user failed: ' . $e->getMessage());
            fail_error('create_failed', 'Could not create the user.', 500);
        }
        audit('admin.user_created', 'user', (string) $userId, ['role' => $role, 'phone' => $phone]);
        json_out([
            'ok' => true, 'source' => 'mysql',
            'credential' => ['label' => $credential['label'], 'value' => $credential['value']],
            'users' => admin_users(), 'metrics' => admin_metrics(),
        ]);
        // no break

    case 'update_user_email':
        $userId = (int) param($data, 'user_id', 1, 20, 'User');
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        if ($email === '' || !email_valid($email)) {
            fail_error('invalid_email', 'Enter a valid email address.', 422);
        }
        $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        if (!$stmt->fetch()) fail_error('not_found', 'User not found.', 404);
        $dup = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
        $dup->execute([$email, $userId]);
        if ($dup->fetch()) fail_error('email_taken', 'That email address is already in use.', 409);
        $pdo->prepare('UPDATE users SET email = ?, email_verified_at = NULL WHERE id = ?')->execute([$email, $userId]);
        audit('admin.user_email_updated', 'user', (string) $userId, ['email' => $email]);
        json_out(['ok' => true, 'source' => 'mysql', 'users' => admin_users(), 'message' => 'Email updated. The customer can now choose email OTP.']);
        // no break

    case 'user_status':
        $userId = (int) param($data, 'user_id', 1, 20, 'User');
        $status = (string) ($data['status'] ?? '');
        if (!in_array($status, ['active', 'suspended'], true)) {
            fail_error('invalid_status', 'Status must be active or suspended.', 422);
        }
        if ($userId === (int) $admin['id']) {
            fail_error('cannot_change_self', 'You cannot change your own account status.', 422);
        }
        $stmt = $pdo->prepare('SELECT id, role, status FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $target = $stmt->fetch();
        if (!$target) {
            fail_error('not_found', 'User not found.', 404);
        }
        if ($target['role'] === 'platform_admin' && $status === 'suspended') {
            $admins = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE role = "platform_admin" AND status = "active"')->fetchColumn();
            if ($admins <= 1) {
                fail_error('last_admin', 'The last active platform admin cannot be suspended.', 422);
            }
        }
        $pdo->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$status, $userId]);
        if ($status === 'suspended') {
            admin_revoke_user_sessions($userId);
        }
        audit('admin.user_' . ($status === 'suspended' ? 'suspended' : 'activated'), 'user', (string) $userId);
        json_out(['ok' => true, 'source' => 'mysql', 'users' => admin_users(), 'metrics' => admin_metrics()]);
        // no break

    case 'verify_user_email':
        $userId = (int) param($data, 'user_id', 1, 20, 'User');
        $stmt = $pdo->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $email = trim((string) ($stmt->fetchColumn() ?: ''));
        if ($email === '') fail_error('email_missing', 'Add an email address before verifying it.', 422);
        $pdo->prepare('UPDATE users SET email_verified_at = NOW() WHERE id = ?')->execute([$userId]);
        audit('admin.user_email_verified', 'user', (string) $userId, ['email' => $email]);
        json_out(['ok' => true, 'source' => 'mysql', 'users' => admin_users(), 'message' => 'Email marked as verified.']);
        // no break

    case 'restore_user':
        $userId = (int) param($data, 'user_id', 1, 20, 'User');
        $stmt = $pdo->prepare('SELECT id, role, status FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $target = $stmt->fetch();
        if (!$target) fail_error('not_found', 'User not found.', 404);
        if (($target['status'] ?? '') !== 'deleted') {
            fail_error('not_deleted', 'Only soft-deleted users can be restored.', 422);
        }
        $pdo->prepare('UPDATE users SET status = "active" WHERE id = ?')->execute([$userId]);
        audit('admin.user_restored', 'user', (string) $userId, ['role' => $target['role']]);
        json_out(['ok' => true, 'source' => 'mysql', 'users' => admin_users(), 'metrics' => admin_metrics(), 'message' => 'User restored to active status.']);
        // no break

    case 'reset_user_credentials':
        $userId = (int) param($data, 'user_id', 1, 20, 'User');
        $stmt = $pdo->prepare('SELECT id, role FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $target = $stmt->fetch();
        if (!$target) {
            fail_error('not_found', 'User not found.', 404);
        }
        $credential = admin_credential_for($target['role']);
        if ($target['role'] === 'freelancer') {
            $pdo->prepare('UPDATE users SET pin_hash = ? WHERE id = ?')->execute([$credential['hash'], $userId]);
        } elseif ($target['role'] === 'customer') {
            $pdo->prepare('UPDATE users SET otp_hash = NULL, otp_expires_at = NULL WHERE id = ?')->execute([$userId]);
        } else {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$credential['hash'], $userId]);
        }
        admin_revoke_user_sessions($userId);
        audit('admin.user_credentials_reset', 'user', (string) $userId, ['role' => $target['role']]);
        json_out(['ok' => true, 'source' => 'mysql', 'credential' => ['label' => $credential['label'], 'value' => $credential['value']], 'users' => admin_users()]);
        // no break

    case 'delete_user':
        $userId = (int) param($data, 'user_id', 1, 20, 'User');
        if ($userId === (int) $admin['id']) {
            fail_error('cannot_delete_self', 'You cannot delete your own account.', 422);
        }
        $stmt = $pdo->prepare('SELECT id, role FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $target = $stmt->fetch();
        if (!$target) {
            fail_error('not_found', 'User not found.', 404);
        }
        if ($target['role'] === 'platform_admin') {
            $admins = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE role = "platform_admin" AND status = "active"')->fetchColumn();
            if ($admins <= 1) {
                fail_error('last_admin', 'The last platform admin cannot be deleted.', 422);
            }
        }
        $pdo->prepare('UPDATE users SET status = "deleted" WHERE id = ?')->execute([$userId]);
        admin_revoke_user_sessions($userId);
        audit('admin.user_deleted', 'user', (string) $userId, ['role' => $target['role']]);
        json_out(['ok' => true, 'source' => 'mysql', 'users' => admin_users(), 'metrics' => admin_metrics()]);
        // no break

    case 'update_claim':
        $claimId = (int) param($data, 'claim_id', 1, 20, 'Claim');
        $status = (string) ($data['status'] ?? '');
        if (!in_array($status, ['under_review', 'approved', 'rejected', 'paid'], true)) {
            fail_error('invalid_status', 'Unsupported claim status.', 422);
        }
        $stmt = $pdo->prepare('SELECT c.*, p.tracking, p.value_kes, p.tier FROM claims c LEFT JOIN parcels p ON p.id = c.parcel_id WHERE c.id = ? LIMIT 1');
        $stmt->execute([$claimId]);
        $claim = $stmt->fetch();
        if (!$claim) { fail_error('not_found', 'Claim not found.', 404); }
        $note = isset($data['resolution_note']) && is_scalar($data['resolution_note']) ? trim((string) $data['resolution_note']) : '';
        if (in_array($status, ['approved', 'rejected', 'paid'], true) && mb_strlen($note) < 5) {
            fail_error('reason_required', 'A decision reason of at least 5 characters is required.', 422);
        }
        $claimValue = (int) ($claim['estimated_value_kes'] ?? $claim['value_kes'] ?? 0);
        $dualRequired = $claimValue > 5000;
        $payoutRef = null;
        if ($status === 'paid') {
            if ($claim['status'] !== 'approved') {
                fail_error('must_approve_first', 'The claim must be approved before a payout can be released.', 422);
            }
            if (empty($claim['payout_hold_until']) || strtotime((string) $claim['payout_hold_until']) > time()) {
                fail_error('payout_hold_active', 'The payout hold period is still active' . (!empty($claim['payout_hold_until']) ? ' until ' . $claim['payout_hold_until'] : '') . '.', 422);
            }
            if ($dualRequired && (int) $claim['approved_by'] === (int) $admin['id']) {
                fail_error('separate_releaser', 'For claims above KES 5,000 a different admin must release the payout.', 422);
            }
            $cap = ($claim['tier'] ?? 'A') === 'B' ? 50000 : 10000;
            $cap = min((int) ($claim['value_kes'] ?? 0), $cap);
            $amount = isset($data['payout_kes']) && is_numeric($data['payout_kes']) ? (int) $data['payout_kes'] : (int) ($claim['estimated_value_kes'] ?? 0);
            if ($amount <= 0) { fail_error('invalid_payout', 'Enter a payout amount.', 422); }
            if ($amount > $cap) { fail_error('payout_cap_exceeded', 'The maximum payout for this parcel is KES ' . number_format($cap) . '.', 422); }
            $payoutRef = 'PAY-' . strtoupper(random_code(8));
            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE claims SET status = "paid", resolution_note = ?, payout_ref = ?, payout_amount_kes = ?, paid_at = NOW(), decided_at = NOW() WHERE id = ?')
                    ->execute([$note, $payoutRef, $amount, $claimId]);
                record_transaction([
                    'reference' => $payoutRef, 'tenant_id' => (int) $claim['tenant_id'], 'user_id' => (int) $admin['id'],
                    'type' => 'claim_payout', 'amount_kes' => $amount, 'method' => 'MANUAL',
                    'subject_type' => 'claim', 'subject_id' => (string) $claimId,
                    'metadata' => ['ref' => $claim['ref'], 'parcel' => $claim['tracking'] ?? ''],
                ]);
                $pdo->prepare('INSERT INTO claim_payouts (claim_id, tenant_id, provider, mode, amount_kes, status, provider_ref, external_ref, idempotency_key, requested_by, requested_at, completed_at)
                    VALUES (?, ?, "manual", "demo", ?, "sent", ?, ?, ?, ?, NOW(), NOW())')
                    ->execute([$claimId, (int) $claim['tenant_id'], $amount, $payoutRef, $payoutRef, 'manual-payout-' . $claimId, (int) $admin['id']]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                error_log('claim payout failed: ' . $e->getMessage());
                fail_error('payout_failed', 'Could not record the payout.', 500);
            }
            claim_event($claimId, 'paid', 'Payout released: ' . $payoutRef, (int) $admin['id']);
            audit('admin.claim_paid', 'claim', (string) $claimId, ['ref' => $claim['ref'], 'payout_ref' => $payoutRef, 'amount' => $amount, 'reason' => $note]);
            notify_claim_status([
                'id' => $claimId, 'ref' => $claim['ref'], 'tenant_id' => (int) $claim['tenant_id'],
                'user_id' => $claim['user_id'] !== null ? (int) $claim['user_id'] : null,
            ], ['id' => $claim['parcel_id'], 'tracking' => $claim['tracking'] ?? ''], 'paid', $note);
            dispatch_webhooks((int) $claim['tenant_id'], 'claim.updated', ['claim' => $claim['ref'], 'status' => 'paid']);
            json_out(['ok' => true, 'source' => 'mysql', 'claims' => admin_claims(),
                'transactions' => admin_transactions(), 'metrics' => admin_metrics(), 'payout_ref' => $payoutRef]);
            // no break
        }
        if ($status === 'approved') {
            if ($dualRequired && empty($claim['first_approved_by'])) {
                db()->prepare('UPDATE claims SET status = "under_review", first_approved_by = ?, first_approved_at = NOW(), resolution_note = ? WHERE id = ?')
                    ->execute([(int) $admin['id'], $note, $claimId]);
                claim_event($claimId, 'first_approved', $note, (int) $admin['id']);
                audit('admin.claim_first_approved', 'claim', (string) $claimId, ['ref' => $claim['ref'], 'reason' => $note]);
                json_out(['ok' => true, 'source' => 'mysql', 'claims' => admin_claims(),
                    'message' => 'First approval recorded. A second admin must approve this claim (value over KES 5,000).']);
                // no break
            }
            if ($dualRequired && (int) $claim['first_approved_by'] === (int) $admin['id']) {
                fail_error('same_approver', 'A different admin must provide the second approval.', 422);
            }
            db()->prepare('UPDATE claims SET status = "approved", approved_by = ?, resolution_note = ?, decided_at = NOW(),
                payout_hold_until = DATE_ADD(NOW(), INTERVAL 48 HOUR) WHERE id = ?')
                ->execute([(int) $admin['id'], $note, $claimId]);
            claim_event($claimId, 'approved', $note, (int) $admin['id']);
            audit('admin.claim_approved', 'claim', (string) $claimId, ['ref' => $claim['ref'], 'reason' => $note, 'dual' => $dualRequired]);
            notify_claim_status([
                'id' => $claimId, 'ref' => $claim['ref'], 'tenant_id' => (int) $claim['tenant_id'],
                'user_id' => $claim['user_id'] !== null ? (int) $claim['user_id'] : null,
            ], ['id' => $claim['parcel_id'], 'tracking' => $claim['tracking'] ?? ''], 'approved', $note);
            dispatch_webhooks((int) $claim['tenant_id'], 'claim.updated', ['claim' => $claim['ref'], 'status' => 'approved']);
            json_out(['ok' => true, 'source' => 'mysql', 'claims' => admin_claims(),
                'message' => 'Claim approved. Payout is on hold for 48 hours.']);
            // no break
        }
        if ($status === 'rejected') {
            db()->prepare('UPDATE claims SET status = "rejected", resolution_note = ?, decided_at = NOW(),
                appeal_deadline = DATE_ADD(NOW(), INTERVAL 7 DAY), first_approved_by = NULL, first_approved_at = NULL, approved_by = NULL WHERE id = ?')
                ->execute([$note, $claimId]);
            claim_event($claimId, 'rejected', $note, (int) $admin['id']);
        } else {
            db()->prepare('UPDATE claims SET status = "under_review", resolution_note = COALESCE(NULLIF(?, ""), resolution_note) WHERE id = ?')
                ->execute([$note, $claimId]);
            claim_event($claimId, 'under_review', $note ?: null, (int) $admin['id']);
        }
        audit('admin.claim_' . $status, 'claim', (string) $claimId, ['ref' => $claim['ref'], 'reason' => $note]);
        if (in_array($status, ['approved', 'rejected', 'paid'], true)) {
            notify_claim_status([
                'id' => $claimId, 'ref' => $claim['ref'], 'tenant_id' => (int) $claim['tenant_id'],
                'user_id' => $claim['user_id'] !== null ? (int) $claim['user_id'] : null,
            ], ['id' => $claim['parcel_id'], 'tracking' => $claim['tracking'] ?? ''], $status, $note);
        }
        dispatch_webhooks((int) $claim['tenant_id'], 'claim.updated', ['claim' => $claim['ref'], 'status' => $status]);
        json_out(['ok' => true, 'source' => 'mysql', 'claims' => admin_claims(),
            'transactions' => admin_transactions(), 'metrics' => admin_metrics()]);
        // no break

    case 'reverse_payout':
        $claimId = (int) param($data, 'claim_id', 1, 20, 'Claim');
        $reason = param($data, 'reason', 5, 255, 'Reversal reason');
        $stmt = $pdo->prepare('SELECT * FROM claims WHERE id = ? LIMIT 1');
        $stmt->execute([$claimId]);
        $claim = $stmt->fetch();
        if (!$claim) { fail_error('not_found', 'Claim not found.', 404); }
        if ($claim['status'] !== 'paid') { fail_error('not_paid', 'Only paid claims can be reversed.', 422); }
        $ref = 'REV-' . strtoupper(random_code(8));
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE claims SET status = "reversed", reversal_ref = ?, reversed_at = NOW(), resolution_note = ? WHERE id = ?')
                ->execute([$ref, $reason, $claimId]);
            record_transaction([
                'reference' => $ref, 'tenant_id' => (int) $claim['tenant_id'], 'user_id' => (int) $admin['id'],
                'type' => 'refund', 'amount_kes' => (int) ($claim['payout_amount_kes'] ?? 0), 'method' => 'MANUAL',
                'subject_type' => 'claim', 'subject_id' => (string) $claimId,
                'metadata' => ['reason' => $reason, 'reversal_of' => $claim['payout_ref'] ?? null],
            ]);
            $pdo->prepare('UPDATE claim_payouts SET status = "reversed", completed_at = NOW() WHERE claim_id = ? AND status = "sent" ORDER BY id DESC LIMIT 1')->execute([$claimId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('claim reversal failed: ' . $e->getMessage());
            fail_error('reversal_failed', 'Could not record the reversal.', 500);
        }
        claim_event($claimId, 'reversed', $reason, (int) $admin['id']);
        $parcelStmt = $pdo->prepare('SELECT tracking FROM parcels WHERE id = ? LIMIT 1');
        $parcelStmt->execute([$claim['parcel_id']]);
        $parcelTracking = (string) ($parcelStmt->fetchColumn() ?: '');
        notify_claim_status([
            'id' => $claimId, 'ref' => $claim['ref'], 'tenant_id' => (int) $claim['tenant_id'],
            'user_id' => $claim['user_id'] !== null ? (int) $claim['user_id'] : null,
        ], ['id' => $claim['parcel_id'], 'tracking' => $parcelTracking], 'reversed', $reason);
        audit('admin.claim_reversed', 'claim', (string) $claimId, ['reversal_ref' => $ref, 'reason' => $reason]);
        json_out(['ok' => true, 'source' => 'mysql', 'claims' => admin_claims(), 'transactions' => admin_transactions(), 'reversal_ref' => $ref]);
        // no break

    case 'record_recovery':
        $claimId = (int) param($data, 'claim_id', 1, 20, 'Claim');
        $recoveryStatus = (string) ($data['recovery_status'] ?? 'requested');
        if (!in_array($recoveryStatus, ['requested', 'recovered', 'rejected', 'none'], true)) {
            fail_error('invalid_recovery_status', 'Unsupported recovery status.', 422);
        }
        $stmt = $pdo->prepare('SELECT * FROM claims WHERE id = ? LIMIT 1');
        $stmt->execute([$claimId]);
        $claim = $stmt->fetch();
        if (!$claim) { fail_error('not_found', 'Claim not found.', 404); }
        if (!in_array($claim['status'], ['paid', 'reversed'], true)) {
            fail_error('not_recoverable', 'Recovery can only be recorded for paid/reversed claims.', 422);
        }
        $amount = isset($data['recovery_amount_kes']) && is_numeric($data['recovery_amount_kes']) ? (int) $data['recovery_amount_kes'] : null;
        $ref = isset($data['recovery_ref']) && is_scalar($data['recovery_ref']) ? mb_substr(trim((string) $data['recovery_ref']), 0, 64) : null;
        $note = isset($data['recovery_note']) && is_scalar($data['recovery_note']) ? mb_substr(trim((string) $data['recovery_note']), 0, 255) : null;
        db()->prepare('UPDATE claims SET recovery_status = ?, recovery_ref = ?, recovery_amount_kes = ?, recovery_note = ? WHERE id = ?')
            ->execute([$recoveryStatus, $ref, $amount, $note, $claimId]);
        $recoveryRailStatus = $recoveryStatus === 'recovered' ? 'recovered' : ($recoveryStatus === 'rejected' ? 'rejected' : ($recoveryStatus === 'none' ? 'cancelled' : 'processing'));
        $railExists = db()->prepare('SELECT id FROM claim_recoveries WHERE claim_id = ? ORDER BY id DESC LIMIT 1');
        $railExists->execute([$claimId]);
        $railId = (int) ($railExists->fetchColumn() ?: 0);
        if ($railId > 0) {
            db()->prepare('UPDATE claim_recoveries SET status = ?, amount_kes = ?, reference = ?, note = ?, completed_at = IF(? IN ("recovered","rejected","cancelled"), NOW(), completed_at) WHERE id = ?')
                ->execute([$recoveryRailStatus, $amount, $ref, $note, $recoveryRailStatus, $railId]);
        } else {
            db()->prepare('INSERT INTO claim_recoveries (claim_id, tenant_id, provider, mode, status, amount_kes, reference, external_ref, note, requested_by, completed_at)
                VALUES (?, ?, "manual", "demo", ?, ?, ?, ?, ?, ?, IF(? IN ("recovered","rejected","cancelled"), NOW(), NULL))')
                ->execute([$claimId, (int) $claim['tenant_id'], $recoveryRailStatus, $amount, $ref,
                    'MAN-REC-' . strtoupper(random_code(8)), $note, (int) $admin['id'], $recoveryRailStatus]);
        }
        claim_event($claimId, 'recovery_' . $recoveryStatus, $note, (int) $admin['id']);
        audit('admin.claim_recovery', 'claim', (string) $claimId, ['status' => $recoveryStatus, 'amount' => $amount, 'ref' => $ref]);
        json_out(['ok' => true, 'source' => 'mysql', 'claims' => admin_claims()]);
        // no break

    case 'add_blacklist':
        $scope = (string) ($data['scope'] ?? 'phone');
        if (!in_array($scope, ['phone', 'user', 'tenant'], true)) {
            fail_error('invalid_scope', 'Blacklist scope must be phone, user or tenant.', 422);
        }
        $rawValue = trim((string) param($data, 'value', 2, 190, 'Value'));
        $value = $scope === 'phone' ? phone_key($rawValue) : $rawValue;
        if ($value === '') {
            fail_error('invalid_value', 'Enter a valid value to blacklist.', 422);
        }
        $reason = isset($data['reason']) && is_scalar($data['reason']) ? mb_substr(trim((string) $data['reason']), 0, 255) : null;
        $days = isset($data['expires_days']) && is_numeric($data['expires_days']) ? max(1, (int) $data['expires_days']) : null;
        $pdo->prepare('INSERT INTO claim_blacklist (scope, value, reason, is_active, expires_at, created_by)
            VALUES (?, ?, ?, 1, ' . ($days !== null ? 'DATE_ADD(NOW(), INTERVAL ' . $days . ' DAY)' : 'NULL') . ', ?)
            ON DUPLICATE KEY UPDATE reason = VALUES(reason), is_active = 1, expires_at = VALUES(expires_at)')
            ->execute([$scope, $value, $reason, (int) $admin['id']]);
        audit('admin.blacklist_added', 'blacklist', $scope . ':' . $value, ['reason' => $reason, 'days' => $days]);
        json_out(['ok' => true, 'source' => 'mysql', 'claim_blacklist' => admin_claim_blacklist(),
            'claims_analytics' => admin_claim_analytics(), 'message' => 'Blacklist entry saved.']);
        // no break

    case 'remove_blacklist':
        $blacklistId = (int) param($data, 'blacklist_id', 1, 20, 'Blacklist entry');
        $pdo->prepare('UPDATE claim_blacklist SET is_active = 0 WHERE id = ?')->execute([$blacklistId]);
        audit('admin.blacklist_removed', 'blacklist', (string) $blacklistId);
        json_out(['ok' => true, 'source' => 'mysql', 'claim_blacklist' => admin_claim_blacklist(),
            'claims_analytics' => admin_claim_analytics(), 'message' => 'Blacklist entry deactivated.']);
        // no break

    case 'remove_email_suppression':
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            fail_error('invalid_email', 'Enter a valid email address.', 422);
        }
        $removed = email_unsuppress($email);
        audit('admin.email_suppression_removed', 'email', $email, ['removed' => $removed]);
        json_out(['ok' => true, 'source' => 'mysql', 'removed' => $removed,
            'email_deliverability' => email_deliverability_data(), 'message' => $removed ? 'Address unsuppressed.' : 'No suppression found.']);
        // no break

    case 'save_claim_rails':
        $view = claim_rails_save($data, (int) $admin['id']);
        json_out(['ok' => true, 'source' => 'mysql', 'claim_rails' => $view,
            'message' => !empty($view['callback_secret_once'])
                ? 'Claim rails saved. Copy the callback secret now — it will not be shown again.'
                : 'Claim rails saved.']);
        // no break

    case 'regenerate_claim_callback_secret':
        $secret = claim_rails_regenerate_callback_secret((int) $admin['id']);
        json_out(['ok' => true, 'source' => 'mysql', 'callback_secret' => $secret,
            'claim_rails' => claim_rails_admin_view(),
            'message' => 'New callback secret generated. Copy it now — the old one no longer works.'], 201);
        // no break

    case 'initiate_claim_payout':
        $claimId = (int) param($data, 'claim_id', 1, 20, 'Claim');
        $amount = isset($data['amount_kes']) && is_numeric($data['amount_kes']) ? (int) $data['amount_kes'] : 0;
        $note = mb_substr(trim((string) ($data['note'] ?? '')), 0, 255);
        $stmt = $pdo->prepare('SELECT c.*, p.tracking, p.receiver_phone, p.value_kes, p.tier
            FROM claims c LEFT JOIN parcels p ON p.id = c.parcel_id WHERE c.id = ? LIMIT 1');
        $stmt->execute([$claimId]);
        $claim = $stmt->fetch();
        if (!$claim) { fail_error('not_found', 'Claim not found.', 404); }
        $result = claim_payout_initiate($claim, $admin, $amount, $note);
        json_out(['ok' => true, 'source' => 'mysql', 'payout' => $result,
            'claims' => admin_claims(), 'transactions' => admin_transactions(),
            'message' => $result['message'] ?? 'Payout processed.']);
        // no break

    case 'request_claim_recovery':
        $claimId = (int) param($data, 'claim_id', 1, 20, 'Claim');
        $amount = isset($data['amount_kes']) && $data['amount_kes'] !== '' && is_numeric($data['amount_kes']) ? (int) $data['amount_kes'] : null;
        $note = mb_substr(trim((string) ($data['note'] ?? '')), 0, 255);
        $stmt = $pdo->prepare('SELECT c.*, p.tracking FROM claims c LEFT JOIN parcels p ON p.id = c.parcel_id WHERE c.id = ? LIMIT 1');
        $stmt->execute([$claimId]);
        $claim = $stmt->fetch();
        if (!$claim) { fail_error('not_found', 'Claim not found.', 404); }
        $result = claim_recovery_request($claim, $admin, $amount, $note);
        json_out(['ok' => $result['ok'], 'source' => 'mysql', 'recovery' => $result,
            'claims' => admin_claims(), 'message' => $result['message'] ?? ($result['error'] ?? 'Recovery requested.')]);
        // no break

    case 'save_scan_actions':
        $items = isset($data['actions']) && is_array($data['actions']) ? $data['actions'] : [];
        $saved = scan_action_save($items);
        audit('admin.scan_actions_updated', 'platform', 'scan_actions', ['actions' => $saved]);
        json_out(['ok' => true, 'source' => 'mysql', 'scan_actions' => $saved,
            'message' => 'Scan action wording and order updated.']);
        // no break

    case 'create_partner_point':
        $name = param($data, 'name', 2, 120, 'Point name');
        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        if (!preg_match('/^[A-Z0-9-]{3,24}$/', $code)) {
            fail_error('invalid_point_code', 'Point code must be 3–24 uppercase letters, numbers or hyphens.', 422);
        }
        $carrierSlug = (string) ($data['partner_slug'] ?? '');
        $carrierCheck = $pdo->prepare('SELECT name FROM partners WHERE slug = ? LIMIT 1');
        $carrierCheck->execute([$carrierSlug]);
        $carrierName = (string) ($carrierCheck->fetchColumn() ?: '');
        if ($carrierName === '') { fail_error('invalid_carrier', 'Choose a valid courier partner.', 422); }
        $pointType = (string) ($data['point_type'] ?? 'multi');
        if (!in_array($pointType, ['dropoff', 'handover', 'arrival', 'collection', 'multi'], true)) {
            fail_error('invalid_point_type', 'Choose a valid point type.', 422);
        }
        $location = mb_substr(trim((string) ($data['location'] ?? '')), 0, 160);
        $workspaceId = isset($data['tenant_id']) && $data['tenant_id'] !== '' ? (int) $data['tenant_id'] : null;
        if ($workspaceId !== null) {
            $tenantCheck = $pdo->prepare('SELECT 1 FROM tenants WHERE id = ? LIMIT 1');
            $tenantCheck->execute([$workspaceId]);
            if ($tenantCheck->fetchColumn() === false) { fail_error('invalid_workspace', 'Choose a valid workspace.', 422); }
        }
        $token = admin_point_token();
        $manualEntry = !isset($data['manual_entry_enabled']) || !empty($data['manual_entry_enabled']) ? 1 : 0;
        try {
            $pdo->prepare('INSERT INTO partner_points (tenant_id, partner_slug, name, code, point_type, location, access_token_prefix, access_token_hash, is_active, manual_entry_enabled)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)')
                ->execute([$workspaceId, $carrierSlug, $name, $code, $pointType, $location !== '' ? $location : null, $token['prefix'], $token['hash'], $manualEntry]);
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') { fail_error('duplicate_point_code', 'That point code is already in use.', 409); }
            throw $e;
        }
        $pointId = (int) $pdo->lastInsertId();
        audit('admin.point_created', 'partner_point', (string) $pointId, ['code' => $code, 'carrier' => $carrierSlug]);
        json_out(['ok' => true, 'source' => 'mysql', 'point_token' => $token['raw'],
            'partner_points' => admin_partner_points(),
            'message' => 'Scan point created. Copy the access token now — it will not be shown again.'], 201);
        // no break

    case 'update_partner_point':
        $pointId = (int) param($data, 'point_id', 1, 20, 'Point');
        $name = param($data, 'name', 2, 120, 'Point name');
        $location = mb_substr(trim((string) ($data['location'] ?? '')), 0, 160);
        $pointType = (string) ($data['point_type'] ?? 'multi');
        if (!in_array($pointType, ['dropoff', 'handover', 'arrival', 'collection', 'multi'], true)) {
            fail_error('invalid_point_type', 'Choose a valid point type.', 422);
        }
        $workspaceId = isset($data['tenant_id']) && $data['tenant_id'] !== '' ? (int) $data['tenant_id'] : null;
        if ($workspaceId !== null) {
            $tenantCheck = $pdo->prepare('SELECT 1 FROM tenants WHERE id = ? LIMIT 1');
            $tenantCheck->execute([$workspaceId]);
            if ($tenantCheck->fetchColumn() === false) { fail_error('invalid_workspace', 'Choose a valid workspace.', 422); }
        }
        $manualEntry = !isset($data['manual_entry_enabled']) || !empty($data['manual_entry_enabled']) ? 1 : 0;
        $stmt = $pdo->prepare('UPDATE partner_points SET tenant_id = ?, name = ?, point_type = ?, location = ?, manual_entry_enabled = ? WHERE id = ?');
        $stmt->execute([$workspaceId, $name, $pointType, $location !== '' ? $location : null, $manualEntry, $pointId]);
        audit('admin.point_updated', 'partner_point', (string) $pointId, ['name' => $name]);
        json_out(['ok' => true, 'source' => 'mysql', 'partner_points' => admin_partner_points(),
            'message' => 'Scan point updated.']);
        // no break

    case 'toggle_partner_point':
        $pointId = (int) param($data, 'point_id', 1, 20, 'Point');
        $stmt = $pdo->prepare('SELECT is_active FROM partner_points WHERE id = ? LIMIT 1');
        $stmt->execute([$pointId]);
        $active = $stmt->fetchColumn();
        if ($active === false) { fail_error('not_found', 'Point not found.', 404); }
        $newActive = ((int) $active) === 1 ? 0 : 1;
        $pdo->prepare('UPDATE partner_points SET is_active = ? WHERE id = ?')->execute([$newActive, $pointId]);
        audit('admin.point_toggled', 'partner_point', (string) $pointId, ['active' => $newActive]);
        json_out(['ok' => true, 'source' => 'mysql', 'partner_points' => admin_partner_points(),
            'message' => $newActive ? 'Point activated.' : 'Point disabled.']);
        // no break

    case 'toggle_point_manual_entry':
        $pointId = (int) param($data, 'point_id', 1, 20, 'Point');
        $stmt = $pdo->prepare('SELECT manual_entry_enabled FROM partner_points WHERE id = ? LIMIT 1');
        $stmt->execute([$pointId]);
        $current = $stmt->fetchColumn();
        if ($current === false) { fail_error('not_found', 'Point not found.', 404); }
        $newValue = ((int) $current) === 1 ? 0 : 1;
        $pdo->prepare('UPDATE partner_points SET manual_entry_enabled = ? WHERE id = ?')->execute([$newValue, $pointId]);
        audit('admin.point_manual_entry_toggled', 'partner_point', (string) $pointId, ['enabled' => $newValue]);
        json_out(['ok' => true, 'source' => 'mysql', 'partner_points' => admin_partner_points(),
            'message' => $newValue ? 'Manual tracking entry enabled.' : 'Manual tracking entry disabled.']);
        // no break

    case 'regenerate_point_token':
        $pointId = (int) param($data, 'point_id', 1, 20, 'Point');
        $check = $pdo->prepare('SELECT code FROM partner_points WHERE id = ? LIMIT 1');
        $check->execute([$pointId]);
        $code = $check->fetchColumn();
        if ($code === false) { fail_error('not_found', 'Point not found.', 404); }
        $token = admin_point_token();
        $pdo->prepare('UPDATE partner_points SET access_token_prefix = ?, access_token_hash = ? WHERE id = ?')
            ->execute([$token['prefix'], $token['hash'], $pointId]);
        audit('admin.point_token_regenerated', 'partner_point', (string) $pointId, ['code' => $code]);
        json_out(['ok' => true, 'source' => 'mysql', 'point_token' => $token['raw'],
            'partner_points' => admin_partner_points(),
            'message' => 'New point token generated. Copy it now — the old token no longer works.'], 201);
        // no break

    case 'delete_partner_point':
        $pointId = (int) param($data, 'point_id', 1, 20, 'Point');
        $stmt = $pdo->prepare('DELETE FROM partner_points WHERE id = ?');
        $stmt->execute([$pointId]);
        if ($stmt->rowCount() === 0) { fail_error('not_found', 'Point not found.', 404); }
        audit('admin.point_deleted', 'partner_point', (string) $pointId);
        json_out(['ok' => true, 'source' => 'mysql', 'partner_points' => admin_partner_points(),
            'message' => 'Scan point deleted.']);
        // no break

    case 'save_template':
        $templateId = isset($data['template_id']) && $data['template_id'] !== '' ? (int) param($data, 'template_id', 1, 20, 'Template') : 0;
        $existing = null;
        if ($templateId > 0) {
            $stmt = $pdo->prepare('SELECT * FROM email_templates WHERE id = ? LIMIT 1');
            $stmt->execute([$templateId]);
            $existing = $stmt->fetch();
            if (!$existing) { fail_error('not_found', 'Template not found.', 404); }
        }
        $key = $existing ? (string) $existing['template_key'] : strtolower((string) param($data, 'template_key', 3, 60, 'Template key'));
        if (!preg_match('/^[a-z0-9_]+$/', $key)) {
            fail_error('invalid_template_key', 'Template key can only use lowercase letters, numbers and underscores.', 422);
        }
        $label = param($data, 'label', 2, 120, 'Template label');
        $stage = mb_substr(trim((string) ($data['stage'] ?? 'General')), 0, 40) ?: 'General';
        $recipient = mb_substr(trim((string) ($data['recipient'] ?? 'Customer')), 0, 120) ?: 'Customer';
        $trigger = mb_substr(trim((string) ($data['trigger'] ?? '')), 0, 190);
        $subject = mb_substr(trim((string) ($data['subject'] ?? '')), 0, 190);
        $bodyText = trim((string) ($data['body_text'] ?? ''));
        $bodyHtml = trim((string) ($data['body_html'] ?? ''));
        if (mb_strlen($bodyText) > 200000 || mb_strlen($bodyHtml) > 500000) {
            fail_error('template_too_large', 'Template content is too large.', 422);
        }
        $active = !empty($data['is_active']) ? 1 : 0;
        if ($existing) {
            $pdo->prepare('UPDATE email_templates SET label = ?, stage = ?, recipient = ?, trigger_text = ?, subject = ?,
                body_text = ?, body_html = ?, is_active = ? WHERE id = ?')
                ->execute([$label, $stage, $recipient, $trigger, $subject !== '' ? $subject : null,
                    $bodyText !== '' ? $bodyText : null, $bodyHtml !== '' ? $bodyHtml : null, $active, $templateId]);
            audit('admin.template_edited', 'email_template', (string) $templateId, ['key' => $key]);
        } else {
            $check = $pdo->prepare('SELECT 1 FROM email_templates WHERE template_key = ? LIMIT 1');
            $check->execute([$key]);
            if ($check->fetchColumn() !== false) {
                fail_error('duplicate_template', 'A template with that key already exists.', 409);
            }
            $pdo->prepare('INSERT INTO email_templates (template_key, label, stage, recipient, trigger_text, subject, body_text, body_html, is_active, is_system)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0)')
                ->execute([$key, $label, $stage, $recipient, $trigger, $subject !== '' ? $subject : null,
                    $bodyText !== '' ? $bodyText : null, $bodyHtml !== '' ? $bodyHtml : null, $active]);
            $templateId = (int) $pdo->lastInsertId();
            audit('admin.template_created', 'email_template', (string) $templateId, ['key' => $key]);
        }
        json_out(['ok' => true, 'source' => 'mysql', 'comms_templates' => comms_template_table(),
            'message' => $existing ? 'Template saved.' : 'Template added.']);
        // no break

    case 'delete_template':
        $templateId = (int) param($data, 'template_id', 1, 20, 'Template');
        $stmt = $pdo->prepare('SELECT template_key, is_system FROM email_templates WHERE id = ? LIMIT 1');
        $stmt->execute([$templateId]);
        $template = $stmt->fetch();
        if (!$template) { fail_error('not_found', 'Template not found.', 404); }
        if ((int) $template['is_system'] === 1) {
            fail_error('system_template', 'System journey templates cannot be deleted; disable or edit them instead.', 422);
        }
        $pdo->prepare('DELETE FROM email_templates WHERE id = ?')->execute([$templateId]);
        audit('admin.template_deleted', 'email_template', (string) $templateId, ['key' => $template['template_key']]);
        json_out(['ok' => true, 'source' => 'mysql', 'comms_templates' => comms_template_table(), 'message' => 'Template deleted.']);
        // no break

    case 'send_test_sms':
        $to = require_phone($data);
        $body = 'Mzigo.online: this is a test SMS from your Infobip configuration. Delivery is working.';
        notification_send('sms', [
            'id' => (int) $admin['id'], 'name' => $admin['name'], 'email' => '',
            'phone' => $to, 'comm_channel' => null,
        ], 'Mzigo.online SMS test', $body, 'sms_test', ['id' => 0, 'tracking' => ''], 'log', 'user', (string) $admin['id'], []);
        $stmt = $pdo->prepare("SELECT status, provider, error FROM notifications WHERE recipient = ? AND template = 'sms_test' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$to]);
        $result = $stmt->fetch() ?: ['status' => 'unknown', 'provider' => null, 'error' => null];
        audit('admin.sms_test', 'platform', 'sms', ['to' => $to, 'status' => $result['status']]);
        json_out(['ok' => true, 'source' => 'mysql', 'status' => $result['status'], 'provider' => $result['provider'],
            'error' => $result['error'], 'message' => $result['status'] === 'sent' ? 'Test SMS sent.' : ('Test SMS ' . $result['status'] . ($result['error'] ? ': ' . $result['error'] : ''))]);
        // no break

    case 'send_test_email':
        $to = param($data, 'email', 5, 160, 'Email');
        if (!email_valid($to)) { fail_error('invalid_email', 'Enter a valid email address.', 422); }
        $body = "This is a test email from your Mzigo.online SMTP configuration. Delivery is working.";
        notification_send('email', [
            'id' => (int) $admin['id'], 'name' => $admin['name'], 'email' => $to,
            'phone' => '', 'comm_channel' => null,
        ], 'Mzigo.online SMTP test', $body, 'smtp_test', ['id' => 0, 'tracking' => ''], 'log', 'user', (string) $admin['id'], []);
        $stmt = $pdo->prepare("SELECT status, provider, error FROM notifications WHERE recipient = ? AND template = 'smtp_test' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$to]);
        $result = $stmt->fetch() ?: ['status' => 'unknown', 'provider' => null, 'error' => null];
        audit('admin.smtp_test', 'platform', 'email', ['to' => $to, 'status' => $result['status']]);
        json_out(['ok' => true, 'source' => 'mysql', 'status' => $result['status'], 'provider' => $result['provider'],
            'error' => $result['error'], 'message' => $result['status'] === 'sent' ? 'Test email sent.' : ('Test email ' . $result['status'] . ($result['error'] ? ': ' . $result['error'] : ''))]);
        // no break

    case 'toggle_demo_accounts':
        $show = !empty($data['show']);
        $pdo->prepare('INSERT INTO platform_settings (setting_key, setting_value) VALUES ("show_demo_accounts", ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
            ->execute([$show ? '1' : '0']);
        audit('admin.demo_accounts_toggled', 'platform', 'show_demo_accounts', ['show' => $show]);
        json_out(['ok' => true, 'source' => 'mysql', 'show_demo_accounts' => $show]);
        // no break

    case 'refund_invoice':
        $invoiceId = (int) param($data, 'invoice_id', 1, 20, 'Invoice');
        $stmt = $pdo->prepare('SELECT i.*, t.name AS tenant_name FROM invoices i JOIN tenants t ON t.id = i.tenant_id WHERE i.id = ? LIMIT 1');
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch();
        if (!$invoice) {
            fail_error('not_found', 'Invoice not found.', 404);
        }
        if ($invoice['status'] !== 'paid') {
            fail_error('not_paid', 'Only paid invoices can be refunded.', 422);
        }
        $refundRef = 'REF-' . strtoupper(random_code(8));
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE invoices SET status = "refunded", refunded_at = NOW(), refund_ref = ? WHERE id = ?')
                ->execute([$refundRef, $invoiceId]);
            record_transaction([
                'reference' => $refundRef, 'tenant_id' => (int) $invoice['tenant_id'], 'user_id' => (int) $admin['id'],
                'type' => 'refund', 'amount_kes' => (int) $invoice['amount_kes'], 'method' => 'MANUAL',
                'subject_type' => 'invoice', 'subject_id' => (string) $invoiceId,
                'metadata' => ['number' => $invoice['number'], 'reason' => 'admin refund'],
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('refund_invoice failed: ' . $e->getMessage());
            fail_error('refund_failed', 'Could not record the refund.', 500);
        }
        audit('admin.invoice_refunded', 'invoice', (string) $invoiceId, ['number' => $invoice['number'], 'reference' => $refundRef]);
        json_out(['ok' => true, 'source' => 'mysql', 'invoices' => admin_invoices(),
            'transactions' => admin_transactions(), 'metrics' => admin_metrics(), 'refund_ref' => $refundRef]);
        // no break

    case 'revoke_session':
        $sessionId = param($data, 'session_id', 10, 128, 'Session');
        if (hash_equals(session_id(), $sessionId)) {
            fail_error('cannot_revoke_current', 'You cannot revoke the session you are using.', 422);
        }
        $stmt = $pdo->prepare('UPDATE sessions SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL');
        $stmt->execute([$sessionId]);
        if ($stmt->rowCount() === 0) {
            fail_error('not_found', 'Session not found or already revoked.', 404);
        }
        audit('admin.session_revoked', 'session', substr($sessionId, 0, 16));
        json_out(['ok' => true, 'source' => 'mysql', 'sessions' => admin_sessions(), 'message' => 'Session revoked.']);
        // no break

    case 'contact_status':
        $contactId = (int) param($data, 'contact_id', 1, 20, 'Message');
        $status = (string) ($data['status'] ?? '');
        if (!in_array($status, ['new', 'read', 'replied', 'archived'], true)) {
            fail_error('invalid_status', 'Status must be new, read, replied or archived.', 422);
        }
        $noteRaw = isset($data['admin_note']) && is_scalar($data['admin_note']) ? trim((string) $data['admin_note']) : '';
        if (mb_strlen($noteRaw) > 500) {
            fail_error('note_too_long', 'Internal note is too long (max 500 characters).', 422,
                [], ['admin_note' => 'Too long.']);
        }
        $stmt = $pdo->prepare('SELECT subject, email FROM contact_messages WHERE id = ? LIMIT 1');
        $stmt->execute([$contactId]);
        $message = $stmt->fetch();
        if (!$message) {
            fail_error('not_found', 'Message not found.', 404);
        }
        $pdo->prepare('UPDATE contact_messages SET status = ?, admin_note = ? WHERE id = ?')
            ->execute([$status, $noteRaw !== '' ? $noteRaw : null, $contactId]);
        audit('admin.contact_' . $status, 'contact', (string) $contactId,
            ['subject' => $message['subject'], 'email' => $message['email']]);
        json_out(['ok' => true, 'source' => 'mysql', 'contact_messages' => admin_contact_messages()]);
        // no break

    case 'contact_note_add': {
        $contactId = (int) param($data, 'contact_id', 1, 20, 'Message');
        $note = param($data, 'note', 2, 2000, 'Note');
        $stmt = $pdo->prepare('SELECT id FROM contact_messages WHERE id = ? LIMIT 1');
        $stmt->execute([$contactId]);
        if (!$stmt->fetch()) { fail_error('not_found', 'Message not found.', 404); }
        $pdo->prepare('INSERT INTO contact_message_notes (message_id, admin_id, note, created_at) VALUES (?, ?, ?, NOW())')
            ->execute([$contactId, (int) $admin['id'], $note]);
        audit('admin.contact_note_added', 'contact', (string) $contactId, ['note' => mb_substr($note, 0, 80)]);
        json_out(['ok' => true, 'source' => 'mysql', 'contact_messages' => admin_contact_messages()]);
    }

    case 'contact_note_delete': {
        $noteId = (int) param($data, 'note_id', 1, 20, 'Note');
        $stmt = $pdo->prepare('SELECT message_id FROM contact_message_notes WHERE id = ? LIMIT 1');
        $stmt->execute([$noteId]);
        $messageId = (int) ($stmt->fetchColumn() ?: 0);
        if (!$messageId) { fail_error('not_found', 'Note not found.', 404); }
        $pdo->prepare('DELETE FROM contact_message_notes WHERE id = ?')->execute([$noteId]);
        audit('admin.contact_note_deleted', 'contact', (string) $messageId, ['note_id' => $noteId]);
        json_out(['ok' => true, 'source' => 'mysql', 'contact_messages' => admin_contact_messages()]);
    }

    case 'contact_reply': {
        $contactId = (int) param($data, 'contact_id', 1, 20, 'Message');
        $body = param($data, 'body', 2, 4000, 'Reply');
        $stmt = $pdo->prepare('SELECT * FROM contact_messages WHERE id = ? LIMIT 1');
        $stmt->execute([$contactId]);
        $message = $stmt->fetch();
        if (!$message) { fail_error('not_found', 'Message not found.', 404); }
        $active = contact_reply_channels();
        if (!$active) {
            fail('No active notification channels are configured. Enable email/SMS/WhatsApp in Communications first.', 422);
        }
        $requested = $data['channels'] ?? [];
        if (!is_array($requested) || !$requested) {
            $requested = [];
            foreach ($active as $channel) {
                if ($channel['code'] === 'email' && !empty($message['email'])) { $requested[] = 'email'; break; }
                if (in_array($channel['code'], ['sms', 'whatsapp'], true) && !empty($message['phone'])) { $requested[] = $channel['code']; break; }
            }
        }
        $requested = array_values(array_unique(array_intersect(array_map('strval', $requested), array_column($active, 'code'))));
        if (!$requested) {
            fail('Choose at least one configured channel with a recipient for this message.', 422);
        }
        $sent = 0;
        foreach ($requested as $channelCode) {
            $result = contact_send_reply($message, $channelCode, $body);
            $recipient = $channelCode === 'email' ? (string) $message['email'] : (string) ($message['phone'] ?? '');
            $pdo->prepare('INSERT INTO contact_message_replies (message_id, admin_id, channel, recipient, body, status, error, created_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, NOW())')
                ->execute([$contactId, (int) $admin['id'], $channelCode, $recipient, $body, $result['status'], $result['error'] ?: null]);
            if ($result['status'] === 'sent') $sent++;
        }
        $pdo->prepare("UPDATE contact_messages SET status = 'replied' WHERE id = ?")->execute([$contactId]);
        audit('admin.contact_replied', 'contact', (string) $contactId, ['channels' => $requested, 'sent' => $sent]);
        if ($sent === 0) {
            fail('The reply could not be delivered on the selected channel(s). Check the channel configuration.', 502);
        }
        json_out(['ok' => true, 'source' => 'mysql', 'contact_messages' => admin_contact_messages()]);
    }

    case 'contact_delete':
        $contactId = (int) param($data, 'contact_id', 1, 20, 'Message');
        $stmt = $pdo->prepare('DELETE FROM contact_messages WHERE id = ?');
        $stmt->execute([$contactId]);
        if ($stmt->rowCount() === 0) {
            fail_error('not_found', 'Message not found.', 404);
        }
        audit('admin.contact_deleted', 'contact', (string) $contactId);
        json_out(['ok' => true, 'source' => 'mysql', 'contact_messages' => admin_contact_messages()]);
        // no break

    case 'faq_save':
        $faqId = isset($data['faq_id']) && (int) $data['faq_id'] > 0
            ? (int) param($data, 'faq_id', 1, 10, 'FAQ') : 0;
        $question = param($data, 'question', 3, 200, 'Question');
        $answer = param($data, 'answer', 5, 2000, 'Answer');
        $sortOrder = isset($data['sort_order']) && is_numeric($data['sort_order']) ? (int) $data['sort_order'] : 0;
        if ($sortOrder < 0 || $sortOrder > 999) {
            fail_error('invalid_order', 'Sort order must be between 0 and 999.', 422, [], ['sort_order' => 'Out of range.']);
        }
        $faqActive = !empty($data['is_active']) ? 1 : 0;
        if ($faqId > 0) {
            $stmt = $pdo->prepare('UPDATE faqs SET question = ?, answer = ?, sort_order = ?, is_active = ? WHERE id = ?');
            $stmt->execute([$question, $answer, $sortOrder, $faqActive, $faqId]);
            $check = $pdo->prepare('SELECT 1 FROM faqs WHERE id = ? LIMIT 1');
            $check->execute([$faqId]);
            if (!$check->fetchColumn()) {
                fail_error('not_found', 'FAQ not found.', 404);
            }
            audit('admin.faq_updated', 'faq', (string) $faqId, ['question' => $question, 'active' => (bool) $faqActive]);
        } else {
            $pdo->prepare('INSERT INTO faqs (question, answer, sort_order, is_active) VALUES (?, ?, ?, ?)')
                ->execute([$question, $answer, $sortOrder, $faqActive]);
            $faqId = (int) $pdo->lastInsertId();
            audit('admin.faq_created', 'faq', (string) $faqId, ['question' => $question]);
        }
        json_out(['ok' => true, 'source' => 'mysql', 'faqs' => cms_faqs(false)]);
        // no break

    case 'faq_delete':
        $faqId = (int) param($data, 'faq_id', 1, 10, 'FAQ');
        $stmt = $pdo->prepare('DELETE FROM faqs WHERE id = ?');
        $stmt->execute([$faqId]);
        if ($stmt->rowCount() === 0) {
            fail_error('not_found', 'FAQ not found.', 404);
        }
        audit('admin.faq_deleted', 'faq', (string) $faqId);
        json_out(['ok' => true, 'source' => 'mysql', 'faqs' => cms_faqs(false)]);
        // no break

    case 'branding_upload':
        $kind = (string) ($data['kind'] ?? '');
        if (!in_array($kind, ['logo', 'favicon', 'hero'], true)) {
            fail_error('invalid_kind', 'Choose whether you are uploading a logo, favicon or hero image.', 422);
        }
        $file = $_FILES['file'] ?? null;
        if (!is_array($file)) {
            fail_error('upload_missing', 'Choose a file to upload.', 422);
        }
        $stored = cms_store_upload($kind, $file);
        audit('admin.branding_uploaded', 'branding', $kind, ['file' => $stored['filename']]);
        json_out(['ok' => true, 'source' => 'mysql', 'branding' => cms_branding(),
            'uploaded' => $kind]);
        // no break

    case 'branding_reset':
        cms_reset_branding();
        audit('admin.branding_reset', 'branding', 'branding');
        json_out(['ok' => true, 'source' => 'mysql', 'branding' => cms_branding()]);
        // no break

    case 'save_seo_settings':
        foreach (['seo_search_console_token', 'seo_bing_token', 'seo_cwv_api_key'] as $key) {
            $inputKey = str_replace('seo_', '', $key);
            $value = isset($data[$inputKey]) && is_scalar($data[$inputKey]) ? trim((string) $data[$inputKey]) : '';
            $value = mb_substr($value, 0, 255);
            db()->prepare('INSERT INTO platform_settings (setting_key, setting_value) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')->execute([$key, $value]);
        }
        audit('admin.seo_settings_updated', 'cms', 'seo', []);
        json_out(['ok' => true, 'source' => 'mysql', 'seo' => cms_seo_settings(), 'web_vitals' => cms_web_vitals_summary()]);
        // no break

    case 'save_social_links':
        foreach (cms_social_catalog() as $network => $meta) {
            $value = isset($data[$network]) && is_scalar($data[$network]) ? trim((string) $data[$network]) : '';
            $normalized = cms_social_normalize($network, $value);
            db()->prepare('INSERT INTO platform_settings (setting_key, setting_value) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
                ->execute(['social_' . $network, $normalized]);
        }
        db()->prepare('INSERT INTO platform_settings (setting_key, setting_value) VALUES ("brand_version", ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')->execute([(string) time()]);
        audit('admin.social_links_updated', 'cms', 'social', []);
        json_out(['ok' => true, 'source' => 'mysql', 'branding' => cms_branding()]);
        // no break

    case 'upload_newsletter_artwork': {
        $cfg = newsletter_save_artwork($_FILES['artwork'] ?? [], (int) $admin['id']);
        json_out(['ok' => true, 'source' => 'mysql', 'newsletter_prompt' => newsletter_admin_payload()]);
    }

    case 'remove_newsletter_artwork': {
        newsletter_remove_artwork((int) $admin['id']);
        json_out(['ok' => true, 'source' => 'mysql', 'newsletter_prompt' => newsletter_admin_payload()]);
    }

    case 'save_newsletter_prompt': {
        $input = is_array($data['config'] ?? null) ? $data['config'] : [];
        $cfg = newsletter_config_save($input);
        audit('admin.newsletter_prompt_saved', 'platform', 'newsletter_prompt', ['enabled' => $cfg['enabled'], 'channels' => $cfg['channels']]);
        json_out(['ok' => true, 'source' => 'mysql', 'newsletter_prompt' => newsletter_admin_payload()]);
    }

    case 'save_hero_copy':
        /* Landing-page hero title/subtitle (also used as the footer tagline).
           Empty values restore the bundled defaults. */
        $heroTitle = isset($data['hero_title']) && is_scalar($data['hero_title']) ? trim((string) $data['hero_title']) : '';
        $heroSubtitle = isset($data['hero_subtitle']) && is_scalar($data['hero_subtitle']) ? trim((string) $data['hero_subtitle']) : '';
        if ($heroTitle !== '' && (mb_strlen($heroTitle) < 2 || mb_strlen($heroTitle) > 80)) {
            fail_error('invalid_hero_title', 'Hero title must be between 2 and 80 characters.', 422,
                [], ['hero_title' => '2–80 characters.']);
        }
        if ($heroSubtitle !== '' && (mb_strlen($heroSubtitle) < 2 || mb_strlen($heroSubtitle) > 140)) {
            fail_error('invalid_hero_subtitle', 'Hero subtitle must be between 2 and 140 characters.', 422,
                [], ['hero_subtitle' => '2–140 characters.']);
        }
        db()->prepare('INSERT INTO platform_settings (setting_key, setting_value) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')->execute(['hero_title', $heroTitle]);
        db()->prepare('INSERT INTO platform_settings (setting_key, setting_value) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')->execute(['hero_subtitle', $heroSubtitle]);
        db()->prepare('INSERT INTO platform_settings (setting_key, setting_value) VALUES ("brand_version", ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')->execute([(string) time()]);
        audit('admin.hero_copy_updated', 'cms', 'hero', [
            'title' => $heroTitle !== '' ? $heroTitle : '(default)',
            'subtitle' => $heroSubtitle !== '' ? $heroSubtitle : '(default)',
        ]);
        json_out(['ok' => true, 'source' => 'mysql', 'branding' => cms_branding()]);
        // no break

    case 'mark_invoice_paid':
    case 'void_invoice':
        $invoiceId = (int) param($data, 'invoice_id', 1, 20, 'Invoice');
        $stmt = $pdo->prepare('SELECT * FROM invoices WHERE id = ? LIMIT 1');
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch();
        if (!$invoice) {
            fail_error('not_found', 'Invoice not found.', 404);
        }
        if ($action === 'mark_invoice_paid') {
            $manualRef = 'ADMIN-' . random_code(6);
            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE invoices SET status = "paid", paid_at = NOW(), method = "MANUAL", reference = ? WHERE id = ?')
                    ->execute([$manualRef, $invoiceId]);
                $pdo->prepare('UPDATE subscriptions SET status = "active", current_period_start = NOW(), current_period_end = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE tenant_id = ?')
                    ->execute([(int) $invoice['tenant_id']]);
                $pdo->prepare('UPDATE tenants SET status = "active" WHERE id = ?')->execute([(int) $invoice['tenant_id']]);
                record_transaction([
                    'reference' => $manualRef, 'tenant_id' => (int) $invoice['tenant_id'], 'user_id' => (int) $admin['id'],
                    'type' => 'manual', 'amount_kes' => (int) $invoice['amount_kes'], 'method' => 'MANUAL',
                    'subject_type' => 'invoice', 'subject_id' => (string) $invoiceId,
                    'metadata' => ['number' => $invoice['number'], 'by' => 'platform_admin'],
                ]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                error_log('admin mark_invoice_paid failed: ' . $e->getMessage());
                fail_error('payment_failed', 'Could not record the payment.', 500);
            }
            $auditAction = 'admin.invoice_paid';
        } else {
            $pdo->prepare('UPDATE invoices SET status = "void" WHERE id = ?')->execute([$invoiceId]);
            $auditAction = 'admin.invoice_voided';
        }
        audit($auditAction, 'invoice', (string) $invoiceId, ['number' => $invoice['number']], (int) $invoice['tenant_id']);
        json_out([
            'ok' => true, 'source' => 'mysql', 'csrf' => csrf_token(),
            'invoices' => admin_invoices(), 'metrics' => admin_metrics(), 'tenants' => admin_tenants(),
        ]);
        // no break

    case 'save_payout_config': {
        $clean = payout_config_save($data);
        audit('admin.payout_config_saved', 'platform', 'payout_config', ['mode' => $clean['mode'], 'schedule' => $clean['schedule']]);
        json_out(['ok' => true, 'payout_config' => $clean]);
    }

    case 'save_promise_config': {
        $hours = (int) param($data, 'hours', 1, 240, 'Promise hours');
        db()->prepare('INSERT INTO platform_settings (setting_key, setting_value) VALUES ("delivery_promise_hours", ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
            ->execute([(string) $hours]);
        audit('admin.promise_config_saved', 'platform', 'delivery_promise_hours', ['hours' => $hours]);
        json_out(['ok' => true, 'hours' => $hours]);
    }

    case 'save_runner_config': {
        $clean = runner_launch_config_save($data);
        audit('admin.runner_config_saved', 'platform', 'runner_launch_config', $clean);
        json_out(['ok' => true, 'runner_launch_config' => $clean]);
    }

    case 'process_payouts': {
        $cfg = payout_config();
        $rows = $pdo->query("SELECT * FROM runner_payouts WHERE status = 'pending' ORDER BY requested_at ASC LIMIT 50")->fetchAll();
        $processed = [];
        foreach ($rows as $payout) {
            if ($cfg['mode'] === 'live') {
                if (!mpesa_credentials_ready($cfg)) {
                    fail('Live payouts are not configured. Add Daraja credentials first.', 422);
                }
                $result = mpesa_b2c_payout($cfg, (string) $payout['phone'], (int) $payout['amount_kes'], 'Mzigo runner payout', (string) $payout['ref']);
                if (!$result['ok']) {
                    $pdo->prepare("UPDATE runner_payouts SET status = 'failed', note = ? WHERE id = ?")
                        ->execute([mb_substr($result['error'], 0, 255), (int) $payout['id']]);
                    $processed[] = ['ref' => $payout['ref'], 'status' => 'failed', 'error' => $result['error']];
                    continue;
                }
                $pdo->prepare("UPDATE runner_payouts SET status = 'processing', provider_ref = ?, note = 'Live Daraja B2C payout' WHERE id = ?")
                    ->execute([$result['provider_ref'], (int) $payout['id']]);
                runner_notify((int) $payout['runner_id'], 'Payout processing', 'KES ' . number_format((int) $payout['amount_kes']) . ' is being sent to ' . $payout['phone'] . '.');
                $processed[] = ['ref' => $payout['ref'], 'status' => 'processing'];
            } else {
                $pdo->prepare("UPDATE runner_payouts SET status = 'paid', paid_at = NOW(), provider_ref = ?, note = 'Demo M-Pesa payout (admin release)' WHERE id = ?")
                    ->execute(['MPESA-DEMO-' . strtoupper(bin2hex(random_bytes(3))), (int) $payout['id']]);
                runner_notify((int) $payout['runner_id'], 'Payout sent', 'KES ' . number_format((int) $payout['amount_kes']) . ' sent to ' . $payout['phone'] . ' (demo).');
                $processed[] = ['ref' => $payout['ref'], 'status' => 'paid'];
            }
        }
        audit('admin.runner_payouts_processed', 'platform', 'runner_payouts', ['count' => count($processed), 'mode' => $cfg['mode']]);
        json_out(['ok' => true, 'processed' => $processed]);
    }

    default:
        fail_error('unknown_action', 'Unknown admin action.', 400);
}
