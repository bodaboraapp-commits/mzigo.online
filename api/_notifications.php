<?php
/**
 * Mzigo.online — outbound notification service.
 *
 * When a business registers an order, the receiver's phone is looked up:
 *  • existing user   → send tracking via their preferred (active) channel(s)
 *  • unknown number  → create a customer account, then send tracking + sign-in note
 *
 * Channels are enabled/disabled by the platform admin (communication_channels),
 * and each row stores the provider + credentials configured in the admin
 * console (Communications → Manage). Customers choose "all available" or one
 * channel (users.comm_channel). All channels are active by default. Delivery
 * uses the log provider in demo mode; the row in `notifications` is the audit
 * record and the SMS/WhatsApp/email seam.
 */
declare(strict_types=1);
require_once __DIR__ . '/_insurance.php';
require_once __DIR__ . '/_mailer.php';
require_once __DIR__ . '/_email_templates.php';
require_once __DIR__ . '/_crypto.php';
require_once __DIR__ . '/_whatsapp_outbound.php';
require_once __DIR__ . '/_sms.php';
require_once __DIR__ . '/_template_store.php';
require_once __DIR__ . '/_notification_prefs.php';

/**
 * Notification channels, ordered for display.
 *
 * `$withConfig` is admin-only: the config JSON may hold provider credentials,
 * so customer-facing endpoints must keep it out of the payload.
 */
function notification_channels(bool $activeOnly = false, bool $withConfig = false): array
{
    $sql = 'SELECT code, label, provider, sender, is_active, sort_order'
        . ($withConfig ? ', config' : '')
        . ' FROM communication_channels'
        . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, code';
    $rows = db()->query($sql)->fetchAll();
    foreach ($rows as &$row) {
        $row['is_active'] = (bool) (int) $row['is_active'];
        if ($withConfig) {
            $decoded = channel_config_decode($row['config'] ?? null);
            $row['config'] = $decoded
                ? channel_config_mask($decoded, (string) ($row['provider'] ?? 'log'))
                : null;
            $row['config_present'] = $decoded !== [];
        }
    }
    return $rows;
}

/**
 * Catalog of messaging providers the admin console can configure.
 *
 * Drives the channel form: each provider lists the real settings it needs
 * (labels + example placeholders). Placeholders lean on platform config where
 * it exists (mail from-address, SMTP host/port, base URL) so admins see the
 * platform's own values as examples.
 */
