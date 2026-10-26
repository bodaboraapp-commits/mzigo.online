<?php
/**
 * Mzigo.online — branded HTML email templates.
 *
 * Every transactional email uses a responsive, inline-styled layout with the
 * Mzigo green brand. Templates receive the legacy plain-text body so existing
 * notification calls keep working, plus optional structured data.
 */
declare(strict_types=1);

function email_e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function email_first_url(string $text): ?string
{
    if (preg_match('~https?://[^\s<]+~', $text, $m)) { return rtrim($m[0], '.,)'); }
    return null;
}

function email_otp(string $text): ?string
{
    if (preg_match('/\b(\d{6})\b/', $text, $m)) { return $m[1]; }
    return null;
}

function email_paragraphs(string $text): string
{
    $lines = preg_split('/\R/', trim($text));
    $out = '';
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') { continue; }
        $line = email_e($line);
        $line = preg_replace('~(https?://[^\s<]+)~', '<a href="$1" style="color:#3c5223;text-decoration:underline;">$1</a>', $line);
        $out .= '<p style="margin:0 0 12px;font-size:14px;line-height:1.65;color:#46523a;">' . $line . '</p>';
    }
    return $out;
}

function email_button(?string $url, ?string $label): string
{
    if (!$url || !$label) { return ''; }
    return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:18px 0 6px;"><tr><td style="border-radius:999px;background:#3c5223;">' .
        '<a href="' . email_e($url) . '" style="display:inline-block;padding:13px 24px;font-size:14px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:999px;">' . email_e($label) . '</a>' .
        '</td></tr></table>';
}

function email_info_card(string $label, string $value): string
{
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 10px;background:#f6f9f1;border:1px solid #e3ebd8;border-radius:12px;">' .
        '<tr><td style="padding:11px 14px;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#78866a;font-weight:700;">' . email_e($label) . '</td>' .
        '<td style="padding:11px 14px;font-size:14px;font-weight:700;color:#26331a;text-align:right;">' . email_e($value) . '</td></tr></table>';
}

