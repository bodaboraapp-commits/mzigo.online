<?php
/**
 * Mzigo.online — customer portal API.
 *
 * A customer is the receiver of a parcel. Sign-in is phone + a 4-digit code
 * (demo code 1234). The portal lists parcels addressed to the customer's phone,
 * pays the delivery fee at collection (demo M-Pesa) and reveals the collection
 * code only after payment.
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_notifications.php';
require __DIR__ . '/_webhooks.php';
require __DIR__ . '/_claims.php';
require_once __DIR__ . '/_verification.php';
require_once __DIR__ . '/_growth.php';
require_once __DIR__ . '/_notification_prefs.php';
require_once __DIR__ . '/_mpesa_payments.php';

$user = require_customer();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$key = phone_key((string) ($user['phone'] ?? ''));
if ($key === '') {
    fail_error('no_phone', 'Your customer account has no phone number.', 422);
}

function customer_parcel_view(array $row): array
{
    $parcel = parcel_api($row);
    $paid = ($parcel['payment_status'] ?? '') === 'paid';
    $parcel['amount_due'] = $paid ? 0 : (int) $parcel['fee']['total'];
    $parcel['can_pay'] = !$paid && ($parcel['status'] ?? '') !== 'COLLECTED_PAID';
    if (!$paid) {
        // Never expose the collection code before the delivery fee is paid.
        $parcel['collect_code'] = null;
    }
    return $parcel;
}

function customer_claims(int $userId, string $phoneKey): array
{
    $stmt = db()->prepare(
        'SELECT c.id, c.ref, c.type, c.claimant_role, c.description, c.evidence, c.estimated_value_kes,
                c.status, c.risk_score, c.risk_flags, c.resolution_note, c.payout_ref, c.payout_hold_until,
                c.paid_at, c.created_at, c.decided_at, c.sla_due_at, c.recovery_status,
                p.tracking, p.status AS parcel_status, p.carrier
         FROM claims c LEFT JOIN parcels p ON p.id = c.parcel_id
         WHERE c.user_id = :uid
         ORDER BY c.id DESC LIMIT 50'
    );
    $stmt->execute([':uid' => $userId]);
    return $stmt->fetchAll();
}

if ($method === 'GET') {
    $stmt = db()->prepare('SELECT * FROM parcels WHERE receiver_phone_key = :key ORDER BY registered_at DESC LIMIT 100');
    $stmt->execute([':key' => $key]);
    $parcels = array_map('customer_parcel_view', $stmt->fetchAll());

    $paidCount = 0;
    $totalPaid = 0;
    foreach ($parcels as $parcel) {
        if (($parcel['payment_status'] ?? '') === 'paid') {
            $paidCount++;
            $totalPaid += (int) $parcel['fee']['total'];
        }
    }
    $prefStmt = db()->prepare('SELECT comm_channel, email, email_verified_at FROM users WHERE id = ? LIMIT 1');
    $prefStmt->execute([(int) $user['id']]);
    $accountRow = $prefStmt->fetch() ?: [];
    $pref = (string) ($accountRow['comm_channel'] ?? '') ?: 'all';
    $waPhone = function_exists('wa_out_phone') ? wa_out_phone((string) ($user['phone'] ?? '')) : '';
    $waStmt = db()->prepare('SELECT consent_at, opted_out FROM wa_contacts WHERE phone = ? OR wa_id = ? LIMIT 1');
    $waStmt->execute([$waPhone, $waPhone]);
    $waContact = $waStmt->fetch() ?: [];
    $whatsappOptin = !empty($waContact['consent_at']) && (int) ($waContact['opted_out'] ?? 0) === 0;
    $notes = db()->prepare('SELECT id, channel, subject, body, template, status, recipient, created_at, read_at
        FROM notifications WHERE recipient_user_id = ? ORDER BY id DESC LIMIT 10');
    $notes->execute([(int) $user['id']]);
    json_out([
        'ok'       => true,
        'source'   => 'mysql',
        'customer' => ['id' => (int) $user['id'], 'name' => $user['name'], 'phone' => $user['phone'],
            'email' => $accountRow['email'] ?? null, 'email_verified' => !empty($accountRow['email_verified_at'])],
        'channels' => notification_channels(true),
        'payment' => mpesa_payment_public_config(),
        'preference' => $pref,
        'email_preferences' => notification_preferences((int) $user['id']),
        'whatsapp_optin' => $whatsappOptin,
        'notifications' => $notes->fetchAll(),
        'summary'  => [
            'parcels' => count($parcels),
            'paid' => $paidCount,
            'unpaid' => count($parcels) - $paidCount,
            'paid_total_kes' => $totalPaid,
        ],
        'parcels' => $parcels,
        'claims' => customer_claims((int) $user['id'], $key),
        'claim_policy' => [
            'otp_ttl_minutes' => CLAIM_OTP_TTL_MINUTES,
            'max_active_claims' => CLAIM_MAX_ACTIVE_PER_TENANT,
            'receiver_claim_limit_30d' => CLAIM_MAX_PER_RECEIVER_30D,
            'evidence_required' => ['lost', 'damaged'],
            'sla_days' => CLAIM_SLA_DAYS,
        ],
    ]);
}

require_post();
verify_csrf();
$data = body_json();
if (($data['action'] ?? '') === 'update_whatsapp_optin') {
    $optIn = !empty($data['opt_in']);
    $phone = wa_out_phone((string) ($user['phone'] ?? ''));
    if ($phone === '') fail('No phone number on this account.', 422);
    $stmt = db()->prepare('SELECT id FROM wa_contacts WHERE phone = ? OR wa_id = ? LIMIT 1');
    $stmt->execute([$phone, $phone]);
    $contactId = (int) $stmt->fetchColumn();
    if ($contactId) {
        db()->prepare('UPDATE wa_contacts SET opted_out = ?, consent_at = CASE WHEN ? = 1 THEN NOW() ELSE consent_at END WHERE id = ?')
            ->execute([$optIn ? 0 : 1, $optIn ? 1 : 0, $contactId]);
    } else {
        db()->prepare('INSERT INTO wa_contacts (phone, wa_id, name, consent_at, opted_out, created_at) VALUES (?, ?, ?, ?, ?, NOW())')
            ->execute([$phone, $phone, (string) ($user['name'] ?? 'Customer'), $optIn ? date('Y-m-d H:i:s') : null, $optIn ? 0 : 1]);
    }
    audit('customer.whatsapp_optin', 'user', (string) $user['id'], ['opt_in' => $optIn]);
    json_out(['ok' => true, 'whatsapp_optin' => $optIn]);
}

if (($data['action'] ?? '') === 'update_preferences') {
    $channel = (string) ($data['channel'] ?? 'all');
    if ($channel !== 'all') {
        $codes = array_column(notification_channels(true), 'code');
        if (!in_array($channel, $codes, true)) {
            fail_error('invalid_channel', 'That communication channel is not available.', 422);
        }
    }
    db()->prepare('UPDATE users SET comm_channel = ? WHERE id = ?')->execute([$channel === 'all' ? null : $channel, (int) $user['id']]);
    audit('customer.communication_preference', 'user', (string) $user['id'], ['channel' => $channel]);
    json_out(['ok' => true, 'source' => 'mysql', 'preference' => $channel]);
}

if (($data['action'] ?? '') === 'update_email') {
    $email = trim((string) ($data['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail_error('invalid_email', 'Enter a valid email address.', 422);
    }
    $email = mb_strtolower($email);
    $dup = db()->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
    $dup->execute([$email, (int) $user['id']]);
    if ($dup->fetchColumn()) {
        fail_error('email_taken', 'That email address is already in use.', 409);
    }
    $same = strcasecmp($email, (string) ($user['email'] ?? '')) === 0;
    if ($same && !empty($user['email_verified_at'])) {
        json_out(['ok' => true, 'source' => 'mysql', 'email' => $email, 'already_verified' => true,
            'message' => 'That email is already verified.']);
    }
    db()->prepare('UPDATE users SET email = ?, email_verified_at = NULL WHERE id = ?')->execute([$email, (int) $user['id']]);
    audit('customer.email_updated', 'user', (string) $user['id'], ['email' => $email]);
    $issued = email_verification_issue((int) $user['id'], $email);
    json_out(['ok' => true, 'source' => 'mysql', 'email' => $email,
        'email_verified' => false, 'verification' => $issued,
        'message' => $issued['sent'] ? 'Email saved. Check your inbox to verify it.' : 'Email saved. Verification email could not be sent.']);
}

if (($data['action'] ?? '') === 'update_notification_preferences') {
    $prefs = [
        'email_enabled' => !empty($data['email_enabled']) ? 1 : 0,
        'parcel_updates' => !empty($data['parcel_updates']) ? 1 : 0,
        'payment_receipts' => !empty($data['payment_receipts']) ? 1 : 0,
        'claim_updates' => !empty($data['claim_updates']) ? 1 : 0,
        'marketing' => !empty($data['marketing']) ? 1 : 0,
    ];
    db()->prepare('INSERT INTO user_notification_preferences (user_id, email_enabled, parcel_updates, payment_receipts, claim_updates, marketing)
        VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE email_enabled = VALUES(email_enabled),
        parcel_updates = VALUES(parcel_updates), payment_receipts = VALUES(payment_receipts),
        claim_updates = VALUES(claim_updates), marketing = VALUES(marketing)')
        ->execute([(int) $user['id'], $prefs['email_enabled'], $prefs['parcel_updates'], $prefs['payment_receipts'],
            $prefs['claim_updates'], $prefs['marketing']]);
    audit('customer.notification_preferences', 'user', (string) $user['id'], $prefs);
    json_out(['ok' => true, 'source' => 'mysql', 'email_preferences' => notification_preferences((int) $user['id']),
        'message' => 'Notification preferences saved.']);
}

$action = (string) ($data['action'] ?? '');
if ($action === 'request_claim_otp') {
    $tracking = param($data, 'tracking', 3, 40, 'tracking');
    $type = (string) ($data['type'] ?? 'damaged');
    if (!in_array($type, ['lost', 'damaged', 'delayed'], true)) {
        fail_error('invalid_type', 'Choose lost, damaged or delayed.', 422);
    }
    $stmt = db()->prepare('SELECT * FROM parcels WHERE tracking = :tracking AND receiver_phone_key = :key LIMIT 1');
    $stmt->execute([':tracking' => $tracking, ':key' => $key]);
    $parcel = $stmt->fetch();
    if (!$parcel) {
        fail_error('not_found', 'No parcel for your account matches that tracking number.', 404);
    }
    if (($eligibleError = claim_eligibility($parcel, $type)) !== null) {
        fail_error('claim_not_eligible', $eligibleError, 422);
    }
    $blacklist = claim_blacklist_match((string) $parcel['receiver_phone_key'], (int) $user['id'], (int) $parcel['tenant_id']);
    if ($blacklist) {
        fail_error('claim_blacklisted', 'Claim reporting is blocked for this phone or parcel. Contact support.', 403);
    }
    rate_limit('claim_otp:' . $user['id'] . ':' . $parcel['id'], 3, 3600);
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    db()->prepare('UPDATE claim_otps SET consumed_at = NOW() WHERE parcel_id = ? AND user_id = ? AND consumed_at IS NULL')
        ->execute([$parcel['id'], (int) $user['id']]);
    db()->prepare('INSERT INTO claim_otps (parcel_id, user_id, phone_key, code_hash, expires_at)
        VALUES (?, ?, ?, ?, ?)')
        ->execute([$parcel['id'], (int) $user['id'], $key, password_hash($code, PASSWORD_DEFAULT), date('Y-m-d H:i:s', time() + (CLAIM_OTP_TTL_MINUTES * 60))]);
    $demo = config()['app']['demo_mode'] === true;
    $delivered = false;
    foreach (notification_targets($user) as $channel) {
        $result = notification_send($channel['code'], $user, 'Your Mzigo verification code',
            'Your Mzigo claim verification code for ' . $tracking . ' is ' . $code . '. It expires in ' . CLAIM_OTP_TTL_MINUTES . ' minutes.',
            'claim_otp', $parcel, (string) ($channel['provider'] ?? 'log'), 'parcel', (string) $parcel['id'],
            ['code' => $code, 'tracking' => $tracking]);
        if (($result['status'] ?? '') === 'sent') $delivered = true;
    }
    audit('customer.claim_otp_requested', 'parcel', (string) $parcel['id'], ['tracking' => $tracking, 'type' => $type, 'delivered' => $delivered]);
    if (!$demo && !$delivered) {
        fail_error('otp_delivery_failed', 'We could not send the verification code. Try again or contact support.', 502);
    }
    json_out(['ok' => true, 'source' => 'mysql', 'sent' => true, 'expires_minutes' => CLAIM_OTP_TTL_MINUTES,
        'demo_otp' => $demo ? $code : null,
        'message' => $demo ? 'Demo verification code: ' . $code : 'Verification code sent to your phone.']);
}

if ($action === 'file_claim') {
    $tracking = param($data, 'tracking', 3, 40, 'tracking');
    $type = (string) ($data['type'] ?? 'damaged');
    if (!in_array($type, ['lost', 'damaged', 'delayed'], true)) {
        fail_error('invalid_type', 'Choose lost, damaged or delayed.', 422);
    }
    $otp = trim((string) ($data['otp'] ?? ''));
    if (!preg_match('/^\d{4,8}$/', $otp)) {
        fail_error('invalid_otp', 'Enter the verification code sent to your phone.', 422);
    }
    $description = param($data, 'description', 10, 1000, 'Description');
    $evidence = isset($data['evidence']) && is_scalar($data['evidence']) ? trim((string) $data['evidence']) : '';
    if (in_array($type, ['lost', 'damaged'], true) && mb_strlen($evidence) < 10) {
        fail_error('evidence_required', 'Evidence is required for loss or damage claims (photo/document references, at least 10 characters).', 422);
    }
    if (mb_strlen($evidence) > 1000) {
        $evidence = mb_substr($evidence, 0, 1000);
    }
    $stmt = db()->prepare('SELECT * FROM parcels WHERE tracking = :tracking AND receiver_phone_key = :key LIMIT 1');
    $stmt->execute([':tracking' => $tracking, ':key' => $key]);
    $parcel = $stmt->fetch();
    if (!$parcel) {
        fail_error('not_found', 'No parcel for your account matches that tracking number.', 404);
    }
    if (($eligibleError = claim_eligibility($parcel, $type)) !== null) {
        fail_error('claim_not_eligible', $eligibleError, 422);
    }
    $blacklist = claim_blacklist_match((string) $parcel['receiver_phone_key'], (int) $user['id'], (int) $parcel['tenant_id']);
    if ($blacklist) {
        fail_error('claim_blacklisted', 'Claim reporting is blocked for this phone or parcel. Contact support.', 403);
    }
    $otpStmt = db()->prepare('SELECT * FROM claim_otps WHERE parcel_id = ? AND user_id = ? AND consumed_at IS NULL ORDER BY id DESC LIMIT 1');
    $otpStmt->execute([$parcel['id'], (int) $user['id']]);
    $otpRow = $otpStmt->fetch();
    if (!$otpRow) {
        fail_error('otp_required', 'Request a verification code before filing this claim.', 422);
    }
    if ((int) $otpRow['attempts'] >= CLAIM_OTP_MAX_ATTEMPTS || strtotime((string) $otpRow['expires_at']) < time()) {
        fail_error('otp_expired', 'Your verification code has expired. Request a new code.', 422);
    }
    db()->prepare('UPDATE claim_otps SET attempts = attempts + 1 WHERE id = ?')->execute([$otpRow['id']]);
    if (!password_verify($otp, (string) $otpRow['code_hash'])) {
        fail_error('otp_invalid', 'The verification code is incorrect.', 422);
    }
    db()->prepare('UPDATE claim_otps SET consumed_at = NOW() WHERE id = ?')->execute([$otpRow['id']]);

    $cap = claim_cap_for($parcel);
    $estimated = isset($data['estimated_value_kes']) && $data['estimated_value_kes'] !== '' ? (int) $data['estimated_value_kes'] : null;
    if ($estimated !== null) {
        if ($estimated < 0) { fail_error('invalid_value', 'Estimated value cannot be negative.', 422); }
        if ($estimated > $cap) { fail_error('claim_cap_exceeded', 'The maximum claim for this parcel is KES ' . number_format($cap) . '.', 422); }
    }
    $dup = db()->prepare('SELECT 1 FROM claims WHERE parcel_id = ? AND status IN ("open","under_review","approved") LIMIT 1');
    $dup->execute([$parcel['id']]);
    if ($dup->fetchColumn()) {
        fail_error('claim_exists', 'An active claim already exists for this parcel.', 409);
    }
    $recent = db()->prepare('SELECT COUNT(*) FROM claims WHERE parcel_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)');
    $recent->execute([$parcel['id']]);
    if ((int) $recent->fetchColumn() >= 2) {
        fail_error('claim_limit_parcel', 'This parcel has already had too many claims in the last 30 days.', 422);
    }
    $receiver = db()->prepare(
        'SELECT COUNT(*) FROM claims c JOIN parcels p ON p.id = c.parcel_id
         WHERE p.receiver_phone_key = ? AND c.created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)'
    );
    $receiver->execute([(string) $parcel['receiver_phone_key']]);
    if ((int) $receiver->fetchColumn() >= CLAIM_MAX_PER_RECEIVER_30D) {
        fail_error('claim_limit_receiver', 'Too many claims for this receiver phone in the last 30 days.', 422);
    }
    $risk = claim_risk_assessment($parcel, $type, 'customer', $estimated, (int) $parcel['tenant_id'], $evidence);
    $ref = 'CLM-' . date('ymd') . '-' . strtoupper(random_code(4));
    $ins = db()->prepare('INSERT INTO claims (ref, tenant_id, parcel_id, user_id, type, claimant_role, risk_score, risk_flags,
        description, evidence, estimated_value_kes) VALUES (?, ?, ?, ?, ?, "customer", ?, ?, ?, ?, ?)');
    $ins->execute([$ref, (int) $parcel['tenant_id'], $parcel['id'], (int) $user['id'], $type,
        $risk['score'], json_encode($risk['flags']), $description, $evidence !== '' ? $evidence : null, $estimated]);
    $claimId = (int) db()->lastInsertId();
    db()->prepare('UPDATE claims SET sla_due_at = DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id = ?')->execute([CLAIM_SLA_DAYS, $claimId]);
    claim_event($claimId, 'created', $description, (int) $user['id']);
    claim_event($claimId, 'customer_reported', 'Reported by receiver via customer portal', (int) $user['id']);
    $claimRow = ['id' => $claimId, 'ref' => $ref, 'type' => $type, 'description' => $description,
        'tenant_id' => (int) $parcel['tenant_id'], 'user_id' => (int) $user['id'], 'status' => 'open'];
    notify_claim_reported($claimRow, $parcel, (int) $parcel['tenant_id']);
    if (!empty($parcel['tenant_id'])) {
        dispatch_webhooks((int) $parcel['tenant_id'], 'claim.created', ['claim' => $ref, 'parcel' => $parcel['tracking'], 'type' => $type, 'reported_by' => 'customer']);
    }
    audit('customer.claim_created', 'claim', (string) $claimId, ['ref' => $ref, 'parcel' => $parcel['tracking'], 'type' => $type, 'risk' => $risk['score']], (int) $parcel['tenant_id']);
    json_out(['ok' => true, 'source' => 'mysql', 'claim_id' => $claimId, 'ref' => $ref,
        'claims' => customer_claims((int) $user['id'], $key),
        'message' => 'Claim ' . $ref . ' submitted to the seller for review.']);
}

if ($action === 'payment_status') {
    $tracking = param($data, 'tracking', 3, 40, 'tracking');
    $stmt = db()->prepare('SELECT * FROM parcels WHERE tracking = :tracking AND receiver_phone_key = :key LIMIT 1');
    $stmt->execute([':tracking' => $tracking, ':key' => $key]);
    $row = $stmt->fetch();
    if (!$row) { fail_error('not_found', 'No parcel for your account matches that tracking number.', 404); }
    $payStmt = db()->prepare('SELECT * FROM mpesa_payments WHERE parcel_id = ? ORDER BY id DESC LIMIT 1');
    $payStmt->execute([$row['id']]);
    $payment = $payStmt->fetch();
    if ($payment && ($payment['status'] ?? '') === 'pending') {
        $refresh = mpesa_refresh_payment($payment);
        $payStmt->execute([$row['id']]);
        $payment = $payStmt->fetch();
    }
    $fresh = db()->prepare('SELECT * FROM parcels WHERE id = ? LIMIT 1');
    $fresh->execute([$row['id']]);
    json_out([
        'ok' => true,
        'status' => $payment['status'] ?? (($row['payment_status'] ?? '') === 'paid' ? 'paid' : 'not_started'),
        'receipt' => (string) ($payment['mpesa_receipt'] ?? ''),
        'message' => $payment ? (string) ($payment['result_desc'] ?? '') : '',
        'parcel' => customer_parcel_view($fresh->fetch()),
    ]);
}

if ($action === 'resend_receipt') {
    $tracking = param($data, 'tracking', 3, 40, 'tracking');
    $stmt = db()->prepare('SELECT * FROM parcels WHERE tracking = :tracking AND receiver_phone_key = :key LIMIT 1');
    $stmt->execute([':tracking' => $tracking, ':key' => $key]);
    $row = $stmt->fetch();
    if (!$row) { fail_error('not_found', 'No parcel for your account matches that tracking number.', 404); }
    if (($row['payment_status'] ?? '') !== 'paid') { fail_error('not_paid', 'The receipt is available after payment.', 422); }
    $sent = notify_customer_payment($row, (string) ($row['payment_ref'] ?? ''));
    audit('customer.receipt_resent', 'parcel', (string) $row['id'], ['tracking' => $tracking]);
    json_out(['ok' => true, 'sent' => $sent, 'message' => 'Receipt and collection code sent to your registered contact details.']);
}

if ($action === 'rate_parcel') {
    $tracking = (string) ($data['tracking'] ?? '');
    $rating = (int) ($data['rating'] ?? 0);
    $comment = isset($data['comment']) && is_scalar($data['comment']) ? mb_substr(trim((string) $data['comment']), 0, 500) : '';
    if ($tracking === '' || $rating < 1 || $rating > 5) {
        fail('Choose 1–5 stars for the delivery.', 422, ['rating' => 'Required.']);
    }
    $stmt = db()->prepare('SELECT id, status, payment_status, csat_rating FROM parcels WHERE tracking = ? AND receiver_phone_key = ? LIMIT 1');
    $stmt->execute([$tracking, $key]);
    $parcel = $stmt->fetch();
    if (!$parcel) fail_error('not_found', 'Parcel not found on this account.', 404);
    if (($parcel['payment_status'] ?? '') !== 'paid' && ($parcel['status'] ?? '') !== 'COLLECTED_PAID') {
        fail('You can rate a delivery after the delivery fee is paid or the parcel is collected.', 422);
    }
    db()->prepare('UPDATE parcels SET csat_rating = ?, csat_comment = ?, csat_at = NOW() WHERE id = ?')->execute([$rating, $comment, $parcel['id']]);
    audit('customer.parcel_rated', 'parcel', (string) $parcel['id'], ['rating' => $rating]);
    json_out(['ok' => true, 'message' => 'Thanks — your delivery rating was saved.']);
}

if ($action !== 'pay') {
    fail_error('unknown_action', 'Unknown action.', 422);
}
$tracking = param($data, 'tracking', 3, 40, 'tracking');
$stmt = db()->prepare('SELECT * FROM parcels WHERE tracking = :tracking AND receiver_phone_key = :key LIMIT 1');
$stmt->execute([':tracking' => $tracking, ':key' => $key]);
$row = $stmt->fetch();
if (!$row) {
    fail_error('not_found', 'No parcel for your account matches that tracking number.', 404);
}
if (($row['payment_status'] ?? '') === 'paid') {
    fail_error('already_paid', 'This parcel is already paid.', 409);
}
$couponCode = isset($data['coupon_code']) && is_scalar($data['coupon_code']) ? trim((string) $data['coupon_code']) : '';
$coupon = null;
$discountKes = 0;
$payableKes = (int) $row['fee_total'];
if ($couponCode !== '') {
    $couponResult = coupon_validate($couponCode, (int) $row['fee_total'], 'delivery', (int) $user['id']);
    if (!$couponResult['ok']) {
        fail($couponResult['error'], 422, ['coupon_code' => $couponResult['error']]);
    }
    $coupon = $couponResult['coupon'];
    $discountKes = (int) $couponResult['discount_kes'];
    $payableKes = (int) $couponResult['final_kes'];
}
$mpesaCfg = mpesa_payment_config();
if (($mpesaCfg['mode'] ?? 'demo') === 'live') {
    $pendingStmt = db()->prepare('SELECT * FROM mpesa_payments WHERE parcel_id = ? AND status = "pending" ORDER BY id DESC LIMIT 1');
    $pendingStmt->execute([$row['id']]);
    $pendingPayment = $pendingStmt->fetch();
    if ($pendingPayment) {
        json_out([
            'ok' => true, 'pending' => true,
            'checkout_request_id' => $pendingPayment['checkout_request_id'],
            'message' => 'An M-Pesa request is already waiting on your phone. Enter your PIN or wait for confirmation.',
        ]);
    }
}
$ref = 'DEMO-MPESA-' . strtoupper(bin2hex(random_bytes(3)));
$creditUsed = growth_credit_use((int) $user['id'], 'parcel_credit', $payableKes, $ref);
if ($creditUsed > 0) { $payableKes = max(0, $payableKes - $creditUsed); }
if (($mpesaCfg['mode'] ?? 'demo') === 'live' && !mpesa_stk_ready($mpesaCfg)) {
    if ($creditUsed > 0) {
        growth_credit_add((int) $user['id'], $creditUsed, 'parcel_credit', 'M-Pesa configuration failed', $ref);
    }
    fail_error('mpesa_not_configured', 'M-Pesa payments are not configured. Contact support.', 422);
}
if (($mpesaCfg['mode'] ?? 'demo') === 'live' && $payableKes > 0) {
    $chargeKes = mpesa_stk_charge_amount($mpesaCfg, $payableKes);
    $stk = mpesa_stk_push($mpesaCfg, (string) ($user['phone'] ?? ''), $chargeKes,
        (string) $row['tracking'], 'Mzigo delivery fee ' . $row['tracking']);
    if (!$stk['ok']) {
        if ($creditUsed > 0) {
            growth_credit_add((int) $user['id'], $creditUsed, 'parcel_credit', 'M-Pesa request failed refund', $ref);
        }
        error_log('M-Pesa STK failed for ' . $row['tracking'] . ': ' . (string) ($stk['error'] ?? 'unknown'));
        fail_error('mpesa_stk_failed', $stk['error'] ?? 'Could not start the M-Pesa payment.', 422);
    }
    db()->prepare('INSERT INTO mpesa_payments (parcel_id, user_id, checkout_request_id, merchant_request_id, phone, amount_kes, account_ref, status, coupon_id, coupon_code, discount_kes, credit_used_kes, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, "pending", ?, ?, ?, ?, NOW())')
        ->execute([$row['id'], (int) $user['id'], $stk['checkout_request_id'], $stk['merchant_request_id'] ?? '',
            (string) ($user['phone'] ?? ''), $chargeKes, (string) $row['tracking'],
            $coupon['id'] ?? null, $coupon['code'] ?? null, $discountKes, $creditUsed]);
    audit('customer.mpesa_stk_requested', 'parcel', (string) $row['id'], [
        'tracking' => $row['tracking'], 'amount_kes' => $chargeKes, 'payable_kes' => $payableKes, 'checkout_request_id' => $stk['checkout_request_id'],
    ], $row['tenant_id'] ? (int) $row['tenant_id'] : null);
    json_out([
        'ok' => true, 'pending' => true,
        'checkout_request_id' => $stk['checkout_request_id'],
        'message' => $stk['customer_message'] ?: 'Check your phone and enter your M-Pesa PIN.',
    ]);
}
$pdo = db();
$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE parcels SET payment_status = "paid", paid_at = NOW(), payment_ref = ?, paid_by_user_id = ?, coupon_code = ?, discount_kes = ?, paid_amount_kes = ? WHERE id = ?')
        ->execute([$ref, (int) $user['id'], $coupon['code'] ?? null, $discountKes + $creditUsed, $payableKes, $row['id']]);
    record_transaction([
        'reference' => $ref, 'tenant_id' => $row['tenant_id'], 'user_id' => (int) $user['id'],
        'type' => 'parcel_payment', 'amount_kes' => $payableKes, 'method' => 'DEMO_MPESA',
        'subject_type' => 'parcel', 'subject_id' => (string) $row['id'],
        'metadata' => ['tracking' => $row['tracking']],
    ]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('customer payment failed: ' . $e->getMessage());
    fail_error('payment_failed', 'Could not record the payment. Please try again.', 500);
}
if ($coupon) {
    coupon_redeem($coupon, (int) $row['fee_total'], $discountKes, 'delivery', $ref, (int) $user['id']);
}
growth_qualify_referral((int) $user['id'], 'customer');
if (!empty($row['tenant_id'])) {
    dispatch_webhooks((int) $row['tenant_id'], 'customer.parcel_paid', ['parcel' => $row['tracking'], 'reference' => $ref, 'discount_kes' => $discountKes]);
}
audit('customer.parcel_paid', 'parcel', (string) $row['id'], [
    'tracking' => $row['tracking'], 'amount_kes' => (int) $row['fee_total'], 'reference' => $ref,
], $row['tenant_id'] ? (int) $row['tenant_id'] : null);

$fresh = db()->prepare('SELECT * FROM parcels WHERE id = ? LIMIT 1');
$fresh->execute([$row['id']]);
$freshRow = $fresh->fetch();
notify_customer_payment($freshRow, $ref);
json_out([
    'ok'        => true,
    'source'    => 'mysql',
    'paid'      => true,
    'reference' => $ref,
    'parcel'    => customer_parcel_view($freshRow),
]);
