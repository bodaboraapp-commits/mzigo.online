<?php
/**
 * Admin form for Firebase Cloud Messaging credentials.
 *
 * GET  /api/admin-firebase-push.php   current push configuration status
 * POST /api/admin-firebase-push.php   save VAPID key / service account JSON
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_crypto.php';
require dirname(__DIR__) . '/_server/firebase.php';
require __DIR__ . '/_notifications.php';

$admin = require_platform_admin();

function firebase_push_row(): array
{
    $stmt = db()->prepare('SELECT code, config, is_active FROM communication_channels WHERE code = ? LIMIT 1');
    $stmt->execute(['firebase']);
    $row = $stmt->fetch();
    if (!$row) {
        db()->prepare("INSERT INTO communication_channels (code, label, provider, config, is_active, sort_order)
            VALUES ('firebase', 'Firebase Phone Auth (SMS OTP)', 'firebase', NULL, 0,
                    (SELECT COALESCE(MAX(sort_order), 0) + 1 FROM communication_channels))")->execute();
        return ['code' => 'firebase', 'config' => null, 'is_active' => 0];
    }
    return $row;
}

function firebase_push_status(array $row): array
{
    $config = channel_config_decode((string) ($row['config'] ?? ''));
    $service = json_decode((string) ($config['service_account_json'] ?? ''), true);
    $c = firebase_config();
    return [
        'configured' => firebase_messaging_ready(),
        'active' => (int) ($row['is_active'] ?? 0) === 1,
        'project_id' => (string) ($c['project_id'] ?? ''),
        'vapid_key' => (string) ($config['vapid_key'] ?? ''),
        'has_vapid' => !empty($config['vapid_key']),
        'has_service_account' => !empty($config['service_account_json']),
        'service_account_email' => is_array($service) ? (string) ($service['client_email'] ?? '') : '',
        'has_server_key' => !empty($config['server_key']),
    ];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_out(['ok' => true, 'source' => 'mysql', 'push' => firebase_push_status(firebase_push_row())]);
}

require_post();
verify_csrf();
$data = body_json();
$row = firebase_push_row();
$config = channel_config_decode((string) ($row['config'] ?? ''));

$vapid = trim((string) ($data['vapid_key'] ?? ''));
if ($vapid !== '') {
    if (strlen($vapid) > 300) fail_error('invalid_vapid', 'The VAPID key is too long.', 422);
    $config['vapid_key'] = $vapid;
}
if (!empty($data['clear_vapid'])) unset($config['vapid_key']);

$serviceRaw = trim((string) ($data['service_account_json'] ?? ''));
if ($serviceRaw !== '') {
    if (strlen($serviceRaw) > 12000) fail_error('invalid_service_account', 'Service account JSON is too large.', 422);
    $service = json_decode($serviceRaw, true);
    if (!is_array($service) || empty($service['client_email']) || empty($service['private_key'])) {
        fail_error('invalid_service_account', 'Paste the full service-account JSON from Firebase.', 422);
    }
    $config['service_account_json'] = $serviceRaw;
}
if (!empty($data['clear_service_account'])) unset($config['service_account_json']);

$serverKey = trim((string) ($data['server_key'] ?? ''));
if ($serverKey !== '') {
    if (strlen($serverKey) > 500) fail_error('invalid_server_key', 'Legacy server key is too long.', 422);
    $config['server_key'] = $serverKey;
}
if (!empty($data['clear_server_key'])) unset($config['server_key']);

$active = !empty($data['active']) ? 1 : 0;
try {
    $encoded = channel_config_encode($config);
} catch (Throwable $e) {
    fail_error('encryption_not_configured', 'Add APP_CONFIG_KEY to the production .env before saving credentials.', 500);
}
db()->prepare('UPDATE communication_channels SET config = ?, is_active = ? WHERE code = ?')
    ->execute([$encoded, $active, 'firebase']);
audit('admin.firebase_push_saved', 'channel', 'firebase', [
    'active' => $active,
    'has_vapid' => !empty($config['vapid_key']),
    'has_service_account' => !empty($config['service_account_json']),
    'has_server_key' => !empty($config['server_key']),
]);
json_out([
    'ok' => true,
    'source' => 'mysql',
    'push' => firebase_push_status(firebase_push_row()),
    'message' => 'Firebase push credentials saved.',
]);
