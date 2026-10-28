<?php
/**
 * Mzigo.online — insurer integration.
 *
 * Parcel insurance is issued through a configurable provider. Demo mode issues
 * clearly-labelled demo policies; live mode calls the provider API configured in
 * Admin → Insurance.
 */
declare(strict_types=1);

function insurance_config(): array
{
    $defaults = [
        'mode' => 'demo',
        'provider_name' => 'Mzigo demo cover',
        'api_base_url' => '',
        'issue_path' => '/policies',
        'auth_type' => 'bearer',
        'api_key' => '',
        'api_secret' => '',
        'product_code' => '',
        'policy_number_path' => 'policy_number',
        'timeout_seconds' => 15,
        'require_policy' => false,
        'terms_url' => '/terms#risk-and-cover',
        'charge_mode' => 'percentage',
        'charge_percent' => 1.5,
        'charge_fixed_kes' => 30,
        'minimum_charge_kes' => 30,
    ];
    $raw = platform_setting('insurance_config', '');
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) $defaults = array_replace($defaults, $decoded);
    }
    return $defaults;
}

function insurance_config_save(array $input): array
{
    $current = insurance_config();
    $clean = $current;
    $clean['mode'] = in_array(($input['mode'] ?? 'demo'), ['demo', 'live'], true) ? (string) $input['mode'] : 'demo';
    foreach (['provider_name', 'api_base_url', 'issue_path', 'auth_type', 'product_code', 'policy_number_path', 'terms_url'] as $key) {
        if (array_key_exists($key, $input)) {
            $clean[$key] = trim((string) $input[$key]);
        }
    }
    if (isset($input['timeout_seconds'])) $clean['timeout_seconds'] = max(3, min(60, (int) $input['timeout_seconds']));
    if (array_key_exists('require_policy', $input)) $clean['require_policy'] = !empty($input['require_policy']);
    if (array_key_exists('charge_mode', $input)) {
        $clean['charge_mode'] = in_array((string) $input['charge_mode'], ['percentage', 'fixed'], true)
            ? (string) $input['charge_mode'] : 'percentage';
    }
    if (array_key_exists('charge_percent', $input)) {
        $clean['charge_percent'] = max(0.0, min(100.0, (float) $input['charge_percent']));
    }
    if (array_key_exists('charge_fixed_kes', $input)) {
        $clean['charge_fixed_kes'] = max(0, min(1000000, (int) $input['charge_fixed_kes']));
    }
    if (array_key_exists('minimum_charge_kes', $input)) {
        $clean['minimum_charge_kes'] = max(0, min(1000000, (int) $input['minimum_charge_kes']));
    }
    // Blank secrets keep the existing value so the form can be saved without re-entering credentials.
    if (isset($input['api_key']) && trim((string) $input['api_key']) !== '') $clean['api_key'] = trim((string) $input['api_key']);
    if (isset($input['api_secret']) && trim((string) $input['api_secret']) !== '') $clean['api_secret'] = trim((string) $input['api_secret']);
    if (!in_array($clean['auth_type'], ['bearer', 'basic', 'api_key'], true)) $clean['auth_type'] = 'bearer';
    db()->prepare('INSERT INTO platform_settings (setting_key, setting_value) VALUES ("insurance_config", ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
        ->execute([json_encode($clean, JSON_UNESCAPED_SLASHES)]);
    return $clean;
}

function insurance_public_config(?array $config = null): array
{
    $config = $config ?? insurance_config();
    return [
        'mode' => $config['mode'],
        'provider_name' => $config['provider_name'],
        'api_base_url' => $config['api_base_url'],
        'issue_path' => $config['issue_path'],
        'auth_type' => $config['auth_type'],
        'api_key_set' => $config['api_key'] !== '',
        'api_secret_set' => $config['api_secret'] !== '',
        'product_code' => $config['product_code'],
        'policy_number_path' => $config['policy_number_path'],
        'timeout_seconds' => (int) $config['timeout_seconds'],
        'require_policy' => !empty($config['require_policy']),
        'terms_url' => $config['terms_url'],
        'charge_mode' => (string) ($config['charge_mode'] ?? 'percentage'),
        'charge_percent' => (float) ($config['charge_percent'] ?? 1.5),
        'charge_fixed_kes' => (int) ($config['charge_fixed_kes'] ?? 30),
        'minimum_charge_kes' => (int) ($config['minimum_charge_kes'] ?? 30),
    ];
}



function insurance_charge_for_value(int $valueKes, ?array $config = null): int
{
    $config = $config ?? insurance_config();
    $mode = (string) ($config['charge_mode'] ?? 'percentage');
    if ($mode === 'fixed') {
        return max(0, (int) round((float) ($config['charge_fixed_kes'] ?? 0)));
    }
    $percent = max(0.0, min(100.0, (float) ($config['charge_percent'] ?? 1.5)));
    $minimum = max(0, (int) ($config['minimum_charge_kes'] ?? 0));
    return max($minimum, (int) round($valueKes * $percent / 100));
}

function insurance_pricing_public(?array $config = null): array
{
    $config = $config ?? insurance_config();
    return [
        'mode' => in_array((string) ($config['charge_mode'] ?? 'percentage'), ['percentage', 'fixed'], true)
            ? (string) $config['charge_mode'] : 'percentage',
        'percent' => (float) ($config['charge_percent'] ?? 1.5),
        'fixed' => (int) ($config['charge_fixed_kes'] ?? 30),
        'minimum' => (int) ($config['minimum_charge_kes'] ?? 30),
    ];
}

function insurance_reference_for_parcel(array $parcel): string
{
    $policy = trim((string) ($parcel['insurance_policy_no'] ?? ''));
    if ($policy !== '') return $policy;
    if (($parcel['insurance_status'] ?? '') === 'failed') return 'PENDING';
    return parcel_insurance_ref((string) ($parcel['tracking'] ?? ''));
}

function insurance_policy_path(array $data, string $path): string
{
    $cursor = $data;
    foreach (explode('.', $path) as $key) {
        if ($key === '') continue;
        if (!is_array($cursor) || !array_key_exists($key, $cursor)) return '';
        $cursor = $cursor[$key];
    }
    return is_scalar($cursor) ? trim((string) $cursor) : '';
}

function insurance_provider_request(array $config, array $payload): array
{
    $base = rtrim((string) $config['api_base_url'], '/');
    $path = (string) ($config['issue_path'] ?? '');
    $url = $base . ($path !== '' ? (str_starts_with($path, '/') ? $path : '/' . $path) : '');
    if ($base === '') return ['ok' => false, 'error' => 'Insurer API base URL is not configured.'];
    if ((string) $config['api_key'] === '') return ['ok' => false, 'error' => 'Insurer API key is not configured.'];
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    $authType = (string) $config['auth_type'];
    if ($authType === 'basic') {
        $headers[] = 'Authorization: Basic ' . base64_encode($config['api_key'] . ':' . $config['api_secret']);
    } elseif ($authType === 'api_key') {
        $headers[] = 'X-API-Key: ' . $config['api_key'];
        if ((string) $config['api_secret'] !== '') $headers[] = 'X-API-Secret: ' . $config['api_secret'];
    } else {
        $headers[] = 'Authorization: Bearer ' . $config['api_key'];
    }
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) $config['timeout_seconds'],
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false) return ['ok' => false, 'error' => 'Insurer connection failed: ' . $error, 'http_status' => $code];
    } else {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => (int) $config['timeout_seconds'],
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($url, false, $context);
        $code = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) $code = (int) $m[1];
        if ($raw === false) return ['ok' => false, 'error' => 'Insurer connection failed.', 'http_status' => $code];
    }
    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'Insurer returned a non-JSON response.', 'http_status' => $code, 'raw' => mb_substr((string) $raw, 0, 1000)];
    }
    if ($code < 200 || $code >= 300) {
        $message = (string) ($decoded['message'] ?? $decoded['error'] ?? 'Insurer rejected the policy request.');
        return ['ok' => false, 'error' => $message, 'http_status' => $code, 'response' => $decoded];
    }
    $policyNo = insurance_policy_path($decoded, (string) $config['policy_number_path']);
    if ($policyNo === '') {
        $policyNo = (string) ($decoded['policy_number'] ?? $decoded['policyNumber'] ?? $decoded['data']['policy_number'] ?? '');
    }
    if ($policyNo === '') {
        return ['ok' => false, 'error' => 'Insurer response did not include a policy number.', 'http_status' => $code, 'response' => $decoded];
    }
    return ['ok' => true, 'policy_no' => $policyNo, 'http_status' => $code, 'response' => $decoded];
}

