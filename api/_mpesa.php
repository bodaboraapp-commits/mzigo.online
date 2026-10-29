<?php
declare(strict_types=1);

/**
 * Daraja M-Pesa STK (customer delivery-fee payment) configuration + helpers.
 *
 * Credentials are editable in Admin -> Settings -> M-Pesa payments so the
 * platform can switch between sandbox and production without a deployment.
 */
function mpesa_payment_config(): array
{
    $cfg = config()['payments']['mpesa'] ?? [];
    $defaults = [
        'mode' => 'demo',                    // demo | live
        'environment' => 'sandbox',          // sandbox | production
        'shortcode' => (string) ($cfg['shortcode'] ?? ''),
        'passkey' => (string) ($cfg['passkey'] ?? ''),
        'consumer_key' => (string) ($cfg['consumer_key'] ?? ''),
        'consumer_secret' => (string) ($cfg['consumer_secret'] ?? ''),
        'callback_url' => (string) ($cfg['callback_url'] ?? ''),
        'transaction_type' => 'CustomerPayBillOnline',
    ];
    $raw = platform_setting('mpesa_payment_config', '');
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $defaults = array_replace($defaults, $decoded);
        }
    }
    if (trim((string) $defaults['callback_url']) === '') {
        $defaults['callback_url'] = rtrim((string) config()['app']['base_url'], '/') . '/api/mpesa-callback.php';
    }
    return $defaults;
}

function mpesa_payment_config_save(array $input): array
{
    $current = mpesa_payment_config();
    $clean = $current;
    $clean['mode'] = in_array(($input['mode'] ?? 'demo'), ['demo', 'live'], true) ? (string) $input['mode'] : 'demo';
    $clean['environment'] = in_array(($input['environment'] ?? 'sandbox'), ['sandbox', 'production'], true)
        ? (string) $input['environment'] : 'sandbox';
    foreach (['shortcode', 'passkey', 'consumer_key', 'consumer_secret', 'callback_url', 'transaction_type'] as $key) {
        if (!array_key_exists($key, $input)) {
            continue;
        }
        $value = is_scalar($input[$key]) ? trim((string) $input[$key]) : '';
        if (in_array($key, ['passkey', 'consumer_secret', 'consumer_key'], true) && $value === '') {
            continue; // blank masked secrets keep the saved value
        }
        if ($key === 'callback_url' && $value !== '' && !preg_match('#^https?://#i', $value)) {
            $value = '';
        }
        $clean[$key] = mb_substr($value, 0, 1000);
    }
    if ($clean['transaction_type'] === '') {
        $clean['transaction_type'] = 'CustomerPayBillOnline';
    }
    db()->prepare('INSERT INTO platform_settings (setting_key, setting_value) VALUES ("mpesa_payment_config", ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
        ->execute([json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
    return $clean;
}

function mpesa_payment_public_config(): array
{
    $cfg = mpesa_payment_config();
    return [
        'mode' => $cfg['mode'],
        'environment' => $cfg['environment'],
        'shortcode' => $cfg['shortcode'],
        'transaction_type' => $cfg['transaction_type'],
        'callback_url' => $cfg['callback_url'],
        'consumer_key_set' => trim((string) $cfg['consumer_key']) !== '',
        'consumer_secret_set' => trim((string) $cfg['consumer_secret']) !== '',
        'passkey_set' => trim((string) $cfg['passkey']) !== '',
        'ready' => mpesa_stk_ready($cfg),
    ];
}

function mpesa_stk_ready(array $cfg): bool
{
    if (($cfg['mode'] ?? 'demo') !== 'live') {
        return false;
    }
    foreach (['shortcode', 'passkey', 'consumer_key', 'consumer_secret', 'callback_url'] as $key) {
        if (trim((string) ($cfg[$key] ?? '')) === '') {
            return false;
        }
    }
    return true;
}

function mpesa_payment_env_base(array $cfg): string
{
    return ($cfg['environment'] ?? 'sandbox') === 'production'
        ? 'https://api.safaricom.co.ke'
        : 'https://sandbox.safaricom.co.ke';
}

function mpesa_payment_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (str_starts_with($digits, '0')) {
        $digits = '254' . substr($digits, 1);
    } elseif (str_starts_with($digits, '7') && strlen($digits) === 9) {
        $digits = '254' . $digits;
    }
    return $digits;
}

function mpesa_stk_token(array $cfg): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'cURL is not available on this server.'];
    }
    $ch = curl_init(mpesa_payment_env_base($cfg) . '/oauth/v1/generate?grant_type=client_credentials');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERPWD => (string) $cfg['consumer_key'] . ':' . (string) $cfg['consumer_secret'],
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    $token = json_decode((string) $raw, true);
    if ($code < 200 || $code >= 300 || empty($token['access_token'])) {
        return ['ok' => false, 'error' => 'M-Pesa authentication failed. Check the consumer key/secret and environment.'];
    }
    return ['ok' => true, 'token' => trim((string) $token['access_token'])];
}

