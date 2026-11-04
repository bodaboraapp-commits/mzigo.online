<?php
/**
 * Firebase Phone Authentication helpers.
 *
 * The browser signs the user in with Firebase and sends the resulting ID token
 * here. This file verifies the token against Google's public certificates —
 * the phone number is never trusted from the client.
 */
declare(strict_types=1);

function firebase_config(): array
{
    $cfg = config()['firebase'] ?? [];
    $out = [
        'enabled' => !empty($cfg['enabled']),
        'api_key' => (string) ($cfg['api_key'] ?? ''),
        'auth_domain' => (string) ($cfg['auth_domain'] ?? ''),
        'project_id' => (string) ($cfg['project_id'] ?? ''),
        'app_id' => (string) ($cfg['app_id'] ?? ''),
        'storage_bucket' => (string) ($cfg['storage_bucket'] ?? ''),
        'messaging_sender_id' => (string) ($cfg['messaging_sender_id'] ?? ''),
        'measurement_id' => (string) ($cfg['measurement_id'] ?? ''),
        'vapid_key' => (string) ($cfg['vapid_key'] ?? ''),
        'service_account_json' => (string) ($cfg['service_account_json'] ?? ''),
        'server_key' => (string) ($cfg['server_key'] ?? ''),
    ];
    // Respect the admin Communications toggle for the Firebase channel.
    if (function_exists('notification_channel_raw')) {
        try {
            $activeRow = db()->query("SELECT is_active FROM communication_channels
                WHERE code = 'firebase' OR provider = 'firebase' ORDER BY is_active DESC LIMIT 1")->fetch();
            if ($activeRow !== false && (int) $activeRow['is_active'] !== 1) {
                $out['enabled'] = false;
            }
        } catch (Throwable $e) {
            // Fall back to environment config when channel storage is unavailable.
        }
        $channel = notification_channel_raw('firebase');
        $chanCfg = $channel['config'] ?? [];
        if ($chanCfg) {
            foreach (['api_key', 'auth_domain', 'project_id', 'app_id', 'storage_bucket', 'messaging_sender_id', 'measurement_id', 'vapid_key', 'service_account_json', 'server_key'] as $key) {
                if (!empty($chanCfg[$key])) $out[$key] = (string) $chanCfg[$key];
            }
            if ($out['api_key'] !== '' && $out['project_id'] !== '' && $out['app_id'] !== '') {
                $out['enabled'] = true;
            }
        }
    }
    return $out;
}

function firebase_ready(): bool
{
    $c = firebase_config();
    return $c['enabled'] && $c['api_key'] !== '' && $c['project_id'] !== '' && $c['app_id'] !== '';
}

/** Public browser config; safe to expose (Firebase web keys are public). */
function firebase_web_config(): array
{
    $c = firebase_config();
    return array_filter([
        'apiKey' => $c['api_key'],
        'authDomain' => $c['auth_domain'] ?: ($c['project_id'] . '.firebaseapp.com'),
        'projectId' => $c['project_id'],
        'storageBucket' => $c['storage_bucket'],
        'messagingSenderId' => $c['messaging_sender_id'],
        'appId' => $c['app_id'],
        'measurementId' => $c['measurement_id'],
    ], static fn ($v) => $v !== '');
}

/**
 * Session context for the Firebase login endpoint. Equivalent to auth_context()
 * in auth.php, which cannot be required here because that file dispatches the
 * auth API on include.
 */
function firebase_login_context(): array
{
    $user = current_user();
    $tenant = current_tenant();
    return [
        'user' => $user,
        'tenant' => $tenant,
        'tenants' => $user ? user_tenants((int) $user['id']) : [],
        'role' => $tenant['role'] ?? ($user['role'] ?? null),
        'usage' => $tenant ? tenant_usage((int) $tenant['id']) : null,
        'csrf' => csrf_token(),
    ];
}

function firebase_b64url_decode(string $value): string
{
    $value = strtr($value, '-_', '+/');
    $pad = strlen($value) % 4;
    if ($pad) $value .= str_repeat('=', 4 - $pad);
    $decoded = base64_decode($value, true);
    if ($decoded === false) throw new RuntimeException('Malformed token segment.');
    return $decoded;
}

function firebase_certs(): array
{
    $cache = __DIR__ . '/logs/firebase-certs.json';
    if (is_file($cache) && filemtime($cache) > time() - 3600) {
        $rows = json_decode((string) file_get_contents($cache), true);
        if (is_array($rows) && $rows) return $rows;
    }
    $url = 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';
    $raw = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
    }
    if ($raw === false) $raw = @file_get_contents($url);
    $rows = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($rows) || !$rows) {
        throw new RuntimeException('Could not load Firebase signing certificates.');
    }
    @file_put_contents($cache, json_encode($rows));
    return $rows;
}

/**
 * Verify a Firebase ID token and return its payload.
 *
 * @throws RuntimeException when the token is invalid or expired.
 */
