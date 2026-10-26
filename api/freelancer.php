<?php
/**
 * Mzigo.online — runner (freelancer) profile, availability, job feed and earnings.
 *
 * GET  /api/freelancer.php           profile + options + jobs + earnings + notifications
 * POST /api/freelancer.php           save-profile | set-availability | accept-job |
 *                                    decline-job | pickup-job | deliver-job |
 *                                    mark-notifications-read
 *
 * Jobs are created automatically from errand bookings (api/_runner_jobs.php).
 * Available jobs hide sender/receiver contact details until a runner accepts.
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_runner_jobs.php';
require_once __DIR__ . '/_payouts.php';
require_once __DIR__ . '/_growth.php';

$user = require_user();
if (($user['role'] ?? '') !== 'freelancer') {
    fail_error('forbidden', 'Freelancer access required.', 403);
}

const FREELANCER_TERMS_VERSION = '2026-10';
const FREELANCER_VEHICLES = ['Walker', 'Boda', 'Car', 'Van', 'Truck'];
const FREELANCER_WINDOWS = ['morning', 'afternoon', 'evening', 'flexible'];
const FREELANCER_DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

function freelancer_row(int $userId): ?array
{
    $stmt = db()->prepare('SELECT * FROM freelancer_profiles WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function freelancer_profile_view(array $user): array
{
    $row = freelancer_row((int) $user['id']) ?: [];
    $days = array_values(array_filter(array_map('trim', explode(',', (string) ($row['availability_days'] ?? '')))));
    return [
        'name'                  => (string) $user['name'],
        'areas'                 => (string) ($row['areas'] ?? ''),
        'vehicle_type'          => (string) ($row['vehicle_type'] ?? ''),
        'availability_days'     => $days,
        'availability_window'   => (string) ($row['availability_window'] ?? ''),
        'available_now'         => (bool) ($row['available_now'] ?? false),
        'payout_phone'          => (string) ($row['payout_phone'] ?? ''),
        'id_number'             => (string) ($row['id_number'] ?? ''),
        'kyc_status'            => (string) ($row['kyc_status'] ?? 'not_submitted'),
        'kyc_note'              => (string) ($row['kyc_note'] ?? ''),
        'job_alerts'            => (bool) ($row['job_alerts'] ?? true),
        'max_distance_km'       => $row['max_distance_km'] !== null ? (int) $row['max_distance_km'] : null,
        'preferred_sizes'       => (string) ($row['preferred_sizes'] ?? ''),
        'terms_version'         => (string) ($row['terms_version'] ?? ''),
        'terms_accepted_at'     => $row['terms_accepted_at'] ?? null,
        'onboarding_completed_at' => $row['onboarding_completed_at'] ?? null,
        'updated_at'            => $row['updated_at'] ?? null,
    ];
}

function runner_job_view(array $job, bool $withContact): array
{
    $view = [
        'id'            => (int) $job['id'],
        'ref'           => (string) $job['ref'],
        'pickup'        => (string) $job['pickup'],
        'dropoff'       => (string) $job['dropoff'],
        'fee_kes'       => (int) $job['fee_kes'],
        'customer_fee_kes' => (int) $job['customer_fee_kes'],
        'status'        => (string) $job['status'],
        'description'   => (string) ($job['description'] ?? ''),
        'size'          => (string) ($job['size'] ?? ''),
        'distance_km'   => isset($job['distance_km']) && $job['distance_km'] !== null ? (float) $job['distance_km'] : null,
        'instructions'  => (string) ($job['instructions'] ?? ''),
        'otp_required'  => (int) ($job['delivery_otp_required'] ?? 0) === 1,
        'created_at'    => $job['created_at'] ?? null,
        'accepted_at'   => $job['accepted_at'] ?? null,
        'picked_up_at'  => $job['picked_up_at'] ?? null,
        'delivered_at'  => $job['delivered_at'] ?? null,
        'proof_note'    => (string) ($job['proof_note'] ?? ''),
        'proof_photo_path' => (string) ($job['proof_photo_path'] ?? ''),
        'rating'        => $job['rating'] !== null ? (int) $job['rating'] : null,
        'rating_note'   => (string) ($job['rating_note'] ?? ''),
        'expires_at'    => $job['expires_at'] ?? null,
    ];
    if ($withContact) {
        $view['contact_name']   = (string) ($job['contact_name'] ?? '');
        $view['contact_phone']  = (string) ($job['contact_phone'] ?? '');
        $view['receiver_name']  = (string) ($job['receiver_name'] ?? '');
        $view['receiver_phone'] = (string) ($job['receiver_phone'] ?? '');
    }
    return $view;
}

function runner_area_tokens(?array $profile): array
{
    if (!$profile) return [];
    $raw = trim((string) ($profile['areas'] ?? ''));
    if ($raw === '') return [];
    $parts = preg_split('/[,;]+/', $raw) ?: [];
    $areas = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if (mb_strlen($part) >= 3) $areas[] = $part;
    }
    return array_values(array_unique($areas));
}

function runner_job_in_launch_city(array $job, array $cities): bool
{
    if (!$cities) return true;
    $haystack = mb_strtolower((string) ($job['pickup'] ?? '') . ' ' . (string) ($job['dropoff'] ?? ''));
    foreach ($cities as $city) {
        $city = mb_strtolower(trim((string) $city));
        if ($city !== '' && mb_strpos($haystack, $city) !== false) return true;
    }
    return false;
}

function runner_area_has_launch_city(array $areas, array $cities): bool
{
    if (!$cities) return true;
    foreach ($cities as $city) {
        $city = mb_strtolower(trim((string) $city));
        if ($city === '') continue;
        foreach ($areas as $area) {
            if (mb_strpos(mb_strtolower($area), $city) !== false) return true;
        }
    }
    return false;
}

function runner_job_matches_areas(array $job, array $areas): bool
{
    if (!$areas) return true;
    $haystack = mb_strtolower((string) $job['pickup'] . ' ' . (string) $job['dropoff']);
    foreach ($areas as $area) {
        if (mb_strpos($haystack, mb_strtolower($area)) !== false) return true;
    }
    return false;
}

function runner_payout_history(int $runnerId): array
{
    $stmt = db()->prepare(
        'SELECT id, ref, amount_kes, bonus_kes, phone, status, provider_ref, note, requested_at, paid_at
           FROM runner_payouts WHERE runner_id = ? ORDER BY requested_at DESC LIMIT 12'
    );
    $stmt->execute([$runnerId]);
    return array_map(fn ($p) => [
        'id'           => (int) $p['id'],
        'ref'          => (string) $p['ref'],
        'amount_kes'   => (int) $p['amount_kes'],
        'bonus_kes'    => (int) ($p['bonus_kes'] ?? 0),
        'phone'        => (string) $p['phone'],
        'status'       => (string) $p['status'],
        'provider_ref' => (string) ($p['provider_ref'] ?? ''),
        'note'         => (string) ($p['note'] ?? ''),
        'requested_at' => $p['requested_at'],
        'paid_at'      => $p['paid_at'],
    ], $stmt->fetchAll());
}

function runner_jobs_snapshot(int $runnerId): array
{
    $pdo = db();

    $stmt = $pdo->prepare(
        "SELECT * FROM runner_jobs j
          WHERE j.status = 'available'
            AND NOT EXISTS (SELECT 1 FROM runner_job_declines d WHERE d.job_id = j.id AND d.runner_id = ?)
            AND j.created_at >= (NOW() - INTERVAL 14 DAY)
            AND (j.expires_at IS NULL OR j.expires_at > NOW())
          ORDER BY j.created_at DESC LIMIT 30"
    );
    $stmt->execute([$runnerId]);
    $profile = freelancer_row($runnerId) ?: [];
    $areas = runner_area_tokens($profile);
    $launch = runner_launch_config();
    $cities = $launch['cities'] ?? [];
    $availableRows = array_values(array_filter(
        $stmt->fetchAll(),
        fn ($j) => runner_job_in_launch_city($j, $cities) && runner_job_matches_areas($j, $areas)
            && runner_job_matches_preferences($j, $profile)
    ));
    $available = array_map(fn ($j) => runner_job_view($j, false), $availableRows);

    $stmt = $pdo->prepare(
        "SELECT * FROM runner_jobs WHERE runner_id = ? AND status IN ('accepted','picked_up')
          ORDER BY accepted_at ASC LIMIT 20"
    );
    $stmt->execute([$runnerId]);
    $active = array_map(fn ($j) => runner_job_view($j, true), $stmt->fetchAll());

    $stmt = $pdo->prepare(
        "SELECT * FROM runner_jobs WHERE runner_id = ? AND status = 'delivered'
          ORDER BY delivered_at DESC LIMIT 20"
    );
    $stmt->execute([$runnerId]);
    $completed = array_map(fn ($j) => runner_job_view($j, true), $stmt->fetchAll());

    $stmt = $pdo->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN delivered_at >= DATE_FORMAT(CURRENT_DATE(), '%Y-%m-01') THEN fee_kes ELSE 0 END), 0) AS this_month_kes,
            COUNT(*) AS completed_jobs,
            COALESCE(SUM(CASE WHEN payout_id IS NULL THEN fee_kes ELSE 0 END), 0) AS pending_kes
         FROM runner_jobs WHERE runner_id = ? AND status = 'delivered'"
    );
    $stmt->execute([$runnerId]);
    $money = $stmt->fetch() ?: ['this_month_kes' => 0, 'completed_jobs' => 0, 'pending_kes' => 0];

    $paidStmt = $pdo->prepare("SELECT COALESCE(SUM(amount_kes), 0) FROM runner_payouts WHERE runner_id = ? AND status = 'paid'");
    $paidStmt->execute([$runnerId]);
    $paidKes = (int) $paidStmt->fetchColumn();
    $payouts = runner_payout_history($runnerId);
    $incentive = runner_incentive_quote($runnerId);
    $ledgerPending = runner_bonus_pending_total($runnerId);
    $tier = runner_tier($runnerId);
    $ratingStmt = $pdo->prepare('SELECT COUNT(*), AVG(rating) FROM runner_jobs WHERE runner_id = ? AND rating IS NOT NULL');
    $ratingStmt->execute([$runnerId]);
    $ratingStats = $ratingStmt->fetch(PDO::FETCH_NUM) ?: [0, null];
    $avgRating = $ratingStats[1] !== null ? round((float) $ratingStats[1], 1) : null;

    $stmt = $pdo->prepare(
        "SELECT id, title, body, job_id, read_at, created_at FROM runner_notifications
          WHERE user_id = ? ORDER BY created_at DESC LIMIT 6"
    );
    $stmt->execute([$runnerId]);
    $notes = $stmt->fetchAll();
    $unreadStmt = $pdo->prepare('SELECT COUNT(*) FROM runner_notifications WHERE user_id = ? AND read_at IS NULL');
    $unreadStmt->execute([$runnerId]);

    return [
        'jobs' => [
            'available' => $available,
            'active'    => $active,
            'completed' => $completed,
        ],
        'earnings' => [
            'this_month_kes' => (int) $money['this_month_kes'],
            'completed_jobs' => (int) $money['completed_jobs'],
            'pending_kes'    => (int) $money['pending_kes'] + (int) $incentive['total_kes'] + $ledgerPending,
            'base_pending_kes' => (int) $money['pending_kes'],
            'bonus_pending_kes' => (int) $incentive['total_kes'] + $ledgerPending,
            'quality_bonus_kes' => $ledgerPending,
            'tier' => $tier,
            'incentive'      => $incentive,
            'paid_kes'       => $paidKes,
            'rating_avg'     => $avgRating,
            'rating_count'   => (int) ($ratingStats[0] ?? 0),
        ],
        'payouts' => $payouts,
        'payout_config' => payout_public_config(),
        'kyc_required' => !empty(runner_launch_config()['kyc_required']),
        'matching_areas' => $areas,
        'notifications' => [
            'unread' => (int) $unreadStmt->fetchColumn(),
            'items'  => array_map(fn ($n) => [
                'id'         => (int) $n['id'],
                'title'      => (string) $n['title'],
                'body'       => (string) $n['body'],
                'job_id'     => $n['job_id'] !== null ? (int) $n['job_id'] : null,
                'read_at'    => $n['read_at'],
                'created_at' => $n['created_at'],
            ], $notes),
        ],
    ];
}

function freelancer_snapshot(array $user): array
{
    $profile = freelancer_profile_view($user);
    $missing = [];
    $digits = preg_replace('/\D+/', '', (string) $user['phone']) ?? '';
    if ($profile['name'] === '' || $profile['name'] === 'Freelancer ' . substr($digits, -4)) {
        $missing[] = 'name';
    }
    if ($profile['areas'] === '') $missing[] = 'areas';
    if (empty($profile['availability_days'])) $missing[] = 'availability_days';
    if ($profile['availability_window'] === '') $missing[] = 'availability_window';
    if ($profile['vehicle_type'] === '') $missing[] = 'vehicle_type';
    if ($profile['payout_phone'] === '') $missing[] = 'payout_phone';
    if (!$profile['terms_accepted_at']) $missing[] = 'terms';

    return [
        'ok'       => true,
        'source'   => 'mysql',
        'profile'  => $profile,
        'missing'  => $missing,
        'options'  => [
            'vehicles' => runner_launch_config()['vehicles'] ?? FREELANCER_VEHICLES,
            'cities' => runner_launch_config()['cities'] ?? [],
            'windows'  => ['morning' => 'Morning (6am–12pm)', 'afternoon' => 'Afternoon (12pm–5pm)', 'evening' => 'Evening (5pm–9pm)', 'flexible' => 'Flexible'],
            'days'     => ['mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun'],
            'terms_version' => FREELANCER_TERMS_VERSION,
        ],
    ] + runner_jobs_snapshot((int) $user['id']);
}

function freelancer_dispatch(array $user): void
{
    $data = body_json();
    $action = (string) ($data['action'] ?? $_GET['action'] ?? '');
    $uid = (int) $user['id'];
    $pdo = db();

    switch ($action) {
        case 'save-profile': {
            $name = param($data, 'name', 2, 120, 'Full name');
            $areas = param($data, 'areas', 2, 255, 'Areas you serve');
            $vehicle = param($data, 'vehicle_type', 2, 40, 'Vehicle type');
            $launch = runner_launch_config();
            $vehicles = $launch['vehicles'] ?? FREELANCER_VEHICLES;
            if (!in_array($vehicle, $vehicles, true)) {
                fail('Choose a supported vehicle type.', 422, ['vehicle_type' => 'Invalid vehicle.']);
            }
            $window = param($data, 'availability_window', 2, 40, 'Availability window');
            if (!in_array($window, FREELANCER_WINDOWS, true)) {
                fail('Choose a valid availability window.', 422, ['availability_window' => 'Invalid window.']);
            }
            $rawDays = $data['availability_days'] ?? [];
            if (!is_array($rawDays)) {
                fail('Select at least one day.', 422, ['availability_days' => 'Invalid days.']);
            }
            $days = array_values(array_unique(array_intersect(
                array_map('strval', array_map('trim', $rawDays)),
                FREELANCER_DAYS
            )));
            if (!$days) {
                fail('Select at least one day you can work.', 422, ['availability_days' => 'Select at least one day.']);
            }
            $areasTokens = preg_split('/[,;]+/', $areas) ?: [];
            $areasTokens = array_values(array_filter(array_map('trim', $areasTokens)));
            $launchCities = runner_launch_config()['cities'] ?? [];
            if ($launchCities && !runner_area_has_launch_city($areasTokens, $launchCities)) {
                fail('Choose an area in a supported city: ' . implode(', ', $launchCities) . '.', 422, ['areas' => 'Unsupported city.']);
            }
            $payoutPhone = require_phone($data, 'payout_phone');
            $idNumber = isset($data['id_number']) && is_scalar($data['id_number']) && trim((string) $data['id_number']) !== ''
                ? mb_substr(trim((string) $data['id_number']), 0, 60)
                : null;
            $accepted = !empty($data['terms_accepted']);
            if (!$accepted) {
                fail('Please accept the Terms of Service and Privacy Policy to continue.', 422, ['terms_accepted' => 'Terms acceptance required.']);
            }

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('UPDATE users SET name = ? WHERE id = ?');
                $stmt->execute([$name, $uid]);

                $existing = freelancer_row($uid);
                $termsAt = $existing['terms_accepted_at'] ?? null;
                $termsVersion = (string) ($existing['terms_version'] ?? '');
                if ($termsAt === null) {
                    $termsAt = gmdate('Y-m-d H:i:s');
                    $termsVersion = FREELANCER_TERMS_VERSION;
                }

                $sql = 'INSERT INTO freelancer_profiles
                    (user_id, areas, vehicle_type, availability_days, availability_window, payout_phone, id_number,
                     available_now, job_alerts, max_distance_km, preferred_sizes,
                     terms_version, terms_accepted_at, onboarding_completed_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, COALESCE(?, 0), ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                      areas = VALUES(areas),
                      vehicle_type = VALUES(vehicle_type),
                      availability_days = VALUES(availability_days),
                      availability_window = VALUES(availability_window),
                      payout_phone = VALUES(payout_phone),
                      id_number = VALUES(id_number),
                      job_alerts = VALUES(job_alerts),
                      max_distance_km = VALUES(max_distance_km),
                      preferred_sizes = VALUES(preferred_sizes),
                      terms_version = VALUES(terms_version),
                      terms_accepted_at = VALUES(terms_accepted_at),
                      onboarding_completed_at = VALUES(onboarding_completed_at)';
                $stmt = $pdo->prepare($sql);
                $now = gmdate('Y-m-d H:i:s');
                $alertOn = array_key_exists('job_alerts', $data) ? !empty($data['job_alerts']) : true;
                $maxDistance = isset($data['max_distance_km']) && is_numeric($data['max_distance_km'])
                    ? max(1, min(200, (int) $data['max_distance_km'])) : null;
                $preferredSizes = isset($data['preferred_sizes']) && is_scalar($data['preferred_sizes'])
                    ? mb_substr(trim((string) $data['preferred_sizes']), 0, 120) : null;
                $stmt->execute([
                    $uid, $areas, $vehicle, implode(',', $days), $window, $payoutPhone, $idNumber,
                    !empty($data['available_now']) ? 1 : null, $alertOn ? 1 : 0, $maxDistance, $preferredSizes,
                    $termsVersion, $termsAt, $now,
                ]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            $user['name'] = $name;
            audit('freelancer.profile_saved', 'user', (string) $uid, [
                'vehicle' => $vehicle, 'areas' => $areas, 'days' => $days,
            ]);
            runner_notify($uid, 'Profile complete', 'Your runner profile is ready. Turn availability on to see errands.');
            json_out(freelancer_snapshot($user));
        }

        case 'request-payout': {
            $profile = freelancer_row($uid);
            if (!$profile || empty($profile['onboarding_completed_at'])) {
                fail('Complete your runner profile before requesting a payout.', 422);
            }
            $phone = trim((string) ($profile['payout_phone'] ?? ''));
            if ($phone === '' || !phone_valid($phone)) {
                fail('Add a valid M-Pesa payout number in your runner profile.', 422);
            }
            $stmt = $pdo->prepare(
                "SELECT COALESCE(SUM(fee_kes), 0) FROM runner_jobs
                  WHERE runner_id = ? AND status = 'delivered' AND payout_id IS NULL"
            );
            $stmt->execute([$uid]);
            $basePending = (int) $stmt->fetchColumn();
            $incentive = runner_incentive_quote($uid);
            $ledgerPending = runner_bonus_pending_total($uid);
            $pending = $basePending + (int) $incentive['total_kes'] + $ledgerPending;
            $cfg = payout_config();
            $min = max(1, (int) $cfg['min_kes']);
            if ($pending < $min) {
                fail('The minimum payout is KES ' . $min . '. Complete more jobs first.', 422);
            }
            if ($cfg['mode'] === 'live' && !mpesa_credentials_ready($cfg)) {
                fail('Live payouts are not configured yet. Please contact support.', 422);
            }

            if ($cfg['schedule'] !== 'instant') {
                $created = runner_payout_create($uid, $phone, $pending, 'pending', 'Scheduled payout (' . $cfg['schedule'] . ')', (int) $incentive['total_kes'] + $ledgerPending);
                runner_bonus_mark_paid($uid, (int) $created['id']);
                runner_notify($uid, 'Payout scheduled', 'KES ' . number_format($pending) . ' is queued for your ' . $cfg['schedule'] . ' payout' . ($incentive['total_kes'] > 0 ? ' (includes KES ' . number_format((int) $incentive['total_kes']) . ' bonus)' : '') . '.');
                audit('runner.payout_scheduled', 'runner_payout', $created['ref'], ['runner_id' => $uid, 'amount_kes' => $pending, 'bonus_kes' => (int) $incentive['total_kes'], 'schedule' => $cfg['schedule']]);
                json_out(freelancer_snapshot($user));
            }

            if ($cfg['mode'] === 'demo') {
                $created = runner_payout_create($uid, $phone, $pending, 'demo', 'Demo M-Pesa payout', (int) $incentive['total_kes']);
                runner_bonus_mark_paid($uid, (int) $created['id']);
                runner_notify($uid, 'Payout sent', 'KES ' . number_format($pending) . ' sent to ' . $phone . ' (' . $created['provider_ref'] . ')' . ($incentive['total_kes'] > 0 ? ', including KES ' . number_format((int) $incentive['total_kes']) . ' bonus' : '') . '.');
                audit('runner.payout_paid_demo', 'runner_payout', $created['ref'], ['runner_id' => $uid, 'amount_kes' => $pending, 'bonus_kes' => (int) $incentive['total_kes']]);
                json_out(freelancer_snapshot($user));
            }

            $result = mpesa_b2c_payout($cfg, $phone, $pending, 'Mzigo runner payout', 'PO-' . $uid);
            if (!$result['ok']) {
                fail($result['error'], 422);
            }
            $pdo->beginTransaction();
            try {
                $ref = unique_token('runner_payouts', 'ref', 'PO-', 6);
                $stmt = $pdo->prepare(
                    "INSERT INTO runner_payouts
                       (ref, runner_id, amount_kes, bonus_kes, phone, status, provider_ref, note, requested_at)
                     VALUES (?, ?, ?, ?, ?, 'processing', ?, 'Live Daraja B2C payout', NOW())"
                );
                $stmt->execute([$ref, $uid, $pending, (int) $incentive['total_kes'], $phone, $result['provider_ref']]);
                $payoutId = (int) $pdo->lastInsertId();
                $stmt = $pdo->prepare(
                    "UPDATE runner_jobs SET payout_id = ?
                      WHERE runner_id = ? AND status = 'delivered' AND payout_id IS NULL"
                );
                $stmt->execute([$payoutId, $uid]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            runner_notify($uid, 'Payout processing', 'KES ' . number_format($pending) . ' is being sent to ' . $phone . '.');
            audit('runner.payout_live_requested', 'runner_payout', $ref, ['runner_id' => $uid, 'amount_kes' => $pending, 'bonus_kes' => (int) $incentive['total_kes'], 'provider_ref' => $result['provider_ref']]);
            json_out(freelancer_snapshot($user));
        }

        case 'set-availability': {
            $available = !empty($data['available_now']);
            $existing = freelancer_row($uid);
            if ($existing === null) {
                fail('Complete your runner profile before going available.', 422);
            }
            $stmt = $pdo->prepare('UPDATE freelancer_profiles SET available_now = ? WHERE user_id = ?');
            $stmt->execute([$available ? 1 : 0, $uid]);
            audit('freelancer.availability_set', 'user', (string) $uid, ['available' => $available]);
            json_out(freelancer_snapshot($user));
        }

        case 'accept-job': {
            $profile = freelancer_row($uid);
            if (!$profile || !$profile['onboarding_completed_at']) {
                fail('Complete your runner profile before accepting jobs.', 422);
            }
            if (!empty(runner_launch_config()['kyc_required']) && ($profile['kyc_status'] ?? 'not_submitted') !== 'approved') {
                fail('Verify your ID before accepting jobs. Upload your document from your runner profile.', 422, ['kyc_status' => 'KYC approval required.']);
            }
            if (empty($profile['available_now'])) {
                fail('Switch availability on before accepting jobs.', 422, ['available_now' => 'You are currently offline.']);
            }
            $jobId = (int) ($data['job_id'] ?? 0);
            $stmt = $pdo->prepare(
                "UPDATE runner_jobs SET status = 'accepted', runner_id = ?, accepted_at = NOW()
                  WHERE id = ? AND status = 'available'"
            );
            $stmt->execute([$uid, $jobId]);
            if ($stmt->rowCount() === 0) {
                fail_error('job_taken', 'That job was just taken. Refresh the list for other errands.', 409);
            }
            runner_job_event($jobId, $uid, 'accepted', 'Runner accepted the job');
            runner_notify($uid, 'Job accepted', 'Check the pickup and drop-off details in your active jobs.');
            $jobStmt = $pdo->prepare('SELECT * FROM runner_jobs WHERE id = ? LIMIT 1');
            $jobStmt->execute([$jobId]);
            $jobRow = $jobStmt->fetch();
            if ($jobRow) runner_notify_job_parties($jobRow, 'accepted');
            audit('runner.job_accepted', 'runner_job', (string) $jobId, ['runner_id' => $uid]);
            json_out(freelancer_snapshot($user));
        }

        case 'decline-job': {
            $jobId = (int) ($data['job_id'] ?? 0);
            $check = $pdo->prepare('SELECT id FROM runner_jobs WHERE id = ? LIMIT 1');
            $check->execute([$jobId]);
            if (!$check->fetch()) {
                fail_error('job_not_found', 'That job is no longer available.', 404);
            }
            $reason = isset($data['reason']) && is_scalar($data['reason']) ? mb_substr(trim((string) $data['reason']), 0, 255) : null;
            $stmt = $pdo->prepare('INSERT IGNORE INTO runner_job_declines (job_id, runner_id, reason) VALUES (?, ?, ?)');
            $stmt->execute([$jobId, $uid, $reason]);
            runner_job_event($jobId, $uid, 'declined', $reason);
            json_out(freelancer_snapshot($user));
        }

        case 'cancel-job': {
            $jobId = (int) ($data['job_id'] ?? 0);
            $reason = isset($data['reason']) && is_scalar($data['reason'])
                ? mb_substr(trim((string) $data['reason']), 0, 255) : null;
            $stmt = $pdo->prepare(
                "UPDATE runner_jobs
                    SET status = 'available', runner_id = NULL, accepted_at = NULL, picked_up_at = NULL,
                        expires_at = DATE_ADD(NOW(), INTERVAL 1 DAY)
                  WHERE id = ? AND runner_id = ? AND status IN ('accepted','picked_up')"
            );
            $stmt->execute([$jobId, $uid]);
            if ($stmt->rowCount() === 0) {
                fail('This job cannot be cancelled.', 409);
            }
            runner_job_event($jobId, $uid, 'cancelled_by_runner', $reason);
            runner_notify($uid, 'Job released', 'The errand has been returned to the available list.');
            json_out(freelancer_snapshot($user));
        }

        case 'pickup-job': {
            $jobId = (int) ($data['job_id'] ?? 0);
            $stmt = $pdo->prepare(
                "UPDATE runner_jobs SET status = 'picked_up', picked_up_at = NOW()
                  WHERE id = ? AND runner_id = ? AND status = 'accepted'"
            );
            $stmt->execute([$jobId, $uid]);
            if ($stmt->rowCount() === 0) {
                fail('This job cannot be marked as picked up.', 409);
            }
            runner_job_event($jobId, $uid, 'picked_up', 'Runner confirmed pickup');
            runner_notify($uid, 'Pickup confirmed', 'Deliver the errand and mark it delivered to add the fee to your earnings.');
            $jobStmt = $pdo->prepare('SELECT * FROM runner_jobs WHERE id = ? LIMIT 1');
            $jobStmt->execute([$jobId]);
            $jobRow = $jobStmt->fetch();
            if ($jobRow) {
                runner_notify_job_parties($jobRow, 'picked_up');
                $otp = runner_job_issue_delivery_otp($jobId);
                if (!empty($otp['required'])) {
                    runner_notify($uid, 'Receiver code sent', 'Ask the receiver for the 6-digit code before marking this errand delivered.');
                }
            }
            json_out(freelancer_snapshot($user));
        }

        case 'resend-delivery-otp': {
            $jobId = (int) ($data['job_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM runner_jobs WHERE id = ? AND runner_id = ? AND status = 'picked_up' LIMIT 1");
            $stmt->execute([$jobId, $uid]);
            if (!$stmt->fetch()) { fail('This job is not awaiting delivery.', 409); }
            $result = runner_job_issue_delivery_otp($jobId);
            if (empty($result['required'])) {
                fail('The receiver has no deliverable email channel; use the delivery note/photo instead.', 422);
            }
            runner_notify($uid, 'Receiver code resent', 'A new 6-digit code was sent to the receiver.');
            json_out(freelancer_snapshot($user));
        }

        case 'deliver-job': {
            $jobId = (int) ($data['job_id'] ?? 0);
            $proof = param($data, 'proof_note', 3, 255, 'Delivery note');
            $jobStmt = $pdo->prepare("SELECT * FROM runner_jobs WHERE id = ? AND runner_id = ? LIMIT 1");
            $jobStmt->execute([$jobId, $uid]);
            $jobRow = $jobStmt->fetch();
            if (!$jobRow || $jobRow['status'] !== 'picked_up') {
                fail('This job cannot be marked as delivered.', 409);
            }
            if (!empty($jobRow['delivery_otp_required'])) {
                $otp = preg_replace('/\D+/', '', (string) ($data['otp'] ?? '')) ?? '';
                if (strlen($otp) !== 6) { fail('Enter the 6-digit receiver code.', 422, ['otp' => 'Enter the 6-digit code from the receiver.']); }
                if (empty($jobRow['delivery_otp_hash']) || empty($jobRow['delivery_otp_expires_at']) || strtotime((string) $jobRow['delivery_otp_expires_at']) < time()) {
                    fail('The receiver code has expired. Resend a new code.', 422);
                }
                if ((int) $jobRow['delivery_otp_attempts'] >= 5) {
                    fail('Too many incorrect receiver codes. Resend a new code.', 429);
                }
                if (!password_verify($otp, (string) $jobRow['delivery_otp_hash'])) {
                    db()->prepare('UPDATE runner_jobs SET delivery_otp_attempts = delivery_otp_attempts + 1 WHERE id = ?')->execute([$jobId]);
                    fail('That receiver code is not correct.', 422, ['otp' => 'Check the code with the receiver.']);
                }
            }
            $stmt = $pdo->prepare(
                "UPDATE runner_jobs SET status = 'delivered', delivered_at = NOW(), proof_note = ?,
                    delivery_otp_hash = NULL, delivery_otp_required = 0, delivery_otp_attempts = 0
                  WHERE id = ? AND runner_id = ? AND status = 'picked_up'"
            );
            $stmt->execute([$proof, $jobId, $uid]);
            if ($stmt->rowCount() === 0) {
                fail('This job cannot be marked as delivered.', 409);
            }
            runner_job_event($jobId, $uid, 'delivered', $proof);
            runner_generate_rating_token($jobId);
            growth_qualify_referral($uid, 'runner');
            runner_notify_job_parties($jobRow, 'delivered');
            runner_notify($uid, 'Job completed', 'KES ' . number_format((int) $jobRow['fee_kes']) . ' added to your pending payout for ' . $jobRow['ref'] . '.');
            audit('runner.job_delivered', 'runner_job', (string) $jobId, ['runner_id' => $uid, 'otp_verified' => !empty($jobRow['delivery_otp_required'])]);
            json_out(freelancer_snapshot($user));
        }

        case 'mark-notifications-read': {
            $stmt = $pdo->prepare('UPDATE runner_notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL');
            $stmt->execute([$uid]);
            json_out(freelancer_snapshot($user));
        }

        default:
            fail_error('unknown_action', 'Unknown freelancer action.', 400);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_out(freelancer_snapshot($user));
}

require_post();
verify_csrf();
freelancer_dispatch($user);
