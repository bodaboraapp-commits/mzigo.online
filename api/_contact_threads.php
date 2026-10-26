<?php
/**
 * Mzigo.online — contact inbox threads.
 * Internal notes + direct replies through the configured notification channels.
 */
declare(strict_types=1);

function contact_thread_notes(int $messageId): array
{
    $stmt = db()->prepare(
        'SELECT n.id, n.note, n.created_at, u.name AS admin_name
           FROM contact_message_notes n LEFT JOIN users u ON u.id = n.admin_id
          WHERE n.message_id = ? ORDER BY n.created_at ASC, n.id ASC'
    );
    $stmt->execute([$messageId]);
    return array_map(fn ($n) => [
        'id' => (int) $n['id'],
        'note' => (string) $n['note'],
        'admin_name' => (string) ($n['admin_name'] ?? 'Admin'),
        'created_at' => $n['created_at'],
    ], $stmt->fetchAll());
}

function contact_thread_replies(int $messageId): array
{
    $stmt = db()->prepare(
        'SELECT r.id, r.channel, r.recipient, r.body, r.status, r.error, r.created_at, u.name AS admin_name
           FROM contact_message_replies r LEFT JOIN users u ON u.id = r.admin_id
          WHERE r.message_id = ? ORDER BY r.created_at ASC, r.id ASC'
    );
    $stmt->execute([$messageId]);
    return array_map(fn ($r) => [
        'id' => (int) $r['id'],
        'channel' => (string) $r['channel'],
        'recipient' => (string) $r['recipient'],
        'body' => (string) $r['body'],
        'status' => (string) $r['status'],
        'error' => (string) ($r['error'] ?? ''),
        'admin_name' => (string) ($r['admin_name'] ?? 'Admin'),
        'created_at' => $r['created_at'],
    ], $stmt->fetchAll());
}

function contact_reply_channels(): array
{
    $active = notification_channels(true);
    return array_values(array_map(fn ($c) => [
        'code' => (string) $c['code'],
        'label' => (string) $c['label'],
    ], $active));
}

/** Send one reply over one configured channel; returns [status, error]. */
function contact_send_reply(array $message, string $channel, string $body): array
{
    $channels = notification_channels(true);
    $target = null;
    foreach ($channels as $candidate) {
        if ($candidate['code'] === $channel) { $target = $candidate; break; }
    }
    if ($target === null) {
        return ['status' => 'skipped', 'error' => 'Channel is not active.'];
    }
    $recipient = $channel === 'email' ? (string) $message['email'] : (string) ($message['phone'] ?? '');
    if ($recipient === '') {
        return ['status' => 'skipped', 'error' => 'No recipient on file for this channel.'];
    }
    $subject = 'Re: ' . (string) $message['subject'];
    $fullBody = $body . "\n\n— Mzigo.online support\nReply reference: CONTACT-" . (int) $message['id'];
    $user = ['id' => 0, 'name' => (string) $message['name'], 'email' => (string) $message['email'], 'phone' => (string) ($message['phone'] ?? ''), 'comm_channel' => null];
    $parcel = ['id' => 0, 'tracking' => 'CONTACT-' . (int) $message['id']];
    try {
        notification_send($channel, $user, $subject, $fullBody, 'contact_reply', $parcel,
            (string) ($target['provider'] ?? 'log'), 'contact', (string) $message['id'], ['message_id' => (int) $message['id']]);
        return ['status' => 'sent', 'error' => ''];
    } catch (Throwable $e) {
        return ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 255)];
    }
}
