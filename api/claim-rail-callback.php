<?php
/**
 * POST /api/claim-rail-callback.php — provider callback for payouts/recoveries.
 *
 * Authorisation: X-Mzigo-Signature: sha256=HMAC(raw body, callback secret)
 * or the callback URL's ?token=<callback secret> (M-Pesa ResultURL style).
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_claims.php';
require __DIR__ . '/_notifications.php';
require_once __DIR__ . '/_claim_rails.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    fail_error('method_not_allowed', 'POST required.', 405);
}
$raw = (string) file_get_contents('php://input');
if (!claim_rails_callback_authorised($raw)) {
    fail_error('invalid_signature', 'Invalid claim callback signature.', 403);
}
$data = json_decode($raw, true);
if (!is_array($data)) { fail_error('invalid_payload', 'Callback payload must be JSON.', 422); }

$kind = '';
$externalRef = '';
$success = false;
$providerRef = null;
$reason = '';
$status = '';
$amount = null;
$note = '';

if (isset($data['Result']) && is_array($data['Result'])) {
    /* M-Pesa B2C result callback. */
    $kind = 'payout';
    $result = $data['Result'];
    $externalRef = (string) ($result['OriginatorConversationID'] ?? $result['ConversationID'] ?? '');
    $providerRef = (string) ($result['TransactionID'] ?? '') ?: null;
    $success = (string) ($result['ResultCode'] ?? '1') === '0';
    $reason = (string) ($result['ResultDesc'] ?? '');
    $status = $success ? 'sent' : 'failed';
} else {
    $kind = strtolower((string) ($data['kind'] ?? ''));
    if ($kind === '') { $kind = str_starts_with((string) ($data['external_ref'] ?? ''), 'REC-') ? 'recovery' : 'payout'; }
    $externalRef = (string) ($data['external_ref'] ?? $data['provider_ref'] ?? $data['reference'] ?? '');
    $status = strtolower((string) ($data['status'] ?? $data['event'] ?? ''));
    $success = in_array($status, ['sent', 'success', 'completed', 'paid', 'recovered'], true);
    $providerRef = isset($data['provider_ref']) ? (string) $data['provider_ref'] : null;
    $reason = (string) ($data['reason'] ?? $data['description'] ?? '');
    $amount = isset($data['amount_kes']) && is_numeric($data['amount_kes']) ? (int) $data['amount_kes'] : null;
    $note = (string) ($data['note'] ?? '');
}
if ($externalRef === '') { fail_error('missing_reference', 'Callback needs an external reference.', 422); }
claim_rail_log_event($kind, $status !== '' ? $status : ($success ? 'success' : 'failed'), $externalRef, $raw);

if ($kind === 'recovery') {
    $result = claim_recovery_process_callback($externalRef, $status !== '' ? $status : ($success ? 'recovered' : 'rejected'), $amount, $providerRef, $note, $data);
} else {
    $result = claim_payout_process_callback($externalRef, $success, $providerRef, $reason, $data);
}
if (empty($result['ok'])) {
    fail_error('callback_failed', (string) ($result['error'] ?? 'Callback could not be processed.'), (int) ($result['code'] ?? 422));
}
if (isset($data['Result'])) {
    json_out(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
}
json_out(['ok' => true, 'source' => 'mysql', 'message' => $result['message'] ?? 'Callback processed.']);
