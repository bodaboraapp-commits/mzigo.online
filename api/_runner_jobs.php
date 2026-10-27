<?php
/**
 * Mzigo.online — runner job helpers.
 * Jobs originate from errand bookings; runners accept from the job feed.
 */
declare(strict_types=1);

function runner_job_event(int $jobId, ?int $runnerId, string $event, ?string $note = null): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO runner_job_events (job_id, runner_id, event, note, created_at) VALUES (?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$jobId, $runnerId, $event, $note]);
    } catch (Throwable $e) {
        error_log('runner job event failed: ' . $e->getMessage());
    }
}

function runner_notify(int $userId, string $title, string $body, ?int $jobId = null): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO runner_notifications (user_id, title, body, job_id, created_at) VALUES (?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$userId, $title, $body, $jobId]);
    } catch (Throwable $e) {
        error_log('runner notification failed: ' . $e->getMessage());
    }
    /* RN-03: mirror runner updates to configured SMS/WhatsApp/email channels. */
    try {
        if (!function_exists('notification_send')) {
            require_once __DIR__ . '/_notifications.php';
    require_once __DIR__ . '/_sms.php';
        }
        $stmt = db()->prepare('SELECT id, name, email, phone, comm_channel FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) return;
        $parcel = ['id' => 0, 'tracking' => $jobId ? (string) $jobId : ''];
        foreach (notification_targets($user) as $channel) {
            notification_send($channel['code'], $user, $title, $body, 'parcel_status', $parcel,
                (string) ($channel['provider'] ?? 'log'), 'runner_job', $jobId ? (string) $jobId : null, ['job_id' => $jobId]);
        }
    } catch (Throwable $e) {
        error_log('runner channel notification failed: ' . $e->getMessage());
    }
}

/** Enforce a runner's saved max-distance and preferred-size filters (RN-12b). */
function runner_job_matches_preferences(array $job, array $profile): bool
{
    $maxDistance = (int) ($profile['max_distance_km'] ?? 0);
    if ($maxDistance > 0) {
        if (!isset($job['distance_km']) || $job['distance_km'] === null) { return false; }
        if ((float) $job['distance_km'] > $maxDistance) { return false; }
    }
    $preferred = array_filter(array_map('trim', preg_split('/[,;]+/', (string) ($profile['preferred_sizes'] ?? '')) ?: []));
    if ($preferred) {
        $jobSize = mb_strtolower(trim((string) ($job['size'] ?? '')));
        if ($jobSize === '') { return false; }
        $allowed = array_map(fn ($s) => mb_strtolower((string) $s), $preferred);
        if (!in_array($jobSize, $allowed, true)) { return false; }
    }
    return true;
}

/** Notify matching available runners when a new job is created (RN-03/RN-12). */
function runner_job_broadcast(array $job): void
{
    try {
        if (!function_exists('notification_send')) require_once __DIR__ . '/_notifications.php';
        $stmt = db()->prepare(
            "SELECT u.id, u.name, u.email, u.phone, u.comm_channel, fp.areas, fp.max_distance_km, fp.preferred_sizes
               FROM freelancer_profiles fp JOIN users u ON u.id = fp.user_id
              WHERE u.role = 'freelancer' AND u.status = 'active'
                AND fp.available_now = 1 AND fp.job_alerts = 1 AND fp.kyc_status = 'approved'
              LIMIT 50"
        );
        $stmt->execute();
        $haystack = mb_strtolower((string) ($job['pickup'] ?? '') . ' ' . (string) ($job['dropoff'] ?? ''));
        $notified = 0;
        foreach ($stmt->fetchAll() as $runner) {
            $areas = array_filter(array_map('trim', preg_split('/[,;]+/', (string) ($runner['areas'] ?? '')) ?: []));
            $matches = !$areas;
            foreach ($areas as $area) {
                if (mb_strlen($area) >= 3 && mb_strpos($haystack, mb_strtolower($area)) !== false) { $matches = true; break; }
            }
            if (!$matches) continue;
            if (!runner_job_matches_preferences($job, $runner)) continue;
            $subject = 'New errand: ' . ($job['pickup'] ?? '') . ' → ' . ($job['dropoff'] ?? '');
            $body = 'A new errand matches your areas. Fee KES ' . (int) ($job['fee_kes'] ?? 0) . '. Open your runner dashboard to accept.';
            runner_notify((int) $runner['id'], 'New errand match', $body, isset($job['id']) ? (int) $job['id'] : null);
            $notified++;
            if ($notified >= 20) break;
        }
    } catch (Throwable $e) {
        error_log('runner job broadcast failed: ' . $e->getMessage());
    }
}

/**
 * Create an available runner job from a booked errand.
 * Runner keeps the base fee; the platform fee stays with Mzigo.online.
 */