function channel_provider_catalog(): array
{
    $mail = config()['mail'] ?? [];
    $smtp = $mail['smtp'] ?? [];
    $from = (string) ($mail['from'] ?? 'no-reply@mzigo.online');
    $base = rtrim((string) (config()['app']['base_url'] ?? 'https://api.mzigo.online'), '/');
    $smtpHost = (string) ($smtp['host'] ?? '') !== '' ? (string) $smtp['host'] : 'smtp.mzigo.online';
    $smtpPort = (string) (int) ($smtp['port'] ?? 587);
    $smtpUser = (string) ($smtp['user'] ?? '') !== '' ? (string) $smtp['user'] : $from;

    return [
        'log' => [
            'label'  => 'Demo log (records only)',
            'group'  => 'generic',
            'hint'   => 'Writes each message to the notifications table and _server/logs — nothing leaves the platform.',
            'fields' => [],
        ],

        'africas-talking' => [
            'label' => "Africa's Talking",
            'group' => 'sms',
            'hint'  => 'Kenyan SMS gateway. The sender ID/shortcode must be approved on your Africa\'s Talking account.',
            'fields' => [
                ['key' => 'username', 'label' => 'Account username', 'placeholder' => 'mzigo', 'required' => true],
                ['key' => 'api_key', 'label' => 'API key', 'placeholder' => 'atsk_8f2c4a9b…', 'type' => 'password', 'required' => true],
                ['key' => 'sender_id', 'label' => 'Sender ID / shortcode', 'placeholder' => 'MZIGO', 'required' => true],
                ['key' => 'sandbox', 'label' => 'Sandbox mode', 'placeholder' => '0 for live, 1 for sandbox'],
            ],
        ],
        'infobip' => [
            'label' => 'Infobip (SMS)',
            'group' => 'sms',
            'hint'  => 'Infobip SMS API. Copy the base URL from the Infobip portal, paste an API key and use an approved sender ID in the Sender field.',
            'fields' => [
                ['key' => 'base_url', 'label' => 'Infobip base URL', 'placeholder' => 'https://xxxxx.api.infobip.com', 'required' => true],
                ['key' => 'api_key', 'label' => 'API key', 'placeholder' => 'your Infobip API key', 'type' => 'password', 'required' => true],
                ['key' => 'sender_id', 'label' => 'Sender ID (optional)', 'placeholder' => 'MZIGO', 'hint' => 'Used only if the channel Sender field is empty.'],
            ],
        ],

        'twilio-sms' => [
            'label' => 'Twilio (SMS)',
            'group' => 'sms',
            'hint'  => 'Global SMS API. Use an E.164 from number, or route through a Messaging Service.',
            'fields' => [
                ['key' => 'account_sid', 'label' => 'Account SID', 'placeholder' => 'AC1a2b3c4d5e6f7g8h9i…', 'required' => true],
                ['key' => 'auth_token', 'label' => 'Auth token', 'placeholder' => 'your Twilio auth token', 'type' => 'password', 'required' => true],
                ['key' => 'from_number', 'label' => 'From number', 'placeholder' => '+254 712 345 678', 'required' => true],
                ['key' => 'messaging_service_sid', 'label' => 'Messaging Service SID (optional)', 'placeholder' => 'MG9f8e7d6c5b4a…'],
                ['key' => 'verify_service_sid', 'label' => 'Verify Service SID for login OTP (recommended for Kenya)', 'placeholder' => 'VA1a2b3c4d…', 'hint' => 'Create a Twilio Verify Service and set its code length to 4 digits. Login OTPs will use Twilio Verify instead of the long code.'],
            ],
        ],

        'meta-whatsapp' => [
            'label' => 'WhatsApp Cloud API (Meta)',
            'group' => 'whatsapp',
            'hint'  => 'Meta WhatsApp Business Cloud API. Access tokens expire — rotate them here.',
            'fields' => [
                ['key' => 'waba_id', 'label' => 'WhatsApp Business Account ID', 'placeholder' => '102290129340398', 'required' => true],
                ['key' => 'phone_number_id', 'label' => 'Phone number ID', 'placeholder' => '123456789012345', 'required' => true],
                ['key' => 'access_token', 'label' => 'Permanent access token', 'placeholder' => 'EAAG…', 'type' => 'password', 'required' => true],
                ['key' => 'verify_token', 'label' => 'Webhook verify token', 'placeholder' => 'mzigo-wa-verify'],
            ],
        ],
        'twilio-whatsapp' => [
            'label' => 'Twilio (WhatsApp)',
            'group' => 'whatsapp',
            'hint'  => 'Twilio WhatsApp sender — the from value is prefixed with whatsapp:.',
            'fields' => [
                ['key' => 'account_sid', 'label' => 'Account SID', 'placeholder' => 'AC1a2b3c4d5e6f7g8h9i…', 'required' => true],
                ['key' => 'auth_token', 'label' => 'Auth token', 'placeholder' => 'your Twilio auth token', 'type' => 'password', 'required' => true],
                ['key' => 'from_number', 'label' => 'WhatsApp sender', 'placeholder' => 'whatsapp:+14155238886', 'required' => true],
            ],
        ],

        'mail' => [
            'label' => 'Server mail (PHP mail)',
            'group' => 'email',
            'hint' => 'Uses the cPanel/local mail transport. This avoids sending through a remote SMTP connection from the web server and is often more reliable for production OTP delivery.',
            'fields' => [],
        ],

        'smtp' => [
            'label' => 'SMTP mailbox',
            'group' => 'email',
            'hint'  => 'Any SMTP mailbox or relay — Zoho, Google Workspace, Postmark, Mailtrap…',
            'fields' => [
                ['key' => 'host', 'label' => 'SMTP host', 'placeholder' => $smtpHost, 'required' => true],
                ['key' => 'port', 'label' => 'Port', 'placeholder' => $smtpPort, 'required' => true],
                ['key' => 'username', 'label' => 'Username', 'placeholder' => $smtpUser, 'required' => true],
                ['key' => 'password', 'label' => 'Password / app password', 'placeholder' => 'your SMTP password', 'type' => 'password', 'required' => true],
                ['key' => 'encryption', 'label' => 'Encryption', 'placeholder' => 'tls on port 587, ssl on port 465'],
            ],
        ],
        'sendgrid' => [
            'label' => 'SendGrid',
            'group' => 'email',
            'hint'  => 'SendGrid Web API v3. Verify the sender domain, then paste the Event Webhook public key here. Event webhook URL: ' . $base . '/api/email-events.php?provider=sendgrid',
            'fields' => [
                ['key' => 'api_key', 'label' => 'API key', 'placeholder' => 'SG.xxxxxxxx.yyyyyyyy', 'type' => 'password', 'required' => true],
                ['key' => 'event_webhook_public_key', 'label' => 'Event webhook public key (PEM)', 'placeholder' => '-----BEGIN PUBLIC KEY-----…', 'type' => 'textarea', 'hint' => 'Used to verify SendGrid Event Webhook ECDSA signatures.'],
            ],
        ],
        'mailgun' => [
            'label' => 'Mailgun',
            'group' => 'email',
            'hint'  => 'Mailgun API — the sending domain must be verified, and the webhook signing key must match the Mailgun webhook. Event webhook URL: ' . $base . '/api/email-events.php?provider=mailgun',
            'fields' => [
                ['key' => 'api_key', 'label' => 'API key', 'placeholder' => 'key-3a4b5c6d7e8f…', 'type' => 'password', 'required' => true],
                ['key' => 'domain', 'label' => 'Sending domain', 'placeholder' => 'mg.mzigo.online', 'required' => true],
                ['key' => 'region', 'label' => 'Region', 'placeholder' => 'us or eu'],
                ['key' => 'webhook_signing_key', 'label' => 'Webhook signing key', 'placeholder' => 'Mailgun HTTP webhook signing key', 'type' => 'password', 'hint' => 'Verifies timestamp+token signatures on Mailgun bounce/complaint events.'],
            ],
        ],

        'webhook' => [
            'label' => 'Custom webhook',
            'group' => 'generic',
            'hint'  => 'POSTs the notification JSON to your own endpoint — a push relay, Slack bridge or automation.',
            'fields' => [
                ['key' => 'url', 'label' => 'Endpoint URL', 'placeholder' => $base . '/hooks/notifications', 'required' => true],
                ['key' => 'secret', 'label' => 'Signing secret', 'placeholder' => 'whsec_…', 'type' => 'password'],
            ],
        ],
    ];
}

