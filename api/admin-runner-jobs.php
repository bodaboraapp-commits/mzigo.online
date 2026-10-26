<?php
/**
 * Mzigo.online — admin/ops runner job dispatch console.
 *
 * GET  /api/admin-runner-jobs.php   jobs + active runners
 * POST /api/admin-runner-jobs.php   create-job | assign-job | cancel-job
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_runner_jobs.php';
require_once __DIR__ . '/_growth.php';

$admin = require_platform_admin();

function admin_runner_jobs_list(): array
{
    $pdo = db();
    $jobs = $pdo->query(
        "SELECT j.*, u.name AS runner_name, u.phone AS runner_phone
           FROM runner_jobs j LEFT JOIN users u ON u.id = j.runner_id
          ORDER BY j.created_at DESC LIMIT 120"
    )->fetchAll();
    $runners = $pdo->query(
        "SELECT u.id, u.name, u.phone, fp.available_now
           FROM users u LEFT JOIN freelancer_profiles fp ON fp.user_id = u.id
          WHERE u.role = 'freelancer' AND u.status = 'active'
          ORDER BY u.name ASC LIMIT 200"
    )->fetchAll();
    return [
        'ok' => true,
        'jobs' => array_map(fn ($j) => [
            'id' => (int) $j['id'],
            'ref' => (string) $j['ref'],
            'pickup' => (string) $j['pickup'],
            'dropoff' => (string) $j['dropoff'],
            'fee_kes' => (int) $j['fee_kes'],
            'customer_fee_kes' => (int) $j['customer_fee_kes'],
            'status' => (string) $j['status'],
            'runner_id' => $j['runner_id'] !== null ? (int) $j['runner_id'] : null,
            'runner_name' => (string) ($j['runner_name'] ?? ''),
            'runner_phone' => (string) ($j['runner_phone'] ?? ''),
            'description' => (string) ($j['description'] ?? ''),
            'created_at' => $j['created_at'],
            'accepted_at' => $j['accepted_at'],
            'delivered_at' => $j['delivered_at'],
            'expires_at' => $j['expires_at'] ?? null,
            'proof_note' => (string) ($j['proof_note'] ?? ''),
            'proof_photo_path' => (string) ($j['proof_photo_path'] ?? ''),
            'rating' => $j['rating'] !== null ? (int) $j['rating'] : null,
            'rating_note' => (string) ($j['rating_note'] ?? ''),
        ], $jobs),
        'runners' => array_map(fn ($r) => [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'phone' => (string) $r['phone'],
            'available_now' => (bool) $r['available_now'],
        ], $runners),
    ];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_out(admin_runner_jobs_list());
}

require_post();
verify_csrf();
$data = body_json();
$action = (string) ($data['action'] ?? '');
$pdo = db();

switch ($action) {
    case 'create-job': {
        $pickup = param($data, 'pickup', 2, 160, 'Pickup');
        $dropoff = param($data, 'dropoff', 2, 160, 'Drop-off');
        $fee = (int) ($data['fee_kes'] ?? 0);
        if ($fee < 1) { fail('Enter a positive runner fee.', 422, ['fee_kes' => 'Invalid fee.']); }
        $description = isset($data['description']) && is_scalar($data['description'])
            ? mb_substr(trim((string) $data['description']), 0, 255) : null;
        $contactName = isset($data['contact_name']) && is_scalar($data['contact_name'])
            ? mb_substr(trim((string) $data['contact_name']), 0, 120) : null;
        $contactPhone = isset($data['contact_phone']) && is_scalar($data['contact_phone'])
            ? mb_substr(trim((string) $data['contact_phone']), 0, 20) : null;
        $receiverName = isset($data['receiver_name']) && is_scalar($data['receiver_name'])
            ? mb_substr(trim((string) $data['receiver_name']), 0, 120) : null;
        $receiverPhone = isset($data['receiver_phone']) && is_scalar($data['receiver_phone'])
            ? mb_substr(trim((string) $data['receiver_phone']), 0, 20) : null;
        $ref = unique_token('runner_jobs', 'ref', 'RJ-', 6);
        $stmt = $pdo->prepare(
            "INSERT INTO runner_jobs
               (ref, pickup, dropoff, fee_kes, customer_fee_kes, status, description,
                contact_name, contact_phone, receiver_name, receiver_phone, created_at, expires_at)
             VALUES (?, ?, ?, ?, ?, 'available', ?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 3 DAY))"
        );
        $stmt->execute([$ref, $pickup, $dropoff, $fee, $fee, $description, $contactName, $contactPhone, $receiverName, $receiverPhone]);
        $jobId = (int) $pdo->lastInsertId();
        runner_job_event($jobId, null, 'created_by_ops', 'Created from admin console');
        runner_job_broadcast(['id' => $jobId, 'pickup' => $pickup, 'dropoff' => $dropoff, 'fee_kes' => $fee]);
        audit('admin.runner_job_created', 'runner_job', (string) $jobId, ['ref' => $ref, 'fee_kes' => $fee]);
        json_out(admin_runner_jobs_list() + ['created' => $ref]);
    }

    case 'assign-job': {
        $jobId = (int) ($data['job_id'] ?? 0);
        $runnerId = (int) ($data['runner_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'freelancer' AND status = 'active' LIMIT 1");
        $stmt->execute([$runnerId]);
        if (!$stmt->fetch()) { fail('Choose an active runner.', 422); }
        $launch = runner_launch_config();
        if (!empty($launch['kyc_required'])) {
            $stmt = $pdo->prepare("SELECT kyc_status FROM freelancer_profiles WHERE user_id = ? LIMIT 1");
            $stmt->execute([$runnerId]);
            $kyc = (string) ($stmt->fetchColumn() ?: 'not_submitted');
            if ($kyc !== 'approved') {
                fail('That runner has not completed KYC verification.', 422);
            }
        }
        $stmt = $pdo->prepare(
            "UPDATE runner_jobs SET status = 'accepted', runner_id = ?, accepted_at = NOW()
              WHERE id = ? AND status = 'available'"
        );
        $stmt->execute([$runnerId, $jobId]);
        if ($stmt->rowCount() === 0) { fail('Only available jobs can be assigned.', 409); }
        $stmt = $pdo->prepare('SELECT * FROM runner_jobs WHERE id = ? LIMIT 1');
        $stmt->execute([$jobId]);
        $job = $stmt->fetch();
        if ($job) {
            runner_job_event($jobId, $runnerId, 'assigned_by_ops', 'Assigned from admin console');
            runner_notify($runnerId, 'Job assigned', 'An errand was assigned to you: ' . $job['pickup'] . ' → ' . $job['dropoff'] . '.');
            runner_notify_job_parties($job, 'accepted');
        }
        audit('admin.runner_job_assigned', 'runner_job', (string) $jobId, ['runner_id' => $runnerId]);
        json_out(admin_runner_jobs_list());
    }

    case 'cancel-job': {
        $jobId = (int) ($data['job_id'] ?? 0);
        $stmt = $pdo->prepare(
            "UPDATE runner_jobs SET status = 'cancelled', cancelled_at = NOW()
              WHERE id = ? AND status <> 'delivered'"
        );
        $stmt->execute([$jobId]);
        if ($stmt->rowCount() === 0) { fail('This job cannot be cancelled.', 409); }
        runner_job_event($jobId, null, 'cancelled_by_ops', 'Cancelled from admin console');
        audit('admin.runner_job_cancelled', 'runner_job', (string) $jobId, []);
        json_out(admin_runner_jobs_list());
    }

    case 'rate-job': {
        $jobId = (int) ($data['job_id'] ?? 0);
        $rating = (int) ($data['rating'] ?? 0);
        $note = isset($data['note']) && is_scalar($data['note']) ? mb_substr(trim((string) $data['note']), 0, 255) : null;
        if ($rating < 1 || $rating > 5) { fail('Choose a rating between 1 and 5.', 422); }
        $stmt = $pdo->prepare("UPDATE runner_jobs SET rating = ?, rating_note = ?, rated_by = ?, rated_at = NOW() WHERE id = ? AND status = 'delivered'");
        $stmt->execute([$rating, $note, (int) $admin['id'], $jobId]);
        if ($stmt->rowCount() === 0) { fail('Only delivered jobs can be rated.', 409); }
        $stmt = $pdo->prepare('SELECT runner_id, fee_kes FROM runner_jobs WHERE id = ? LIMIT 1');
        $stmt->execute([$jobId]);
        $rated = $stmt->fetch();
        if ($rated) {
            $pdo->prepare('INSERT INTO runner_ratings (job_id, runner_id, rating, comment, source, created_at) VALUES (?, ?, ?, ?, "admin", NOW()) ON DUPLICATE KEY UPDATE rating = VALUES(rating), comment = VALUES(comment), source = "admin"')
                ->execute([$jobId, (int) $rated['runner_id'], $rating, $note]);
            runner_evaluate_bonuses((int) $rated['runner_id'], $jobId, (int) $rated['fee_kes']);
        }
        audit('admin.runner_job_rated', 'runner_job', (string) $jobId, ['rating' => $rating]);
        json_out(admin_runner_jobs_list());
    }

    case 'reopen-job': {
        $jobId = (int) ($data['job_id'] ?? 0);
        $stmt = $pdo->prepare(
            "UPDATE runner_jobs SET status = 'available', runner_id = NULL, accepted_at = NULL,
                    picked_up_at = NULL, expires_at = DATE_ADD(NOW(), INTERVAL 2 DAY)
              WHERE id = ? AND status IN ('cancelled','accepted','picked_up')"
        );
        $stmt->execute([$jobId]);
        if ($stmt->rowCount() === 0) { fail('Only cancelled or active jobs can be reopened.', 409); }
        runner_job_event($jobId, null, 'reopened_by_ops', 'Reopened from admin console');
        audit('admin.runner_job_reopened', 'runner_job', (string) $jobId, []);
        json_out(admin_runner_jobs_list());
    }

    default:
        fail_error('unknown_action', 'Unknown runner job action.', 400);
}

/* Unreachable: every case returns. */
