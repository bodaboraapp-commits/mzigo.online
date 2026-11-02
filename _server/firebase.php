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
    ];
    // Admin -> Communications -> Firebase channel is the preferred source.
    if (function_exists('notification_channel_raw')) {
        $channel = notification_channel_raw('firebase');
        $chanCfg = $channel['config'] ?? [];
        if ($chanCfg) {
            foreach (['api_key', 'auth_domain', 'project_id', 'app_id', 'storage_bucket', 'messaging_sender_id', 'measurement_id'] as $key) {
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

function firebase_find_user_by_phone(string $phone): ?array
{
    $key = phone_key($phone);
    $stmt = db()->prepare(
        'SELECT id, name, email, phone, role, status FROM users
         WHERE RIGHT(REGEXP_REPLACE(phone, "[^0-9]", ""), 9) = ? LIMIT 1'
    );
    $stmt->execute([$key]);
    $user = $stmt->fetch();
    return $user ?: null;
}
