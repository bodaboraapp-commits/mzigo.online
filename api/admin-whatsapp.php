<?php
/**
 * Admin WhatsApp Phase 1 API: config, AI settings, conversations, handoff, reply, demo simulate.
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_whatsapp.php';
require_once __DIR__ . '/_whatsapp_outbound.php';

$admin = require_platform_admin();
$pdo = db();

function wa_admin_payload(): array
{
    $pdo = db();
    $conversations = $pdo->query(
        'SELECT c.id, c.status, c.last_message_at, c.assigned_admin_id, ct.wa_id, ct.phone, ct.name, ct.opted_out,
                (SELECT body FROM wa_messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_message,
                (SELECT COUNT(*) FROM wa_messages m WHERE m.conversation_id = c.id) AS message_count,
                (SELECT COUNT(*) FROM wa_handoffs h WHERE h.conversation_id = c.id AND h.status <> "resolved") AS open_handoffs
           FROM wa_conversations c JOIN wa_contacts ct ON ct.id = c.contact_id
          ORDER BY COALESCE(c.last_message_at, c.created_at) DESC LIMIT 100'
    )->fetchAll();
    $handoffs = $pdo->query(
        'SELECT h.*, c.status AS conversation_status, ct.name AS contact_name, ct.wa_id
           FROM wa_handoffs h JOIN wa_conversations c ON c.id = h.conversation_id JOIN wa_contacts ct ON ct.id = c.contact_id
          WHERE h.status <> "resolved" ORDER BY h.created_at ASC LIMIT 100'
    )->fetchAll();
    $metrics = [
        'conversations' => (int) $pdo->query('SELECT COUNT(*) FROM wa_conversations')->fetchColumn(),
        'open_handoffs' => (int) $pdo->query("SELECT COUNT(*) FROM wa_handoffs WHERE status <> 'resolved'")->fetchColumn(),
        'messages_today' => (int) $pdo->query('SELECT COUNT(*) FROM wa_messages WHERE DATE(created_at) = CURRENT_DATE()')->fetchColumn(),
        'queued' => (int) $pdo->query("SELECT COUNT(*) FROM wa_jobs WHERE status IN ('queued','failed')")->fetchColumn(),
    ];
    $templates = $pdo->query('SELECT event, template_name, language, active FROM wa_templates ORDER BY FIELD(event, "registered","ready","payment_received","collected","status")')->fetchAll();
    $outbound = $pdo->query('SELECT event, tracking, phone, template_name, status, error, created_at FROM wa_outbound_events ORDER BY id DESC LIMIT 20')->fetchAll();
    return [
        'ok' => true,
        'config' => wa_public_config(),
        'metrics' => $metrics,
        'conversations' => $conversations,
        'handoffs' => $handoffs,
        'templates' => $templates,
        'outbound' => $outbound,
    ];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_out(wa_admin_payload());
}

require_post();
verify_csrf();
$data = body_json();
$action = (string) ($data['action'] ?? '');

if ($action === 'save_config') {
    wa_config_save($data['config'] ?? []);
    audit('admin.whatsapp_config_saved', 'platform', 'whatsapp_config');
    json_out(wa_admin_payload());
}

if ($action === 'save_templates') {
    $allowed = ['registered', 'ready', 'payment_received', 'collected', 'status'];
    $templates = $data['templates'] ?? [];
    if (!is_array($templates)) fail('Invalid template payload.', 422);
    foreach ($templates as $event => $row) {
        if (!in_array($event, $allowed, true) || !is_array($row)) continue;
        $name = trim((string) ($row['template_name'] ?? ''));
        $lang = trim((string) ($row['language'] ?? 'en'));
        $active = !empty($row['active']) ? 1 : 0;
        if ($name === '') continue;
        $stmt = $pdo->prepare('INSERT INTO wa_templates (event, template_name, language, active, created_at) VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE template_name = VALUES(template_name), language = VALUES(language), active = VALUES(active)');
        $stmt->execute([$event, mb_substr($name, 0, 120), $lang !== '' ? mb_substr($lang, 0, 10) : 'en', $active]);
    }
    audit('admin.whatsapp_templates_saved', 'platform', 'wa_templates');
    json_out(wa_admin_payload());
}

if ($action === 'test_template') {
    $event = (string) ($data['event'] ?? '');
    $phone = wa_normalize_phone((string) ($data['phone'] ?? ''));
    $allowed = ['registered', 'ready', 'payment_received', 'collected', 'status'];
    if (!in_array($event, $allowed, true) || $phone === '') fail('Choose an event and phone number.', 422);
    $stmt = $pdo->prepare('SELECT name FROM wa_contacts WHERE phone = ? OR wa_id = ? LIMIT 1');
    $stmt->execute([$phone, $phone]);
    $name = (string) ($stmt->fetchColumn() ?: 'Customer');
    $fakeParcel = ['id' => 0, 'tracking' => 'TRK-TEST0001', 'receiver_phone' => $phone, 'receiver_name' => $name,
        'carrier' => 'Test Carrier', 'from_city' => 'Nairobi', 'to_city' => 'Mombasa', 'status' => 'READY_FOR_COLLECTION',
        'fee_total' => 350, 'collect_code' => '1234'];
    $result = wa_notify_parcel_event($event, $fakeParcel, true);
    audit('admin.whatsapp_template_test', 'wa_template', $event, ['phone' => $phone, 'result' => $result]);
    json_out(['ok' => true, 'result' => $result, 'whatsapp' => wa_admin_payload()]);
}

if ($action === 'send_test_whatsapp') {
    $phone = wa_normalize_phone((string) ($data['phone'] ?? ''));
    $body = trim((string) ($data['body'] ?? ''));
    if ($phone === '') fail('Enter a test phone number.', 422);
    if ($body === '') $body = 'Mzigo.online: this is a WhatsApp delivery test from your admin console.';
    $result = wa_send_text($phone, $body, false);
    audit('admin.whatsapp_test', 'platform', 'whatsapp', ['phone' => $phone, 'ok' => !empty($result['ok']), 'provider' => $result['provider'] ?? '']);
    json_out(['ok' => true, 'result' => $result]);
}

if ($action === 'process_queue') {
    $results = wa_process_queue((int) ($data['limit'] ?? 20));
    json_out(['ok' => true, 'processed' => count($results), 'results' => $results, 'whatsapp' => wa_admin_payload()]);
}

if ($action === 'simulate_inbound') {
    $phone = preg_replace('/\D+/', '', (string) ($data['phone'] ?? '254700000000')) ?: '254700000000';
    $text = trim((string) ($data['body'] ?? 'How do I track my parcel?'));
    if ($text === '') fail('Enter a test message.', 422);
    $contact = wa_contact($phone, 'Demo WhatsApp customer');
    $conversation = wa_conversation((int) $contact['id']);
    $messageId = wa_store_message((int) $conversation['id'], 'inbound', $text, '', 'received');
    wa_enqueue($messageId);
    $results = wa_process_queue(5, true);
    $stmt = $pdo->prepare('SELECT * FROM wa_messages WHERE conversation_id = ? ORDER BY id ASC');
    $stmt->execute([(int) $conversation['id']]);
    audit('admin.whatsapp_simulated', 'wa_conversation', (string) $conversation['id'], ['phone' => $phone]);
    json_out(['ok' => true, 'conversation_id' => (int) $conversation['id'], 'messages' => $stmt->fetchAll(), 'results' => $results, 'whatsapp' => wa_admin_payload()]);
}

if ($action === 'conversation') {
    $id = (int) ($data['conversation_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT c.*, ct.wa_id, ct.phone, ct.name, ct.opted_out FROM wa_conversations c JOIN wa_contacts ct ON ct.id = c.contact_id WHERE c.id = ? LIMIT 1');
    $stmt->execute([$id]);
    $conv = $stmt->fetch();
    if (!$conv) fail_error('not_found', 'Conversation not found.', 404);
    $stmt = $pdo->prepare('SELECT * FROM wa_messages WHERE conversation_id = ? ORDER BY id ASC LIMIT 200');
    $stmt->execute([$id]);
    $stmt = $pdo->prepare('SELECT * FROM wa_handoffs WHERE conversation_id = ? ORDER BY id DESC LIMIT 20');
    $stmt->execute([$id]);
    json_out(['ok' => true, 'conversation' => $conv, 'messages' => $stmt->fetchAll(), 'handoffs' => $stmt->fetchAll()]);
}

if ($action === 'takeover' || $action === 'close' || $action === 'reopen') {
    $id = (int) ($data['conversation_id'] ?? 0);
    if ($action === 'close') {
        $pdo->prepare('UPDATE wa_conversations SET status = "closed" WHERE id = ?')->execute([$id]);
        $pdo->prepare('UPDATE wa_handoffs SET status = "resolved", resolved_at = NOW() WHERE conversation_id = ? AND status <> "resolved"')->execute([$id]);
    } elseif ($action === 'reopen') {
        $pdo->prepare('UPDATE wa_conversations SET status = "bot" WHERE id = ?')->execute([$id]);
    } else {
        $pdo->prepare('UPDATE wa_conversations SET status = "human", assigned_admin_id = ? WHERE id = ?')->execute([(int) $admin['id'], $id]);
        $pdo->prepare('UPDATE wa_handoffs SET status = "assigned", assigned_admin_id = ? WHERE conversation_id = ? AND status <> "resolved"')->execute([(int) $admin['id'], $id]);
    }
    audit('admin.whatsapp_' . $action, 'wa_conversation', (string) $id);
    json_out(wa_admin_payload());
}

if ($action === 'reply') {
    $id = (int) ($data['conversation_id'] ?? 0);
    $body = trim((string) ($data['body'] ?? ''));
    if ($body === '') fail('Enter a reply.', 422);
    $stmt = $pdo->prepare('SELECT c.id, ct.wa_id FROM wa_conversations c JOIN wa_contacts ct ON ct.id = c.contact_id WHERE c.id = ? LIMIT 1');
    $stmt->execute([$id]);
    $conv = $stmt->fetch();
    if (!$conv) fail_error('not_found', 'Conversation not found.', 404);
    $result = wa_send_text((string) $conv['wa_id'], $body);
    wa_store_message($id, 'outbound', $body, (string) ($result['message_id'] ?? ''), !empty($result['ok']) ? 'sent' : 'failed', '', (string) ($result['error'] ?? ''));
    $pdo->prepare('UPDATE wa_conversations SET status = "human", assigned_admin_id = ? WHERE id = ?')->execute([(int) $admin['id'], $id]);
    audit('admin.whatsapp_reply', 'wa_conversation', (string) $id, ['ok' => !empty($result['ok'])]);
    if (empty($result['ok'])) fail($result['error'] ?? 'Could not send reply.', 502);
    json_out(['ok' => true, 'messages' => [], 'whatsapp' => wa_admin_payload()]);
}

if ($action === 'resolve_handoff') {
    $handoffId = (int) ($data['handoff_id'] ?? 0);
    $pdo->prepare('UPDATE wa_handoffs SET status = "resolved", resolved_at = NOW() WHERE id = ?')->execute([$handoffId]);
    $stmt = $pdo->prepare('SELECT conversation_id FROM wa_handoffs WHERE id = ?');
    $stmt->execute([$handoffId]);
    $convId = (int) $stmt->fetchColumn();
    if ($convId) $pdo->prepare('UPDATE wa_conversations SET status = "bot" WHERE id = ?')->execute([$convId]);
    audit('admin.whatsapp_handoff_resolved', 'wa_handoff', (string) $handoffId);
    json_out(wa_admin_payload());
}

fail_error('unknown_action', 'Unknown WhatsApp action.', 400);
