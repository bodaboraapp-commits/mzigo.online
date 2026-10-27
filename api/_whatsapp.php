<?php
/**
 * Mzigo.online — WhatsApp Phase 1: FAQ + tracking + human handoff.
 * Demo mode runs without Meta credentials; live mode uses WhatsApp Cloud API.
 */
declare(strict_types=1);

function wa_config(): array
{
    $defaults = [
        'enabled' => false,
        'mode' => 'demo',
        'provider' => 'meta',
        'graph_version' => 'v23.0',
        'infobip_base_url' => '',
        'infobip_api_key' => '',
        'infobip_sender' => '',
        'infobip_webhook_secret' => '',
        'phone_number_id' => '',
        'waba_id' => '',
        'access_token' => '',
        'verify_token' => '',
        'app_secret' => '',
        'business_name' => 'Mzigo.online',
        'handoff_message' => "I'm connecting you to a Mzigo support agent. They will reply here during business hours.",
        'ai_provider' => 'demo',
        'ai_base_url' => '',
        'ai_model' => 'gpt-4o-mini',
        'ai_api_key' => '',
        'ai_system_prompt' => "You are Mzigo.online's WhatsApp assistant for parcel delivery in Kenya. Be concise and helpful. Never invent parcel statuses, never reveal personal data, never promise refunds or payments. If the request is account-specific, a payment issue, a claim, a complaint or you are unsure, offer a human handoff.",
        'auto_reply' => true,
    ];
    $raw = platform_setting('whatsapp_config', '');
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) $defaults = array_replace($defaults, $decoded);
    }
    if ($defaults['verify_token'] === '') {
        $defaults['verify_token'] = 'MZIGO-WA-' . strtoupper(bin2hex(random_bytes(8)));
        try {
            db()->prepare('INSERT INTO platform_settings (setting_key, setting_value) VALUES ("whatsapp_config", ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
                ->execute([json_encode($defaults, JSON_UNESCAPED_SLASHES)]);
        } catch (Throwable $e) { error_log('WhatsApp config init failed: ' . $e->getMessage()); }
    }
    return $defaults;
}

function wa_config_save(array $input): array
{
    $config = wa_config();
    foreach (['mode', 'provider', 'graph_version', 'phone_number_id', 'waba_id', 'business_name', 'handoff_message',
              'infobip_base_url', 'infobip_sender', 'ai_provider', 'ai_base_url', 'ai_model', 'ai_system_prompt'] as $key) {
        if (array_key_exists($key, $input)) $config[$key] = trim((string) $input[$key]);
    }
    $config['mode'] = $config['mode'] === 'live' ? 'live' : 'demo';
    $config['provider'] = in_array($config['provider'], ['meta', 'infobip'], true) ? $config['provider'] : 'meta';
    $config['ai_provider'] = in_array($config['ai_provider'], ['demo', 'openai', 'anthropic'], true) ? $config['ai_provider'] : 'demo';
    if (array_key_exists('enabled', $input)) $config['enabled'] = !empty($input['enabled']);
    if (array_key_exists('auto_reply', $input)) $config['auto_reply'] = !empty($input['auto_reply']);
    if (isset($input['access_token']) && trim((string) $input['access_token']) !== '') $config['access_token'] = trim((string) $input['access_token']);
    if (isset($input['app_secret']) && trim((string) $input['app_secret']) !== '') $config['app_secret'] = trim((string) $input['app_secret']);
    if (isset($input['ai_api_key']) && trim((string) $input['ai_api_key']) !== '') $config['ai_api_key'] = trim((string) $input['ai_api_key']);
    if (isset($input['infobip_api_key']) && trim((string) $input['infobip_api_key']) !== '') $config['infobip_api_key'] = trim((string) $input['infobip_api_key']);
    if (isset($input['infobip_webhook_secret']) && trim((string) $input['infobip_webhook_secret']) !== '') $config['infobip_webhook_secret'] = trim((string) $input['infobip_webhook_secret']);
    if (isset($input['verify_token']) && trim((string) $input['verify_token']) !== '') $config['verify_token'] = trim((string) $input['verify_token']);
    if ($config['verify_token'] === '') $config['verify_token'] = 'MZIGO-WA-' . strtoupper(bin2hex(random_bytes(8)));
    db()->prepare('INSERT INTO platform_settings (setting_key, setting_value) VALUES ("whatsapp_config", ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
        ->execute([json_encode($config, JSON_UNESCAPED_SLASHES)]);
    return $config;
}

function wa_public_config(?array $config = null): array
{
    $config = $config ?? wa_config();
    return [
        'enabled' => !empty($config['enabled']),
        'mode' => $config['mode'],
        'provider' => $config['provider'],
        'provider_options' => ['meta', 'infobip'],
        'infobip_base_url' => $config['infobip_base_url'],
        'infobip_sender' => $config['infobip_sender'],
        'infobip_api_key_set' => $config['infobip_api_key'] !== '',
        'infobip_webhook_secret_set' => $config['infobip_webhook_secret'] !== '',
        'graph_version' => $config['graph_version'],
        'phone_number_id' => $config['phone_number_id'],
        'waba_id' => $config['waba_id'],
        'access_token_set' => $config['access_token'] !== '',
        'app_secret_set' => $config['app_secret'] !== '',
        'verify_token' => $config['verify_token'],
        'webhook_url' => rtrim((string) (config()['app']['base_url'] ?? 'http://localhost:8090'), '/') . '/api/whatsapp-webhook.php',
        'business_name' => $config['business_name'],
        'handoff_message' => $config['handoff_message'],
        'auto_reply' => !empty($config['auto_reply']),
        'ai_provider' => $config['ai_provider'],
        'ai_base_url' => $config['ai_base_url'],
        'ai_model' => $config['ai_model'],
        'ai_api_key_set' => $config['ai_api_key'] !== '',
        'ai_system_prompt' => $config['ai_system_prompt'],
    ];
}

function wa_signature_valid(string $rawBody, string $signature, string $appSecret): bool
{
    if ($appSecret === '') return false;
    if ($signature === '') return false;
    $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $appSecret);
    return hash_equals($expected, $signature);
}

