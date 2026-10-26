<?php
/**
 * Mzigo.online — payout configuration + demo/live M-Pesa disbursement helpers.
 *
 * Payout configuration is stored in platform_settings.payout_config as JSON so
 * admin can switch demo/live, set minimums/schedule and supply Daraja credentials
 * without a deploy. Live B2C calls only run when the configured credentials are
 * present; otherwise the caller gets a clear configuration error.
 */
declare(strict_types=1);

function payout_config(): array
{
    $defaults = [
        'mode' => 'demo',                 // demo | live
        'schedule' => 'instant',          // instant | weekly | manual
        'min_kes' => 50,
        'daraja_env' => 'sandbox',        // sandbox | production
        'consumer_key' => '',
        'consumer_secret' => '',
        'shortcode' => '',
        'passkey' => '',
        'initiator_name' => '',
        'security_credential' => '',
        'callback_url' => '',
        'result_url' => '',
        'timeout_url' => '',
        'incentive_enabled' => 1,
        'incentive_min_rating' => 4.5,
        'incentive_per_job_kes' => 20,
        'incentive_volume_threshold' => 10,
        'incentive_volume_bonus_kes' => 500,
    ];
    $raw = platform_setting('payout_config', '');
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $defaults = array_merge($defaults, $decoded);
        }
    }
    return $defaults;
}

function payout_config_save(array $input): array
{
    $current = payout_config();
    $clean = [];
    $clean['mode'] = in_array(($input['mode'] ?? 'demo'), ['demo', 'live'], true) ? (string) $input['mode'] : 'demo';
    $clean['schedule'] = in_array(($input['schedule'] ?? 'instant'), ['instant', 'weekly', 'manual'], true)
        ? (string) $input['schedule'] : 'instant';
    $clean['min_kes'] = max(1, min(100000, (int) ($input['min_kes'] ?? 50)));
    $clean['daraja_env'] = in_array(($input['daraja_env'] ?? 'sandbox'), ['sandbox', 'production'], true)
        ? (string) $input['daraja_env'] : 'sandbox';
    foreach (['consumer_key', 'consumer_secret', 'shortcode', 'passkey', 'initiator_name', 'security_credential', 'callback_url', 'result_url', 'timeout_url'] as $key) {
        $value = isset($input[$key]) && is_scalar($input[$key]) ? trim((string) $input[$key]) : '';
        $clean[$key] = mb_substr($value, 0, 1000);
    }
    $clean['incentive_enabled'] = !empty($input['incentive_enabled']) ? 1 : 0;
    $clean['incentive_min_rating'] = max(1, min(5, (float) ($input['incentive_min_rating'] ?? 4.5)));
    $clean['incentive_per_job_kes'] = max(0, min(10000, (int) ($input['incentive_per_job_kes'] ?? 20)));
    $clean['incentive_volume_threshold'] = max(1, min(1000, (int) ($input['incentive_volume_threshold'] ?? 10)));
    $clean['incentive_volume_bonus_kes'] = max(0, min(100000, (int) ($input['incentive_volume_bonus_kes'] ?? 500)));
    if ($clean['mode'] === 'live' && $clean['consumer_key'] === '' && $current['consumer_key'] !== '') {
        $clean['consumer_key'] = $current['consumer_key'];
    }
    if ($clean['consumer_secret'] === '' && $current['consumer_secret'] !== '') {
        $clean['consumer_secret'] = $current['consumer_secret'];
    }
    if ($clean['passkey'] === '' && $current['passkey'] !== '') {
        $clean['passkey'] = $current['passkey'];
    }
    if ($clean['security_credential'] === '' && $current['security_credential'] !== '') {
        $clean['security_credential'] = $current['security_credential'];
    }
    $json = json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    db()->prepare(
        'INSERT INTO platform_settings (setting_key, setting_value) VALUES ("payout_config", ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    )->execute([$json]);
    return $clean;
}

function payout_public_config(): array
{
    $c = payout_config();
    return [
        'mode' => $c['mode'],
        'schedule' => $c['schedule'],
        'min_kes' => (int) $c['min_kes'],
        'live_ready' => $c['mode'] === 'demo' ? true : mpesa_credentials_ready($c),
        'incentive' => [
            'enabled' => !empty($c['incentive_enabled']),
            'min_rating' => (float) $c['incentive_min_rating'],
            'per_job_kes' => (int) $c['incentive_per_job_kes'],
            'volume_threshold' => (int) $c['incentive_volume_threshold'],
            'volume_bonus_kes' => (int) $c['incentive_volume_bonus_kes'],
        ],
    ];
}

function mpesa_credentials_ready(array $cfg): bool
{
    foreach (['consumer_key', 'consumer_secret', 'shortcode', 'passkey', 'initiator_name', 'security_credential'] as $key) {
        if (trim((string) ($cfg[$key] ?? '')) === '') return false;
    }
    return true;
}

/** Normalise a Kenyan phone to Daraja's 2547… form. */
function mpesa_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (str_starts_with($digits, '0')) $digits = '254' . substr($digits, 1);
    if (str_starts_with($digits, '254')) return $digits;
    if (str_starts_with($digits, '7') && strlen($digits) === 9) return '254' . $digits;
    return $digits;
}

/**
 * Initiate a Daraja B2C payout. Returns ok=false with an error when credentials
 * are incomplete or the API rejects the call. The caller records the payout.
 */