function email_shell(string $preheader, string $heading, string $content, ?string $ctaUrl = null, ?string $ctaLabel = null, array $opts = []): string
{
    $support = (string) (config()['app']['support_email'] ?? 'hello@mzigo.online');
    $base = rtrim((string) (config()['app']['base_url'] ?? 'http://localhost:8090'), '/');
    $trackUrl = $base . '/track-mzigo';
    $helpUrl = $base . '/help';
    $termsUrl = $base . '/terms';
    $privacyUrl = $base . '/privacy';
    $unsubscribeUrl = trim((string) ($opts['unsubscribe_url'] ?? ''));
    $isMarketing = !empty($opts['marketing']) || $unsubscribeUrl !== '';
    $footerNote = $isMarketing
        ? 'You are receiving this because you subscribed to Mzigo.online updates.'
        : 'This is a service message about your Mzigo.online account.';
    $preferenceUrl = $isMarketing && $unsubscribeUrl !== '' ? $unsubscribeUrl : $base . '/profile#notifications';
    $preferenceLabel = $isMarketing && $unsubscribeUrl !== '' ? 'Unsubscribe' : 'Manage notifications';
    return '<!doctype html><html lang="en"><head><meta charset="utf-8">' .
        '<meta name="viewport" content="width=device-width,initial-scale=1">' .
        '<meta name="color-scheme" content="light">' .
        '<title>' . email_e($heading) . '</title></head>' .
        '<body style="margin:0;padding:0;background:#eef5e6;">' .
        '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . email_e($preheader) . '</div>' .
        '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef5e6;padding:26px 12px;"><tr><td align="center">' .
        '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:18px;overflow:hidden;border:1px solid #dfe8d3;font-family:\'Segoe UI\',Arial,sans-serif;">' .
        '<tr><td style="background:#ffffff;padding:18px 28px 16px;border-bottom:1px solid #e7eee0;">' .
        '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>' .
        '<td style="vertical-align:middle;"><div style="font-size:22px;font-weight:800;color:#26331a;letter-spacing:-.03em;">Mzigo<span style="color:#6f8f3a;">.online</span></div>' .
        '<div style="font-size:9px;font-weight:700;color:#8a987c;letter-spacing:.16em;text-transform:uppercase;margin-top:3px;">Parcel network</div></td>' .
        '<td align="right" style="vertical-align:middle;font-size:11px;line-height:1.5;color:#6b7a5c;">' .
        '<a href="mailto:' . email_e($support) . '" style="color:#3c5223;text-decoration:none;">' . email_e($support) . '</a><br>' .
        '<a href="' . email_e($trackUrl) . '" style="display:inline-block;margin-top:6px;background:#f2c94c;color:#26331a;padding:8px 14px;border-radius:999px;font-weight:700;text-decoration:none;font-size:11px;">Track a parcel</a>' .
        '</td></tr></table></td></tr>' .
        '<tr><td style="padding:28px 28px 22px;">' .
        '<h1 style="margin:0 0 14px;font-size:23px;line-height:1.25;color:#26331a;">' . email_e($heading) . '</h1>' .
        $content . email_button($ctaUrl, $ctaLabel) .
        '</td></tr>' .
        '<tr><td style="background:#1d2418;padding:24px 28px 22px;">' .
        '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>' .
        '<td style="vertical-align:top;"><div style="font-size:19px;font-weight:800;color:#c2f8cb;letter-spacing:-.02em;">Mzigo<span style="color:#a8e8b4;">.online</span></div>' .
        '<div style="font-size:11px;color:#9aa78c;margin-top:4px;">Parcel tracking, claims and collection across Kenya.</div></td>' .
        '<td align="right" style="vertical-align:top;font-size:11px;line-height:1.9;"><a href="' . email_e($helpUrl) . '" style="color:#e8f2df;text-decoration:none;">Help centre</a> &nbsp; <a href="' . email_e($termsUrl) . '" style="color:#e8f2df;text-decoration:none;">Terms</a> &nbsp; <a href="' . email_e($privacyUrl) . '" style="color:#e8f2df;text-decoration:none;">Privacy</a></td>' .
        '</tr></table>' .
        '<div style="border-top:1px solid #35412a;margin:18px 0 14px;"></div>' .
        '<p style="margin:0 0 6px;font-size:11px;line-height:1.6;color:#aebba2;">Questions? <a href="mailto:' . email_e($support) . '" style="color:#c2f8cb;text-decoration:underline;">' . email_e($support) . '</a></p>' .
        '<p style="margin:0 0 10px;font-size:11px;line-height:1.6;color:#8f9d83;">' . email_e($footerNote) . '</p>' .
        '<p style="margin:0;font-size:11px;line-height:1.6;color:#8f9d83;"><a href="' . email_e($preferenceUrl) . '" style="color:#c2f8cb;text-decoration:underline;">' . email_e($preferenceLabel) . '</a> &nbsp;&middot;&nbsp; Mzigo.online &middot; Nairobi, Kenya</p>' .
        '</td></tr></table></td></tr></table></body></html>';
}