function firebase_verify_id_token(string $token): array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) throw new RuntimeException('Malformed ID token.');
    [$headerB64, $payloadB64, $signatureB64] = $parts;
    $header = json_decode(firebase_b64url_decode($headerB64), true);
    $payload = json_decode(firebase_b64url_decode($payloadB64), true);
    if (!is_array($header) || !is_array($payload)) throw new RuntimeException('Malformed ID token.');
    if (($header['alg'] ?? '') !== 'RS256' || empty($header['kid'])) {
        throw new RuntimeException('Unsupported token algorithm.');
    }

    $certs = firebase_certs();
    $cert = $certs[$header['kid']] ?? null;
    if (!$cert) throw new RuntimeException('Unknown signing key.');

    $ok = openssl_verify($headerB64 . '.' . $payloadB64, firebase_b64url_decode($signatureB64), $cert, OPENSSL_ALGO_SHA256);
    if ($ok !== 1) throw new RuntimeException('Invalid token signature.');

    $c = firebase_config();
    $now = time();
    if (($payload['aud'] ?? '') !== $c['project_id']) throw new RuntimeException('Token audience mismatch.');
    if (($payload['iss'] ?? '') !== 'https://securetoken.google.com/' . $c['project_id']) {
        throw new RuntimeException('Token issuer mismatch.');
    }
    if ((int) ($payload['exp'] ?? 0) <= $now) throw new RuntimeException('Token expired.');
    if ((int) ($payload['iat'] ?? 0) > $now + 60) throw new RuntimeException('Token issued in the future.');
    if (($payload['sub'] ?? '') === '') throw new RuntimeException('Token subject missing.');
    if (($payload['phone_number'] ?? '') === '') throw new RuntimeException('Token has no verified phone number.');

    return $payload;
}

/** Convert a Firebase E.164 number (+2547…) to the local 07… format. */
function firebase_local_phone(string $e164): string
{
    $digits = preg_replace('/\D+/', '', $e164) ?? '';
    if (str_starts_with($digits, '254')) $digits = '0' . substr($digits, 3);
    if (!preg_match('/^07\d{8}$/', $digits)) {
        throw new RuntimeException('Not a Kenyan mobile number.');
    }
    return $digits;
}

function firebase_find_user_by_phone(string $phone, string $preferRole = ''): ?array
{
    // Use the shared normalised lookup so 0712…, 254712… and +254712…
    // always resolve to the same account, preferring the requested role.
    return find_best_phone_user($phone, $preferRole);
}

function firebase_messaging_ready(): bool
{
    $c = firebase_config();
    return firebase_ready() && $c['vapid_key'] !== ''
        && ($c['service_account_json'] !== '' || $c['server_key'] !== '');
}

/** Config safe for the browser push SDK. */
function firebase_messaging_public_config(): array
{
    $c = firebase_config();
    return [
        'config' => firebase_web_config(),
        'vapidKey' => $c['vapid_key'],
    ];
}

function firebase_b64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function firebase_service_account(): ?array
{
    $raw = trim(firebase_config()['service_account_json']);
    if ($raw === '') return null;
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) && is_file($raw)) {
        $decoded = json_decode((string) file_get_contents($raw), true);
    }
    return is_array($decoded) ? $decoded : null;
}

