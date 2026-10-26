<?php
/**
 * Mzigo.online — WhatsApp Phase 2 outbound parcel templates.
 * Only sends to WhatsApp contacts with recorded consent; never messages cold numbers.
 */
declare(strict_types=1);

function wa_out_config(): array
{
    $raw = platform_setting('whatsapp_config', '');
    $config = $raw !== '' ? (json_decode($raw, true) ?: []) : [];
    return array_replace([
        'enabled' => false, 'mode' => 'demo', 'graph_version' => 'v23.0',
        'phone_number_id' => '', 'access_token' => '', 'business_name' => 'Mzigo.online',
    ], $config);
}

function wa_out_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if ($digits !== '' && $digits[0] === '0') $digits = '254' . substr($digits, 1);
    return $digits;
}

function wa_out_template(string $event): ?array
{
    $stmt = db()->prepare('SELECT * FROM wa_templates WHERE event = ? AND active = 1 LIMIT 1');
    $stmt->execute([$event]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function wa_out_log(array $parcel, string $event, string $phone, ?array $template, string $status, string $error, array $params): void
{
    try {
        db()->prepare('INSERT INTO wa_outbound_events (parcel_id, tracking, phone, event, template_name, status, error, params_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())')
            ->execute([(string) ($parcel['id'] ?? ''), (string) ($parcel['tracking'] ?? ''), $phone, $event, $template['template_name'] ?? null, $status, $error !== '' ? mb_substr($error, 0, 255) : null, json_encode($params, JSON_UNESCAPED_SLASHES)]);
    } catch (Throwable $e) { error_log('WhatsApp outbound log failed: ' . $e->getMessage()); }
}

function wa_out_store_message(int $contactId, string $body, string $status, string $metaId, string $error = ''): void
{
    try {
        $stmt = db()->prepare('SELECT id FROM wa_conversations WHERE contact_id = ? LIMIT 1');
        $stmt->execute([$contactId]);
        $conversationId = (int) $stmt->fetchColumn();
        if (!$conversationId) {
            db()->prepare('INSERT INTO wa_conversations (contact_id, status, created_at, updated_at) VALUES (?, "bot", NOW(), NOW())')->execute([$contactId]);
            $conversationId = (int) db()->lastInsertId();
        }
        db()->prepare('INSERT INTO wa_messages (conversation_id, direction, wa_message_id, body, message_type, status, error, created_at) VALUES (?, "outbound", ?, ?, "template", ?, ?, NOW())')
            ->execute([$conversationId, $metaId !== '' ? $metaId : null, $body, $status, $error !== '' ? mb_substr($error, 0, 255) : null]);
        db()->prepare('UPDATE wa_conversations SET last_message_at = NOW() WHERE id = ?')->execute([$conversationId]);
    } catch (Throwable $e) { error_log('WhatsApp outbound message store failed: ' . $e->getMessage()); }
}

/** Send an approved Meta template; demo mode never calls Meta. */
function wa_out_send_template(string $to, string $templateName, string $language, array $params, bool $forceDemo = false): array
{
    $config = wa_out_config();
    if ($forceDemo || $config['mode'] !== 'live' || empty($config['enabled']) || $config['access_token'] === '' || $config['phone_number_id'] === '') {
        return ['ok' => true, 'demo' => true, 'message_id' => 'DEMO-WA-TPL-' . strtoupper(bin2hex(random_bytes(4)))];
    }
    $url = 'https://graph.facebook.com/' . rawurlencode($config['graph_version']) . '/' . rawurlencode($config['phone_number_id']) . '/messages';
    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => $to,
        'type' => 'template',
        'template' => [
            'name' => $templateName,
            'language' => ['code' => $language],
            'components' => [['type' => 'body', 'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => (string) $v], $params)]],
        ],
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $config['access_token']],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    $decoded = json_decode((string) $raw, true);
    if ($raw === false || $code < 200 || $code >= 300) {
        return ['ok' => false, 'error' => $error !== '' ? $error : (string) ($decoded['error']['message'] ?? ('HTTP ' . $code))];
    }
    return ['ok' => true, 'message_id' => (string) ($decoded['messages'][0]['id'] ?? '')];
}

function wa_out_params(string $event, array $parcel): array
{
    $name = (string) ($parcel['receiver_name'] ?? 'Customer');
    $tracking = (string) ($parcel['tracking'] ?? '');
    $total = (string) (int) ($parcel['fee_total'] ?? 0);
    return match ($event) {
        'registered' => [$name, $tracking, (string) ($parcel['carrier'] ?? 'Mzigo partner'), (string) ($parcel['from_city'] ?? '') . ' → ' . (string) ($parcel['to_city'] ?? '')],
        'ready' => [$name, $tracking, $total],
        'payment_received' => [$name, $tracking, $total, (string) ($parcel['collect_code'] ?? '')],
        'collected' => [$name, $tracking],
        'status' => [$name, $tracking, (string) ($parcel['status'] ?? ''), ''],
        default => [$name, $tracking],
    };
}

function wa_notify_parcel_event(string $event, array $parcel, bool $forceDemo = false): array
{
    $phone = wa_out_phone((string) ($parcel['receiver_phone'] ?? ''));
    $config = wa_out_config();
    $template = wa_out_template($event);
    if ($phone === '') {
        return ['ok' => false, 'skipped' => 'no_phone'];
    }
    if (!$forceDemo && (empty($config['enabled']) || $config['mode'] !== 'live')) {
        wa_out_log($parcel, $event, $phone, $template, 'skipped', 'WhatsApp live mode is not enabled.', wa_out_params($event, $parcel));
        return ['ok' => false, 'skipped' => 'disabled'];
    }
    if (!$template) {
        wa_out_log($parcel, $event, $phone, null, 'skipped', 'No active approved template for this event.', wa_out_params($event, $parcel));
        return ['ok' => false, 'skipped' => 'no_template'];
    }
    $stmt = db()->prepare('SELECT id, consent_at, opted_out FROM wa_contacts WHERE phone = ? OR wa_id = ? LIMIT 1');
    $stmt->execute([$phone, $phone]);
    $contact = $stmt->fetch();
    if (!$contact || empty($contact['consent_at']) || (int) $contact['opted_out'] === 1) {
        wa_out_log($parcel, $event, $phone, $template, 'skipped', 'Contact has not opted in to WhatsApp messages.', wa_out_params($event, $parcel));
        return ['ok' => false, 'skipped' => 'no_consent'];
    }
    $params = wa_out_params($event, $parcel);
    $result = wa_out_send_template($phone, (string) $template['template_name'], (string) $template['language'], $params, $forceDemo);
    $status = !empty($result['ok']) ? 'sent' : 'failed';
    $error = (string) ($result['error'] ?? '');
    wa_out_log($parcel, $event, $phone, $template, $status, $error, $params);
    wa_out_store_message((int) $contact['id'], '[template:' . $template['template_name'] . '] ' . implode(' · ', $params), $status === 'sent' ? 'sent' : 'failed', (string) ($result['message_id'] ?? ''), $error);
    return ['ok' => !empty($result['ok']), 'status' => $status, 'error' => $error, 'demo' => !empty($result['demo'])];
}

function wa_notify_from_status(array $parcel, string $template): void
{
    $event = $template === 'parcel_ready' ? 'ready' : ($template === 'parcel_collected' ? 'collected' : 'status');
    try { wa_notify_parcel_event($event, $parcel); } catch (Throwable $e) { error_log('WhatsApp status event failed: ' . $e->getMessage()); }
}