/** Placeholder + hint for the sender/from field, keyed by channel code. */
function channel_sender_hints(): array
{
    $from = (string) (config()['mail']['from'] ?? 'no-reply@mzigo.online');
    return [
        'sms'      => ['placeholder' => 'MZIGO', 'hint' => 'Alphanumeric sender ID approved by your SMS provider (11 characters max).'],
        'whatsapp' => ['placeholder' => '+254 712 345 678', 'hint' => 'WhatsApp Business number in E.164 format.'],
        'email'    => ['placeholder' => $from, 'hint' => 'From address on a domain verified with your email provider.'],
    ];
}

function notification_user_by_phone(string $phone): ?array
{
    if (trim($phone) === '') return null;
    $stmt = db()->prepare(
        'SELECT id, name, email, phone, role, status, comm_channel FROM users
         WHERE RIGHT(REGEXP_REPLACE(phone, "[^0-9]", ""), 9) = ? AND status = "active" LIMIT 1'
    );
    $stmt->execute([phone_key($phone)]);
    return $stmt->fetch() ?: null;
}

function notification_customer_for(string $receiverPhone, string $receiverName): array
{
    $key = phone_key($receiverPhone);
    $existing = notification_user_by_phone($receiverPhone);
    if ($existing) {
        return ['user' => $existing, 'created' => false];
    }
    $stmt = db()->prepare('INSERT INTO users (name, phone, role, status) VALUES (?, ?, "customer", "active")');
    $stmt->execute([$receiverName !== '' ? $receiverName : 'Customer ' . substr($key, -4), $receiverPhone]);
    $id = (int) db()->lastInsertId();
    return ['user' => ['id' => $id, 'name' => $receiverName, 'email' => null, 'phone' => $receiverPhone,
        'role' => 'customer', 'status' => 'active', 'comm_channel' => null], 'created' => true];
}

function notification_targets(array $user): array
{
    $active = notification_channels(true);
    if (!$active) {
        return [];
    }
    $preferred = (string) ($user['comm_channel'] ?? '');
    if ($preferred !== '' && $preferred !== 'all') {
        return array_values(array_filter($active, fn ($c) => $c['code'] === $preferred));
    }
    return $active;
}

/** Receiver order updates always cover phone and email when both are active. */
function receiver_notification_targets(array $user): array
{
    $active = notification_channels(true);
    return array_values(array_filter($active, fn ($c) => in_array($c['code'], ['sms', 'email'], true)));
}

