<?php
/**
 * Admin marketing campaigns API.
 *
 * GET  /api/admin-campaigns.php
 * POST /api/admin-campaigns.php { action: save_campaign | schedule_campaign | send_campaign | cancel_campaign | delete_campaign }
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_campaigns.php';

$admin = require_platform_admin();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    json_out(['ok' => true, 'source' => 'mysql'] + marketing_campaign_payload());
}

require_post();
verify_csrf();
$data = body_json();
$action = (string) ($data['action'] ?? '');

switch ($action) {
    case 'save_campaign':
        json_out(['ok' => true, 'source' => 'mysql'] + marketing_campaign_save($data, (int) $admin['id']));

    case 'schedule_campaign':
        $id = (int) ($data['id'] ?? 0);
        $when = (string) ($data['scheduled_at'] ?? '');
        if ($id <= 0) { fail('Choose a campaign to schedule.', 422); }
        json_out(['ok' => true, 'source' => 'mysql'] + marketing_campaign_schedule($id, $when));

    case 'send_campaign':
        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0) { fail('Choose a campaign to send.', 422); }
        $result = marketing_campaign_send($id);
        if (empty($result['ok'])) { fail_error('send_failed', (string) ($result['error'] ?? 'Campaign could not be sent.'), 422); }
        json_out(['ok' => true, 'source' => 'mysql'] + $result);

    case 'cancel_campaign':
        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0) { fail('Choose a campaign to cancel.', 422); }
        json_out(['ok' => true, 'source' => 'mysql'] + marketing_campaign_cancel($id));

    case 'delete_campaign':
        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0) { fail('Choose a campaign to delete.', 422); }
        json_out(['ok' => true, 'source' => 'mysql'] + marketing_campaign_delete($id));

    default:
        fail_error('unknown_action', 'Unknown campaign action.', 400);
}
