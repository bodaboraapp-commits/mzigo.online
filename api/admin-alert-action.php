<?php
/** POST /api/admin-alert-action.php — acknowledge or escalate an overview alert. */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
$admin = require_platform_admin();
require_post();
verify_csrf();
$data = body_json();
$key = preg_replace('/[^a-z0-9_]/', '', (string) ($data['alert_key'] ?? '')) ?? '';
$action = (string) ($data['action'] ?? 'ack');
if ($key === '' || !in_array($action, ['ack', 'escalate'], true)) {
    fail('Choose a valid alert action.', 422);
}
$note = isset($data['note']) && is_scalar($data['note']) ? mb_substr(trim((string) $data['note']), 0, 255) : '';
db()->prepare('INSERT INTO admin_alert_actions (alert_key, action, note, actor_user_id, created_at) VALUES (?, ?, ?, ?, NOW())')
    ->execute([$key, $action, $note, (int) $admin['id']]);
audit('admin.alert_' . $action, 'admin_alert', $key, ['note' => $note]);
json_out(['ok' => true, 'message' => $action === 'escalate' ? 'Escalated to the owner team.' : 'Alert acknowledged.']);
