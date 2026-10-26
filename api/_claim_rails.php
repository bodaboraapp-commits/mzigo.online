<?php
/**
 * Mzigo.online — claim payout and recovery rails.
 *
 * Payout providers: demo (immediate), mpesa (Daraja B2C sandbox/live).
 * Recovery providers: demo, webhook (signed HTTP integration for insurers/carriers).
 * Provider credentials and callback secrets are encrypted at rest.
 */
declare(strict_types=1);
require_once __DIR__ . '/_crypto.php';

function claim_rails_payout_fields(): array
{
    return ['consumer_key', 'consumer_secret', 'shortcode', 'passkey', 'initiator_name', 'security_credential', 'callback_url'];
}

function claim_rails_recovery_fields(): array
{
    return ['endpoint', 'api_key', 'signing_secret', 'callback_url'];
}

function claim_rails_config(): array
{
    $defaults = [
        'payout_mode' => 'demo', 'payout_provider' => 'demo', 'payout_config' => [],
        'recovery_mode' => 'demo', 'recovery_provider' => 'demo', 'recovery_config' => [],
        'callback_secret' => '',
    ];
    try {
        $row = db()->query('SELECT * FROM claim_rails_config WHERE id = 1 LIMIT 1')->fetch();
        if (!$row) { return $defaults; }
        $defaults['payout_mode'] = (string) $row['payout_mode'];
        $defaults['payout_provider'] = (string) $row['payout_provider'];
        $defaults['recovery_mode'] = (string) $row['recovery_mode'];
        $defaults['recovery_provider'] = (string) $row['recovery_provider'];
        $payout = secure_value_decrypt((string) ($row['payout_config'] ?? ''));
        $recovery = secure_value_decrypt((string) ($row['recovery_config'] ?? ''));
        $secret = secure_value_decrypt((string) ($row['callback_secret'] ?? ''));
        $defaults['payout_config'] = $payout ? (json_decode($payout, true) ?: []) : [];
        $defaults['recovery_config'] = $recovery ? (json_decode($recovery, true) ?: []) : [];
        $defaults['callback_secret'] = (string) ($secret ?: '');
    } catch (Throwable $e) {
        error_log('claim rails config failed: ' . $e->getMessage());
    }
    return $defaults;
}

function claim_rails_callback_endpoint(): string
{
    $base = rtrim((string) (config()['app']['base_url'] ?? 'http://localhost:8090'), '/');
    return $base . '/api/claim-rail-callback.php';
}

function claim_rails_mask(array $config, array $secretFields): array
{
    $masked = [];
    foreach ($config as $key => $value) {
        $isSecret = in_array($key, $secretFields, true) || preg_match('/secret|pass|key|credential|token/i', $key);
        $masked[$key] = $isSecret && (string) $value !== '' ? '••••••••' : $value;
    }
    return $masked;
}

function claim_rails_admin_view(): array
{
    $c = claim_rails_config();
    return [
        'payout_mode' => $c['payout_mode'], 'payout_provider' => $c['payout_provider'],
        'payout_config' => claim_rails_mask($c['payout_config'], ['consumer_secret', 'passkey', 'security_credential']),
        'recovery_mode' => $c['recovery_mode'], 'recovery_provider' => $c['recovery_provider'],
        'recovery_config' => claim_rails_mask($c['recovery_config'], ['api_key', 'signing_secret']),
        'callback_endpoint' => claim_rails_callback_endpoint(),
        'callback_secret_set' => $c['callback_secret'] !== '',
        'payout_ready' => claim_rails_payout_ready($c),
        'recovery_ready' => claim_rails_recovery_ready($c),
    ];
}

function claim_rails_payout_ready(array $c): bool
{
    if ($c['payout_mode'] === 'demo' || $c['payout_provider'] === 'demo') { return true; }
    foreach (['consumer_key', 'consumer_secret', 'shortcode', 'passkey', 'initiator_name', 'security_credential'] as $field) {
        if (trim((string) ($c['payout_config'][$field] ?? '')) === '') { return false; }
    }
    return $c['callback_secret'] !== '';
}