function insurance_issue_for_parcel(string $parcelId): array
{
    $stmt = db()->prepare('SELECT * FROM parcels WHERE id = ? LIMIT 1');
    $stmt->execute([$parcelId]);
    $parcel = $stmt->fetch();
    if (!$parcel) return ['ok' => false, 'error' => 'Parcel not found.'];
    if (trim((string) ($parcel['insurance_policy_no'] ?? '')) !== '') {
        return ['ok' => true, 'policy_no' => (string) $parcel['insurance_policy_no'], 'already' => true];
    }
    $config = insurance_config();
    $provider = (string) $config['provider_name'];
    if ($config['mode'] === 'demo') {
        $policyNo = 'DEMO-POL-' . strtoupper(substr(hash('sha256', 'mzigo-demo-insurance:' . $parcel['tracking']), 0, 10));
        $response = ['demo' => true, 'note' => 'Demo policy — no real cover is in force until insurer API is configured.'];
    } else {
        $payload = [
            'product_code' => $config['product_code'],
            'reference' => (string) $parcel['tracking'],
            'currency' => 'KES',
            'declared_value_kes' => (int) $parcel['value_kes'],
            'insurance_fee_kes' => (int) $parcel['fee_insurance'],
            'sender' => ['name' => (string) $parcel['sender_name'], 'phone' => (string) $parcel['sender_phone']],
            'receiver' => ['name' => (string) $parcel['receiver_name'], 'phone' => (string) $parcel['receiver_phone']],
            'description' => (string) $parcel['description'],
            'route' => ['from' => (string) $parcel['from_city'], 'to' => (string) $parcel['to_city']],
            'carrier' => (string) $parcel['carrier'],
        ];
        $result = insurance_provider_request($config, $payload);
        if (!$result['ok']) {
            db()->prepare('UPDATE parcels SET insurance_status = "failed", insurance_provider = ? WHERE id = ?')
                ->execute([$provider, $parcelId]);
            return ['ok' => false, 'error' => $result['error'], 'response' => $result];
        }
        $policyNo = (string) $result['policy_no'];
        $response = $result['response'];
    }
    db()->prepare('UPDATE parcels SET insurance_policy_no = ?, insurance_status = "issued", insurance_provider = ?, insurance_issued_at = NOW(), insurance_response = ? WHERE id = ?')
        ->execute([$policyNo, $provider, json_encode($response, JSON_UNESCAPED_SLASHES), $parcelId]);
    return ['ok' => true, 'policy_no' => $policyNo, 'provider' => $provider];
}

function insurance_retry_pending(int $limit = 20): array
{
    $limit = max(1, min(100, $limit));
    $rows = db()->query("SELECT id FROM parcels WHERE insurance_status IN ('pending','failed') AND insurance_policy_no IS NULL ORDER BY registered_at DESC LIMIT " . $limit)->fetchAll();
    $results = [];
    foreach ($rows as $row) {
        $results[] = ['id' => (string) $row['id']] + insurance_issue_for_parcel((string) $row['id']);
    }
    return $results;
}

function insurance_validate_live_config(array $config): ?string
{
    if ($config['mode'] !== 'live') return null;
    if (trim((string) $config['api_base_url']) === '') return 'Insurer API base URL is required for live mode.';
    if (trim((string) $config['api_key']) === '') return 'Insurer API key is required for live mode.';
    if (trim((string) $config['product_code']) === '') return 'Insurer product code is required for live mode.';
    return null;
}