function mpesa_b2c_payout(array $cfg, string $phone, int $amountKes, string $remarks, string $ref): array
{
    if (!mpesa_credentials_ready($cfg)) {
        return ['ok' => false, 'error' => 'Live payouts are not configured. Add Daraja credentials in Admin → Settings → Payouts.'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'cURL is not available on this server.'];
    }
    $base = $cfg['daraja_env'] === 'production' ? 'https://api.safaricom.co.ke' : 'https://sandbox.safaricom.co.ke';
    $ch = curl_init($base . '/oauth/v1/generate?grant_type=client_credentials');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERPWD => $cfg['consumer_key'] . ':' . $cfg['consumer_secret'],
    ]);
    $tokenRaw = curl_exec($ch);
    $tokenCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $token = json_decode((string) $tokenRaw, true);
    if ($tokenCode < 200 || $tokenCode >= 300 || empty($token['access_token'])) {
        return ['ok' => false, 'error' => 'Daraja authentication failed. Check the consumer key/secret and environment.'];
    }
    $payload = [
        'InitiatorName' => $cfg['initiator_name'],
        'SecurityCredential' => $cfg['security_credential'],
        'CommandID' => 'BusinessPayment',
        'Amount' => $amountKes,
        'PartyA' => $cfg['shortcode'],
        'PartyB' => mpesa_phone($phone),
        'Remarks' => mb_substr($remarks, 0, 100),
        'QueueTimeOutURL' => $cfg['timeout_url'] ?: ($cfg['callback_url'] ?? ''),
        'ResultURL' => $cfg['result_url'] ?: ($cfg['callback_url'] ?? ''),
        'Occasion' => mb_substr($ref, 0, 100),
    ];
    $ch = curl_init($base . '/mpesa/b2c/v1/paymentrequest');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token['access_token'],
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $body = json_decode((string) $raw, true);
    if ($code < 200 || $code >= 300 || !is_array($body)) {
        return ['ok' => false, 'error' => 'Daraja B2C request failed (HTTP ' . $code . ').'];
    }
    if (!empty($body['ResponseCode']) && (string) $body['ResponseCode'] !== '0') {
        return ['ok' => false, 'error' => (string) ($body['ResponseDescription'] ?? 'Daraja rejected the payout.')];
    }
    return [
        'ok' => true,
        'status' => 'processing',
        'provider_ref' => (string) ($body['ConversationID'] ?? ''),
        'response' => $body,
    ];
}

/** Quote the currently claimable runner incentive for unpayout jobs (RN-11b). */
function runner_incentive_quote(int $runnerId): array
{
    $cfg = payout_config();
    if (empty($cfg['incentive_enabled'])) {
        return ['eligible_jobs' => 0, 'per_job_kes' => 0, 'volume_bonus_kes' => 0, 'total_kes' => 0];
    }
    $stmt = db()->prepare("SELECT rating FROM runner_jobs
        WHERE runner_id = ? AND status = 'delivered' AND payout_id IS NULL AND rating IS NOT NULL");
    $stmt->execute([$runnerId]);
    $minRating = (float) $cfg['incentive_min_rating'];
    $eligible = 0;
    foreach ($stmt->fetchAll() as $row) {
        if ((int) $row['rating'] >= $minRating) { $eligible++; }
    }
    $perJob = $eligible * (int) $cfg['incentive_per_job_kes'];
    $volume = $eligible >= (int) $cfg['incentive_volume_threshold'] ? (int) $cfg['incentive_volume_bonus_kes'] : 0;
    return ['eligible_jobs' => $eligible, 'per_job_kes' => $perJob, 'volume_bonus_kes' => $volume,
        'total_kes' => $perJob + $volume];
}

/** Create a payout record and reserve the delivered jobs it covers. */
function runner_payout_create(int $runnerId, string $phone, int $amountKes, string $mode, string $note = '', int $bonusKes = 0): array
{
    $pdo = db();
    $ref = unique_token('runner_payouts', 'ref', 'PO-', 6);
    $status = $mode === 'demo' ? 'paid' : 'pending';
    $providerRef = $mode === 'demo' ? 'MPESA-DEMO-' . strtoupper(bin2hex(random_bytes(3))) : null;
    $paidAt = $mode === 'demo' ? gmdate('Y-m-d H:i:s') : null;
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO runner_payouts
               (ref, runner_id, amount_kes, bonus_kes, phone, status, provider_ref, note, requested_at, paid_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)"
        );
        $stmt->execute([$ref, $runnerId, $amountKes, $bonusKes, $phone, $status, $providerRef, $note, $paidAt]);
        $payoutId = (int) $pdo->lastInsertId();
        $stmt = $pdo->prepare(
            "UPDATE runner_jobs SET payout_id = ?
              WHERE runner_id = ? AND status = 'delivered' AND payout_id IS NULL"
        );
        $stmt->execute([$payoutId, $runnerId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['id' => $payoutId, 'ref' => $ref, 'status' => $status, 'provider_ref' => $providerRef];
}


function runner_launch_config(): array
{
    $defaults = [
        'cities' => ['Nairobi', 'Mombasa', 'Nakuru', 'Nyeri', 'Naivasha', 'Thika', 'Machakos'],
        'vehicles' => ['Walker', 'Boda', 'Car', 'Van', 'Truck'],
        'kyc_required' => true,
    ];
    $raw = platform_setting('runner_launch_config', '');
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $defaults = array_merge($defaults, $decoded);
        }
    }
    return $defaults;
}

function runner_launch_config_save(array $input): array
{
    $cities = array_values(array_filter(array_map('trim', is_array($input['cities'] ?? null) ? $input['cities'] : [])));
    $vehicles = array_values(array_filter(array_map('trim', is_array($input['vehicles'] ?? null) ? $input['vehicles'] : [])));
    $clean = [
        'cities' => $cities ?: ['Nairobi'],
        'vehicles' => $vehicles ?: ['Boda'],
        'kyc_required' => !empty($input['kyc_required']),
    ];
    db()->prepare(
        'INSERT INTO platform_settings (setting_key, setting_value) VALUES ("runner_launch_config", ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    )->execute([json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
    return $clean;
}