function firebase_http_post(string $url, string $body, array $headers = []): array
{
    $headers[] = 'Content-Type: application/json';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        return ['http' => $http, 'body' => $raw === false ? '' : (string) $raw, 'error' => $error];
    }
    $context = stream_context_create(['http' => [
        'method' => 'POST', 'header' => implode("\r\n", $headers),
        'content' => $body, 'timeout' => 15, 'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents($url, false, $context);
    $http = 0;
    if (isset($http_response_header[0]) && preg_match('~\s(\d{3})\s~', $http_response_header[0], $m)) $http = (int) $m[1];
    return ['http' => $http, 'body' => $raw === false ? '' : (string) $raw, 'error' => $raw === false ? 'HTTP request failed.' : ''];
}

function firebase_fcm_access_token(): string
{
    $cache = __DIR__ . '/logs/fcm-token.json';
    if (is_file($cache)) {
        $cached = json_decode((string) file_get_contents($cache), true);
        if (is_array($cached) && (int) ($cached['expires_at'] ?? 0) > time() + 60) {
            return (string) $cached['access_token'];
        }
    }
    $sa = firebase_service_account();
    if (!$sa || empty($sa['client_email']) || empty($sa['private_key'])) {
        throw new RuntimeException('Firebase service account JSON is missing or invalid.');
    }
    $now = time();
    $header = firebase_b64url_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claims = firebase_b64url_encode(json_encode([
        'iss' => (string) $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => (string) ($sa['token_uri'] ?? 'https://oauth2.googleapis.com/token'),
        'iat' => $now,
        'exp' => $now + 3600,
    ]));
    $unsigned = $header . '.' . $claims;
    $signature = '';
    if (!openssl_sign($unsigned, $signature, (string) $sa['private_key'], OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Could not sign the Firebase service-account assertion.');
    }
    $assertion = $unsigned . '.' . firebase_b64url_encode($signature);
    $tokenUrl = (string) ($sa['token_uri'] ?? 'https://oauth2.googleapis.com/token');
    $body = http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $assertion,
    ]);
    $headers = ['Content-Type: application/x-www-form-urlencoded'];
    if (function_exists('curl_init')) {
        $ch = curl_init($tokenUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $context = stream_context_create(['http' => [
            'method' => 'POST', 'header' => implode("\r\n", $headers),
            'content' => $body, 'timeout' => 15, 'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($tokenUrl, false, $context);
        $http = 0;
    }
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded) || empty($decoded['access_token'])) {
        throw new RuntimeException('Firebase OAuth token request failed (HTTP ' . $http . ').');
    }
    $expires = $now + (int) ($decoded['expires_in'] ?? 3600);
    @file_put_contents($cache, json_encode([
        'access_token' => (string) $decoded['access_token'],
        'expires_at' => $expires,
    ]));
    return (string) $decoded['access_token'];
}

/**
 * Send one push to a list of FCM registration tokens.
 * Returns ['sent'=>int,'failed'=>int,'removed'=>array].
 */
function firebase_send_push(array $tokens, string $title, string $body, string $link = '', array $data = []): array
{
    $tokens = array_values(array_unique(array_filter(array_map('strval', $tokens))));
    if (!$tokens) return ['sent' => 0, 'failed' => 0, 'removed' => []];
    $c = firebase_config();
    $sent = 0;
    $failed = 0;
    $removed = [];
    try {
        if ($c['service_account_json'] !== '') {
            $access = firebase_fcm_access_token();
            $endpoint = 'https://fcm.googleapis.com/v1/projects/' . rawurlencode($c['project_id']) . '/messages:send';
            foreach (array_slice($tokens, 0, 500) as $token) {
                $message = [
                    'message' => [
                        'token' => $token,
                        'notification' => ['title' => $title, 'body' => $body],
                        'data' => array_map('strval', $data),
                    ],
                ];
                if ($link !== '') $message['message']['webpush'] = ['fcm_options' => ['link' => $link]];
                $res = firebase_http_post($endpoint, json_encode($message), ['Authorization: Bearer ' . $access]);
                $decoded = json_decode($res['body'], true);
                if ($res['http'] >= 200 && $res['http'] < 300) {
                    $sent++;
                } else {
                    $failed++;
                    $reason = (string) ($decoded['error']['status'] ?? '');
                    if (in_array($reason, ['UNREGISTERED', 'NOT_FOUND', 'INVALID_ARGUMENT'], true)) $removed[] = $token;
                }
            }
        } elseif ($c['server_key'] !== '') {
            foreach (array_chunk($tokens, 500) as $chunk) {
                $payload = [
                    'registration_ids' => $chunk,
                    'notification' => ['title' => $title, 'body' => $body],
                    'data' => array_map('strval', $data),
                ];
                if ($link !== '') $payload['webpush'] = ['fcm_options' => ['link' => $link]];
                $res = firebase_http_post('https://fcm.googleapis.com/fcm/send', json_encode($payload), ['Authorization: key=' . $c['server_key']]);
                $decoded = json_decode($res['body'], true);
                if ($res['http'] >= 200 && $res['http'] < 300 && is_array($decoded)) {
                    $sent += (int) ($decoded['success'] ?? 0);
                    $failed += (int) ($decoded['failure'] ?? 0);
                    foreach (($decoded['results'] ?? []) as $i => $row) {
                        if (!empty($row['error']) && in_array($row['error'], ['NotRegistered', 'InvalidRegistration'], true)) {
                            $removed[] = $chunk[$i] ?? null;
                        }
                    }
                } else {
                    $failed += count($chunk);
                }
            }
        } else {
            $failed = count($tokens);
        }
    } catch (Throwable $e) {
        error_log('firebase push failed: ' . $e->getMessage());
        $failed = count($tokens);
    }
    $removed = array_values(array_filter($removed));
    if ($removed) {
        try {
            $placeholders = implode(',', array_fill(0, count($removed), '?'));
            db()->prepare('DELETE FROM push_tokens WHERE token IN (' . $placeholders . ')')->execute($removed);
        } catch (Throwable $e) {}
    }
    return ['sent' => $sent, 'failed' => $failed, 'removed' => $removed];
}

/** Push to every device registered by a user (optionally marketing-only). */
function firebase_push_user(int $userId, string $title, string $body, string $link = '', array $data = [], bool $marketingOnly = false): array
{
    if ($userId <= 0) return ['sent' => 0, 'failed' => 0];
    try {
        $sql = 'SELECT token FROM push_tokens WHERE user_id = ?' . ($marketingOnly ? ' AND marketing_opt_in = 1' : '');
        $stmt = db()->prepare($sql);
        $stmt->execute([$userId]);
        $tokens = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return firebase_send_push($tokens, $title, $body, $link, $data);
    } catch (Throwable $e) {
        error_log('firebase push user failed: ' . $e->getMessage());
        return ['sent' => 0, 'failed' => 0];
    }
}
