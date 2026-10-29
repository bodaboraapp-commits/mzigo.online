<?php
declare(strict_types=1);

require_once __DIR__ . '/_mpesa.php';
require_once __DIR__ . '/_notifications.php';
require_once __DIR__ . '/_webhooks.php';
require_once __DIR__ . '/_growth.php';

/** Normalise a Daraja STK callback/query result into a small array. */
function mpesa_callback_result(array $payload): array
{
    $body = $payload['Body']['stkCallback'] ?? $payload;
    $resultCode = isset($body['ResultCode']) ? (string) $body['ResultCode'] : '';
    $receipt = '';
    $amount = null;
    $phone = '';
    foreach (($body['CallbackMetadata']['Item'] ?? []) as $item) {
        if (!is_array($item)) continue;
        $name = (string) ($item['Name'] ?? '');
        if ($name === 'MpesaReceiptNumber') $receipt = (string) ($item['Value'] ?? '');
        if ($name === 'Amount') $amount = (int) ($item['Value'] ?? 0);
        if ($name === 'PhoneNumber') $phone = (string) ($item['Value'] ?? '');
    }
    return [
        'result_code' => $resultCode,
        'result_desc' => (string) ($body['ResultDesc'] ?? ''),
        'receipt' => $receipt,
        'amount' => $amount,
        'phone' => $phone,
        'raw' => $payload,
    ];
}

/**
 * Apply a successful/failed STK result to the payment and parcel atomically.
 * Safe to call repeatedly (callback + status polling).
 */