/** Issue a one-time receiver code for a picked-up job (RN-06b). */
function runner_job_issue_delivery_otp(int $jobId): array
{
    require_once __DIR__ . '/_notifications.php';
    $stmt = db()->prepare('SELECT * FROM runner_jobs WHERE id = ? LIMIT 1');
    $stmt->execute([$jobId]);
    $job = $stmt->fetch();
    if (!$job) { return ['ok' => false, 'required' => false, 'sent' => 0]; }
    $phone = trim((string) ($job['receiver_phone'] ?? ''));
    $name = trim((string) ($job['receiver_name'] ?? 'Customer'));
    if ($phone === '') {
        db()->prepare('UPDATE runner_jobs SET delivery_otp_hash = NULL, delivery_otp_required = 0 WHERE id = ?')->execute([$jobId]);
        return ['ok' => true, 'required' => false, 'sent' => 0];
    }
    $customer = notification_customer_for($phone, $name);
    $user = $customer['user'];
    $targets = notification_targets($user);
    $smsTarget = null;
    $emailTarget = null;
    foreach ($targets as $target) {
        $code = (string) ($target['code'] ?? '');
        $provider = (string) ($target['provider'] ?? 'log');
        if ($code === 'sms' && $provider !== 'log' && function_exists('sms_channel_ready') && sms_channel_ready()) {
            $smsTarget = $target;
        }
        if ($code === 'email' && $provider !== 'log' && trim((string) ($user['email'] ?? '')) !== '') {
            $emailTarget = $target;
        }
    }
    $target = $smsTarget ?: $emailTarget;
    if ($target === null) {
        db()->prepare('UPDATE runner_jobs SET delivery_otp_hash = NULL, delivery_otp_required = 0 WHERE id = ?')->execute([$jobId]);
        return ['ok' => true, 'required' => false, 'sent' => 0];
    }
    $channel = (string) $target['code'];
    $provider = (string) ($target['provider'] ?? 'log');
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $hash = password_hash($code, PASSWORD_DEFAULT);
    db()->prepare('UPDATE runner_jobs SET delivery_otp_hash = ?, delivery_otp_expires_at = DATE_ADD(NOW(), INTERVAL 30 MINUTE),
        delivery_otp_attempts = 0, delivery_otp_sent_at = NOW(), delivery_otp_required = 1, delivery_otp_channel = ? WHERE id = ?')
        ->execute([$hash, $channel, $jobId]);
    $parcel = ['id' => 0, 'tracking' => (string) ($job['ref'] ?? ''), 'receiver_name' => $name,
        'receiver_phone' => $phone, 'sender_name' => (string) ($job['contact_name'] ?? ''),
        'sender_phone' => (string) ($job['contact_phone'] ?? ''), 'tenant_id' => null];
    $body = "Hi " . $name . ",\n\nYour receiver code for errand " . $job['ref'] . " is " . $code . ".\n\nGive this code to the runner only after you receive the item. It expires in 30 minutes.";
    $result = notification_send($channel, $user, 'Receiver code for errand ' . $job['ref'], $body, 'delivery_otp', $parcel,
        $provider, 'runner_job', (string) $jobId, ['code' => $code, 'ref' => $job['ref'], 'tracking' => $job['ref']]);
    if (($result['status'] ?? '') !== 'sent' && $channel === 'sms' && $emailTarget !== null) {
        $result = notification_send('email', $user, 'Receiver code for errand ' . $job['ref'], $body, 'delivery_otp', $parcel,
            (string) ($emailTarget['provider'] ?? 'log'), 'runner_job', (string) $jobId, ['code' => $code, 'ref' => $job['ref'], 'tracking' => $job['ref']]);
        $channel = 'email';
        $provider = (string) ($emailTarget['provider'] ?? 'log');
        db()->prepare('UPDATE runner_jobs SET delivery_otp_channel = ? WHERE id = ?')->execute([$channel, $jobId]);
    }
    if (($result['status'] ?? '') !== 'sent') {
        db()->prepare('UPDATE runner_jobs SET delivery_otp_hash = NULL, delivery_otp_required = 0 WHERE id = ?')->execute([$jobId]);
        return ['ok' => false, 'required' => false, 'sent' => 0, 'error' => (string) ($result['error'] ?? 'Could not deliver the receiver code.')];
    }
    return ['ok' => true, 'required' => true, 'sent' => 1, 'channel' => $channel, 'user_id' => (int) $user['id']];
}

function runner_job_create_from_errand(array $errand): ?int
{
    try {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT id FROM runner_jobs WHERE errand_id = ? LIMIT 1');
        $stmt->execute([$errand['id']]);
        if ($stmt->fetch()) {
            return null;
        }
        $ref = unique_token('runner_jobs', 'ref', 'RJ-', 6);
        $stmt = $pdo->prepare(
            'INSERT INTO runner_jobs
               (ref, errand_id, pickup, dropoff, fee_kes, customer_fee_kes, status,
                description, size, distance_km, instructions, contact_name, contact_phone, receiver_name, receiver_phone, created_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, \'available\', ?, ?, ?, ?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 3 DAY))'
        );
        $stmt->execute([
            $ref,
            $errand['id'],
            $errand['pickup'],
            $errand['dropoff'],
            (int) $errand['fee_base'],
            (int) $errand['fee_total'],
            $errand['description'] ?? null,
            $errand['size'] ?? null,
            isset($errand['distance_km']) && $errand['distance_km'] !== null ? (float) $errand['distance_km'] : null,
            $errand['instructions'] ?? null,
            $errand['sender_name'],
            $errand['sender_phone'],
            $errand['recipient_name'],
            $errand['recipient_phone'],
        ]);
        $jobId = (int) $pdo->lastInsertId();
        runner_job_event($jobId, null, 'available', 'Created from errand ' . $errand['ref']);
        $job = ['id' => $jobId, 'pickup' => $errand['pickup'], 'dropoff' => $errand['dropoff'], 'fee_kes' => (int) $errand['fee_base'],
            'size' => $errand['size'] ?? null, 'distance_km' => $errand['distance_km'] ?? null];
        runner_job_broadcast($job);
        return $jobId;
    } catch (Throwable $e) {
        error_log('runner job create failed: ' . $e->getMessage());
        return null;
    }
}


/**
 * RN-07: notify the receiver (and sender when identifiable) when a runner
 * accepts, picks up or delivers an errand.
 */
function runner_notify_job_parties(array $job, string $event): void
{
    if (!function_exists('notification_send')) {
        require_once __DIR__ . '/_notifications.php';
    }
    $receiverPhone = (string) ($job['receiver_phone'] ?? '');
    $senderPhone = (string) ($job['contact_phone'] ?? '');
    $receiverName = (string) ($job['receiver_name'] ?? 'Customer');
    $senderName = (string) ($job['contact_name'] ?? 'Sender');
    $ref = (string) ($job['ref'] ?? '');
    $pretty = [
        'accepted'  => 'A runner is on the way',
        'picked_up' => 'Your errand has been picked up',
        'delivered' => 'Your errand has been delivered',
    ][$event] ?? 'Errand update';
    $subject = $pretty . ' — ' . $ref;
    $body = implode("\n", [
        'Hi ' . $receiverName . ',',
        $pretty . '.',
        'Errand: ' . ($job['pickup'] ?? '') . ' → ' . ($job['dropoff'] ?? ''),
        'Reference: ' . $ref,
        $event === 'delivered' ? 'Thank you for using Mzigo.online.' : 'You can follow updates from your Mzigo.online account.',
        $event === 'delivered' ? 'Know someone sending? Invite them and earn: ' . rtrim((string) (config()['app']['url'] ?? 'https://mzigo.online'), '/') . '/refer' : '',
    ]);
    if ($event === 'delivered') {
        try {
            $stmt = db()->prepare('SELECT rating_token FROM runner_jobs WHERE id = ? LIMIT 1');
            $stmt->execute([(int) ($job['id'] ?? 0)]);
            $ratingToken = (string) ($stmt->fetchColumn() ?: '');
            if ($ratingToken !== '') {
                $body .= "\n\nRate your runner: " . rtrim((string) (config()['app']['url'] ?? 'https://mzigo.online'), '/') . '/rate-runner?token=' . rawurlencode($ratingToken);
            }
        } catch (Throwable $e) { error_log('rating link failed: ' . $e->getMessage()); }
    }
    $parcel = [
        'id' => 0,
        'tracking' => $ref,
        'receiver_name' => $receiverName,
        'receiver_phone' => $receiverPhone,
        'sender_name' => $senderName,
        'sender_phone' => $senderPhone,
        'tenant_id' => null,
    ];

    if ($receiverPhone !== '') {
        notify_customer_email($parcel, 'parcel_status', $subject, $body, [
            'tracking' => $ref, 'status_label' => $pretty,
        ]);
    }

    /* Sender copy: use the registered sender user when available, otherwise the
       sender phone as a channel recipient. */
    if ($senderPhone !== '' || !empty($job['errand_id'])) {
        $senderUser = ['id' => 0, 'name' => $senderName, 'email' => '', 'phone' => $senderPhone, 'comm_channel' => null];
        try {
            $stmt = db()->prepare('SELECT user_id FROM errands WHERE id = ? LIMIT 1');
            $stmt->execute([$job['errand_id']]);
            $userId = (int) ($stmt->fetchColumn() ?: 0);
            if ($userId) {
                $stmt = db()->prepare('SELECT id, name, email, phone, comm_channel FROM users WHERE id = ? LIMIT 1');
                $stmt->execute([$userId]);
                $row = $stmt->fetch();
                if ($row) { $senderUser = $row; }
            }
        } catch (Throwable $e) {
            error_log('runner sender notification lookup failed: ' . $e->getMessage());
        }
        foreach (notification_targets($senderUser) as $channel) {
            notification_send($channel['code'], $senderUser, $subject, $body, 'parcel_status', $parcel,
                (string) ($channel['provider'] ?? 'log'), 'errand', (string) $ref, ['tracking' => $ref, 'status_label' => $pretty]);
        }
    }
}
