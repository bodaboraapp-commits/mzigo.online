<?php
/** Customer-journey notification template catalogue used by the admin Communications view. */
declare(strict_types=1);

function comms_template_catalog(): array
{
    return [
        'email_verification' => ['stage' => 'Account', 'label' => 'Welcome / verify email', 'recipient' => 'Admin / merchant', 'trigger' => 'Signup, resend verification'],
        'password_reset' => ['stage' => 'Account', 'label' => 'Password reset link', 'recipient' => 'Admin / merchant', 'trigger' => 'Forgot password'],
        'password_changed' => ['stage' => 'Account', 'label' => 'Password changed confirmation', 'recipient' => 'Admin / merchant', 'trigger' => 'Password reset completed'],
        'email_verified' => ['stage' => 'Account', 'label' => 'Email verified confirmation', 'recipient' => 'Admin / merchant', 'trigger' => 'Email verification completed'],
        'parcel_created' => ['stage' => 'Parcel', 'label' => 'Parcel registered', 'recipient' => 'Customer', 'trigger' => 'Parcel registered (existing customer)'],
        'parcel_created_new_customer' => ['stage' => 'Parcel', 'label' => 'Parcel registered (new customer)', 'recipient' => 'Customer', 'trigger' => 'Parcel registered (new customer)'],
        'parcel_status' => ['stage' => 'Journey', 'label' => 'Parcel status update', 'recipient' => 'Customer / owner copy', 'trigger' => 'Orders → Advance'],
        'parcel_ready' => ['stage' => 'Journey', 'label' => 'Ready for collection', 'recipient' => 'Customer / owner copy', 'trigger' => 'Orders → Advance to ready'],
        'payment_receipt' => ['stage' => 'Payment', 'label' => 'Payment receipt / collection code', 'recipient' => 'Customer / owner copy', 'trigger' => 'Customer pays delivery fee'],
        'parcel_cancelled' => ['stage' => 'Journey', 'label' => 'Parcel cancelled', 'recipient' => 'Customer / owner copy', 'trigger' => 'Order cancellation'],
        'parcel_returned' => ['stage' => 'Journey', 'label' => 'Parcel return requested', 'recipient' => 'Customer / owner copy', 'trigger' => 'Order return'],
        'claim_otp' => ['stage' => 'Claims', 'label' => 'Claim verification code', 'recipient' => 'Customer', 'trigger' => 'Customer requests claim OTP'],
        'delivery_otp' => ['stage' => 'Journey', 'label' => 'Delivery confirmation code', 'recipient' => 'Receiver', 'trigger' => 'Runner confirms pickup'],
        'newsletter_confirm' => ['stage' => 'Growth', 'label' => 'Newsletter confirmation', 'recipient' => 'Subscriber', 'trigger' => 'Public newsletter opt-in'],
        'claim_reported' => ['stage' => 'Claims', 'label' => 'New claim reported', 'recipient' => 'Workspace owner', 'trigger' => 'Customer files a claim'],
        'claim_approved' => ['stage' => 'Claims', 'label' => 'Claim approved', 'recipient' => 'Customer + owner', 'trigger' => 'Admin approves claim'],
        'claim_rejected' => ['stage' => 'Claims', 'label' => 'Claim rejected', 'recipient' => 'Customer + owner', 'trigger' => 'Admin rejects claim'],
        'claim_paid' => ['stage' => 'Claims', 'label' => 'Claim payout released', 'recipient' => 'Customer + owner', 'trigger' => 'Admin releases payout'],
        'claim_reversed' => ['stage' => 'Claims', 'label' => 'Claim payout reversed', 'recipient' => 'Customer + owner', 'trigger' => 'Admin reverses payout'],
        'claim_message' => ['stage' => 'Claims', 'label' => 'Claim message', 'recipient' => 'Other claim participants', 'trigger' => 'Claim thread message'],
        'claim_sla_escalated' => ['stage' => 'Claims', 'label' => 'Claim SLA escalation', 'recipient' => 'Workspace owner', 'trigger' => 'Cron: claim SLA overdue'],
        'invoice_paid' => ['stage' => 'Billing', 'label' => 'Subscription payment receipt', 'recipient' => 'Merchant', 'trigger' => 'Invoice paid'],
        'team_invite' => ['stage' => 'Team', 'label' => 'Team invitation', 'recipient' => 'Employee', 'trigger' => 'Team member added'],
        'smtp_test' => ['stage' => 'System', 'label' => 'SMTP delivery test', 'recipient' => 'Admin', 'trigger' => 'Admin Communications → Send test email'],
    ];
}

function comms_template_table(): array
{
    if (function_exists('email_template_ensure_seeded')) {
        email_template_ensure_seeded();
    }
    $defs = [];
    try {
        foreach (db()->query('SELECT id, template_key, label, stage, recipient, trigger_text AS trigger_text, subject, body_text,
            body_html, is_active, is_system, updated_at FROM email_templates ORDER BY stage ASC, id ASC')->fetchAll() as $row) {
            $defs[] = $row;
        }
    } catch (Throwable $e) {
        $defs = [];
    }
    if (!$defs) {
        foreach (comms_template_catalog() as $key => $meta) {
            $defs[] = ['id' => 0, 'template_key' => $key, 'label' => $meta['label'], 'stage' => $meta['stage'],
                'recipient' => $meta['recipient'], 'trigger_text' => $meta['trigger'], 'subject' => null,
                'body_text' => null, 'body_html' => null, 'is_active' => 1, 'is_system' => 1, 'updated_at' => null];
        }
    }
    $counts = [];
    foreach (db()->query('SELECT template, COUNT(*) AS attempts, SUM(status = "sent") AS sent, MAX(created_at) AS last_sent
        FROM notifications GROUP BY template')->fetchAll() as $countRow) {
        $counts[$countRow['template']] = $countRow;
    }
    $rows = [];
    foreach ($defs as $row) {
        $key = (string) $row['template_key'];
        $count = $counts[$key] ?? null;
        $rows[] = [
            'id' => (int) $row['id'], 'template' => $key, 'label' => $row['label'], 'stage' => $row['stage'],
            'recipient' => $row['recipient'], 'trigger' => $row['trigger_text'] ?? '',
            'subject' => $row['subject'] ?? '', 'body_text' => $row['body_text'] ?? '',
            'body_html' => $row['body_html'] ?? '', 'is_active' => (int) $row['is_active'],
            'is_system' => (int) $row['is_system'], 'updated_at' => $row['updated_at'] ?? null,
            'channels' => 'Email HTML + active channels',
            'attempts' => (int) ($count['attempts'] ?? 0), 'sent' => (int) ($count['sent'] ?? 0),
            'last_sent' => $count['last_sent'] ?? null,
        ];
    }
    return $rows;
}