function notification_send(string $channel, array $user, string $subject, string $body, string $template, array $parcel, string $provider = 'log',
    ?string $relatedType = null, ?string $relatedId = null, array $data = [], bool $force = false): array
{
    $recipient = $channel === 'email' ? (string) ($user['email'] ?? '') : (string) ($user['phone'] ?? '');
    $status = 'sent';
    $error = null;
    $prefBlocked = !$force && $channel === 'email' && $recipient !== ''
        && !notification_preference_allows((int) ($user['id'] ?? 0), $template);
    if ($recipient === '') {
        $status = 'skipped';
        $error = $channel === 'email' ? 'No email address on file.' : 'No phone number on file.';
    } elseif ($prefBlocked) {
        $status = 'skipped';
        $error = 'Email notifications are disabled in the customer preferences.';
    } elseif ($channel === 'email') {
        $override = email_template_override($template);
        $overrideHtml = null;
        if ($override) {
            if (trim((string) $override['subject']) !== '') {
                $subject = email_template_apply((string) $override['subject'], $user, $parcel, $data);
            }
            if (trim((string) $override['body_text']) !== '') {
                $body = email_template_apply((string) $override['body_text'], $user, $parcel, $data);
            }
            if (trim((string) $override['body_html']) !== '') {
                $overrideHtml = email_template_apply((string) $override['body_html'], $user, $parcel, $data);
            }
        }
        if ($overrideHtml) {
            $looksFull = preg_match('/<!doctype|<html[\s>]/i', $overrideHtml);
            $html = $looksFull ? $overrideHtml : email_shell($subject, $subject, $overrideHtml);
        } else {
            $html = email_render($template, $user, $subject, $body, $parcel, $data);
        }
        $mail = mzigo_mail_send($recipient, $subject, $body, $html);
        $provider = (string) $mail['provider'];
        $status = $mail['sent'] ? 'sent' : (($mail['skipped'] ?? false) ? 'skipped' : 'failed');
        $error = $mail['error'];
    } elseif ($channel === 'sms') {
        $sms = sms_send($recipient, $body);
        $provider = (string) ($sms['provider'] ?? 'log');
        $status = !empty($sms['ok']) ? 'sent' : (!empty($sms['skipped']) ? 'skipped' : 'failed');
        $error = (string) ($sms['error'] ?? '');
    }
    $stmt = db()->prepare(
        'INSERT INTO notifications (channel, recipient_user_id, recipient, subject, body, template,
          related_type, related_id, status, provider, error, created_at, sent_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
    );
    $stmt->execute([
        $channel, (int) $user['id'], $recipient !== '' ? $recipient : null, $subject, $body, $template,
        $relatedType !== null ? $relatedType : 'parcel',
        $relatedId !== null ? $relatedId : (string) ($parcel['id'] ?? ''),
        $status, $provider, $error,
    ]);
    $logDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . '_server' . DIRECTORY_SEPARATOR . 'logs';
    if (is_dir($logDir) && is_writable($logDir)) {
        @file_put_contents($logDir . DIRECTORY_SEPARATOR . 'notifications.log',
            json_encode(['at' => date('c'), 'channel' => $channel, 'to' => $recipient, 'status' => $status,
                'template' => $template, 'parcel' => $parcel['tracking'] ?? null, 'subject' => $subject]) . "\n",
            FILE_APPEND);
    }
    return ['status' => $status, 'provider' => $provider, 'error' => $error];
}


/** Owners/admins of a workspace who should receive claim notifications. */
function claim_tenant_recipients(int $tenantId): array
{
    $stmt = db()->prepare(
        'SELECT u.id, u.name, u.email, u.phone, u.role, u.status, u.comm_channel
         FROM tenant_members m JOIN users u ON u.id = m.user_id
         WHERE m.tenant_id = ? AND m.role IN ("owner", "admin") AND u.status = "active"'
    );
    $stmt->execute([$tenantId]);
    return $stmt->fetchAll();
}

/** Send a claim notification to a set of users across their enabled channels. */
function notify_claim_event(array $claim, array $parcel, string $template, string $subject, string $body, array $recipients, array $data = []): array
{
    $attempts = [];
    foreach ($recipients as $recipient) {
        $targets = notification_targets($recipient);
        if (!$targets) {
            /* Keep an auditable in-app claim notification even when external channels are disabled. */
            $targets = [['code' => 'in_app', 'provider' => 'internal']];
        }
        foreach ($targets as $channel) {
            notification_send(
                $channel['code'], $recipient, $subject, $body, $template, $parcel,
                (string) ($channel['provider'] ?? 'log'), 'claim', (string) ($claim['id'] ?? ''), $data
            );
            $attempts[] = ['user_id' => (int) $recipient['id'], 'channel' => $channel['code']];
        }
    }
    if ($attempts) {
        audit('notification.claim_event', 'claim', (string) ($claim['id'] ?? ''), [
            'template' => $template, 'channels' => $attempts,
        ]);
    }
    return $attempts;
}

/** Tell the workspace that a customer/receiver filed a claim. */
function notify_claim_reported(array $claim, array $parcel, int $tenantId): array
{
    $recipients = claim_tenant_recipients($tenantId);
    if (!$recipients) {
        return [];
    }
    $subject = 'New customer claim ' . ($claim['ref'] ?? '') . ' for ' . ($parcel['tracking'] ?? 'parcel');
    $body = implode("\n", [
        'A customer has reported an issue with parcel ' . ($parcel['tracking'] ?? '') . '.',
        'Claim: ' . ($claim['ref'] ?? '') . ' (' . ($claim['type'] ?? '') . ')',
        'Description: ' . mb_substr((string) ($claim['description'] ?? ''), 0, 300),
        'Open Claims in the merchant dashboard to review and respond.',
    ]);
    return notify_claim_event($claim, $parcel, 'claim_reported', $subject, $body, $recipients, [
        'ref' => $claim['ref'] ?? '', 'tracking' => $parcel['tracking'] ?? '',
        'note' => $claim['description'] ?? '',
    ]);
}

