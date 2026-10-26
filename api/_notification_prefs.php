<?php
/** Customer email/notification preferences. */
declare(strict_types=1);
if (function_exists('notification_preferences')) { return; }

function notification_preferences(int $userId): array
{
    $defaults = [
        'email_enabled' => true,
        'parcel_updates' => true,
        'payment_receipts' => true,
        'claim_updates' => true,
        'marketing' => false,
    ];
    try {
        $stmt = db()->prepare('SELECT email_enabled, parcel_updates, payment_receipts, claim_updates, marketing
            FROM user_notification_preferences WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row) { return $defaults; }
        foreach ($defaults as $key => $value) {
            $defaults[$key] = (int) ($row[$key] ?? 0) === 1;
        }
        return $defaults;
    } catch (Throwable $e) {
        return $defaults;
    }
}

function notification_preference_category(string $template): string
{
    if (str_starts_with($template, 'claim_')) { return 'claim_updates'; }
    if (in_array($template, ['payment_receipt', 'customer_parcel_paid', 'invoice_paid'], true)) { return 'payment_receipts'; }
    if (in_array($template, ['parcel_created', 'parcel_created_new_customer', 'parcel_status', 'parcel_ready',
        'parcel_cancelled', 'parcel_returned'], true)) { return 'parcel_updates'; }
    if (str_starts_with($template, 'marketing') || $template === 'newsletter') { return 'marketing'; }
    return 'account';
}

function notification_preference_allows(int $userId, string $template): bool
{
    if ($userId <= 0) { return true; }
    $category = notification_preference_category($template);
    if ($category === 'account') { return true; }
    $prefs = notification_preferences($userId);
    if (!$prefs['email_enabled']) { return false; }
    return !empty($prefs[$category]);
}