function claim_rails_recovery_ready(array $c): bool
{
    if ($c['recovery_mode'] === 'demo' || $c['recovery_provider'] === 'demo') { return true; }
    return trim((string) ($c['recovery_config']['endpoint'] ?? '')) !== ''
        && trim((string) ($c['recovery_config']['signing_secret'] ?? '')) !== '';
}

function claim_rails_save(array $data, int $adminId): array
{
    $current = claim_rails_config();
    $payoutMode = in_array($data['payout_mode'] ?? '', ['demo', 'sandbox', 'live'], true) ? $data['payout_mode'] : 'demo';
    $payoutProvider = in_array($data['payout_provider'] ?? '', ['demo', 'mpesa'], true) ? $data['payout_provider'] : 'demo';
    $recoveryMode = in_array($data['recovery_mode'] ?? '', ['demo', 'sandbox', 'live'], true) ? $data['recovery_mode'] : 'demo';
    $recoveryProvider = in_array($data['recovery_provider'] ?? '', ['demo', 'webhook'], true) ? $data['recovery_provider'] : 'demo';
    $payoutInput = is_array($data['payout_config'] ?? null) ? $data['payout_config'] : [];
    $recoveryInput = is_array($data['recovery_config'] ?? null) ? $data['recovery_config'] : [];
    $payoutConfig = [];
    foreach (claim_rails_payout_fields() as $field) {
        $value = trim((string) ($payoutInput[$field] ?? ''));
        if ($value === '••••••••' || $value === '') {
            $value = (string) ($current['payout_config'][$field] ?? '');
        }
        if ($value !== '') { $payoutConfig[$field] = $value; }
    }
    $recoveryConfig = [];
    foreach (claim_rails_recovery_fields() as $field) {
        $value = trim((string) ($recoveryInput[$field] ?? ''));
        if ($value === '••••••••' || $value === '') {
            $value = (string) ($current['recovery_config'][$field] ?? '');
        }
        if ($value !== '') { $recoveryConfig[$field] = $value; }
    }
    $callbackSecret = trim((string) ($data['callback_secret'] ?? ''));
    $generatedSecret = false;
    if ($callbackSecret === '' || $callbackSecret === '••••••••') {
        $callbackSecret = (string) ($current['callback_secret'] ?? '');
        if ($callbackSecret === '') {
            $callbackSecret = 'crl_' . bin2hex(random_bytes(24));
            $generatedSecret = true;
        }
    }
    $candidate = [
        'payout_mode' => $payoutMode, 'payout_provider' => $payoutProvider, 'payout_config' => $payoutConfig,
        'recovery_mode' => $recoveryMode, 'recovery_provider' => $recoveryProvider,
        'recovery_config' => $recoveryConfig, 'callback_secret' => $callbackSecret,
    ];
    if (in_array($payoutMode, ['sandbox', 'live'], true) && $payoutProvider !== 'demo' && !claim_rails_payout_ready($candidate)) {
        fail_error('payout_credentials_required', 'Add the M-Pesa consumer key/secret, shortcode, passkey, initiator and security credential before enabling sandbox/live payouts.', 422);
    }
    if (in_array($recoveryMode, ['sandbox', 'live'], true) && $recoveryProvider !== 'demo' && !claim_rails_recovery_ready($candidate)) {
        fail_error('recovery_credentials_required', 'Add the recovery endpoint and signing secret before enabling sandbox/live recovery.', 422);
    }
    db()->prepare('INSERT INTO claim_rails_config (id, payout_mode, payout_provider, payout_config, recovery_mode, recovery_provider, recovery_config, callback_secret, updated_by)
        VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE payout_mode = VALUES(payout_mode), payout_provider = VALUES(payout_provider),
          payout_config = VALUES(payout_config), recovery_mode = VALUES(recovery_mode), recovery_provider = VALUES(recovery_provider),
          recovery_config = VALUES(recovery_config), callback_secret = VALUES(callback_secret), updated_by = VALUES(updated_by)')
        ->execute([$payoutMode, $payoutProvider, secure_value_encrypt(json_encode($payoutConfig, JSON_UNESCAPED_SLASHES)),
            $recoveryMode, $recoveryProvider, secure_value_encrypt(json_encode($recoveryConfig, JSON_UNESCAPED_SLASHES)),
            secure_value_encrypt($callbackSecret), $adminId]);
    audit('admin.claim_rails_saved', 'claim_rails', '1', [
        'payout_mode' => $payoutMode, 'payout_provider' => $payoutProvider,
        'recovery_mode' => $recoveryMode, 'recovery_provider' => $recoveryProvider,
    ]);
    $view = claim_rails_admin_view();
    if ($generatedSecret) { $view['callback_secret_once'] = $callbackSecret; }
    return $view;
}

function claim_rails_regenerate_callback_secret(int $adminId): string
{
    $secret = 'crl_' . bin2hex(random_bytes(24));
    db()->prepare('UPDATE claim_rails_config SET callback_secret = ?, updated_by = ? WHERE id = 1')
        ->execute([secure_value_encrypt($secret), $adminId]);
    audit('admin.claim_rails_callback_secret_regenerated', 'claim_rails', '1', []);
    return $secret;
}

function claim_rails_http(string $url, string $method = 'POST', ?string $body = null, array $headers = [], array $auth = []): array
{
    if (!function_exists('curl_init')) { return ['ok' => false, 'code' => 0, 'body' => 'cURL unavailable', 'error' => 'cURL unavailable']; }
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
    ];
    if ($body !== null) { $opts[CURLOPT_POSTFIELDS] = $body; }
    if ($auth) { $opts[CURLOPT_USERPWD] = $auth[0] . ':' . $auth[1]; }
    curl_setopt_array($ch, $opts);
    $response = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    return ['ok' => $code >= 200 && $code < 300, 'code' => $code, 'body' => $response, 'error' => $error];
}

