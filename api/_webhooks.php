<?php
/**
 * Mzigo.online — outbound webhooks (tenant integrations).
 *
 * dispatch_webhooks($tenantId, $event, $payload) posts a signed JSON payload to
 * every active tenant webhook subscribed to the event. Secrets are encrypted at
 * rest and signatures are HMAC-SHA256 of the raw body:
 *   X-Mzigo-Signature: sha256=<hex>
 *
 * Failed deliveries retry with exponential backoff (1m, 5m, 15m, 1h, 2h) via
 * retry_webhook_deliveries(), called from api/cron.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/_crypto.php';

const WEBHOOK_EVENTS = ['parcel.created', 'parcel.cancelled', 'parcel.returned', 'invoice.paid', 'customer.parcel_paid', 'claim.created', 'claim.updated', 'parcel.status_updated'];
const WEBHOOK_MAX_ATTEMPTS = 5;

function webhook_url_is_safe(string $url, ?bool $production = null): bool
{
    if (!preg_match('#^https?://#i', $url)) { return false; }
    /* Local/staging may target localhost for integration tests. Production must
       never allow webhooks to private/loopback/link-local destinations. */
    $production = $production ?? ((config()['app']['env'] ?? 'local') === 'production');
    if (!$production) { return true; }
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
        return false;
    }
    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) { $ips[] = $host; }
    else {
        foreach (gethostbynamel($host) ?: [] as $ip) { $ips[] = $ip; }
        foreach (dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (!empty($record['ipv6'])) { $ips[] = $record['ipv6']; }
        }
    }
    if (!$ips) { return false; }
    foreach ($ips as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
    }
    return true;
}

function webhook_secret_plain(string $stored): string
{
    if (str_starts_with($stored, 'enc:v1:')) {
        return (string) (secure_value_decrypt($stored) ?? '');
    }
    return $stored;
}

function webhook_retry_delay(int $attempts): int
{
    $delays = [60, 300, 900, 3600, 7200];
    return $delays[max(0, min(count($delays) - 1, $attempts - 1))];
}

function webhook_post(string $url, string $event, string $body, string $secret, ?int &$code = null, ?string &$response = null): bool
{
    $signature = 'sha256=' . hash_hmac('sha256', $body, $secret);
    $code = 0;
    $response = '';
    if (!function_exists('curl_init')) {
        $response = 'cURL is not available.';
        return false;
    }
    $decodedBody = json_decode($body, true);
    $headers = [
        'Content-Type: application/json',
        'X-Mzigo-Event: ' . $event,
        'X-Mzigo-Signature: ' . $signature,
        'User-Agent: Mzigo.online-Webhooks/1.0',
    ];
    if (is_array($decodedBody) && !empty($decodedBody['event_id'])) { $headers[] = 'X-Mzigo-Event-Id: ' . $decodedBody['event_id']; }
    if (is_array($decodedBody) && !empty($decodedBody['idempotency_key'])) { $headers[] = 'X-Mzigo-Idempotency-Key: ' . $decodedBody['idempotency_key']; }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 4,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $response = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($response === '' && curl_error($ch) !== '') {
        $response = curl_error($ch);
    }
    curl_close($ch);
    return $code >= 200 && $code < 300;
}

