<?php
/**
 * POST /api/mpesa-callback.php — Safaricom Daraja STK callback.
 * The response must always be a Daraja-compatible acknowledgement.
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_mpesa_payments.php';

$raw = (string) file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    json_out(['ResultCode' => 1, 'ResultDesc' => 'Invalid callback payload']);
}
$callback = $payload['Body']['stkCallback'] ?? [];
$checkoutId = trim((string) ($callback['CheckoutRequestID'] ?? ''));
if ($checkoutId === '') {
    json_out(['ResultCode' => 0, 'ResultDesc' => 'Accepted without checkout request ID']);
}
try {
    $stmt = db()->prepare('SELECT * FROM mpesa_payments WHERE checkout_request_id = ? LIMIT 1');
    $stmt->execute([$checkoutId]);
    $payment = $stmt->fetch();
    if ($payment) {
        mpesa_finalize_payment($payment, mpesa_callback_result($payload));
    }
} catch (Throwable $e) {
    error_log('M-Pesa callback failed: ' . $e->getMessage());
}
// Always acknowledge so Safaricom does not retry endlessly; failures are
// recoverable through the payment-status/query path.
json_out(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