/** Notify the customer claimant (and workspace owners) when a claim changes status. */
function notify_claim_status(array $claim, array $parcel, string $status, string $note = ''): array
{
    $recipients = [];
    if (!empty($claim['user_id'])) {
        $stmt = db()->prepare('SELECT id, name, email, phone, role, status, comm_channel FROM users WHERE id = ? AND role = "customer" LIMIT 1');
        $stmt->execute([(int) $claim['user_id']]);
        $customer = $stmt->fetch();
        if ($customer && $customer['status'] === 'active') {
            $recipients[] = $customer;
        }
    }
    if (!empty($claim['tenant_id']) && in_array($status, ['approved', 'rejected', 'paid', 'reversed'], true)) {
        $recipients = array_merge($recipients, claim_tenant_recipients((int) $claim['tenant_id']));
    }
    if (!$recipients) {
        return [];
    }
    $labels = [
        'approved' => 'approved — payout is on hold',
        'rejected' => 'rejected',
        'paid' => 'paid',
        'reversed' => 'reversed',
    ];
    $subject = 'Claim ' . ($claim['ref'] ?? '') . ' ' . ($labels[$status] ?? $status);
    $body = implode("\n", [
        'Your Mzigo claim ' . ($claim['ref'] ?? '') . ' for parcel ' . ($parcel['tracking'] ?? '') . ' was ' . ($labels[$status] ?? $status) . '.',
        $note !== '' ? 'Note: ' . $note : '',
        'Open your account to see the full claim timeline.',
    ]);
    return notify_claim_event($claim, $parcel, 'claim_' . $status, $subject, trim($body), $recipients, [
        'ref' => $claim['ref'] ?? '', 'tracking' => $parcel['tracking'] ?? '',
        'note' => $note, 'status_label' => $labels[$status] ?? $status,
    ]);
}


/** Notify the other claim participants about a new message in the thread. */
function notify_claim_message(array $claim, array $parcel, array $sender, string $senderRole, string $message): array
{
    $recipients = [];
    if ($senderRole !== 'customer' && !empty($claim['user_id'])) {
        $stmt = db()->prepare('SELECT id, name, email, phone, role, status, comm_channel FROM users WHERE id = ? AND role = "customer" AND status = "active" LIMIT 1');
        $stmt->execute([(int) $claim['user_id']]);
        $customer = $stmt->fetch();
        if ($customer) { $recipients[] = $customer; }
    }
    if ($senderRole !== 'merchant' && !empty($claim['tenant_id'])) {
        $recipients = array_merge($recipients, claim_tenant_recipients((int) $claim['tenant_id']));
    }
    $seen = [];
    $recipients = array_values(array_filter($recipients, function ($r) use ($sender, &$seen) {
        $id = (int) ($r['id'] ?? 0);
        if ($id === 0 || $id === (int) ($sender['id'] ?? 0) || isset($seen[$id])) { return false; }
        $seen[$id] = true;
        return true;
    }));
    if (!$recipients) { return []; }
    $subject = 'New message on claim ' . ($claim['ref'] ?? '');
    $body = ($sender['name'] ?? 'A participant') . ' (' . $senderRole . ') wrote: ' . mb_substr($message, 0, 500);
    return notify_claim_event($claim, $parcel, 'claim_message', $subject, $body, $recipients, [
        'ref' => $claim['ref'] ?? '', 'tracking' => $parcel['tracking'] ?? '', 'note' => $message,
    ]);
}