function email_render(string $template, array $user, string $subject, string $body, array $parcel = [], array $data = []): string
{
    $name = (string) ($data['name'] ?? ($user['name'] ?? 'there'));
    $tracking = (string) ($data['tracking'] ?? ($parcel['tracking'] ?? ''));
    $ref = (string) ($data['ref'] ?? '');
    $note = (string) ($data['note'] ?? '');
    $link = (string) ($data['link'] ?? '');
    $amount = isset($data['amount']) ? 'KES ' . number_format((int) $data['amount']) : '';
    $statusLabel = (string) ($data['status_label'] ?? '');
    $base = rtrim((string) (config()['app']['base_url'] ?? 'http://localhost:8090'), '/');
    $greeting = '<p style="margin:0 0 14px;font-size:14px;line-height:1.65;color:#46523a;">Hi ' . email_e($name) . ',</p>';

    if ($template === 'email_verification' || $template === 'welcome') {
        $url = $link ?: (email_first_url($body) ?? '');
        $content = $greeting .
            '<p style="margin:0 0 14px;font-size:14px;line-height:1.65;color:#46523a;">Welcome to Mzigo.online. Confirm your email address so we can send you parcel updates, receipts, password resets and account notices.</p>' .
            email_info_card('Account', (string) ($user['email'] ?? '')) .
            email_paragraphs($url === '' ? $body : 'This link expires in 24 hours. If you did not create this account, you can ignore this email.');
        return email_shell('Confirm your Mzigo.online account email', 'Confirm your email', $content, $url ?: null, 'Verify my email');
    }

    if ($template === 'password_reset') {
        $url = $link ?: (email_first_url($body) ?? '');
        $content = $greeting .
            '<p style="margin:0 0 14px;font-size:14px;line-height:1.65;color:#46523a;">We received a request to reset the password for your Mzigo.online account.</p>' .
            '<p style="margin:0 0 14px;font-size:14px;line-height:1.65;color:#46523a;">This single-use link expires in 60 minutes. If you did not request it, no action is needed.</p>';
        return email_shell('Reset your Mzigo.online password', 'Reset your password', $content, $url ?: null, 'Choose a new password');
    }

    if ($template === 'password_changed') {
        $content = $greeting .
            '<div style="padding:14px 16px;background:#f0fdf4;border:1px solid #bbf0c4;border-radius:12px;font-size:14px;color:#166534;margin-bottom:14px;"><strong>Your password was changed.</strong></div>' .
            '<p style="margin:0;font-size:14px;line-height:1.65;color:#46523a;">If this was not you, reset your password immediately and contact support.</p>';
        return email_shell('Your Mzigo.online password changed', 'Password changed', $content, $base . '/forgot-password', 'Reset password');
    }

    if ($template === 'email_verified') {
        $content = $greeting . '<p style="margin:0 0 14px;font-size:14px;line-height:1.65;color:#46523a;">Your email address is now verified. You can receive password-reset links and account notices.</p>';
        return email_shell('Email verified', 'Email verified', $content, $base . '/profile', 'Open dashboard');
    }

    if (in_array($template, ['parcel_created', 'parcel_created_new_customer'], true)) {
        $route = trim((string) ($parcel['from_city'] ?? '') . ' to ' . (string) ($parcel['to_city'] ?? ''), ' to ');
        $fee = isset($parcel['fee_total']) ? (int) $parcel['fee_total'] : (int) ($data['amount'] ?? 0);
        $declared = (int) ($parcel['value_kes'] ?? 0);
        $insuranceFee = (int) ($parcel['fee_insurance'] ?? 0);
        $insuranceRef = (string) ($data['insurance_ref'] ?? (function_exists('parcel_insurance_ref') ? parcel_insurance_ref($tracking) : ''));
        $sender = (string) ($parcel['sender_name'] ?? '');
        $carrier = (string) ($parcel['carrier'] ?? '');
        $parcelSize = (string) ($parcel['size'] ?? '');
        $termsUrl = $base . '/terms#risk-and-cover';
        $content = $greeting .
            '<p style="margin:0 0 14px;font-size:14px;line-height:1.65;color:#46523a;">Your parcel has been registered with Mzigo.online. Keep this tracking number and share it with the sender.</p>' .
            email_info_card('Tracking number', $tracking) .
            ($carrier !== '' ? email_info_card('Courier', $carrier) : '') .
            ($route !== '' ? email_info_card('Route', $route) : '') .
            ($sender !== '' ? email_info_card('Sender', $sender) : '') .
            ($parcelSize !== '' ? email_info_card('Parcel size', $parcelSize) : '') .
            ($declared > 0 ? email_info_card('Declared parcel value', 'KES ' . number_format($declared)) : '') .
            ($insuranceRef !== '' ? email_info_card(str_starts_with($insuranceRef, 'DEMO-POL-') ? 'Demo insurance reference' : 'Insurance number', $insuranceRef === 'PENDING' ? 'Pending — insurer will issue' : $insuranceRef) : '') .
            ($declared > 0 ? email_info_card('Insurance cover', 'KES ' . number_format($declared)) : '') .
            ($insuranceFee > 0 ? email_info_card('Insurance fee', 'KES ' . number_format($insuranceFee)) : '') .
            ($fee > 0 ? email_info_card('Total delivery cost', 'KES ' . number_format($fee)) : '') .
            '<p style="margin:0 0 12px;font-size:13px;line-height:1.6;color:#78866a;"><strong>Status: registered.</strong> Tracking updates begin after the sender drops the parcel off at the courier office.</p>' .
            '<p style="margin:0 0 12px;font-size:13px;line-height:1.6;color:#78866a;">Insurance cover is subject to our <a href="' . email_e($termsUrl) . '" style="color:#3c5223;text-decoration:underline;">insurance terms &amp; conditions</a>. The collection code is issued after the delivery fee is paid.</p>';
        return email_shell('Parcel registered: ' . $tracking, 'Your parcel is registered', $content, $base . '/track-mzigo?num=' . rawurlencode($tracking), 'Track parcel');
    }

    if (in_array($template, ['parcel_status', 'parcel_ready', 'parcel_collected', 'parcel_cancelled', 'parcel_returned'], true)) {
        $labels = [
            'parcel_status' => $statusLabel !== '' ? $statusLabel : 'Parcel update',
            'parcel_ready' => 'Ready for collection',
            'parcel_collected' => 'Parcel collected',
            'parcel_cancelled' => 'Parcel cancelled',
            'parcel_returned' => 'Return in progress',
        ];
        $titles = [
            'parcel_status' => 'Parcel update',
            'parcel_ready' => 'Ready for collection',
            'parcel_collected' => 'Collected — thank you',
            'parcel_cancelled' => 'Parcel cancelled',
            'parcel_returned' => 'Parcel return requested',
        ];
        $title = $titles[$template] ?? 'Parcel update';
        $label = $labels[$template] ?? $statusLabel;
        $content = $greeting;
        $content .= '<div style="display:inline-block;padding:7px 12px;border-radius:999px;background:#e4fbdc;color:#2f4d1f;font-size:12px;font-weight:700;margin-bottom:14px;">' . email_e($label) . '</div>';
        $content .= email_info_card('Tracking number', $tracking);
        if ($note !== '') { $content .= email_paragraphs($note); }
        if ($template === 'parcel_ready') {
            $fee = abs((int) ($data['amount'] ?? ($parcel['fee_total'] ?? 0)));
            $content .= '<p style="margin:0 0 12px;font-size:14px;line-height:1.65;color:#46523a;">Pay the delivery fee from your customer account to receive the collection code, or pay at the destination office.</p>';
            if ($fee > 0) { $content .= email_info_card('Amount due', 'KES ' . number_format($fee)); }
            return email_shell('Parcel ' . $tracking . ' is ready for collection', $title, $content, $base . '/customer', 'Open my parcels');
        }
        return email_shell('Parcel ' . $tracking . ' update', $title, $content, $base . '/track-mzigo?num=' . rawurlencode($tracking), 'Track parcel');
    }

    if (in_array($template, ['payment_receipt', 'customer_parcel_paid'], true)) {
        $content = $greeting . '<p style="margin:0 0 14px;font-size:14px;line-height:1.65;color:#46523a;">Payment received. Show the collection code below at the destination office.</p>' .
            email_info_card('Tracking number', $tracking) .
            ($amount !== '' ? email_info_card('Amount paid', $amount) : '') .
            (($data['reference'] ?? '') !== '' ? email_info_card('Receipt', (string) $data['reference']) : '') .
            (($data['collect_code'] ?? '') !== '' ? email_info_card('Collection code', (string) $data['collect_code']) : '');
        return email_shell('Payment received for ' . $tracking, 'Payment received', $content, $base . '/customer', 'Open my parcels');
    }

    if ($template === 'claim_otp') {
        $code = (string) ($data['code'] ?? (email_otp($body) ?? ''));
        $content = $greeting . '<p style="margin:0 0 14px;font-size:14px;line-height:1.65;color:#46523a;">Use this code to verify your phone and submit your claim for parcel <strong>' . email_e($tracking) . '</strong>.</p>' .
            '<div style="font-size:32px;letter-spacing:.25em;font-weight:800;color:#26331a;background:#f6f9f1;border:1px solid #e3ebd8;border-radius:14px;padding:16px;text-align:center;margin:0 0 14px;">' . email_e($code) . '</div>' .
            '<p style="margin:0;font-size:13px;color:#78866a;">The code expires in 10 minutes. Do not share it with anyone.</p>';
        return email_shell('Your Mzigo claim verification code', 'Verify your claim', $content);
    }

    if ($template === 'delivery_otp') {
        $code = (string) ($data['code'] ?? (email_otp($body) ?? ''));
        $content = $greeting .
            '<p style="margin:0 0 14px;font-size:14px;line-height:1.65;color:#46523a;">Give this code to the runner only after you receive your item.</p>' .
            '<div style="font-size:32px;letter-spacing:.25em;font-weight:800;color:#26331a;background:#f6f9f1;border:1px solid #e3ebd8;border-radius:14px;padding:16px;text-align:center;margin:0 0 14px;">' . email_e($code) . '</div>' .
            ($tracking !== '' ? email_info_card('Errand', $tracking) : '') .
            '<p style="margin:0;font-size:13px;color:#78866a;">This code expires in 30 minutes. Do not share it before handover.</p>';
        return email_shell('Your delivery code', 'Confirm your delivery', $content);
    }

    if ($template === 'newsletter_confirm') {
        $url = (string) ($data['confirm_url'] ?? (email_first_url($body) ?? ''));
        $artwork = (string) ($data['artwork_url'] ?? '');
        $artworkHtml = $artwork !== ''
            ? '<img src="' . email_e($artwork) . '" alt="Mzigo.online updates" style="display:block;width:100%;max-width:520px;height:auto;border-radius:12px;margin:0 0 16px;">'
            : '';
        $leadUrl = (string) ($data['lead_magnet_url'] ?? '');
        $leadLabel = (string) ($data['lead_magnet_label'] ?? 'Download your guide');
        $leadHtml = $leadUrl !== ''
            ? '<p style="margin:0 0 14px;font-size:13px;line-height:1.6;color:#78866a;">Your guide: <a href="' . email_e($leadUrl) . '" style="color:#3c5223;text-decoration:underline;">' . email_e($leadLabel) . '</a></p>'
            : '';
        $content = $greeting . $artworkHtml .
            '<p style="margin:0 0 14px;font-size:14px;line-height:1.65;color:#46523a;">Confirm that you want to receive Mzigo.online news and product alerts. We will only send the updates you asked for.</p>' .
            $leadHtml .
            email_info_card('Updates', 'Newsletter and product alerts') .
            '<p style="margin:0 0 12px;font-size:13px;line-height:1.6;color:#78866a;">This confirmation link expires in 48 hours. If you did not request this, you can ignore this email.</p>';
        return email_shell('Confirm your Mzigo.online updates', 'Confirm your subscription', $content, $url ?: null, 'Confirm subscription');
    }

    if (str_starts_with($template, 'claim_')) {
        $statusMap = [
            'claim_reported' => ['New claim reported', 'A customer has reported an issue and the claim is awaiting review.'],
            'claim_approved' => ['Claim approved', 'The claim has been approved. Any payout is placed on a short hold before release.'],
            'claim_rejected' => ['Claim rejected', 'The claim was reviewed and rejected.'],
            'claim_paid' => ['Claim paid', 'The payout has been released.'],
            'claim_reversed' => ['Claim payout reversed', 'The payout was reversed after review.'],
            'claim_message' => ['New claim message', 'A new message was added to the claim thread.'],
            'claim_sla_escalated' => ['Claim SLA overdue', 'This claim has passed its decision SLA and needs attention.'],
        ];
        [$heading, $defaultLine] = $statusMap[$template] ?? ['Claim update', 'There is an update on your claim.'];
        $content = $greeting . '<div style="display:inline-block;padding:7px 12px;border-radius:999px;background:#e4fbdc;color:#2f4d1f;font-size:12px;font-weight:700;margin-bottom:14px;">' . email_e($heading) . '</div>' .
            ($ref !== '' ? email_info_card('Claim reference', $ref) : '') .
            ($tracking !== '' ? email_info_card('Parcel', $tracking) : '') .
            ($amount !== '' ? email_info_card('Payout', $amount) : '');
        $content .= '<p style="margin:0 0 12px;font-size:14px;line-height:1.65;color:#46523a;">' . email_e($note !== '' ? $note : $defaultLine) . '</p>';
        return email_shell($heading . ($ref !== '' ? ' · ' . $ref : ''), $heading, $content, $base . '/claims', 'Open claims');
    }

    if ($template === 'invoice_paid') {
        $content = $greeting . '<p style="margin:0 0 14px;font-size:14px;line-height:1.65;color:#46523a;">We received your subscription payment. Your workspace is active.</p>' .
            (($data['invoice'] ?? '') !== '' ? email_info_card('Invoice', (string) $data['invoice']) : '') .
            ($amount !== '' ? email_info_card('Amount paid', $amount) : '') .
            (($data['reference'] ?? '') !== '' ? email_info_card('Receipt', (string) $data['reference']) : '');
        return email_shell('Payment received for your Mzigo.online subscription', 'Subscription payment received', $content, $base . '/billing', 'Open billing');
    }

    if ($template === 'team_invite') {
        $content = $greeting . '<p style="margin:0 0 14px;font-size:14px;line-height:1.65;color:#46523a;">You have been invited to join the <strong>' . email_e((string) ($data['business'] ?? 'Mzigo.online')) . '</strong> workspace.</p>' .
            email_info_card('Email', (string) ($data['email'] ?? ($user['email'] ?? ''))) .
            (($data['password'] ?? '') !== '' ? email_info_card('Temporary password', (string) $data['password']) : '') .
            '<p style="margin:0;font-size:13px;color:#78866a;">Sign in and change your password from Settings.</p>';
        return email_shell('You are invited to Mzigo.online', 'Welcome to the team', $content, $base . '/login', 'Sign in');
    }

    if ($template === 'smtp_test') {
        $content = $greeting . '<p style="margin:0 0 14px;font-size:14px;line-height:1.65;color:#46523a;">This is a test email from your Mzigo.online SMTP configuration. Delivery is working.</p>' .
            email_info_card('Sent at', date('d M Y, H:i')) . email_info_card('Recipient', (string) ($user['email'] ?? ''));
        return email_shell('Mzigo.online SMTP test', 'SMTP test successful', $content, $base . '/admin#communications', 'Open communications');
    }

    if ($template === 'marketing_campaign') {
        $artwork = (string) ($data['artwork_url'] ?? '');
        $ctaUrl = (string) ($data['cta_url'] ?? '');
        $ctaLabel = (string) ($data['cta_label'] ?? '');
        $unsub = (string) ($data['unsubscribe_url'] ?? '');
        $text = preg_replace('/\n\nUnsubscribe:.*$/s', '', $body) ?? $body;
        $content = $greeting .
            ($artwork !== '' ? '<img src="' . email_e($artwork) . '" alt="Mzigo.online" style="display:block;width:100%;max-width:520px;height:auto;border-radius:12px;margin:0 0 16px;">' : '') .
            email_paragraphs($text);
        return email_shell($subject, $subject, $content, $ctaUrl ?: null, $ctaLabel ?: null, ['marketing' => true, 'unsubscribe_url' => $unsub]);
    }

    // Generic fallback: keep the plain-text content but wrap it in the brand shell.
    $content = $greeting . email_paragraphs($body) . ($note !== '' ? email_paragraphs($note) : '');
    $cta = email_first_url($body);
    return email_shell($subject, $subject, $content, $cta, $cta ? 'Open link' : null);
}