function mpesa_stk_password(array $cfg, string $timestamp): string
{
    return base64_encode((string) $cfg['shortcode'] . (string) $cfg['passkey'] . $timestamp);
}


/**
 * Sandbox testing charges KES 1; production charges the actual payable amount.
 */
function mpesa_stk_charge_amount(array $cfg, int $payableKes): int
{
    if (($cfg['environment'] ?? 'sandbox') !== 'production') {
        return 1;
    }
    return max(1, $payableKes);
}

function mpesa_stk_push(array $cfg, string $phone, int $amountKes, string $accountRef, string $description): array
{
    if (!mpesa_stk_ready($cfg)) {
        return ['ok' => false, 'error' => 'Live M-Pesa payments are not configured. Add the Daraja credentials in Admin -> Settings -> M-Pesa payments.'];
    }
    if ($amountKes < 1) {
        return ['ok' => false, 'error' => 'The amount must be at least KES 1.'];
    }
    $endpoint = mpesa_payment_env_base($cfg) . '/mpesa/stkpush/v1/processrequest';
    $last = ['ok' => false, 'error' => 'Daraja STK request failed.'];
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $token = mpesa_stk_token($cfg);
        if (!$token['ok']) {
            return $token;
        }
        $timestamp = date('YmdHis');
        $payload = [
            'BusinessShortCode' => (string) $cfg['shortcode'],
            'Password' => mpesa_stk_password($cfg, $timestamp),
            'Timestamp' => $timestamp,
            'TransactionType' => (string) ($cfg['transaction_type'] ?: 'CustomerPayBillOnline'),
            'Amount' => $amountKes,
            'PartyA' => mpesa_payment_phone($phone),
            'PartyB' => (string) $cfg['shortcode'],
            'PhoneNumber' => mpesa_payment_phone($phone),
            'CallBackURL' => (string) $cfg['callback_url'],
            'AccountReference' => mb_substr($accountRef, 0, 40),
            'TransactionDesc' => mb_substr($description, 0, 100),
        ];
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token['token'],
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        $body = json_decode((string) $raw, true);
        if ($code >= 200 && $code < 300 && is_array($body) && empty($body['errorCode'])
            && (empty($body['ResponseCode']) || (string) $body['ResponseCode'] === '0')
            && !empty($body['CheckoutRequestID'])) {
            return [
                'ok' => true,
                'merchant_request_id' => (string) ($body['MerchantRequestID'] ?? ''),
                'checkout_request_id' => (string) $body['CheckoutRequestID'],
                'customer_message' => (string) ($body['CustomerMessage'] ?? 'Check your phone and enter your M-Pesa PIN.'),
                'response' => $body,
            ];
        }
        $errorCode = is_array($body) ? (string) ($body['errorCode'] ?? '') : '';
        $detail = is_array($body)
            ? (string) ($body['errorMessage'] ?? $body['ResponseDescription'] ?? '')
            : mb_substr(trim(strip_tags((string) $raw)), 0, 180);
        $last = [
            'ok' => false,
            'error' => 'Daraja STK request failed (HTTP ' . $code . ')' . ($detail !== '' ? ': ' . $detail : '.'),
            'http_status' => $code,
            'response' => $body,
        ];
        if ($error ?: ($errorCode !== '404.001.03')) {
            break;
        }
        // A stale/invalid token can be fixed by one fresh OAuth attempt.
        usleep(150000);
    }
    return $last;
}

function mpesa_stk_query(array $cfg, string $checkoutRequestId): array
{
    if (!mpesa_stk_ready($cfg)) {
        return ['ok' => false, 'error' => 'Live M-Pesa payments are not configured.'];
    }
    $token = mpesa_stk_token($cfg);
    if (!$token['ok']) {
        return $token;
    }
    $timestamp = date('YmdHis');
    $payload = [
        'BusinessShortCode' => (string) $cfg['shortcode'],
        'Password' => mpesa_stk_password($cfg, $timestamp),
        'Timestamp' => $timestamp,
        'CheckoutRequestID' => $checkoutRequestId,
    ];
    $ch = curl_init(mpesa_payment_env_base($cfg) . '/mpesa/stkpushquery/v1/query');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token['token'],
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $body = json_decode((string) $raw, true);
    if ($code < 200 || $code >= 300 || !is_array($body)) {
        return ['ok' => false, 'error' => 'Could not query the M-Pesa payment status.', 'http_status' => $code];
    }
    return ['ok' => true, 'response' => $body];
}