function wa_normalize_phone(string $value): string
{
    $digits = preg_replace('/\D+/', '', $value) ?? '';
    if ($digits !== '' && $digits[0] === '0') $digits = '254' . substr($digits, 1);
    return $digits;
}

function wa_contact(string $waId, string $name = ''): array
{
    $phone = wa_normalize_phone($waId);
    $stmt = db()->prepare('SELECT * FROM wa_contacts WHERE wa_id = ? LIMIT 1');
    $stmt->execute([$waId]);
    $row = $stmt->fetch();
    if ($row) {
        db()->prepare('UPDATE wa_contacts SET last_seen_at = NOW(), name = COALESCE(NULLIF(?, ""), name) WHERE id = ?')->execute([trim($name), $row['id']]);
        return $row;
    }
    $stmt = db()->prepare('INSERT INTO wa_contacts (phone, wa_id, name, consent_at, last_seen_at, created_at) VALUES (?, ?, ?, NOW(), NOW(), NOW())');
    $stmt->execute([$phone, $waId, $name !== '' ? $name : 'WhatsApp customer']);
    $id = (int) db()->lastInsertId();
    $stmt = db()->prepare('SELECT * FROM wa_contacts WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function wa_conversation(int $contactId): array
{
    $stmt = db()->prepare('SELECT * FROM wa_conversations WHERE contact_id = ? LIMIT 1');
    $stmt->execute([$contactId]);
    $row = $stmt->fetch();
    if ($row) return $row;
    db()->prepare('INSERT INTO wa_conversations (contact_id, status, created_at, updated_at) VALUES (?, "bot", NOW(), NOW())')->execute([$contactId]);
    $id = (int) db()->lastInsertId();
    $stmt = db()->prepare('SELECT * FROM wa_conversations WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function wa_store_message(int $conversationId, string $direction, string $body, string $metaId = '', string $status = 'received', string $model = '', string $error = ''): int
{
    try {
        $stmt = db()->prepare('INSERT INTO wa_messages (conversation_id, direction, wa_message_id, body, message_type, status, ai_model, error, created_at) VALUES (?, ?, ?, ?, "text", ?, ?, ?, NOW())');
        $stmt->execute([$conversationId, $direction, $metaId !== '' ? $metaId : null, $body, $status, $model !== '' ? $model : null, $error !== '' ? mb_substr($error, 0, 255) : null]);
        $id = (int) db()->lastInsertId();
        db()->prepare('UPDATE wa_conversations SET last_message_at = NOW() WHERE id = ?')->execute([$conversationId]);
        return $id;
    } catch (Throwable $e) {
        if (str_contains($e->getMessage(), 'Duplicate')) {
            $stmt = db()->prepare('SELECT id FROM wa_messages WHERE wa_message_id = ? LIMIT 1');
            $stmt->execute([$metaId]);
            return (int) $stmt->fetchColumn();
        }
        throw $e;
    }
}

function wa_enqueue(int $messageId): void
{
    db()->prepare('INSERT IGNORE INTO wa_jobs (message_id, status, attempts, created_at, updated_at) VALUES (?, "queued", 0, NOW(), NOW())')->execute([$messageId]);
}

/** Public parcel tracking text. No receiver name, phone, code or account details. */
function wa_tracking_answer(string $tracking): ?string
{
    $tracking = strtoupper(trim($tracking));
    if (!preg_match('/^TRK-[A-Z0-9]{4,}$/', $tracking)) return null;
    $stmt = db()->prepare('SELECT tracking, carrier, from_city, to_city, status, payment_status FROM parcels WHERE tracking = ? LIMIT 1');
    $stmt->execute([$tracking]);
    $parcel = $stmt->fetch();
    if (!$parcel) return "I couldn't find parcel {$tracking}. Check the tracking number and try again, or reply 'human' to reach support.";
    $labels = [
        'CREATED' => 'registered — waiting for drop-off', 'AT_ORIGIN_POINT' => 'dropped off at the origin office',
        'HANDED_TO_TRANSPORTER' => 'handed to the transporter', 'IN_TRANSIT' => 'in transit',
        'ARRIVED_DEST_POINT' => 'arrived at the destination office', 'READY_FOR_COLLECTION' => 'ready for collection',
        'COLLECTED_PAID' => 'collected', 'CANCELLED' => 'cancelled', 'RETURN_IN_TRANSIT' => 'returning to sender',
    ];
    $status = $labels[$parcel['status']] ?? strtolower(str_replace('_', ' ', (string) $parcel['status']));
    $lines = [
        "Parcel {$tracking}",
        "Courier: {$parcel['carrier']}",
        "Route: {$parcel['from_city']} → {$parcel['to_city']}",
        "Status: {$status}",
    ];
    if (($parcel['payment_status'] ?? '') === 'paid') {
        $lines[] = 'Delivery fee: paid';
    } elseif ($parcel['status'] === 'READY_FOR_COLLECTION') {
        $lines[] = 'Delivery fee: unpaid — sign in to the customer portal to pay and get your collection code.';
    }
    $lines[] = "Track again at " . rtrim((string) (config()['app']['base_url'] ?? 'https://mzigo.online'), '/') . '/track-mzigo?num=' . rawurlencode($tracking);
    $lines[] = "For account-specific help or a payment/claim issue, reply 'human'.";
    return implode("\n", $lines);
}

function wa_faq_match(string $text): ?array
{
    $words = array_values(array_filter(preg_split('/[^a-z0-9]+/', strtolower($text)) ?: [], fn ($w) => strlen($w) > 3));
    if (!$words) return null;
    $stmt = db()->query('SELECT question, answer FROM faqs WHERE is_active = 1 ORDER BY sort_order ASC LIMIT 100');
    $best = null;
    $bestScore = 0;
    foreach ($stmt->fetchAll() as $faq) {
        $haystack = strtolower($faq['question'] . ' ' . $faq['answer']);
        $score = 0;
        foreach ($words as $word) if (str_contains($haystack, $word)) $score++;
        if ($score > $bestScore) { $bestScore = $score; $best = $faq; }
    }
    return $bestScore >= 2 ? $best : null;
}

function wa_handoff(int $conversationId, string $reason, string $note = ''): void
{
    db()->prepare('INSERT INTO wa_handoffs (conversation_id, reason, note, status, created_at) VALUES (?, ?, ?, "open", NOW())')
        ->execute([$conversationId, mb_substr($reason, 0, 120), mb_substr($note, 0, 500)]);
    db()->prepare('UPDATE wa_conversations SET status = "human" WHERE id = ?')->execute([$conversationId]);
}

function wa_infobip_base(array $config): string
{
    $base = trim((string) ($config['infobip_base_url'] ?? ''));
    if ($base !== '' && !preg_match('#^https?://#i', $base)) $base = 'https://' . $base;
    return rtrim($base, '/');
}

function wa_send_text(string $to, string $body, bool $forceDemo = false): array
{
    $config = wa_config();
    $provider = $config['provider'] ?? 'meta';
    if ($provider === 'infobip') {
        if ($forceDemo || $config['mode'] !== 'live') {
            return ['ok' => true, 'provider' => 'demo', 'message_id' => 'DEMO-WA-' . strtoupper(bin2hex(random_bytes(4)))];
        }
        $base = wa_infobip_base($config);
        $sender = trim((string) ($config['infobip_sender'] ?? ''));
        if ($base === '' || $sender === '' || trim((string) ($config['infobip_api_key'] ?? '')) === '') {
            return ['ok' => false, 'provider' => 'infobip', 'error' => 'Infobip WhatsApp base URL, API key or sender is missing.'];
        }
        $payload = ['from' => $sender, 'to' => wa_normalize_phone($to), 'content' => ['text' => mb_substr($body, 0, 4096)]];
        $ch = curl_init($base . '/whatsapp/1/message/text');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: App ' . $config['infobip_api_key']],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        $decoded = json_decode((string) $raw, true);
        if ($raw === false || $code < 200 || $code >= 300) {
            $error = $curlError !== '' ? $curlError : (string) ($decoded['requestError']['serviceException']['text'] ?? ('HTTP ' . $code));
            return ['ok' => false, 'provider' => 'infobip', 'error' => $error];
        }
        return ['ok' => true, 'provider' => 'infobip', 'message_id' => (string) ($decoded['messageId'] ?? '')];
    }
    if ($forceDemo || $config['mode'] !== 'live' || $config['access_token'] === '' || $config['phone_number_id'] === '') {
        return ['ok' => true, 'provider' => 'demo', 'message_id' => 'DEMO-WA-' . strtoupper(bin2hex(random_bytes(4)))];
    }
    $url = 'https://graph.facebook.com/' . rawurlencode($config['graph_version']) . '/' . rawurlencode($config['phone_number_id']) . '/messages';
    $payload = ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'text', 'text' => ['preview_url' => false, 'body' => mb_substr($body, 0, 4096)]];
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
        return ['ok' => false, 'provider' => 'meta', 'error' => $error !== '' ? $error : (string) ($decoded['error']['message'] ?? ('HTTP ' . $code))];
    }
    return ['ok' => true, 'provider' => 'meta', 'message_id' => (string) ($decoded['messages'][0]['id'] ?? '')];
}

function wa_ai_reply(array $history, string $context, string $userText): ?array
{
    $config = wa_config();
    $provider = $config['ai_provider'];
    $key = $config['ai_api_key'];
    if ($provider === 'demo' || $key === '') return null;
    $system = $config['ai_system_prompt'] . "\n\nVerified public context:\n" . ($context !== '' ? $context : 'No extra context available.');
    $messages = [['role' => 'system', 'content' => $system]];
    $history = array_slice($history, -8);
    $last = end($history);
    if ($last && $last['direction'] === 'inbound' && trim((string) $last['body']) === trim($userText)) array_pop($history);
    foreach ($history as $item) {
        $messages[] = ['role' => $item['direction'] === 'inbound' ? 'user' : 'assistant', 'content' => (string) $item['body']];
    }
    $messages[] = ['role' => 'user', 'content' => $userText];
    if ($provider === 'anthropic') {
        $base = $config['ai_base_url'] !== '' ? rtrim($config['ai_base_url'], '/') : 'https://api.anthropic.com';
        $url = $base . '/v1/messages';
        $body = ['model' => $config['ai_model'], 'max_tokens' => 500, 'system' => $system, 'messages' => array_values(array_filter($messages, fn ($m) => $m['role'] !== 'system'))];
        $headers = ['Content-Type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'];
        $parse = function ($decoded) { return (string) ($decoded['content'][0]['text'] ?? ''); };
    } else {
        $base = $config['ai_base_url'] !== '' ? rtrim($config['ai_base_url'], '/') : 'https://api.openai.com/v1';
        $url = $base . '/chat/completions';
        $body = ['model' => $config['ai_model'], 'messages' => $messages, 'temperature' => 0.3, 'max_tokens' => 500];
        $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $key];
        $parse = function ($decoded) { return (string) ($decoded['choices'][0]['message']['content'] ?? ''); };
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $code < 200 || $code >= 300) {
        error_log('WhatsApp AI failed: ' . ($error !== '' ? $error : ('HTTP ' . $code . ' ' . mb_substr((string) $raw, 0, 300))));
        return null;
    }
    $decoded = json_decode((string) $raw, true);
    $text = $parse($decoded ?: []);
    if (trim($text) === '') return null;
    return ['text' => trim($text), 'model' => $config['ai_model']];
}

function wa_process_job(int $jobId, bool $forceDemo = false): array
{
    $stmt = db()->prepare('SELECT j.*, m.conversation_id, m.body AS inbound_body, m.id AS msg_id FROM wa_jobs j JOIN wa_messages m ON m.id = j.message_id WHERE j.id = ? LIMIT 1');
    $stmt->execute([$jobId]);
    $job = $stmt->fetch();
    if (!$job || !in_array($job['status'], ['queued', 'failed'], true)) return ['ok' => false, 'skipped' => true];
    db()->prepare('UPDATE wa_jobs SET status = "processing", attempts = attempts + 1 WHERE id = ?')->execute([$jobId]);
    try {
        $stmt = db()->prepare('SELECT * FROM wa_conversations WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $job['conversation_id']]);
        $conversation = $stmt->fetch();
        if (!$conversation) throw new RuntimeException('Conversation not found');
        $stmt = db()->prepare('SELECT * FROM wa_contacts WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $conversation['contact_id']]);
        $contact = $stmt->fetch();
        $text = trim((string) $job['inbound_body']);
        $upper = strtoupper($text);
        $config = wa_config();
        if (empty($config['enabled']) && !$forceDemo) {
            db()->prepare('UPDATE wa_jobs SET status = "done" WHERE id = ?')->execute([$jobId]);
            return ['ok' => true, 'skipped' => true, 'reason' => 'assistant disabled'];
        }
        $reply = '';
        $model = '';
        $reason = '';
        if (in_array($upper, ['STOP', 'UNSUBSCRIBE', 'OPT OUT'], true)) {
            db()->prepare('UPDATE wa_contacts SET opted_out = 1 WHERE id = ?')->execute([$contact['id']]);
            $reply = 'You are opted out of WhatsApp messages. Reply START to opt in again.';
        } elseif (in_array($upper, ['START', 'OPT IN', 'UNSTOP'], true)) {
            db()->prepare('UPDATE wa_contacts SET opted_out = 0, consent_at = NOW() WHERE id = ?')->execute([$contact['id']]);
            $reply = 'Thanks — you are opted in. Ask about Mzigo parcel tracking, delivery, pricing or reply human to reach support.';
        } elseif (!empty($contact['opted_out'])) {
            $reply = '';
        } elseif (empty($config['auto_reply'])) {
            wa_handoff((int) $conversation['id'], 'Auto-reply is paused', mb_substr($text, 0, 500));
            $reply = $config['handoff_message'];
        } else {
            if (preg_match('/\b(TRK-[A-Z0-9]{4,})\b/i', $text, $m)) {
                $tracking = wa_tracking_answer($m[1]);
                if ($tracking) $reply = $tracking;
            }
            if ($reply === '' && preg_match('/\b(human|agent|support|refund|claim|lost|damaged|payment issue|complain)\b/i', $text)) {
                $reason = 'Customer requested human assistance';
            }
            if ($reply === '' && $reason === '') {
                $faq = wa_faq_match($text);
                if ($faq) {
                    $reply = $faq['answer'] . "\n\nWas this helpful? Reply human if you need more help.";
                }
            }
            if ($reply === '' && $reason === '') {
                $history = [];
                $stmt = db()->prepare('SELECT direction, body FROM wa_messages WHERE conversation_id = ? ORDER BY id ASC LIMIT 8');
                $stmt->execute([(int) $conversation['id']]);
                foreach ($stmt->fetchAll() as $row) $history[] = $row;
                $ai = wa_ai_reply($history, '', $text);
                if ($ai) { $reply = $ai['text']; $model = $ai['model']; }
            }
            if ($reply === '' && $reason !== '') {
                wa_handoff((int) $conversation['id'], $reason, mb_substr($text, 0, 500));
                $reply = $config['handoff_message'];
            }
            if ($reply === '') {
                wa_handoff((int) $conversation['id'], 'No automated answer matched', mb_substr($text, 0, 500));
                $reply = "I couldn't safely answer that automatically. " . $config['handoff_message'];
            }
        }
        $outboundId = null;
        $status = 'sent';
        $error = '';
        if ($reply !== '') {
            $result = wa_send_text((string) $contact['wa_id'], $reply, $forceDemo);
            $status = !empty($result['ok']) ? 'sent' : 'failed';
            $error = (string) ($result['error'] ?? '');
            $outboundId = wa_store_message((int) $conversation['id'], 'outbound', $reply, (string) ($result['message_id'] ?? ''), $status, $model, $error);
        }
        db()->prepare('UPDATE wa_jobs SET status = "done", error = NULL WHERE id = ?')->execute([$jobId]);
        return ['ok' => true, 'reply' => $reply, 'outbound_id' => $outboundId, 'status' => $status, 'error' => $error];
    } catch (Throwable $e) {
        error_log('WhatsApp job failed: ' . $e->getMessage());
        db()->prepare('UPDATE wa_jobs SET status = "failed", error = ? WHERE id = ?')->execute([mb_substr($e->getMessage(), 0, 255), $jobId]);
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function wa_process_queue(int $limit = 20, bool $forceDemo = false): array
{
    $limit = max(1, min(100, $limit));
    $stmt = db()->query("SELECT id FROM wa_jobs WHERE status IN ('queued','failed') AND attempts < 3 ORDER BY created_at ASC LIMIT " . $limit);
    $results = [];
    foreach ($stmt->fetchAll() as $row) $results[] = ['job_id' => (int) $row['id']] + wa_process_job((int) $row['id'], $forceDemo);
    return $results;
}

function wa_handle_webhook(array $body): int
{
    $count = 0;
    foreach (($body['entry'] ?? []) as $entry) {
        foreach (($entry['changes'] ?? []) as $change) {
            $value = $change['value'] ?? [];
            $profileNames = [];
            foreach (($value['contacts'] ?? []) as $contact) $profileNames[(string) ($contact['wa_id'] ?? '')] = (string) ($contact['profile']['name'] ?? '');
            foreach (($value['messages'] ?? []) as $message) {
                $waId = (string) ($message['from'] ?? '');
                if ($waId === '') continue;
                $metaId = (string) ($message['id'] ?? '');
                $text = (string) ($message['text']['body'] ?? '');
                if ($text === '') {
                    $text = '[Unsupported message type: ' . (string) ($message['type'] ?? 'unknown') . ']';
                }
                $contact = wa_contact($waId, $profileNames[$waId] ?? '');
                $conversation = wa_conversation((int) $contact['id']);
                $messageId = wa_store_message((int) $conversation['id'], 'inbound', $text, $metaId, 'received');
                if ($messageId > 0 && !empty(wa_config()['enabled'])) { wa_enqueue($messageId); $count++; }
            }
            foreach (($value['statuses'] ?? []) as $status) {
                $metaId = (string) ($status['id'] ?? '');
                if ($metaId === '') continue;
                $state = (string) ($status['status'] ?? '');
                $mapped = in_array($state, ['failed'], true) ? 'failed' : 'sent';
                db()->prepare('UPDATE wa_messages SET status = ? WHERE wa_message_id = ? AND direction = "outbound"')->execute([$mapped, $metaId]);
            }
        }
    }
    return $count;
}
