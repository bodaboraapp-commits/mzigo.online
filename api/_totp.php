<?php
/** Minimal RFC 6238 TOTP helpers (base32 secret, SHA-1, 6 digits, 30s). */
declare(strict_types=1);

function totp_base32_encode(string $data): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($data) as $char) {
        $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        $out .= $alphabet[bindec($chunk)];
    }
    return $out;
}

function totp_base32_decode(string $secret): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $secret) ?? '');
    $bits = '';
    foreach (str_split($secret) as $char) {
        $pos = strpos($alphabet, $char);
        if ($pos === false) { continue; }
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) { $out .= chr(bindec($byte)); }
    }
    return $out;
}

function totp_code(string $secret, ?int $time = null, int $digits = 6): string
{
    $time = $time ?? time();
    $counter = (int) floor($time / 30);
    $key = totp_base32_decode($secret);
    $hash = hash_hmac('sha1', pack('N*', 0, $counter), $key, true);
    $offset = ord($hash[19]) & 0x0f;
    $binary = substr($hash, $offset, 4);
    $value = unpack('N', $binary)[1] & 0x7fffffff;
    return str_pad((string) ($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}

function totp_verify(string $secret, string $code, int $window = 1): bool
{
    $code = preg_replace('/\D/', '', $code) ?? '';
    if (strlen($code) !== 6) { return false; }
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totp_code($secret, time() + ($i * 30)), $code)) { return true; }
    }
    return false;
}