function webhook_attempt_delivery(int $deliveryId, array $hook, string $event, string $body, int $attempt = 1): array
{
    $secret = webhook_secret_plain((string) ($hook['secret'] ?? ''));
    if ($secret === '') {
        return ['status' => 'failed', 'code' => 0, 'error' => 'Webhook secret could not be decrypted.'];
    }
    $code = 0;
    $response = '';
    $ok = webhook_post((string) $hook['url'], $event, $body, $secret, $code, $response);
    $status = $ok ? 'sent' : 'failed';
    $retryAt = null;
    if (!$ok && $attempt < WEBHOOK_MAX_ATTEMPTS) {
        $retryAt = date('Y-m-d H:i:s', time() + webhook_retry_delay($attempt));
    }
    try {
        $errorText = $ok ? null : mb_substr(trim($response), 0, 255);
        db()->prepare('UPDATE webhook_deliveries SET attempts = ?, last_attempt_at = NOW(), response_code = NULLIF(?, 0),
            response_body = ?, status = ?, next_retry_at = ?, error = ?, completed_at = IF(? = "sent", NOW(), NULL) WHERE id = ?')
            ->execute([$attempt, $code, substr(trim($response), 0, 500), $status, $retryAt, $errorText, $status, $deliveryId]);
        db()->prepare('UPDATE webhooks SET last_delivery_at = NOW(), last_status = ? WHERE id = ?')
            ->execute([$status, (int) $hook['id']]);
    } catch (Throwable $e) {
        error_log('webhook delivery log failed: ' . $e->getMessage());
    }
    return ['status' => $status, 'code' => $code, 'error' => $ok ? null : mb_substr(trim($response), 0, 240), 'retry_at' => $retryAt];
}

function dispatch_webhooks(int $tenantId, string $event, array $payload, ?string $idempotencyKey = null): int
{
    try {
        $stmt = db()->prepare('SELECT id, url, secret, events FROM webhooks WHERE tenant_id = ? AND is_active = 1');
        $stmt->execute([$tenantId]);
        $hooks = $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('webhook lookup failed: ' . $e->getMessage());
        return 0;
    }
    if (!$hooks) {
        return 0;
    }
    $eventId = function_exists('uuid4') ? uuid4() : sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', random_int(0, 65535), random_int(0, 65535), random_int(0, 65535), random_int(0, 4095) | 0x4000, random_int(0, 16383) | 0x8000, random_int(0, 65535), random_int(0, 65535), random_int(0, 65535));
    $idempotencyKey = $idempotencyKey !== null && trim($idempotencyKey) !== '' ? mb_substr(trim($idempotencyKey), 0, 120) : null;
    $body = json_encode(['event' => $event, 'event_id' => $eventId, 'idempotency_key' => $idempotencyKey, 'created_at' => date('c'), 'data' => $payload], JSON_UNESCAPED_SLASHES);
    $attempts = 0;
    foreach ($hooks as $hook) {
        $events = array_map('trim', explode(',', (string) $hook['events']));
        if (!in_array($event, $events, true)) {
            continue;
        }
        try {
            db()->prepare('INSERT INTO webhook_deliveries (webhook_id, event_id, idempotency_key, event, payload, status, attempts, created_at)
                VALUES (?, ?, ?, ?, ?, "queued", 0, NOW())')->execute([(int) $hook['id'], $eventId, $idempotencyKey, $event, $body]);
            $deliveryId = (int) db()->lastInsertId();
        } catch (Throwable $e) {
            /* A duplicate (webhook_id, idempotency_key) means this event was already queued. */
            continue;
        }
        $attempts++;
        webhook_attempt_delivery($deliveryId, $hook, $event, $body, 1);
    }
    return $attempts;
}

function webhook_deliveries_for_tenant(int $tenantId, int $limit = 50): array
{
    try {
        $stmt = db()->prepare('SELECT d.id, d.webhook_id, d.event_id, d.idempotency_key, d.event, d.status, d.attempts,
                d.response_code, d.response_body, d.error, d.created_at, d.last_attempt_at, d.next_retry_at,
                d.completed_at, w.url
            FROM webhook_deliveries d JOIN webhooks w ON w.id = d.webhook_id
            WHERE w.tenant_id = ? ORDER BY d.id DESC LIMIT ' . max(1, min(200, $limit)));
        $stmt->execute([$tenantId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('webhook delivery history failed: ' . $e->getMessage());
        return [];
    }
}

function retry_webhook_delivery(int $deliveryId, int $tenantId): array
{
    $stmt = db()->prepare('SELECT d.*, w.id AS hook_id, w.url, w.secret, w.events, w.is_active
        FROM webhook_deliveries d JOIN webhooks w ON w.id = d.webhook_id
        WHERE d.id = ? AND w.tenant_id = ? LIMIT 1');
    $stmt->execute([$deliveryId, $tenantId]);
    $delivery = $stmt->fetch();
    if (!$delivery) { return ['ok' => false, 'error' => 'Delivery not found.', 'code' => 404]; }
    if ($delivery['status'] === 'sent') { return ['ok' => true, 'status' => 'sent', 'message' => 'This delivery already succeeded.']; }
    if (!$delivery['is_active']) { return ['ok' => false, 'error' => 'This webhook is disabled.', 'code' => 422]; }
    $attempt = (int) $delivery['attempts'] + 1;
    $hook = [
        'id' => (int) $delivery['hook_id'], 'url' => $delivery['url'], 'secret' => $delivery['secret'],
        'events' => $delivery['events'], 'is_active' => $delivery['is_active'],
    ];
    $result = webhook_attempt_delivery($deliveryId, $hook, (string) $delivery['event'], (string) $delivery['payload'], $attempt);
    return ['ok' => true, 'status' => $result['status'] ?? 'failed', 'attempts' => $attempt,
        'retry_at' => $result['retry_at'] ?? null, 'error' => $result['error'] ?? null,
        'message' => ($result['status'] ?? '') === 'sent' ? 'Delivery retried successfully.' : 'Retry failed; it remains in the retry queue if attempts remain.'];
}

/** Retry due failed deliveries. Returns a small summary for cron output. */
function retry_webhook_deliveries(int $limit = 25): array
{
    $summary = ['attempted' => 0, 'sent' => 0, 'failed' => 0];
    try {
        $stmt = db()->prepare('SELECT d.id AS delivery_id, d.event, d.payload, d.attempts, w.id, w.url, w.secret, w.events, w.is_active
            FROM webhook_deliveries d JOIN webhooks w ON w.id = d.webhook_id
            WHERE d.status = "failed" AND d.next_retry_at IS NOT NULL AND d.next_retry_at <= NOW()
              AND d.attempts < ? AND w.is_active = 1
            ORDER BY d.next_retry_at ASC LIMIT ' . (int) $limit);
        $stmt->execute([WEBHOOK_MAX_ATTEMPTS]);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('webhook retry lookup failed: ' . $e->getMessage());
        return $summary;
    }
    foreach ($rows as $row) {
        $summary['attempted']++;
        $result = webhook_attempt_delivery((int) $row['delivery_id'], $row, (string) $row['event'], (string) $row['payload'], (int) $row['attempts'] + 1);
        if (($result['status'] ?? '') === 'sent') { $summary['sent']++; } else { $summary['failed']++; }
    }
    return $summary;
}
