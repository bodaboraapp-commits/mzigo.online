<?php
/**
 * Mzigo.online — admin controls for the MCP microservice privacy policy.
 *
 * GET  /api/admin-mcp.php        current policy (platform admin only)
 * POST /api/admin-mcp.php        { action: save | regenerate_token, ... }
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

function mcp_policy(): array
{
    $row = db()->query('SELECT * FROM mcp_privacy_policies WHERE id = 1 LIMIT 1')->fetch();
    if (!$row) {
        db()->prepare('INSERT INTO mcp_privacy_policies (id) VALUES (1) ON DUPLICATE KEY UPDATE id = id')->execute();
        $row = db()->query('SELECT * FROM mcp_privacy_policies WHERE id = 1 LIMIT 1')->fetch();
    }
    return [
        'enabled' => (int) $row['enabled'] === 1,
        'mode' => (string) $row['mode'],
        'token' => (string) ($row['token'] ?? ''),
        'allowed_scopes' => (string) $row['allowed_scopes'],
        'mask_fields' => (string) $row['mask_fields'],
        'max_rows' => (int) $row['max_rows'],
        'rate_limit_per_min' => (int) $row['rate_limit_per_min'],
        'tenant_isolation' => (int) $row['tenant_isolation'] === 1,
        'read_only' => (int) $row['read_only'] === 1,
        'retention_days' => (int) $row['retention_days'],
        'updated_at' => $row['updated_at'],
    ];
}

function mcp_write_microservice_config(array $policy): void
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'microservice';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $payload = [
        'enabled' => $policy['enabled'],
        'mode' => $policy['mode'],
        'token' => $policy['token'],
        'allowed_scopes' => array_values(array_filter(array_map('trim', explode(',', $policy['allowed_scopes'])))),
        'mask_fields' => array_values(array_filter(array_map('trim', explode(',', $policy['mask_fields'])))),
        'max_rows' => $policy['max_rows'],
        'rate_limit_per_min' => $policy['rate_limit_per_min'],
        'tenant_isolation' => $policy['tenant_isolation'],
        'read_only' => $policy['read_only'],
        'retention_days' => $policy['retention_days'],
    ];
    file_put_contents($dir . DIRECTORY_SEPARATOR . 'config.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

$admin = require_platform_admin();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_out(['ok' => true, 'source' => 'mysql', 'policy' => mcp_policy()]);
}

require_post();
verify_csrf();
$data = body_json();
$action = (string) ($data['action'] ?? 'save');

$policy = mcp_policy();
if ($action === 'regenerate_token') {
    $policy['token'] = 'mcp_' . bin2hex(random_bytes(24));
    db()->prepare('UPDATE mcp_privacy_policies SET token = ?, updated_by = ? WHERE id = 1')
        ->execute([$policy['token'], (int) $admin['id']]);
    mcp_write_microservice_config($policy);
    audit('admin.mcp_token_regenerated', 'mcp_policy', '1');
    json_out(['ok' => true, 'source' => 'mysql', 'policy' => $policy, 'message' => 'New MCP token generated.']);
}

if ($action !== 'save') {
    fail_error('unknown_action', 'Unknown MCP action.', 400);
}

$mode = ($data['mode'] ?? 'sandbox') === 'proxy' ? 'proxy' : 'sandbox';
$scopes = mb_substr(trim((string) ($data['allowed_scopes'] ?? '')), 0, 255);
$mask = mb_substr(trim((string) ($data['mask_fields'] ?? '')), 0, 255);
$maxRows = max(1, min(500, (int) ($data['max_rows'] ?? 50)));
$rpm = max(1, min(1000, (int) ($data['rate_limit_per_min'] ?? 60)));
$retention = max(1, min(3650, (int) ($data['retention_days'] ?? 30)));
$enabled = !empty($data['enabled']) ? 1 : 0;
$isolation = !empty($data['tenant_isolation']) ? 1 : 0;
$readOnly = !empty($data['read_only']) ? 1 : 0;

$policy = [
    'enabled' => $enabled === 1,
    'mode' => $mode,
    'token' => trim((string) ($data['token'] ?? '')),
    'allowed_scopes' => $scopes,
    'mask_fields' => $mask,
    'max_rows' => $maxRows,
    'rate_limit_per_min' => $rpm,
    'tenant_isolation' => $isolation === 1,
    'read_only' => $readOnly === 1,
    'retention_days' => $retention,
];
if ($policy['token'] === '') {
    $policy['token'] = 'mcp_' . bin2hex(random_bytes(24));
}

db()->prepare('UPDATE mcp_privacy_policies SET enabled = ?, mode = ?, token = ?, allowed_scopes = ?, mask_fields = ?,
    max_rows = ?, rate_limit_per_min = ?, tenant_isolation = ?, read_only = ?, retention_days = ?, updated_by = ?
    WHERE id = 1')
    ->execute([$enabled, $mode, $policy['token'], $scopes, $mask, $maxRows, $rpm, $isolation, $readOnly, $retention, (int) $admin['id']]);

mcp_write_microservice_config($policy);
audit('admin.mcp_policy_saved', 'mcp_policy', '1', ['enabled' => $enabled, 'mode' => $mode]);
json_out(['ok' => true, 'source' => 'mysql', 'policy' => $policy, 'message' => 'MCP privacy policy saved.']);
