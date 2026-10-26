<?php
/**
 * Seed / refresh all customer-journey email template content in MySQL.
 *
 * Each template stores subject, plain-text body and an HTML content fragment.
 * Fragments contain {{placeholders}} and are wrapped in the branded Mzigo
 * layout at send time unless they are full HTML documents.
 */
declare(strict_types=1);
$config = require __DIR__ . '/../config.php';
$db = $config['db'];
$pdo = new PDO($db['dsn'], $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
require __DIR__ . '/../../api/_comms_catalog.php';

$greet = '<p style="margin:0 0 14px;font-size:14px;line-height:1.65;color:#46523a;">Hi {{name}},</p>';
$p = fn (string $text): string => '<p style="margin:0 0 12px;font-size:14px;line-height:1.65;color:#46523a;">' . $text . '</p>';
$info = fn (string $label, string $value): string => '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 10px;background:#f6f9f1;border:1px solid #e3ebd8;border-radius:12px;"><tr><td style="padding:11px 14px;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#78866a;font-weight:700;">' . $label . '</td><td style="padding:11px 14px;font-size:14px;font-weight:700;color:#26331a;text-align:right;">' . $value . '</td></tr></table>';
$btn = fn (string $url, string $label): string => '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:18px 0 6px;"><tr><td style="border-radius:999px;background:#3c5223;"><a href="' . $url . '" style="display:inline-block;padding:13px 24px;font-size:14px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:999px;">' . $label . '</a></td></tr></table>';
$alert = fn (string $text, string $color = '#f0fdf4', string $border = '#bbf0c4', string $fg = '#166534'): string => '<div style="padding:14px 16px;background:' . $color . ';border:1px solid ' . $border . ';border-radius:12px;font-size:14px;color:' . $fg . ';margin-bottom:14px;"><strong>' . $text . '</strong></div>';

$templates = [
    'email_verification' => [
        'subject' => 'Verify your Mzigo.online email',
        'text' => "Hi {{name}},\nWelcome to Mzigo.online. Confirm your email address:\n{{link}}\nThis link expires in 24 hours.",
        'html' => $greet . $p('Welcome to Mzigo.online. Confirm your email address so we can send parcel updates, receipts and account notices.') .
            $info('Account email', '{{email}}') . $btn('{{link}}', 'Verify my email') .
            $p('This link expires in 24 hours. If you did not create this account, you can ignore this email.'),
    ],
    'password_reset' => [
        'subject' => 'Reset your Mzigo.online password',
        'text' => "Hi {{name}},\nReset your password using this single-use link:\n{{link}}\nIt expires in 60 minutes.",
        'html' => $greet . $p('We received a request to reset the password for your Mzigo.online account.') .
            $p('This single-use link expires in 60 minutes. If you did not request it, no action is needed.') .
            $btn('{{link}}', 'Choose a new password'),
    ],
    'password_changed' => [
        'subject' => 'Your Mzigo.online password was changed',
        'text' => "Hi {{name}},\nYour Mzigo.online password was changed. If this was not you, reset it immediately.",
        'html' => $greet . $alert('Your password was changed.') .
            $p('If this was not you, reset your password immediately and contact support at {{support_email}}.') .
            $btn('{{login_url}}', 'Sign in'),
    ],
    'email_verified' => [
        'subject' => 'Your Mzigo.online email is verified',
        'text' => "Hi {{name}},\nYour email address is now verified.\n{{profile_url}}",
        'html' => $greet . $alert('Your email address is now verified.') .
            $p('You can now receive password-reset links, parcel notices and account updates.') .
            $btn('{{profile_url}}', 'Open dashboard'),
    ],
    'parcel_created' => [
        'subject' => 'Your parcel {{tracking}} is on the way',
        'text' => "Hi {{name}},\nA parcel is on its way to you.\nTracking: {{tracking}}\nRoute: {{from}} to {{to}}\nDelivery fee: KES {{fee}}\nTrack: {{track_url}}",
        'html' => $greet . $p('A parcel is on its way to you. Track it with the number below.') .
            $info('Tracking number', '{{tracking}}') . $info('Route', '{{from}} → {{to}}') . $info('Delivery fee', 'KES {{fee}}') .
            $p('The collection code is issued after the delivery fee is paid.') . $btn('{{track_url}}', 'Track parcel'),
    ],
    'parcel_created_new_customer' => [
        'subject' => 'Your first Mzigo parcel {{tracking}}',
        'text' => "Hi {{name}},\nWelcome to Mzigo.online. A parcel is on its way to you.\nTracking: {{tracking}}\nTrack: {{track_url}}",
        'html' => $greet . $p('Welcome to Mzigo.online. A parcel is on its way to you.') .
            $info('Tracking number', '{{tracking}}') . $info('Route', '{{from}} → {{to}}') . $info('Delivery fee', 'KES {{fee}}') .
            $p('Track and pay the delivery fee from your customer account. The collection code appears after payment.') .
            $btn('{{track_url}}', 'Track parcel'),
    ],
    'parcel_status' => [
        'subject' => 'Parcel {{tracking}} · {{status}}',
        'text' => "Hi {{name}},\nParcel {{tracking}} is now {{status}}.\n{{note}}\nTrack: {{track_url}}",
        'html' => $greet . '<div style="display:inline-block;padding:7px 12px;border-radius:999px;background:#e4fbdc;color:#2f4d1f;font-size:12px;font-weight:700;margin-bottom:14px;">{{status}}</div>' .
            $info('Tracking number', '{{tracking}}') . $p('{{note}}') . $btn('{{track_url}}', 'Track parcel'),
    ],
    'parcel_ready' => [
        'subject' => 'Parcel {{tracking}} is ready for collection',
        'text' => "Hi {{name}},\nParcel {{tracking}} is ready for collection.\nAmount due: KES {{fee}}\nPay from your customer account: {{customer_url}}",
        'html' => $greet . '<div style="display:inline-block;padding:7px 12px;border-radius:999px;background:#e4fbdc;color:#2f4d1f;font-size:12px;font-weight:700;margin-bottom:14px;">Ready for collection</div>' .
            $info('Tracking number', '{{tracking}}') . $p('Pay the delivery fee from your customer account to receive the collection code, or pay at the destination office.') .
            $info('Amount due', 'KES {{fee}}') . $btn('{{customer_url}}', 'Open my parcels'),
    ],
    'payment_receipt' => [
        'subject' => 'Payment received for {{tracking}}',
        'text' => "Hi {{name}},\nWe received your delivery payment.\nTracking: {{tracking}}\nAmount: KES {{amount}}\nReceipt: {{reference}}\nCollection code: {{code}}",
        'html' => $greet . $alert('Payment received.') .
            $p('Show the collection code below at the destination office to collect your parcel.') .
            $info('Tracking number', '{{tracking}}') . $info('Amount paid', 'KES {{amount}}') .
            $info('Receipt', '{{reference}}') . $info('Collection code', '{{code}}') . $btn('{{customer_url}}', 'Open my parcels'),
    ],
    'parcel_cancelled' => [
        'subject' => 'Parcel {{tracking}} was cancelled',
        'text' => "Hi {{name}},\nParcel {{tracking}} was cancelled.\n{{note}}\nIf you paid a fee, contact support for a refund.",
        'html' => $greet . $alert('Parcel cancelled.', '#fffbeb', '#f0d68a', '#92400e') .
            $info('Tracking number', '{{tracking}}') . $p('{{note}}') . $p('If you paid a delivery fee, contact {{support_email}} for a refund review.'),
    ],
    'parcel_returned' => [
        'subject' => 'Return requested for parcel {{tracking}}',
        'text' => "Hi {{name}},\nA return has been requested for parcel {{tracking}}.\n{{note}}\nTrack: {{track_url}}",
        'html' => $greet . $alert('Return in progress.', '#fffbeb', '#f0d68a', '#92400e') .
            $info('Tracking number', '{{tracking}}') . $p('{{note}}') . $btn('{{track_url}}', 'Track parcel'),
    ],
    'claim_otp' => [
        'subject' => 'Your Mzigo claim verification code',
        'text' => "Hi {{name}},\nYour verification code for parcel {{tracking}} is {{code}}.\nIt expires in 10 minutes.",
        'html' => $greet . $p('Use this code to verify your phone and submit your claim for parcel <strong>{{tracking}}</strong>.') .
            '<div style="font-size:32px;letter-spacing:.25em;font-weight:800;color:#26331a;background:#f6f9f1;border:1px solid #e3ebd8;border-radius:14px;padding:16px;text-align:center;margin:0 0 14px;">{{code}}</div>' .
            $p('The code expires in 10 minutes. Do not share it with anyone.'),
    ],
    'claim_reported' => [
        'subject' => 'New customer claim {{claim_ref}} for {{tracking}}',
        'text' => "A customer reported an issue with parcel {{tracking}}.\nClaim: {{claim_ref}}\n{{note}}\nOpen claims: {{claims_url}}",
        'html' => $greet . $alert('A customer has reported an issue and the claim is awaiting review.', '#fffbeb', '#f0d68a', '#92400e') .
            $info('Claim reference', '{{claim_ref}}') . $info('Parcel', '{{tracking}}') . $p('{{note}}') . $btn('{{claims_url}}', 'Open claims'),
    ],
    'claim_approved' => [
        'subject' => 'Claim {{claim_ref}} approved',
        'text' => "Hi {{name}},\nYour claim {{claim_ref}} for parcel {{tracking}} was approved.\n{{note}}\n{{claims_url}}",
        'html' => $greet . $alert('Claim approved.') . $info('Claim reference', '{{claim_ref}}') . $info('Parcel', '{{tracking}}') .
            $p('{{note}}') . $p('Any payout is placed on a short hold before release.') . $btn('{{claims_url}}', 'Open claims'),
    ],
    'claim_rejected' => [
        'subject' => 'Claim {{claim_ref}} rejected',
        'text' => "Hi {{name}},\nYour claim {{claim_ref}} for parcel {{tracking}} was rejected.\n{{note}}\n{{claims_url}}",
        'html' => $greet . $alert('Claim rejected.', '#fef2f2', '#fecaca', '#991b1b') .
            $info('Claim reference', '{{claim_ref}}') . $info('Parcel', '{{tracking}}') . $p('{{note}}') .
            $p('If you have new evidence you can ask for a review from the claim page.') . $btn('{{claims_url}}', 'Open claims'),
    ],
    'claim_paid' => [
        'subject' => 'Claim {{claim_ref}} payout released',
        'text' => "Hi {{name}},\nThe payout for claim {{claim_ref}} has been released.\n{{note}}\n{{claims_url}}",
        'html' => $greet . $alert('Claim paid.') . $info('Claim reference', '{{claim_ref}}') . $info('Parcel', '{{tracking}}') .
            $p('{{note}}') . $btn('{{claims_url}}', 'Open claims'),
    ],
    'claim_reversed' => [
        'subject' => 'Claim {{claim_ref}} payout reversed',
        'text' => "Hi {{name}},\nThe payout for claim {{claim_ref}} was reversed after review.\n{{note}}\nContact {{support_email}} with questions.",
        'html' => $greet . $alert('Payout reversed.', '#fffbeb', '#f0d68a', '#92400e') .
            $info('Claim reference', '{{claim_ref}}') . $info('Parcel', '{{tracking}}') . $p('{{note}}') .
            $p('Contact {{support_email}} if you need more information.'),
    ],
    'claim_message' => [
        'subject' => 'New message on claim {{claim_ref}}',
        'text' => "Hi {{name}},\nA new message was added to claim {{claim_ref}} for parcel {{tracking}}.\n{{note}}\n{{claims_url}}",
        'html' => $greet . $info('Claim reference', '{{claim_ref}}') . $info('Parcel', '{{tracking}}') .
            $p('New message:') . '<div style="padding:12px 14px;background:#f6f9f1;border-left:3px solid #3c5223;border-radius:8px;font-size:14px;color:#46523a;margin-bottom:14px;">{{note}}</div>' .
            $btn('{{claims_url}}', 'Reply in claims'),
    ],
    'claim_sla_escalated' => [
        'subject' => 'Claim {{claim_ref}} has passed its SLA',
        'text' => "Claim {{claim_ref}} for parcel {{tracking}} has passed its decision SLA. Please review it.\n{{claims_url}}",
        'html' => $greet . $alert('This claim has passed its decision SLA.', '#fffbeb', '#f0d68a', '#92400e') .
            $info('Claim reference', '{{claim_ref}}') . $info('Parcel', '{{tracking}}') . $btn('{{claims_url}}', 'Review claim'),
    ],
    'invoice_paid' => [
        'subject' => 'Subscription payment received ({{invoice}})',
        'text' => "Hi {{name}},\nWe received your subscription payment.\nInvoice: {{invoice}}\nAmount: KES {{amount}}\nReceipt: {{reference}}\n{{billing_url}}",
        'html' => $greet . $alert('Subscription payment received.') . $info('Invoice', '{{invoice}}') .
            $info('Amount paid', 'KES {{amount}}') . $info('Receipt', '{{reference}}') .
            $p('Your workspace remains active.') . $btn('{{billing_url}}', 'Open billing'),
    ],
    'team_invite' => [
        'subject' => 'You are invited to {{business}} on Mzigo.online',
        'text' => "Hi {{name}},\nYou have been invited to the {{business}} workspace.\nEmail: {{email}}\nTemporary password: {{password}}\nSign in: {{login_url}}",
        'html' => $greet . $p('You have been invited to join the <strong>{{business}}</strong> workspace.') .
            $info('Email', '{{email}}') . $info('Temporary password', '{{password}}') .
            $p('Sign in and change your password from Settings.') . $btn('{{login_url}}', 'Sign in'),
    ],
    'smtp_test' => [
        'subject' => 'Mzigo.online SMTP test',
        'text' => "This is a test email from your Mzigo.online SMTP configuration. Delivery is working.\nSent: {{date}}\nRecipient: {{email}}",
        'html' => $greet . $alert('SMTP test successful.') . $p('This is a test email from your Mzigo.online SMTP configuration. Delivery is working.') .
            $info('Sent at', '{{date}}') . $info('Recipient', '{{email}}') . $btn('{{admin_url}}', 'Open communications'),
    ],
];

$update = $pdo->prepare('UPDATE email_templates SET subject = ?, body_text = ?, body_html = ? WHERE template_key = ?');
$insert = $pdo->prepare('INSERT INTO email_templates (template_key, label, stage, recipient, trigger_text, subject, body_text, body_html, is_active, is_system)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 1)');
$catalog = comms_template_catalog();
$updated = 0;
foreach ($templates as $key => $template) {
    $stmt = $pdo->prepare('SELECT id FROM email_templates WHERE template_key = ? LIMIT 1');
    $stmt->execute([$key]);
    if ($stmt->fetchColumn()) {
        $update->execute([$template['subject'], $template['text'], $template['html'], $key]);
    } else {
        $meta = $catalog[$key] ?? ['label' => $key, 'stage' => 'General', 'recipient' => 'Customer', 'trigger' => ''];
        $insert->execute([$key, $meta['label'], $meta['stage'], $meta['recipient'], $meta['trigger'], $template['subject'], $template['text'], $template['html']]);
    }
    $updated++;
}
/* Fill any catalogue template that is missing content. */
foreach ($catalog as $key => $meta) {
    if (isset($templates[$key])) { continue; }
    $update->execute(['Mzigo.online · ' . $meta['label'], 'Hi {{name}}, ' . $meta['label'] . ' for {{tracking}}.', $greet . $p($meta['label']) . $btn('{{login_url}}', 'Open Mzigo.online'), $key]);
    $updated++;
}
echo "Seeded/updated {$updated} template(s) with subject, plain-text and HTML content.\n";
