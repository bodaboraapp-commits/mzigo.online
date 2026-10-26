<?php
/** Application-level encryption for provider credentials stored in MySQL. */
declare(strict_types=1);
function config_crypto_key(): string
{
    $key = (string) (config()['app']['config_key'] ?? '');
    if ($key === '') {
        throw new RuntimeException('APP_CONFIG_KEY is not configured. Cannot encrypt provider credentials.');
    }
    return hash('sha256', $key, true);
}

function crypto_key_material(string $key): string
{
    if ($key === '') {
        throw new RuntimeException('Encryption key is empty.');
    }
    return hash('sha256', $key, true);
}

function secure_value_encrypt_with_key(string $plain, string $key): string
{
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', crypto_key_material($key), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) {
        throw new RuntimeException('Could not encrypt provider settings.');
    }
    return 'enc:v1:' . base64_encode($iv . $tag . $cipher);
}

function secure_value_decrypt_with_key(string $stored, string $key): ?string
{
    if (!str_starts_with($stored, 'enc:v1:')) {
        return $stored; // legacy plaintext; callers encrypt on next save
    }
    $raw = base64_decode(substr($stored, 7), true);
    if ($raw === false || strlen($raw) < 29) {
        return null;
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', crypto_key_material($key), OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? null : $plain;
}

function secure_value_encrypt(string $plain): string
{
    $key = (string) (config()['app']['config_key'] ?? '');
    if ($key === '') { throw new RuntimeException('APP_CONFIG_KEY is not configured. Cannot encrypt provider credentials.'); }
    return secure_value_encrypt_with_key($plain, $key);
}

function secure_value_decrypt(string $stored): ?string
{
    if (!str_starts_with($stored, 'enc:v1:')) {
        return $stored; // legacy plaintext; callers encrypt on next save
    }
    $key = (string) (config()['app']['config_key'] ?? '');
    if ($key === '') { return null; }
    return secure_value_decrypt_with_key($stored, $key);
}

function channel_config_encode(array $config): ?string
{
    if (!$config) { return null; }
    return secure_value_encrypt(json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function channel_config_decode(?string $stored): array
{
    if ($stored === null || trim($stored) === '') { return []; }
    $plain = secure_value_decrypt($stored);
    if ($plain === null) { return []; }
    $decoded = json_decode($plain, true);
    return is_array($decoded) ? $decoded : [];
}

/** Mask credential values before sending channel config to the admin browser. */
function channel_config_mask(array $config, string $provider): array
{
    $secretFields = [];
    $catalog = function_exists('channel_provider_catalog') ? channel_provider_catalog() : [];
    if (isset($catalog[$provider]['fields'])) {
        foreach ($catalog[$provider]['fields'] as $field) {
            if (($field['type'] ?? '') === 'password') {
                $secretFields[] = $field['key'];
            }
        }
    }
    $masked = [];
    foreach ($config as $key => $value) {
        $isSecret = in_array($key, $secretFields, true) || preg_match('/pass|secret|token|api_key|access_key|auth/i', (string) $key);
        $masked[$key] = $isSecret && (string) $value !== '' ? '••••••••' : $value;
    }
    return $masked;
}