function mpesa_finalize_payment(array $payment, array $result): array
{
    if (($payment['status'] ?? '') === 'paid') {
        return ['ok' => true, 'status' => 'paid', 'already' => true, 'receipt' => $payment['mpesa_receipt'] ?? ''];
    }
    $pdo = db();
    $resultCode = (string) ($result['result_code'] ?? '');
    $success = $resultCode === '0';
    $callbackAmount = (int) ($result['amount'] ?? 0);
    if ($success && $callbackAmount > 0 && $callbackAmount < (int) ($payment['amount_kes'] ?? 0)) {
        $success = false;
        $result['result_code'] = 'amount_mismatch';
        $result['result_desc'] = 'M-Pesa amount was lower than the expected delivery fee.';
        $resultCode = 'amount_mismatch';
    }
    if (!$success) {
        $pdo->prepare('UPDATE mpesa_payments SET status = "failed", result_code = ?, result_desc = ?, raw_callback = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$resultCode, mb_substr((string) ($result['result_desc'] ?? ''), 0, 255),
                json_encode($result['raw'] ?? [], JSON_UNESCAPED_SLASHES), (int) $payment['id']]);
        if ((int) ($payment['credit_used_kes'] ?? 0) > 0 && !empty($payment['user_id'])) {
            growth_credit_add((int) $payment['user_id'], (int) $payment['credit_used_kes'], 'parcel_credit',
                'M-Pesa payment failed refund', (string) ($payment['checkout_request_id'] ?? ''));
        }
        return ['ok' => false, 'status' => 'failed', 'error' => (string) ($result['result_desc'] ?? 'Payment failed.')];
    }
    $parcelStmt = $pdo->prepare('SELECT * FROM parcels WHERE id = ? LIMIT 1');
    $parcelStmt->execute([(string) $payment['parcel_id']]);
    $parcel = $parcelStmt->fetch();
    if (!$parcel) {
        return ['ok' => false, 'status' => 'failed', 'error' => 'Parcel not found.'];
    }
    if (($parcel['payment_status'] ?? '') === 'paid') {
        $pdo->prepare('UPDATE mpesa_payments SET status = "paid", result_code = ?, result_desc = ?, mpesa_receipt = ?, raw_callback = ?, updated_at = NOW() WHERE id = ?')
            ->execute(['0', mb_substr((string) ($result['result_desc'] ?? ''), 0, 255),
                mb_substr((string) ($result['receipt'] ?? ''), 0, 40),
                json_encode($result['raw'] ?? [], JSON_UNESCAPED_SLASHES), (int) $payment['id']]);
        return ['ok' => true, 'status' => 'paid', 'already' => true, 'receipt' => (string) ($result['receipt'] ?? '')];
    }
    $receipt = trim((string) ($result['receipt'] ?? ''));
    $ref = $receipt !== '' ? $receipt : ('STK-' . strtoupper(substr(hash('sha256', (string) $payment['checkout_request_id']), 0, 12)));
    $amount = (int) ($payment['amount_kes'] ?? 0);
    $couponCode = (string) ($payment['coupon_code'] ?? '');
    $discountKes = (int) ($payment['discount_kes'] ?? 0);
    $creditUsed = (int) ($payment['credit_used_kes'] ?? 0);
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE parcels SET payment_status = "paid", paid_at = NOW(), payment_ref = ?, paid_by_user_id = ?, coupon_code = ?, discount_kes = ?, paid_amount_kes = ? WHERE id = ?')
            ->execute([$ref, $payment['user_id'] ? (int) $payment['user_id'] : null,
                $couponCode !== '' ? $couponCode : null, $discountKes + $creditUsed, $amount, $parcel['id']]);
        $transactionId = record_transaction([
            'reference' => $ref, 'tenant_id' => $parcel['tenant_id'], 'user_id' => $payment['user_id'] ? (int) $payment['user_id'] : null,
            'type' => 'parcel_payment', 'amount_kes' => $amount, 'method' => 'MPESA',
            'subject_type' => 'parcel', 'subject_id' => (string) $parcel['id'],
            'metadata' => ['tracking' => $parcel['tracking'], 'checkout_request_id' => $payment['checkout_request_id']],
        ]);
        $pdo->prepare('UPDATE mpesa_payments SET status = "paid", result_code = "0", result_desc = ?, mpesa_receipt = ?, transaction_id = ?, raw_callback = ?, updated_at = NOW() WHERE id = ?')
            ->execute([mb_substr((string) ($result['result_desc'] ?? ''), 0, 255), mb_substr($receipt, 0, 40),
                $transactionId, json_encode($result['raw'] ?? [], JSON_UNESCAPED_SLASHES), (int) $payment['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('M-Pesa payment finalize failed: ' . $e->getMessage());
        return ['ok' => false, 'status' => 'failed', 'error' => 'Could not record the payment.'];
    }
    if ($couponCode !== '') {
        try {
            $v = coupon_validate($couponCode, (int) $parcel['fee_total'], 'delivery', (int) $payment['user_id']);
            if ($v['ok']) {
                coupon_redeem($v['coupon'], (int) $parcel['fee_total'], (int) $v['discount_kes'], 'delivery', $ref, (int) $payment['user_id']);
            }
        } catch (Throwable $e) {
            error_log('M-Pesa coupon redemption failed: ' . $e->getMessage());
        }
    }
    if (!empty($payment['user_id'])) {
        growth_qualify_referral((int) $payment['user_id'], 'customer');
    }
    try {
        $fresh = $pdo->prepare('SELECT * FROM parcels WHERE id = ? LIMIT 1');
        $fresh->execute([$parcel['id']]);
        notify_customer_payment($fresh->fetch(), $ref);
    } catch (Throwable $e) {
        error_log('M-Pesa receipt notification failed: ' . $e->getMessage());
    }
    if ($parcel['tenant_id'] !== null) {
        try {
            dispatch_webhooks((int) $parcel['tenant_id'], 'customer.parcel_paid', [
                'parcel' => $parcel['tracking'], 'reference' => $ref, 'discount_kes' => $discountKes + $creditUsed,
            ]);
        } catch (Throwable $e) {
            error_log('M-Pesa webhook failed: ' . $e->getMessage());
        }
    }
    audit('customer.parcel_paid', 'parcel', (string) $parcel['id'], [
        'tracking' => $parcel['tracking'], 'amount_kes' => $amount, 'reference' => $ref, 'method' => 'MPESA',
    ], $parcel['tenant_id'] !== null ? (int) $parcel['tenant_id'] : null);
    return ['ok' => true, 'status' => 'paid', 'receipt' => $receipt, 'reference' => $ref];
}

/** Find and refresh one pending payment using Daraja's STK query endpoint. */
function mpesa_refresh_payment(array $payment): array
{
    if (($payment['status'] ?? '') !== 'pending') {
        return ['ok' => true, 'status' => $payment['status'], 'payment' => $payment];
    }
    $query = mpesa_stk_query(mpesa_payment_config(), (string) $payment['checkout_request_id']);
    if (!$query['ok']) {
        return ['ok' => true, 'status' => 'pending', 'message' => 'Waiting for M-Pesa confirmation.'];
    }
    $body = $query['response'];
    $resultCode = (string) ($body['ResultCode'] ?? '');
    if ($resultCode === '') {
        return ['ok' => true, 'status' => 'pending', 'message' => 'Waiting for M-Pesa confirmation.'];
    }
    $result = [
        'result_code' => $resultCode,
        'result_desc' => (string) ($body['ResultDesc'] ?? ''),
        'receipt' => (string) ($body['MpesaReceiptNumber'] ?? ''),
        'amount' => (int) ($payment['amount_kes'] ?? 0),
        'phone' => (string) ($payment['phone'] ?? ''),
        'raw' => ['query' => $body],
    ];
    $final = mpesa_finalize_payment($payment, $result);
    return ['ok' => !empty($final['ok']), 'status' => $final['status'] ?? 'failed', 'message' => $final['error'] ?? '', 'result' => $final];
}