function claim_rails_phone(array $claim): string
{
    if (!empty($claim['user_id'])) {
        $stmt = db()->prepare('SELECT phone FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $claim['user_id']]);
        $phone = (string) ($stmt->fetchColumn() ?: '');
        if ($phone !== '') { return preg_replace('/\D+/', '', $phone) ?? ''; }
    }
    return preg_replace('/\D+/', '', (string) ($claim['receiver_phone'] ?? '')) ?? '';
}

function claim_payout_finalize(int $payoutId, string $externalRef, ?string $providerRef, array $response, string $note = ''): void
{
    $stmt = db()->prepare('SELECT p.*, c.status AS claim_status, c.ref AS claim_ref FROM claim_payouts p JOIN claims c ON c.id = p.claim_id WHERE p.id = ? LIMIT 1');
    $stmt->execute([$payoutId]);
    $payout = $stmt->fetch();
    if (!$payout || $payout['status'] === 'sent') { return; }
    db()->prepare('UPDATE claim_payouts SET status = "sent", external_ref = COALESCE(external_ref, ?), provider_ref = ?, response_payload = ?, completed_at = NOW(), error = NULL WHERE id = ?')
        ->execute([$externalRef, $providerRef, json_encode($response, JSON_UNESCAPED_SLASHES), $payoutId]);
    $claimStmt = db()->prepare('SELECT * FROM claims WHERE id = ? LIMIT 1');
    $claimStmt->execute([(int) $payout['claim_id']]);
    $claim = $claimStmt->fetch();
    if (!$claim) { return; }
    db()->prepare('UPDATE claims SET status = "paid", payout_ref = ?, payout_amount_kes = ?, paid_at = NOW(), decided_at = NOW(), resolution_note = COALESCE(NULLIF(?, ""), resolution_note) WHERE id = ?')
        ->execute([$providerRef ?: $externalRef, (int) $payout['amount_kes'], $note, (int) $claim['id']]);
    $existing = db()->prepare('SELECT id FROM transactions WHERE subject_type = "claim" AND subject_id = ? AND type = "claim_payout" LIMIT 1');
    $existing->execute([(string) $claim['id']]);
    if (!$existing->fetchColumn()) {
        record_transaction([
            'reference' => $providerRef ?: $externalRef, 'tenant_id' => (int) $claim['tenant_id'], 'user_id' => null,
            'type' => 'claim_payout', 'amount_kes' => (int) $payout['amount_kes'], 'method' => strtoupper($payout['provider']),
            'subject_type' => 'claim', 'subject_id' => (string) $claim['id'],
            'metadata' => ['ref' => $claim['ref'], 'payout_id' => $payoutId, 'provider' => $payout['provider']],
        ]);
    }
    claim_event((int) $claim['id'], 'paid', 'Payout completed via ' . $payout['provider'] . ': ' . ($providerRef ?: $externalRef), null);
    audit('claim.payout_completed', 'claim', (string) $claim['id'], ['payout_id' => $payoutId, 'provider' => $payout['provider'], 'amount' => (int) $payout['amount_kes']]);
    $p = db()->prepare('SELECT tracking FROM parcels WHERE id = ? LIMIT 1');
    $p->execute([(string) $claim['parcel_id']]);
    $tracking = (string) ($p->fetchColumn() ?: '');
    notify_claim_status([
        'id' => (int) $claim['id'], 'ref' => $claim['ref'], 'tenant_id' => (int) $claim['tenant_id'],
        'user_id' => $claim['user_id'] !== null ? (int) $claim['user_id'] : null,
    ], ['id' => $claim['parcel_id'], 'tracking' => $tracking], 'paid', $note);
}

function claim_mpesa_token(array $cfg, string $mode): array
{
    $base = $mode === 'sandbox' ? 'https://sandbox.safaricom.co.ke' : 'https://api.safaricom.co.ke';
    $result = claim_rails_http($base . '/oauth/v1/generate?grant_type=client_credentials', 'GET', null, [], [
        (string) ($cfg['consumer_key'] ?? ''), (string) ($cfg['consumer_secret'] ?? ''),
    ]);
    $data = json_decode($result['body'], true);
    return ['ok' => $result['ok'] && !empty($data['access_token']), 'token' => $data['access_token'] ?? null, 'error' => $result['error'] ?: ($data['error_description'] ?? 'OAuth failed'), 'body' => $result['body']];
}

function claim_payout_initiate(array $claim, array $admin, int $amount, string $note = ''): array
{
    $c = claim_rails_config();
    if (!in_array($claim['status'], ['approved', 'payout_processing'], true)) {
        fail_error('payout_not_ready', 'The claim must be approved before a payout can be sent.', 422);
    }
    if ($claim['status'] === 'approved' && (empty($claim['payout_hold_until']) || strtotime((string) $claim['payout_hold_until']) > time())) {
        fail_error('payout_hold_active', 'The payout hold period is still active.', 422);
    }
    $cap = min((int) ($claim['value_kes'] ?? 0), ($claim['tier'] ?? 'A') === 'B' ? 50000 : 10000);
    if ($amount <= 0 || $amount > $cap) {
        fail_error('payout_cap_exceeded', 'The maximum payout for this parcel is KES ' . number_format($cap) . '.', 422);
    }
    if ((int) ($claim['estimated_value_kes'] ?? 0) > 5000 && (int) ($claim['approved_by'] ?? 0) === (int) $admin['id']) {
        fail_error('separate_releaser', 'For claims above KES 5,000 a different admin must release the payout.', 422);
    }
    $existing = db()->prepare('SELECT * FROM claim_payouts WHERE claim_id = ? AND status IN ("created","processing","sent") ORDER BY id DESC LIMIT 1');
    $existing->execute([(int) $claim['id']]);
    if ($row = $existing->fetch()) {
        return ['ok' => true, 'status' => $row['status'], 'message' => 'A payout is already in progress or completed.', 'payout_id' => (int) $row['id']];
    }
    $phone = claim_rails_phone($claim);
    if ($phone === '') { fail_error('payout_phone_missing', 'No payout phone number is available for this claim.', 422); }
    $idempotency = 'claim-payout-' . (int) $claim['id'] . '-' . $amount;
    $pdo = db();
    $pdo->prepare('INSERT INTO claim_payouts (claim_id, tenant_id, provider, mode, amount_kes, phone, status, idempotency_key, requested_by)
        VALUES (?, ?, ?, ?, ?, ?, "created", ?, ?)')
        ->execute([(int) $claim['id'], (int) $claim['tenant_id'], $c['payout_provider'], $c['payout_mode'], $amount, $phone, $idempotency, (int) $admin['id']]);
    $payoutId = (int) $pdo->lastInsertId();
    if ($c['payout_mode'] === 'demo' || $c['payout_provider'] === 'demo') {
        $ref = 'DEMO-PAY-' . strtoupper(random_code(8));
        claim_payout_finalize($payoutId, $ref, $ref, ['demo' => true], $note);
        return ['ok' => true, 'status' => 'sent', 'provider_ref' => $ref, 'message' => 'Demo payout completed.'];
    }
    if ($c['payout_provider'] !== 'mpesa') {
        db()->prepare('UPDATE claim_payouts SET status = "failed", error = ? WHERE id = ?')->execute(['Unsupported payout provider.', $payoutId]);
        fail_error('payout_provider_error', 'Unsupported payout provider.', 422);
    }
    $cfg = $c['payout_config'];
    $token = claim_mpesa_token($cfg, $c['payout_mode']);
    if (!$token['ok']) {
        db()->prepare('UPDATE claim_payouts SET status = "failed", error = ? WHERE id = ?')->execute([mb_substr((string) $token['error'], 0, 255), $payoutId]);
        fail_error('payout_auth_failed', 'M-Pesa authentication failed. Check payout credentials.', 422);
    }
    $originatorId = 'MZ-' . strtoupper(random_code(10));
    $callback = trim((string) ($cfg['callback_url'] ?? '')) ?: claim_rails_callback_endpoint();
    $request = [
        'OriginatorConversationID' => $originatorId,
        'InitiatorName' => (string) ($cfg['initiator_name'] ?? ''),
        'SecurityCredential' => (string) ($cfg['security_credential'] ?? ''),
        'CommandID' => 'BusinessPayment',
        'Amount' => $amount,
        'PartyA' => (string) ($cfg['shortcode'] ?? ''),
        'PartyB' => $phone,
        'Remarks' => 'Mzigo claim payout ' . $claim['ref'],
        'QueueTimeOutURL' => $callback,
        'ResultURL' => $callback,
        'Occasion' => 'Claim ' . $claim['ref'],
    ];
    $base = $c['payout_mode'] === 'sandbox' ? 'https://sandbox.safaricom.co.ke' : 'https://api.safaricom.co.ke';
    $res = claim_rails_http($base . '/mpesa/b2c/v1/paymentrequest', 'POST', json_encode($request), [
        'Content-Type: application/json', 'Authorization: Bearer ' . $token['token'],
    ]);
    $decoded = json_decode($res['body'], true);
    if ($res['ok'] && isset($decoded['ResponseCode']) && (string) $decoded['ResponseCode'] === '0') {
        $external = (string) ($decoded['ConversationID'] ?? $originatorId);
        db()->prepare('UPDATE claim_payouts SET status = "processing", external_ref = ?, request_payload = ?, response_payload = ? WHERE id = ?')
            ->execute([$external, json_encode($request, JSON_UNESCAPED_SLASHES), $res['body'], $payoutId]);
        db()->prepare('UPDATE claims SET status = "payout_processing" WHERE id = ?')->execute([(int) $claim['id']]);
        claim_event((int) $claim['id'], 'payout_processing', 'Provider payout submitted: ' . $external, (int) $admin['id']);
        audit('claim.payout_submitted', 'claim', (string) $claim['id'], ['payout_id' => $payoutId, 'provider_ref' => $external]);
        return ['ok' => true, 'status' => 'processing', 'provider_ref' => $external,
            'message' => 'Payout submitted to ' . strtoupper($c['payout_mode']) . ' M-Pesa. Await the signed callback.'];
    }
    $error = $decoded['errorMessage'] ?? $decoded['ResponseDescription'] ?? $res['error'] ?: 'M-Pesa rejected the payout.';
    db()->prepare('UPDATE claim_payouts SET status = "failed", request_payload = ?, response_payload = ?, error = ? WHERE id = ?')
        ->execute([json_encode($request, JSON_UNESCAPED_SLASHES), $res['body'], mb_substr((string) $error, 0, 255), $payoutId]);
    audit('claim.payout_failed', 'claim', (string) $claim['id'], ['payout_id' => $payoutId, 'error' => $error]);
    fail_error('payout_provider_error', (string) $error, 422);
}

function claim_payout_process_callback(string $externalRef, bool $success, ?string $providerRef, string $reason, array $payload): array
{
    $stmt = db()->prepare('SELECT * FROM claim_payouts WHERE external_ref = ? OR provider_ref = ? OR idempotency_key = ? LIMIT 1');
    $stmt->execute([$externalRef, $externalRef, $externalRef]);
    $payout = $stmt->fetch();
    if (!$payout) { return ['ok' => false, 'error' => 'Unknown payout reference.', 'code' => 404]; }
    if ($payout['status'] === 'sent') { return ['ok' => true, 'idempotent' => true, 'message' => 'Payout already completed.']; }
    db()->prepare('UPDATE claim_payouts SET response_payload = ?, provider_ref = COALESCE(provider_ref, ?) WHERE id = ?')
        ->execute([json_encode($payload, JSON_UNESCAPED_SLASHES), $providerRef, (int) $payout['id']]);
    if ($success) {
        claim_payout_finalize((int) $payout['id'], $externalRef, $providerRef, $payload, $reason);
        return ['ok' => true, 'message' => 'Payout completed.'];
    }
    db()->prepare('UPDATE claim_payouts SET status = "failed", error = ? WHERE id = ?')->execute([mb_substr($reason, 0, 255), (int) $payout['id']]);
    db()->prepare('UPDATE claims SET status = "approved" WHERE id = ? AND status = "payout_processing"')->execute([(int) $payout['claim_id']]);
    claim_event((int) $payout['claim_id'], 'payout_failed', 'Provider payout failed: ' . $reason, null);
    audit('claim.payout_failed', 'claim', (string) $payout['claim_id'], ['payout_id' => (int) $payout['id'], 'reason' => $reason]);
    notify_claim_status(['id' => (int) $payout['claim_id'], 'ref' => $payout['claim_id'], 'tenant_id' => (int) $payout['tenant_id']],
        ['id' => '', 'tracking' => ''], 'approved', 'The payout provider reported a failure. Manual reconciliation may be required.');
    return ['ok' => true, 'message' => 'Payout failure recorded.'];
}

function claim_recovery_request(array $claim, array $admin, ?int $amount, string $note = ''): array
{
    if (!in_array($claim['status'], ['paid', 'reversed'], true)) {
        fail_error('recovery_not_allowed', 'Recovery can only be requested for paid or reversed claims.', 422);
    }
    $c = claim_rails_config();
    $external = 'REC-' . strtoupper(random_code(10));
    $pdo = db();
    $pdo->prepare('INSERT INTO claim_recoveries (claim_id, tenant_id, provider, mode, status, amount_kes, external_ref, note, requested_by)
        VALUES (?, ?, ?, ?, "requested", ?, ?, ?, ?)')
        ->execute([(int) $claim['id'], (int) $claim['tenant_id'], $c['recovery_provider'], $c['recovery_mode'],
            $amount, $external, $note, (int) $admin['id']]);
    $recoveryId = (int) $pdo->lastInsertId();
    db()->prepare('UPDATE claims SET recovery_status = "requested", recovery_ref = ?, recovery_amount_kes = ?, recovery_note = ? WHERE id = ?')
        ->execute([$external, $amount, $note, (int) $claim['id']]);
    claim_event((int) $claim['id'], 'recovery_requested', 'Recovery requested: ' . $external, (int) $admin['id']);
    audit('claim.recovery_requested', 'claim', (string) $claim['id'], ['recovery_id' => $recoveryId, 'provider' => $c['recovery_provider']]);
    if ($c['recovery_mode'] === 'demo' || $c['recovery_provider'] === 'demo') {
        return ['ok' => true, 'status' => 'requested', 'provider_ref' => $external, 'message' => 'Demo recovery request recorded.'];
    }
    $cfg = $c['recovery_config'];
    $body = json_encode(['kind' => 'recovery', 'external_ref' => $external, 'claim_ref' => $claim['ref'],
        'tracking' => $claim['tracking'] ?? '', 'amount_kes' => $amount, 'note' => $note], JSON_UNESCAPED_SLASHES);
    $signature = 'sha256=' . hash_hmac('sha256', $body, (string) ($cfg['signing_secret'] ?? ''));
    $headers = ['Content-Type: application/json', 'X-Mzigo-Signature: ' . $signature];
    if (!empty($cfg['api_key'])) { $headers[] = 'Authorization: Bearer ' . $cfg['api_key']; }
    $res = claim_rails_http((string) $cfg['endpoint'], 'POST', $body, $headers);
    db()->prepare('UPDATE claim_recoveries SET status = ?, request_payload = ?, response_payload = ?, reference = ?, error = ? WHERE id = ?')
        ->execute([$res['ok'] ? 'processing' : 'requested', $body, $res['body'], $external,
            $res['ok'] ? null : mb_substr((string) ($res['error'] ?: 'Provider request failed'), 0, 255), $recoveryId]);
    if (!$res['ok']) {
        return ['ok' => false, 'status' => 'requested', 'error' => 'Recovery provider request failed; manual reconciliation may be required.'];
    }
    return ['ok' => true, 'status' => 'processing', 'provider_ref' => $external, 'message' => 'Recovery request submitted to provider.'];
}

function claim_recovery_process_callback(string $externalRef, string $status, ?int $amount, ?string $reference, string $note, array $payload): array
{
    $stmt = db()->prepare('SELECT * FROM claim_recoveries WHERE external_ref = ? OR reference = ? LIMIT 1');
    $stmt->execute([$externalRef, $externalRef]);
    $recovery = $stmt->fetch();
    if (!$recovery) { return ['ok' => false, 'error' => 'Unknown recovery reference.', 'code' => 404]; }
    $status = in_array($status, ['requested', 'processing', 'recovered', 'rejected', 'cancelled'], true) ? $status : 'processing';
    db()->prepare('UPDATE claim_recoveries SET status = ?, amount_kes = COALESCE(?, amount_kes), reference = COALESCE(?, reference), response_payload = ?, note = COALESCE(NULLIF(?, ""), note), completed_at = IF(? IN ("recovered","rejected","cancelled"), NOW(), completed_at) WHERE id = ?')
        ->execute([$status, $amount, $reference, json_encode($payload, JSON_UNESCAPED_SLASHES), $note, $status, (int) $recovery['id']]);
    $recoveryStatus = $status === 'recovered' ? 'recovered' : ($status === 'rejected' ? 'rejected' : ($status === 'cancelled' ? 'none' : 'requested'));
    db()->prepare('UPDATE claims SET recovery_status = ?, recovery_ref = COALESCE(?, recovery_ref), recovery_amount_kes = COALESCE(?, recovery_amount_kes), recovery_note = COALESCE(NULLIF(?, ""), recovery_note) WHERE id = ?')
        ->execute([$recoveryStatus, $reference, $amount, $note, (int) $recovery['claim_id']]);
    claim_event((int) $recovery['claim_id'], 'recovery_' . $status, $note ?: ('Recovery provider status: ' . $status), null);
    audit('claim.recovery_' . $status, 'claim', (string) $recovery['claim_id'], ['recovery_id' => (int) $recovery['id'], 'amount' => $amount]);
    return ['ok' => true, 'message' => 'Recovery updated.'];
}

function claim_rail_log_event(string $kind, string $eventType, ?string $externalRef, string $payload): void
{
    try {
        db()->prepare('INSERT INTO claim_rail_events (kind, external_ref, event_type, payload) VALUES (?, ?, ?, ?)')
            ->execute([$kind, $externalRef, $eventType, $payload]);
    } catch (Throwable $e) {
        error_log('claim rail event log failed: ' . $e->getMessage());
    }
}

function claim_rails_callback_authorised(string $raw): bool
{
    $secret = claim_rails_config()['callback_secret'];
    if ($secret === '') { return false; }
    $provided = (string) ($_SERVER['HTTP_X_MZIGO_SIGNATURE'] ?? '');
    if ($provided !== '' && hash_equals('sha256=' . hash_hmac('sha256', $raw, $secret), $provided)) { return true; }
    $token = (string) ($_GET['token'] ?? '');
    return $token !== '' && hash_equals($secret, $token);
}
