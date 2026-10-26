<?php
/**
 * Mzigo.online — workspace settings: business profile, team members, API keys.
 *
 * GET  -> tenant, members, api keys, usage, role
 * POST verify_csrf + owner/admin:
 *   update_business   { name, email, phone }
 *   add_member        { name, email, phone, password?, role }
 *   remove_member     { user_id }
 *   create_api_key    { name }
 *   revoke_api_key    { key_id }
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_webhooks.php';
require_once __DIR__ . '/_crypto.php';
require_once __DIR__ . '/_recovery.php';
require_once __DIR__ . '/_tenant_branding.php';
require __DIR__ . '/_notifications.php';

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$user = require_user();
$tenant = current_tenant();
if (!$tenant) {
    fail_error('no_tenant', 'This account has no business workspace yet.', 403);
}
$ctx = ['tenant' => $tenant, 'user' => $user, 'role' => $tenant['role']];
$tenantId = (int) $tenant['id'];

function tenant_members_list(int $tenantId): array
{
    $stmt = db()->prepare(
        'SELECT u.id, u.name, u.email, u.phone, m.role, m.created_at
         FROM tenant_members m JOIN users u ON u.id = m.user_id
         WHERE m.tenant_id = ? ORDER BY m.role = "owner" DESC, u.name ASC'
    );
    $stmt->execute([$tenantId]);
    return $stmt->fetchAll();
}

function tenant_webhooks(int $tenantId): array
{
    $stmt = db()->prepare('SELECT id, url, events, is_active, created_at, last_delivery_at, last_status
        FROM webhooks WHERE tenant_id = ? ORDER BY id DESC LIMIT 20');
    $stmt->execute([$tenantId]);
    return $stmt->fetchAll();
}

function tenant_api_usage(int $tenantId): array
{
    $stmt = db()->prepare(
        'SELECT u.day, u.api_key_id, COALESCE(k.name, CONCAT("Key #", u.api_key_id)) AS key_name, u.requests
         FROM api_usage_daily u LEFT JOIN api_keys k ON k.id = u.api_key_id
         WHERE u.tenant_id = ? AND u.day >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
         ORDER BY u.day DESC, key_name ASC LIMIT 120'
    );
    $stmt->execute([$tenantId]);
    $rows = $stmt->fetchAll();
    $totalStmt = db()->prepare('SELECT COALESCE(SUM(requests),0) FROM api_usage_daily WHERE tenant_id = ? AND day >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)');
    $totalStmt->execute([$tenantId]);
    return ['rows' => $rows, 'total_30d' => (int) $totalStmt->fetchColumn()];
}

function tenant_api_keys(int $tenantId): array
{
    $stmt = db()->prepare(
        'SELECT id, name, prefix, scopes, last_used_at, created_at, revoked_at
         FROM api_keys WHERE tenant_id = ? ORDER BY created_at DESC'
    );
    $stmt->execute([$tenantId]);
    return $stmt->fetchAll();
}

if ($method === 'GET') {
    json_out([
        'ok'      => true,
        'source'  => 'mysql',
        'csrf'    => csrf_token(),
        'tenant'  => $tenant,
        'role'    => $tenant['role'],
        'usage'   => tenant_usage($tenantId),
        'members' => tenant_members_list($tenantId),
        'apiKeys' => (($ctx['role'] ?? '') === 'staff') ? [] : tenant_api_keys($tenantId),
        'webhooks' => (($ctx['role'] ?? '') === 'staff') ? [] : tenant_webhooks($tenantId),
        'webhook_events' => WEBHOOK_EVENTS,
        'api_usage' => (($ctx['role'] ?? '') === 'staff') ? ['rows' => [], 'total_30d' => 0] : tenant_api_usage($tenantId),
        'branding' => tenant_branding($tenantId),
        'rate_cards' => (($ctx['role'] ?? '') === 'staff') ? [] : tenant_rate_cards($tenantId),
        'rate_card_options' => (($ctx['role'] ?? '') === 'staff') ? ['carriers' => [], 'sizes' => []] : [
            'carriers' => db()->query('SELECT slug, name FROM partners ORDER BY name ASC')->fetchAll(),
            'sizes' => MZ_PACKAGE_SIZES,
        ],
        'webhook_deliveries' => (($ctx['role'] ?? '') === 'staff') ? [] : webhook_deliveries_for_tenant($tenantId),
        'twofa' => (function () use ($user) {
            $stmt = db()->prepare('SELECT enabled_at FROM user_2fa WHERE user_id = ? LIMIT 1');
            $stmt->execute([(int) $user['id']]);
            $row = $stmt->fetch();
            return ['enabled' => $row && !empty($row['enabled_at']), 'enabled_at' => $row['enabled_at'] ?? null,
                'recovery_remaining' => recovery_codes_remaining((int) $user['id'])];
        })(),
    ]);
}

require_post();
verify_csrf();
tenant_owner_or_admin($ctx);
$data = body_json();
$action = (string) ($data['action'] ?? $_GET['action'] ?? '');
$pdo = db();

switch ($action) {
    case 'update_business':
        $name = param($data, 'name', 2, 160, 'Business name');
        $email = param($data, 'email', 5, 160, 'Billing email');
        if (!email_valid($email)) {
            fail('Enter a valid email address.', 422, ['email' => 'Invalid email.']);
        }
        $phone = require_phone($data);
        $pdo->prepare('UPDATE tenants SET name = ?, email = ?, phone = ? WHERE id = ?')
            ->execute([$name, $email, $phone, $tenantId]);
        audit('tenant.updated', 'tenant', (string) $tenantId, ['name' => $name, 'email' => $email]);
        json_out([
            'ok' => true, 'source' => 'mysql', 'csrf' => csrf_token(),
            'tenant' => array_merge($tenant, ['name' => $name, 'email' => $email, 'phone' => $phone]),
            'message' => 'Business profile updated.',
        ]);
        // no break

    case 'add_member':
        $name = param($data, 'name', 3, 120, 'Name');
        $email = param($data, 'email', 5, 160, 'Email');
        if (!email_valid($email)) {
            fail('Enter a valid email address.', 422, ['email' => 'Invalid email.']);
        }
        $phone = require_phone($data);
        $role = isset($data['role']) && $data['role'] === 'admin' ? 'admin' : 'staff';
        $password = isset($data['password']) && is_scalar($data['password']) && strlen((string) $data['password']) >= 8
            ? (string) $data['password']
            : 'Welcome' . random_code(6) . '1';

        $usage = tenant_usage($tenantId);
        if ($usage['staff_used'] >= $usage['plan']['staff_limit']) {
            fail_error(
                'staff_limit',
                'The ' . $usage['plan']['name'] . ' plan allows ' . $usage['plan']['staff_limit'] . ' team members. Upgrade to add more.',
                402,
                ['limit' => $usage['plan']['staff_limit'], 'used' => $usage['staff_used'], 'upgrade_url' => '/billing']
            );
        }

        $stmt = $pdo->prepare('SELECT id, email FROM users WHERE email = ? OR phone = ? LIMIT 1');
        $stmt->execute([$email, $phone]);
        $existing = $stmt->fetch();
        if ($existing) {
            $memberId = (int) $existing['id'];
            $pdo->prepare('UPDATE users SET name = ? WHERE id = ?')->execute([$name, $memberId]);
        } else {
            $pdo->prepare('INSERT INTO users (name, email, phone, password_hash, role) VALUES (?, ?, ?, ?, "user")')
                ->execute([$name, $email, $phone, password_hash($password, PASSWORD_DEFAULT)]);
            $memberId = (int) $pdo->lastInsertId();
        }
        $check = $pdo->prepare('SELECT 1 FROM tenant_members WHERE tenant_id = ? AND user_id = ? LIMIT 1');
        $check->execute([$tenantId, $memberId]);
        if ($check->fetchColumn() !== false) {
            fail_error('already_member', 'That person is already on this workspace.', 409);
        }
        $pdo->prepare('INSERT INTO tenant_members (tenant_id, user_id, role) VALUES (?, ?, ?)')
            ->execute([$tenantId, $memberId, $role]);
        if (!$existing) {
            notify_team_invite($name, $email, (string) $tenant['name'], $password);
        }
        audit('team.member_added', 'user', (string) $memberId, ['role' => $role, 'email' => $email]);
        json_out([
            'ok' => true, 'source' => 'mysql', 'csrf' => csrf_token(),
            'members' => tenant_members_list($tenantId),
            'usage' => tenant_usage($tenantId),
            'temporary_password' => $existing ? null : $password,
            'message' => 'Team member added.',
        ], 201);
        // no break

    case 'remove_member':
        if ($tenant['role'] !== 'owner') {
            fail_error('forbidden', 'Only the workspace owner can remove members.', 403);
        }
        $memberId = (int) param($data, 'user_id', 1, 20, 'Member');
        if ($memberId === (int) $user['id']) {
            fail_error('cannot_remove_self', 'You cannot remove yourself from the workspace.', 422);
        }
        $stmt = $pdo->prepare('SELECT role FROM tenant_members WHERE tenant_id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$tenantId, $memberId]);
        $membership = $stmt->fetch();
        if (!$membership) {
            fail_error('not_found', 'Member not found.', 404);
        }
        if ($membership['role'] === 'owner') {
            fail_error('cannot_remove_owner', 'The workspace owner cannot be removed.', 422);
        }
        $pdo->prepare('DELETE FROM tenant_members WHERE tenant_id = ? AND user_id = ?')->execute([$tenantId, $memberId]);
        audit('team.member_removed', 'user', (string) $memberId);
        json_out([
            'ok' => true, 'source' => 'mysql', 'csrf' => csrf_token(),
            'members' => tenant_members_list($tenantId),
            'usage' => tenant_usage($tenantId),
        ]);
        // no break

    case 'create_api_key':
        $name = param($data, 'name', 2, 80, 'Key name');
        $count = $pdo->prepare('SELECT COUNT(*) FROM api_keys WHERE tenant_id = ? AND revoked_at IS NULL');
        $count->execute([$tenantId]);
        if ((int) $count->fetchColumn() >= 10) {
            fail_error('api_key_limit', 'You can keep up to 10 active API keys.', 402);
        }
        $scopes = isset($data['scopes']) && is_array($data['scopes']) ? array_values(array_intersect($data['scopes'], ['read', 'write'])) : ['read', 'write'];
        if (!$scopes) {
            fail_error('invalid_scopes', 'Choose at least one API scope.', 422);
        }
        $raw = 'mzk_' . bin2hex(random_bytes(20));
        $prefix = substr($raw, 0, 12);
        $pdo->prepare('INSERT INTO api_keys (tenant_id, name, prefix, key_hash, scopes) VALUES (?, ?, ?, ?, ?)')
            ->execute([$tenantId, $name, $prefix, hash('sha256', $raw), implode(',', $scopes)]);
        audit('api_key.created', 'api_key', (string) $pdo->lastInsertId(), ['name' => $name]);
        json_out([
            'ok' => true, 'source' => 'mysql', 'csrf' => csrf_token(),
            'key' => $raw,
            'keys' => tenant_api_keys($tenantId),
            'message' => 'API key created. Copy it now — it will not be shown again.',
        ], 201);
        // no break

    case 'create_webhook':
        $url = param($data, 'url', 8, 500, 'Webhook URL');
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
            fail_error('invalid_url', 'Enter a valid http(s) webhook URL.', 422);
        }
        if (!webhook_url_is_safe($url)) {
            fail_error('unsafe_webhook_url', 'Webhook URLs must resolve to a public internet host.', 422);
        }
        $events = isset($data['events']) && is_array($data['events']) ? array_values(array_intersect($data['events'], WEBHOOK_EVENTS)) : [];
        if (!$events) {
            fail_error('no_events', 'Choose at least one webhook event.', 422);
        }
        $secret = 'whsec_' . bin2hex(random_bytes(16));
        $pdo->prepare('INSERT INTO webhooks (tenant_id, url, secret, events) VALUES (?, ?, ?, ?)')
            ->execute([$tenantId, $url, secure_value_encrypt($secret), implode(',', $events)]);
        $id = (int) $pdo->lastInsertId();
        audit('webhook.created', 'webhook', (string) $id, ['url' => $url, 'events' => $events]);
        json_out(['ok' => true, 'source' => 'mysql', 'webhook_secret' => $secret, 'webhooks' => tenant_webhooks($tenantId),
            'message' => 'Webhook created. Copy the signing secret now — it will not be shown again.'], 201);
        // no break

    case 'delete_webhook':
        $webhookId = (int) param($data, 'webhook_id', 1, 20, 'Webhook');
        $stmt = $pdo->prepare('DELETE FROM webhooks WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$webhookId, $tenantId]);
        if ($stmt->rowCount() === 0) {
            fail_error('not_found', 'Webhook not found.', 404);
        }
        audit('webhook.deleted', 'webhook', (string) $webhookId);
        json_out(['ok' => true, 'source' => 'mysql', 'webhooks' => tenant_webhooks($tenantId)]);
        // no break

    case 'retry_webhook_delivery':
        if (!in_array($ctx['role'], ['owner', 'admin'], true)) {
            fail_error('forbidden', 'Only workspace owners and admins can retry webhooks.', 403);
        }
        $deliveryId = (int) param($data, 'delivery_id', 1, 20, 'Delivery');
        $result = retry_webhook_delivery($deliveryId, $tenantId);
        if (empty($result['ok'])) { fail_error('retry_failed', (string) ($result['error'] ?? 'Retry failed.'), (int) ($result['code'] ?? 422)); }
        json_out(['ok' => true, 'source' => 'mysql', 'result' => $result,
            'webhook_deliveries' => webhook_deliveries_for_tenant($tenantId),
            'message' => $result['message'] ?? 'Delivery retried.']);
        // no break

    case 'revoke_api_key':
        $keyId = (int) param($data, 'key_id', 1, 20, 'API key');
        $stmt = $pdo->prepare('UPDATE api_keys SET revoked_at = NOW() WHERE id = ? AND tenant_id = ? AND revoked_at IS NULL');
        $stmt->execute([$keyId, $tenantId]);
        if ($stmt->rowCount() === 0) {
            fail_error('not_found', 'API key not found.', 404);
        }
        audit('api_key.revoked', 'api_key', (string) $keyId);
        json_out([
            'ok' => true, 'source' => 'mysql', 'csrf' => csrf_token(),
            'keys' => tenant_api_keys($tenantId),
        ]);
        // no break

    case 'save_branding':
        $branding = tenant_branding_save($tenantId, $data, (int) $user['id']);
        json_out(['ok' => true, 'source' => 'mysql', 'branding' => $branding, 'message' => 'Workspace branding saved.']);
        // no break

    case 'upload_branding_logo':
        $file = $_FILES['logo'] ?? null;
        if (!is_array($file)) { fail_error('upload_missing', 'Choose a logo image.', 422); }
        $branding = tenant_branding_upload_logo($tenantId, $file, (int) $user['id']);
        json_out(['ok' => true, 'source' => 'mysql', 'branding' => $branding, 'message' => 'Logo uploaded.']);
        // no break

    case 'remove_branding_logo':
        $branding = tenant_branding_remove_logo($tenantId, (int) $user['id']);
        json_out(['ok' => true, 'source' => 'mysql', 'branding' => $branding, 'message' => 'Logo removed.']);
        // no break

    case 'save_rate_card':
        $id = isset($data['id']) && $data['id'] !== '' ? (int) $data['id'] : 0;
        $rateCards = tenant_rate_card_save($tenantId, $data, $id);
        json_out(['ok' => true, 'source' => 'mysql', 'rate_cards' => $rateCards, 'message' => 'Rate card saved.']);
        // no break

    case 'delete_rate_card':
        $id = (int) param($data, 'id', 1, 20, 'Rate card');
        $rateCards = tenant_rate_card_delete($tenantId, $id);
        json_out(['ok' => true, 'source' => 'mysql', 'rate_cards' => $rateCards, 'message' => 'Rate card deleted.']);
        // no break

    default:
        fail_error('unknown_action', 'Unknown settings action.', 400);
}
