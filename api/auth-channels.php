<?php
/**
 * GET /api/auth-channels.php
 *
 * Public list of OTP delivery channels the login page may offer. The admin
 * channel Active checkbox controls this list: disabling SMS removes the SMS
 * option from the runner/customer sign-in dropdowns.
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$rows = db()->query(
    "SELECT code FROM communication_channels
     WHERE is_active = 1 AND code IN ('sms', 'email')
     ORDER BY FIELD(code, 'sms', 'email')"
)->fetchAll();

$channels = [];
foreach ($rows as $row) {
    $code = (string) $row['code'];
    if ($code === 'sms') {
        $channels[] = ['code' => 'sms', 'label' => 'SMS (phone number)'];
    } elseif ($code === 'email') {
        $channels[] = ['code' => 'email', 'label' => 'Email (address on my account)'];
    }
}
if (!$channels) {
    // Keep the login usable even before channel setup; the server falls back
    // to configured mail/SMS defaults.
    $channels[] = ['code' => 'email', 'label' => 'Email (address on my account)'];
}

header('Cache-Control: no-store');
json_out(['ok' => true, 'channels' => $channels]);