/** Send a branded transactional email to the receiver (if they have an email). */
function notify_customer_in_app(array $parcel, array $user, string $subject, string $body, string $template): void
{
    if (empty($user['id'])) { return; }
    try {
        $stmt = db()->prepare(
            'INSERT INTO notifications
               (channel, recipient_user_id, recipient, subject, body, template, related_type, related_id, status, provider, created_at)
             VALUES (\'in_app\', ?, ?, ?, ?, ?, \'parcel\', ?, \'sent\', \'internal\', NOW())'
        );
        $stmt->execute([
            (int) $user['id'],
            (string) ($user['phone'] ?? $user['email'] ?? ''),
            $subject,
            $body,
            $template,
            (string) ($parcel['id'] ?? ''),
        ]);
    } catch (Throwable $e) {
        error_log('customer in-app notification failed: ' . $e->getMessage());
    }
}

function notify_customer_email(array $parcel, string $template, string $subject, string $body, array $data = []): array
{
    $phone = (string) ($parcel['receiver_phone'] ?? '');
    if ($phone === '') { return []; }
    $customer = notification_customer_for($phone, (string) ($parcel['receiver_name'] ?? 'Customer'));
    $user = $customer['user'];
    notify_customer_in_app($parcel, $user, $subject, $body, $template);
    $targets = receiver_notification_targets($user);
    $hasEmail = !empty($user['email']);
    $sent = 0;

    foreach ($targets as $channel) {
        if ($channel['code'] !== 'email') { continue; }
        if (!$hasEmail) { continue; }
        notification_send('email', $user, $subject, $body, $template, $parcel,
            (string) ($channel['provider'] ?? 'log'), 'parcel', (string) ($parcel['id'] ?? ''), $data, true);
        $sent++;
    }

    /* Customers sign in by phone and may not have an email yet: send the same
       journey email as a business copy to the workspace owner so updates are not lost. */
    if (!$hasEmail) {
        $owners = claim_tenant_recipients((int) ($parcel['tenant_id'] ?? 0));
        foreach ($owners as $owner) {
            if (empty($owner['email'])) { continue; }
            $copyBody = "Business copy — the customer has no email on file.\n" . $body;
            notification_send('email', $owner, $subject, $copyBody, $template, $parcel, 'log', 'parcel', (string) ($parcel['id'] ?? ''), $data, true);
            $sent++;
        }
    }

    /* CU-01: proactive SMS/WhatsApp (or any other active channel) for the
       customer, using the same opt-in/target resolution as parcel creation. */
    foreach ($targets as $channel) {
        if ($channel['code'] === 'email') { continue; }
        notification_send($channel['code'], $user, $subject, $body, $template, $parcel,
            (string) ($channel['provider'] ?? 'log'), 'parcel', (string) ($parcel['id'] ?? ''), $data, true);
        $sent++;
    }

    return $sent ? ['sent' => $sent, 'channels' => array_map(fn ($c) => $c['code'], $targets)] : [];
}


/**
 * Email the person who created the order (and the workspace owner as a
 * fallback when the creator has no email). Receiver notifications are handled
 * separately.
 */
function notify_order_creator(array $parcel, string $template, string $subject, string $body, array $data = [], int $excludeUserId = 0): array
{
    $creatorId = (int) ($parcel['user_id'] ?? 0);
    $tenantId = (int) ($parcel['tenant_id'] ?? 0);
    $sent = 0;
    $tried = [];
    $seen = [];
    $sendTo = function (array $user, int $userId) use ($parcel, $template, $subject, $body, $data, &$sent, &$tried) {
        $tried[] = $userId;
        foreach (receiver_notification_targets($user) as $channel) {
            $result = notification_send($channel['code'], $user, $subject, $body, $template, $parcel,
                (string) ($channel['provider'] ?? 'log'), 'parcel', (string) ($parcel['id'] ?? ''), $data, true);
            if (($result['status'] ?? '') === 'sent') $sent++;
        }
    };
    $creator = null;
    if ($creatorId > 0) {
        $stmt = db()->prepare('SELECT id, name, email, phone, role, status, comm_channel FROM users WHERE id = ? AND status = "active" LIMIT 1');
        $stmt->execute([$creatorId]);
        $creator = $stmt->fetch() ?: null;
    }
    if (!$creator && (string) ($parcel['sender_phone'] ?? '') !== '') {
        // Public/walk-in orders have no user_id; match the sender phone to the
        // merchant/customer account so the order creator still gets alerts.
        $creator = notification_user_by_phone((string) $parcel['sender_phone']);
        if ($creator) $creatorId = (int) $creator['id'];
    }
    if ($creator && $creatorId > 0 && $creatorId !== $excludeUserId) {
        $seen[$creatorId] = true;
        $sendTo($creator, $creatorId);
    }
    if ($tenantId > 0) {
        foreach (claim_tenant_recipients($tenantId) as $owner) {
            $ownerId = (int) ($owner['id'] ?? 0);
            if ($ownerId <= 0 || $ownerId === $excludeUserId || isset($seen[$ownerId])) continue;
            $seen[$ownerId] = true;
            $sendTo($owner, $ownerId);
        }
    }
    return ['sent' => $sent, 'recipients' => $tried];
}

function notify_customer_payment(array $parcel, string $reference): array
{
    $tracking = (string) ($parcel['tracking'] ?? '');
    $body = implode("\n", [
        'Hi ' . ($parcel['receiver_name'] ?? 'there') . ',',
        'We received your delivery payment for ' . $tracking . '.',
        'Amount: KES ' . (int) ($parcel['fee_total'] ?? 0),
        'Receipt: ' . $reference,
        'Collection code: ' . ($parcel['collect_code'] ?? ''),
        'Show the collection code at the destination office to collect your parcel.',
    ]);
    $result = notify_customer_email($parcel, 'payment_receipt', 'Payment received for ' . $tracking, $body, [
        'tracking' => $tracking, 'amount' => (int) ($parcel['fee_total'] ?? 0),
        'reference' => $reference, 'collect_code' => (string) ($parcel['collect_code'] ?? ''),
    ]);
    $creatorBody = implode("\n", [
        'Hi ' . ($parcel['sender_name'] ?? 'there') . ',',
        'Payment received for order ' . $tracking . '.',
        'Amount: KES ' . (int) ($parcel['fee_total'] ?? 0),
        'Receipt: ' . $reference,
        'The customer/receiver has paid the delivery fee.',
    ]);
    $receiver = notification_customer_for((string) ($parcel['receiver_phone'] ?? ''), (string) ($parcel['receiver_name'] ?? 'Customer'));
    notify_order_creator($parcel, 'payment_receipt', 'Payment received for ' . $tracking, $creatorBody, [
        'tracking' => $tracking, 'amount' => (int) ($parcel['fee_total'] ?? 0),
        'reference' => $reference, 'collect_code' => (string) ($parcel['collect_code'] ?? ''),
    ], (int) ($receiver['user']['id'] ?? 0));
    try { wa_notify_parcel_event('payment_received', $parcel); } catch (Throwable $e) { error_log('WhatsApp payment template failed: ' . $e->getMessage()); }
    return $result;
}

function notify_customer_status(array $parcel, string $template, string $label, string $note = ''): array
{
    $tracking = (string) ($parcel['tracking'] ?? '');
    $body = implode("\n", array_filter([
        'Hi ' . ($parcel['receiver_name'] ?? 'there') . ',',
        'Parcel ' . $tracking . ' is now: ' . $label . '.',
        $note,
        'Track your parcel any time from your Mzigo.online account.',
    ]));
    $result = notify_customer_email($parcel, $template, 'Parcel ' . $tracking . ' · ' . $label, $body, [
        'tracking' => $tracking, 'status_label' => $label, 'note' => $note,
        'amount' => (int) ($parcel['fee_total'] ?? 0),
    ]);
    $receiver = notification_customer_for((string) ($parcel['receiver_phone'] ?? ''), (string) ($parcel['receiver_name'] ?? 'Customer'));
    $creatorBody = implode("\n", array_filter([
        'Hi ' . ($parcel['sender_name'] ?? 'there') . ',',
        'Order ' . $tracking . ' for ' . ($parcel['receiver_name'] ?? 'the receiver') . ' is now: ' . $label . '.',
        $note,
        'Track: ' . rtrim((string) (config()['app']['base_url'] ?? ''), '/') . '/track-mzigo?num=' . rawurlencode($tracking),
    ]));
    notify_order_creator($parcel, $template, 'Order ' . $tracking . ' · ' . $label, $creatorBody,
        ['tracking' => $tracking, 'status_label' => $label, 'note' => $note],
        (int) ($receiver['user']['id'] ?? 0));
    try { wa_notify_from_status($parcel, $template); } catch (Throwable $e) { error_log('WhatsApp status template failed: ' . $e->getMessage()); }
    return $result;
}

function notify_invoice_paid(array $invoice, array $user): array
{
    if (empty($user['email'])) { return []; }
    $body = implode("\n", [
        'Hi ' . ($user['name'] ?? 'there') . ',',
        'We received your Mzigo.online subscription payment.',
        'Invoice: ' . ($invoice['number'] ?? ''),
        'Amount: KES ' . (int) ($invoice['amount_kes'] ?? 0),
        'Your workspace remains active.',
    ]);
    notification_send('email', $user, 'Subscription payment received', $body, 'invoice_paid',
        ['id' => 0, 'tracking' => ''], 'log', 'invoice', (string) ($invoice['id'] ?? ''), [
            'invoice' => (string) ($invoice['number'] ?? ''), 'amount' => (int) ($invoice['amount_kes'] ?? 0),
            'reference' => (string) ($invoice['payment_reference'] ?? ''),
        ]);
    return ['sent' => 1];
}

function notify_team_invite(string $name, string $email, string $business, string $password): array
{
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { return []; }
    $user = ['id' => 0, 'name' => $name, 'email' => $email, 'phone' => '', 'comm_channel' => null];
    $body = implode("\n", [
        'Hi ' . $name . ',',
        'You have been invited to the ' . $business . ' workspace on Mzigo.online.',
        'Email: ' . $email,
        'Temporary password: ' . $password,
        'Sign in and change your password from Settings.',
    ]);
    notification_send('email', $user, 'You are invited to ' . $business . ' on Mzigo.online', $body, 'team_invite',
        ['id' => 0, 'tracking' => ''], 'log', 'user', '', ['business' => $business, 'email' => $email, 'password' => $password]);
    return ['sent' => 1];
}

/**
 * Notify the receiver after a parcel is created. Returns a summary of the
 * notification attempts so API consumers/ops can see what happened.
 */
function notify_parcel_created(array $parcel): array
{
    $phone = (string) ($parcel['receiver_phone'] ?? '');
    if ($phone === '') {
        return ['sent' => 0, 'created_account' => false, 'channels' => []];
    }
    $customer = notification_customer_for($phone, (string) ($parcel['receiver_name'] ?? 'Customer'));
    $user = $customer['user'];
    $base = rtrim((string) (config()['app']['base_url'] ?? 'http://localhost:8090'), '/');
    $tracking = (string) $parcel['tracking'];
    $trackUrl = $base . '/track-mzigo?num=' . rawurlencode($tracking);
    $signInUrl = $base . '/login';
    $subject = 'Parcel registered: ' . $tracking;
    $insuranceRef = function_exists('insurance_reference_for_parcel')
        ? insurance_reference_for_parcel($parcel)
        : parcel_insurance_ref($tracking);
    $lines = [
        'Hi ' . ($user['name'] ?? 'there') . ',',
        'A parcel has been registered with Mzigo.online for delivery to ' . ($parcel['to_city'] ?? 'you') . '.',
        'Status: registered. The sender still needs to drop it off at the courier office; tracking updates begin after drop-off.',
        'Courier: ' . ($parcel['carrier'] ?? 'Mzigo partner') . '.',
        'Sender: ' . ($parcel['sender_name'] ?? 'the sender') . '.',
        'Tracking: ' . $tracking,
        'Parcel size: ' . ($parcel['size'] ?? '—') . '.',
        'Declared parcel value: KES ' . (int) ($parcel['value_kes'] ?? 0) . '.',
        'Insurance cover: KES ' . (int) ($parcel['value_kes'] ?? 0) . ' (' . ($insuranceRef === 'PENDING' ? 'policy pending' : $insuranceRef) . (str_starts_with($insuranceRef, 'DEMO-POL-') ? ' — demo policy, not real cover' : '') . ').',
        'Insurance fee: KES ' . (int) ($parcel['fee_insurance'] ?? 0) . '.',
        'Total delivery cost: KES ' . (int) ($parcel['fee_total'] ?? 0) . '.',
        'Insurance terms & conditions: ' . $base . '/terms#risk-and-cover',
        $customer['created'] ? 'We created a customer account for this phone. Sign in at ' . $signInUrl . ' to track and pay.' : 'Track and pay at ' . $signInUrl . '.',
        'Track: ' . $trackUrl,
    ];
    $body = implode("\n", $lines);
    $template = $customer['created'] ? 'parcel_created_new_customer' : 'parcel_created';
    $attempts = [];
    foreach (receiver_notification_targets($user) as $channel) {
        notification_send($channel['code'], $user, $subject, $body, $template, $parcel,
            (string) ($channel['provider'] ?? 'log'), 'parcel', $tracking, ['insurance_ref' => $insuranceRef], true);
        $attempts[] = $channel['code'];
    }
    try { wa_notify_parcel_event('registered', $parcel); } catch (Throwable $e) { error_log('WhatsApp registered template failed: ' . $e->getMessage()); }
    $creatorBody = implode("\n", [
        'Hi ' . ($parcel['sender_name'] ?? 'there') . ',',
        'Your order ' . $tracking . ' has been created for ' . ($parcel['receiver_name'] ?? 'the receiver') . '.',
        'Destination: ' . ($parcel['to_city'] ?? '—') . '.',
        'Receiver: ' . ($parcel['receiver_name'] ?? '—') . ' (' . ($parcel['receiver_phone'] ?? '') . ').',
        'Status: registered. Tracking updates will be sent to you and the receiver by SMS and email.',
        'Track: ' . $trackUrl,
    ]);
    $createdNotify = notify_order_creator($parcel, $template, 'Order created: ' . $tracking, $creatorBody,
        ['insurance_ref' => $insuranceRef], (int) ($user['id'] ?? 0));
    audit('notification.parcel_created', 'parcel', (string) ($parcel['id'] ?? ''), [
        'tracking' => $tracking, 'new_account' => $customer['created'], 'channels' => $attempts,
    ]);
    return ['sent' => count($attempts), 'created_account' => $customer['created'],
        'customer_id' => (int) $user['id'], 'channels' => $attempts];
}


/** CU-02: notify guest receivers who opted in to updates for this parcel. */
function notify_track_subscribers(array $parcel, string $label, string $note = ''): void
{
    $tracking = (string) ($parcel['tracking'] ?? '');
    if ($tracking === '') return;
    try {
        $stmt = db()->prepare('SELECT * FROM track_subscriptions WHERE tracking = ? AND consent = 1 ORDER BY created_at DESC LIMIT 100');
        $stmt->execute([$tracking]);
        foreach ($stmt->fetchAll() as $sub) {
            $subject = 'Parcel ' . $tracking . ' · ' . $label;
            $body = 'Parcel ' . $tracking . ' is now: ' . $label . '.' . ($note ? "\n" . $note : '') . "\nTrack: " . rtrim((string) (config()['app']['base_url'] ?? ''), '/') . '/track-mzigo?num=' . rawurlencode($tracking);
            $user = ['id' => 0, 'name' => 'Receiver', 'email' => (string) ($sub['email'] ?? ''), 'phone' => (string) ($sub['phone'] ?? ''), 'comm_channel' => null];
            foreach (receiver_notification_targets($user) as $channel) {
                notification_send($channel['code'], $user, $subject, $body, 'parcel_status', $parcel,
                    (string) ($channel['provider'] ?? 'log'), 'parcel', (string) ($parcel['id'] ?? ''), ['tracking' => $tracking, 'status_label' => $label], true);
            }
        }
        db()->prepare('UPDATE track_subscriptions SET last_notified_at = NOW() WHERE tracking = ?')->execute([$tracking]);
    } catch (Throwable $e) {
        error_log('track subscriber notification failed: ' . $e->getMessage());
    }
}
