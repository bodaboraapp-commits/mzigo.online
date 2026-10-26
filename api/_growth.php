<?php
/**
 * Mzigo.online — growth engine: runner ratings/tiers/bonuses, referrals, coupons.
 */
declare(strict_types=1);

function incentive_config(): array
{
    $defaults = [
        'enabled' => true,
        'bonus_cap_month_kes' => 2000,
        'tiers' => [
            'silver'   => ['label' => 'Silver',   'min_jobs' => 20,  'min_rating' => 4.5, 'rate' => 3],
            'gold'     => ['label' => 'Gold',     'min_jobs' => 50,  'min_rating' => 4.7, 'rate' => 5],
            'platinum' => ['label' => 'Platinum', 'min_jobs' => 100, 'min_rating' => 4.8, 'rate' => 8],
        ],
        'welcome' => ['enabled' => true, 'jobs' => 3, 'min_rating' => 4.0, 'amount_kes' => 100],
        'reliability' => ['enabled' => true, 'jobs' => 10, 'min_rating' => 4.5, 'amount_kes' => 100],
        'comeback' => ['enabled' => true, 'days' => 14, 'jobs' => 3, 'amount_kes' => 100],
    ];
    $raw = platform_setting('incentive_config', '');
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) $defaults = array_replace_recursive($defaults, $decoded);
    }
    return $defaults;
}

function incentive_config_save(array $input): array
{
    $current = incentive_config();
    $clean = array_replace_recursive($current, $input);
    $clean['enabled'] = !empty($clean['enabled']);
    $clean['bonus_cap_month_kes'] = max(0, min(1000000, (int) $clean['bonus_cap_month_kes']));
    db()->prepare('INSERT INTO platform_settings (setting_key, setting_value) VALUES ("incentive_config", ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
        ->execute([json_encode($clean, JSON_UNESCAPED_SLASHES)]);
    return $clean;
}

function referral_config(): array
{
    $defaults = [
        'enabled' => true,
        'attribution_days' => 60,
        'budget_cap_month_kes' => 25000,
        'runner' => ['referrer_kes' => 500, 'referee_kes' => 100, 'qualify_jobs' => 5],
        'business' => ['referrer_kes' => 500, 'referee_kes' => 0, 'referee_percent' => 10],
        'customer' => ['referrer_kes' => 100, 'referee_kes' => 100],
        'milestones' => [[3, 300], [10, 1000], [25, 2500]],
        'ab_test' => [
            'enabled' => true,
            'variants' => [
                'A' => 'I use Mzigo.online to send parcels across Kenya. Join with my code {code}: ',
                'B' => 'Sending parcels should be easy. I use Mzigo.online - register, drop off once, track to collection. Use my code {code}: ',
            ],
        ],
    ];
    $raw = platform_setting('referral_config', '');
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) $defaults = array_replace_recursive($defaults, $decoded);
    }
    return $defaults;
}

function referral_config_save(array $input): array
{
    $clean = array_replace_recursive(referral_config(), $input);
    $clean['enabled'] = !empty($clean['enabled']);
    $clean['attribution_days'] = max(1, min(365, (int) $clean['attribution_days']));
    $clean['budget_cap_month_kes'] = max(0, (int) $clean['budget_cap_month_kes']);
    $ab = is_array($clean['ab_test'] ?? null) ? $clean['ab_test'] : [];
    $clean['ab_test'] = [
        'enabled' => !empty($ab['enabled']),
        'variants' => [
            'A' => mb_substr(trim((string) (($ab['variants']['A'] ?? '') ?: 'I use Mzigo.online to send parcels across Kenya. Join with my code {code}: ')), 0, 500),
            'B' => mb_substr(trim((string) (($ab['variants']['B'] ?? '') ?: 'Sending parcels should be easy. I use Mzigo.online - register, drop off once, track to collection. Use my code {code}: ')), 0, 500),
        ],
    ];
    db()->prepare('INSERT INTO platform_settings (setting_key, setting_value) VALUES ("referral_config", ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
        ->execute([json_encode($clean, JSON_UNESCAPED_SLASHES)]);
    return $clean;
}

/* --------------------------------------------------------------- runner quality */
function runner_rating_stats(int $runnerId, int $days = 90): array
{
    $stmt = db()->prepare(
        "SELECT COUNT(*) AS jobs,
                AVG(r.rating) AS avg_rating,
                SUM(j.status = 'delivered') AS delivered
           FROM runner_jobs j
           LEFT JOIN runner_ratings r ON r.job_id = j.id
          WHERE j.runner_id = ? AND j.accepted_at >= DATE_SUB(NOW(), INTERVAL ? DAY)"
    );
    $stmt->execute([$runnerId, $days]);
    $row = $stmt->fetch() ?: [];
    $delivered = (int) ($row['delivered'] ?? 0);
    $stmt = db()->prepare("SELECT COUNT(*) FROM runner_job_events WHERE runner_id = ? AND event = 'cancelled_by_runner' AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)");
    $stmt->execute([$runnerId, $days]);
    $cancelled = (int) $stmt->fetchColumn();
    $avg = $row['avg_rating'] !== null ? round((float) $row['avg_rating'], 2) : null;
    $completion = ($delivered + $cancelled) > 0 ? round(100 * $delivered / ($delivered + $cancelled)) : 100;
    return ['jobs' => $delivered, 'rating' => $avg, 'completion' => $completion];
}

function runner_tier(int $runnerId): array
{
    $cfg = incentive_config();
    $stats = runner_rating_stats($runnerId);
    $tierCode = 'bronze';
    $tier = ['label' => 'Bronze', 'rate' => 0, 'min_jobs' => 0, 'min_rating' => 0];
    $next = null;
    foreach ($cfg['tiers'] as $code => $candidate) {
        if ($stats['jobs'] >= (int) $candidate['min_jobs'] && $stats['rating'] !== null && $stats['rating'] >= (float) $candidate['min_rating'] && $stats['completion'] >= 90) {
            $tierCode = $code;
            $tier = ['label' => $candidate['label'], 'rate' => (int) $candidate['rate'], 'min_jobs' => (int) $candidate['min_jobs'], 'min_rating' => (float) $candidate['min_rating']];
        } elseif ($next === null) {
            $next = ['code' => $code, 'label' => $candidate['label'], 'jobs_needed' => max(0, (int) $candidate['min_jobs'] - $stats['jobs'])];
        }
    }
    return ['code' => $tierCode, 'label' => $tier['label'], 'rate' => $tier['rate'], 'stats' => $stats, 'next' => $next, 'cap_kes' => (int) $cfg['bonus_cap_month_kes']];
}

function runner_bonus_month_total(int $runnerId): int
{
    $stmt = db()->prepare("SELECT COALESCE(SUM(amount_kes),0) FROM runner_bonus_ledger WHERE runner_id = ? AND created_at >= DATE_FORMAT(CURRENT_DATE(), '%Y-%m-01') AND status <> 'void'");
    $stmt->execute([$runnerId]);
    return (int) $stmt->fetchColumn();
}

function runner_bonus_add(int $runnerId, ?int $jobId, string $rule, string $label, int $amount, array $meta = []): void
{
    if ($amount <= 0) return;
    try {
        db()->prepare('INSERT IGNORE INTO runner_bonus_ledger (runner_id, job_id, rule_code, label, amount_kes, status, meta, created_at) VALUES (?, ?, ?, ?, ?, "pending", ?, NOW())')
            ->execute([$runnerId, $jobId, $rule, $label, $amount, json_encode($meta, JSON_UNESCAPED_SLASHES)]);
    } catch (Throwable $e) {
        error_log('bonus add failed: ' . $e->getMessage());
    }
}

function runner_evaluate_bonuses(int $runnerId, int $jobId, int $feeKes): void
{
    $cfg = incentive_config();
    if (empty($cfg['enabled'])) return;
    $tier = runner_tier($runnerId);
    $cap = (int) $cfg['bonus_cap_month_kes'];
    $used = runner_bonus_month_total($runnerId);
    $remaining = max(0, $cap - $used);
    $quality = (int) floor($feeKes * ($tier['rate'] / 100));
    if ($quality > 0) {
        runner_bonus_add($runnerId, $jobId, 'tier_bonus', $tier['label'] . ' quality bonus', min($quality, $remaining), ['tier' => $tier['code']]);
        $remaining -= min($quality, $remaining);
    }
    $stmt = db()->prepare('SELECT COUNT(*) FROM runner_jobs WHERE runner_id = ? AND status = "delivered"');
    $stmt->execute([$runnerId]);
    $delivered = (int) $stmt->fetchColumn();
    if (!empty($cfg['welcome']['enabled']) && $delivered >= (int) $cfg['welcome']['jobs'] && $remaining > 0) {
        runner_bonus_add($runnerId, 0, 'welcome_bonus', 'Welcome bonus', min((int) $cfg['welcome']['amount_kes'], $remaining));
    }
    if (!empty($cfg['reliability']['enabled']) && $remaining > 0) {
        $stmt = db()->prepare("SELECT COUNT(*) FROM runner_jobs WHERE runner_id = ? AND status = 'delivered' AND delivered_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $stmt->execute([$runnerId]);
        $weekJobs = (int) $stmt->fetchColumn();
        $stmt = db()->prepare("SELECT COUNT(*) FROM runner_bonus_ledger WHERE runner_id = ? AND rule_code = 'reliability_bonus' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $stmt->execute([$runnerId]);
        if ($weekJobs >= (int) $cfg['reliability']['jobs'] && !(int) $stmt->fetchColumn()) {
            runner_bonus_add($runnerId, $jobId, 'reliability_bonus', 'Reliability bonus', min((int) $cfg['reliability']['amount_kes'], $remaining));
        }
    }
    try {
        db()->prepare('UPDATE runner_jobs SET bonus_kes = (SELECT COALESCE(SUM(amount_kes),0) FROM runner_bonus_ledger WHERE job_id = ? AND status <> "void") WHERE id = ?')
            ->execute([$jobId, $jobId]);
    } catch (Throwable $e) { error_log('job bonus total failed: ' . $e->getMessage()); }
}


function runner_bonus_pending_total(int $runnerId): int
{
    $stmt = db()->prepare("SELECT COALESCE(SUM(amount_kes),0) FROM runner_bonus_ledger WHERE runner_id = ? AND status = 'pending'");
    $stmt->execute([$runnerId]);
    return (int) $stmt->fetchColumn();
}

function runner_bonus_mark_paid(int $runnerId, int $payoutId): void
{
    try {
        db()->prepare("UPDATE runner_bonus_ledger SET status = 'paid', payout_id = ? WHERE runner_id = ? AND status = 'pending'")
            ->execute([$payoutId, $runnerId]);
    } catch (Throwable $e) { error_log('bonus mark paid failed: ' . $e->getMessage()); }
}

function runner_rate_job(string $token, int $rating, string $comment, ?int $customerUserId = null, string $source = 'customer'): array
{
    $stmt = db()->prepare('SELECT * FROM runner_jobs WHERE rating_token = ? AND status = "delivered" LIMIT 1');
    $stmt->execute([$token]);
    $job = $stmt->fetch();
    if (!$job) return ['ok' => false, 'error' => 'Rating link is not valid or the job is not delivered yet.'];
    if ($rating < 1 || $rating > 5) return ['ok' => false, 'error' => 'Choose a rating from 1 to 5.'];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO runner_ratings (job_id, runner_id, customer_user_id, rating, comment, source, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE rating = VALUES(rating), comment = VALUES(comment), source = VALUES(source)')
            ->execute([(int) $job['id'], (int) $job['runner_id'], $customerUserId, $rating, $comment, $source]);
        $pdo->prepare('UPDATE runner_jobs SET rating = ?, rating_note = ?, rated_at = NOW() WHERE id = ?')
            ->execute([$rating, $comment, (int) $job['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    runner_evaluate_bonuses((int) $job['runner_id'], (int) $job['id'], (int) $job['fee_kes']);
    return ['ok' => true, 'job' => ['ref' => $job['ref'], 'runner_id' => (int) $job['runner_id']]];
}

function runner_generate_rating_token(int $jobId): void
{
    try {
        db()->prepare('UPDATE runner_jobs SET rating_token = ? WHERE id = ? AND rating_token IS NULL')
            ->execute([bin2hex(random_bytes(16)), $jobId]);
    } catch (Throwable $e) {
        error_log('rating token failed: ' . $e->getMessage());
    }
}

/* ------------------------------------------------------------------ referrals */
function growth_ensure_referral_code(int $userId, string $program): array
{
    $stmt = db()->prepare('SELECT code FROM referral_codes WHERE user_id = ? AND program = ? LIMIT 1');
    $stmt->execute([$userId, $program]);
    $code = $stmt->fetchColumn();
    if ($code) return ['code' => (string) $code];
    $code = 'MZ-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
    try {
        db()->prepare('INSERT INTO referral_codes (user_id, code, program, active, created_at) VALUES (?, ?, ?, 1, NOW())')
            ->execute([$userId, $code, $program]);
    } catch (Throwable $e) {
        $stmt->execute([$userId, $program]);
        $code = $stmt->fetchColumn() ?: $code;
    }
    return ['code' => (string) $code];
}

function growth_attribute_referral(int $refereeId, string $program, string $code, string $source = ''): void
{
    if ($code === '' || !referral_config()['enabled']) return;
    $stmt = db()->prepare('SELECT * FROM referral_codes WHERE code = ? AND program = ? AND active = 1 LIMIT 1');
    $stmt->execute([strtoupper(trim($code)), $program]);
    $row = $stmt->fetch();
    if (!$row) return;
    if ((int) $row['user_id'] === $refereeId) return;
    try {
        $meta = $source !== '' ? json_encode(['source' => mb_substr(trim($source), 0, 40)], JSON_UNESCAPED_SLASHES) : null;
        db()->prepare('INSERT IGNORE INTO referrals (referrer_user_id, referee_user_id, program, code, status, attributed_at, meta) VALUES (?, ?, ?, ?, "attributed", NOW(), ?)')
            ->execute([(int) $row['user_id'], $refereeId, $program, $row['code'], $meta]);
    } catch (Throwable $e) {
        error_log('referral attribution failed: ' . $e->getMessage());
    }
}

function growth_notify_user(int $userId, string $subject, string $body): void
{
    try {
        $stmt = db()->prepare('SELECT id, name, email, phone, comm_channel FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user || !function_exists('notification_send')) return;
        $parcel = ['id' => 0, 'tracking' => 'REFERRAL'];
        foreach (notification_targets($user) as $channel) {
            notification_send((string) $channel['code'], $user, $subject, $body, 'referral', $parcel,
                (string) ($channel['provider'] ?? 'log'), 'referral', (string) $userId, []);
        }
    } catch (Throwable $e) {
        error_log('referral notify failed: ' . $e->getMessage());
    }
}

function growth_award_milestones(int $userId, string $program): void
{
    $cfg = referral_config();
    $stmt = db()->prepare('SELECT COUNT(*) FROM referrals WHERE referrer_user_id = ? AND program = ? AND status = "qualified"');
    $stmt->execute([$userId, $program]);
    $qualified = (int) $stmt->fetchColumn();
    $kind = $program === 'runner' ? 'mpesa' : ($program === 'business' ? 'bill_credit' : 'parcel_credit');
    foreach (($cfg['milestones'] ?? []) as $milestone) {
        $count = (int) ($milestone[0] ?? 0);
        $amount = (int) ($milestone[1] ?? 0);
        if ($count <= 0 || $qualified < $count || $amount <= 0) continue;
        try {
            $stmt = db()->prepare('INSERT IGNORE INTO referral_milestones (user_id, program, milestone, amount_kes, kind, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
            $stmt->execute([$userId, $program, $count, $amount, $kind]);
            if ($stmt->rowCount() === 0) continue;
            if ($kind === 'mpesa') {
                runner_bonus_add($userId, 0, 'referral_milestone_' . $count, $count . '-referral milestone', $amount, ['milestone' => $count]);
            } else {
                growth_credit_add($userId, $amount, $kind, $count . '-referral milestone reward', 'MILESTONE-' . $count);
            }
            growth_notify_user($userId, 'Referral milestone unlocked', 'You reached ' . $count . ' activated referrals and earned KES ' . number_format($amount) . '. Keep sharing!');
        } catch (Throwable $e) {
            error_log('referral milestone failed: ' . $e->getMessage());
        }
    }
}

function growth_credit_add(int $userId, int $amount, string $kind, string $note, string $reference = ''): void
{
    if ($amount <= 0) return;
    try {
        db()->prepare('INSERT INTO credit_ledger (user_id, amount_kes, kind, status, reference, note, created_at) VALUES (?, ?, ?, "available", ?, ?, NOW())')
            ->execute([$userId, $amount, $kind, $reference, $note]);
    } catch (Throwable $e) {
        error_log('credit add failed: ' . $e->getMessage());
    }
}

function growth_referral_reward(int $referralId, string $role, int $beneficiaryId, int $amount, string $kind, string $note): void
{
    if ($amount <= 0) return;
    try {
        db()->prepare('INSERT IGNORE INTO referral_rewards (referral_id, beneficiary_user_id, role, amount_kes, kind, status, note, created_at, paid_at) VALUES (?, ?, ?, ?, ?, "paid", ?, NOW(), NOW())')
            ->execute([$referralId, $beneficiaryId, $role, $amount, $kind, $note]);
        if ($kind === 'mpesa') {
            runner_bonus_add($beneficiaryId, 0, 'referral_' . $role, 'Referral reward', $amount, ['referral_id' => $referralId]);
        } else {
            growth_credit_add($beneficiaryId, $amount, $kind, $note, 'REF-' . $referralId);
        }
    } catch (Throwable $e) {
        error_log('referral reward failed: ' . $e->getMessage());
    }
}

function growth_qualify_referral(int $refereeId, string $program): void
{
    $stmt = db()->prepare("SELECT * FROM referrals WHERE referee_user_id = ? AND program = ? AND status <> 'cancelled' LIMIT 1");
    $stmt->execute([$refereeId, $program]);
    $referral = $stmt->fetch();
    if (!$referral) return;
    $cfg = referral_config();
    $stmt = db()->prepare('SELECT COUNT(*) FROM referrals WHERE referrer_user_id = ? AND program = ? AND status = "qualified" AND attributed_at >= DATE_FORMAT(CURRENT_DATE(), "%Y-%m-01")');
    $stmt->execute([(int) $referral['referrer_user_id'], $program]);
    if ((int) $stmt->fetchColumn() >= 50) return; // simple monthly cap
    if ($program === 'runner') {
        $stmt = db()->prepare('SELECT COUNT(*) FROM runner_jobs WHERE runner_id = ? AND status = "delivered"');
        $stmt->execute([$refereeId]);
        $jobs = (int) $stmt->fetchColumn();
        if ($jobs >= 1) {
            growth_referral_reward((int) $referral['id'], 'referee', $refereeId, (int) $cfg['runner']['referee_kes'], 'mpesa', 'Referee first-job reward');
        }
        if ($jobs >= (int) $cfg['runner']['qualify_jobs']) {
            db()->prepare('UPDATE referrals SET status = "qualified", qualified_at = NOW() WHERE id = ?')->execute([(int) $referral['id']]);
            growth_referral_reward((int) $referral['id'], 'referrer', (int) $referral['referrer_user_id'], (int) $cfg['runner']['referrer_kes'], 'mpesa', 'Runner referral reward');
            growth_award_milestones((int) $referral['referrer_user_id'], 'runner');
        }
    } elseif ($program === 'business') {
        $stmt = db()->prepare('SELECT COUNT(*) FROM parcels WHERE user_id = ?');
        $stmt->execute([$refereeId]);
        if ((int) $stmt->fetchColumn() >= 1) {
            db()->prepare('UPDATE referrals SET status = "qualified", qualified_at = NOW() WHERE id = ?')->execute([(int) $referral['id']]);
            growth_referral_reward((int) $referral['id'], 'referrer', (int) $referral['referrer_user_id'], (int) $cfg['business']['referrer_kes'], 'bill_credit', 'Business referral credit');
            growth_award_milestones((int) $referral['referrer_user_id'], 'business');
        }
    } else {
        $paid = 0;
        try {
            $stmt = db()->prepare('SELECT phone FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$refereeId]);
            $phone = (string) $stmt->fetchColumn();
            if ($phone !== '') {
                $stmt = db()->prepare('SELECT COUNT(*) FROM parcels WHERE receiver_phone_key = ? AND payment_status = "paid"');
                $stmt->execute([phone_key($phone)]);
                $paid = (int) $stmt->fetchColumn();
            }
        } catch (Throwable $e) { $paid = 0; }
        if ($paid >= 1) {
            db()->prepare('UPDATE referrals SET status = "qualified", qualified_at = NOW() WHERE id = ?')->execute([(int) $referral['id']]);
            growth_referral_reward((int) $referral['id'], 'referrer', (int) $referral['referrer_user_id'], (int) $cfg['customer']['referrer_kes'], 'parcel_credit', 'Customer referral credit');
            growth_referral_reward((int) $referral['id'], 'referee', $refereeId, (int) $cfg['customer']['referee_kes'], 'parcel_credit', 'Welcome referral credit');
            growth_award_milestones((int) $referral['referrer_user_id'], 'customer');
        }
    }
}


function growth_campaign_message(array $campaign, string $code): string
{
    $link = rtrim((string) (config()['app']['url'] ?? 'https://mzigo.online'), '/') . '/refer?ref=' . rawurlencode($code) . '&src=campaign';
    $message = (string) ($campaign['message'] ?? '');
    return str_replace(['{code}', '{link}'], [$code, $link], $message !== '' ? $message : 'Share your Mzigo.online code {code} and earn when a friend activates. {link}');
}

function growth_campaign_send(int $campaignId): array
{
    $stmt = db()->prepare('SELECT * FROM referral_campaigns WHERE id = ? LIMIT 1');
    $stmt->execute([$campaignId]);
    $campaign = $stmt->fetch();
    if (!$campaign) return ['ok' => false, 'sent' => 0, 'error' => 'Campaign not found.'];
    $stmt = db()->query(
        "SELECT u.id, rc.code FROM users u JOIN referral_codes rc ON rc.user_id = u.id
          WHERE rc.active = 1
            AND NOT EXISTS (SELECT 1 FROM referrals r WHERE r.referrer_user_id = u.id AND r.attributed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY))
          ORDER BY u.id ASC LIMIT 60"
    );
    $sent = 0;
    foreach ($stmt->fetchAll() as $row) {
        growth_notify_user((int) $row['id'], (string) $campaign['name'], growth_campaign_message($campaign, (string) $row['code']));
        $sent++;
    }
    $next = null;
    if ($campaign['recurrence'] === 'weekly') {
        $next = date('Y-m-d H:i:s', strtotime('+7 days'));
    } elseif ($campaign['recurrence'] === 'monthly') {
        $next = date('Y-m-d H:i:s', strtotime('+1 month'));
    }
    db()->prepare('UPDATE referral_campaigns SET sent_count = sent_count + ?, last_sent_at = NOW(), status = ?, next_run_at = ? WHERE id = ?')
        ->execute([$sent, $campaign['recurrence'] === 'once' ? 'sent' : 'scheduled', $next, $campaignId]);
    return ['ok' => true, 'sent' => $sent];
}

function growth_campaign_run_due(): array
{
    $stmt = db()->query("SELECT id FROM referral_campaigns WHERE status = 'scheduled' AND next_run_at <= NOW() ORDER BY next_run_at ASC LIMIT 10");
    $results = [];
    foreach ($stmt->fetchAll() as $row) {
        $results[] = ['id' => (int) $row['id']] + growth_campaign_send((int) $row['id']);
    }
    return $results;
}

function growth_credit_balance(int $userId, string $kind): int
{
    $stmt = db()->prepare('SELECT COALESCE(SUM(amount_kes - used_kes),0) FROM credit_ledger WHERE user_id = ? AND kind = ? AND status = "available"');
    $stmt->execute([$userId, $kind]);
    return (int) $stmt->fetchColumn();
}

/** Spend up to $max from available referral credits; returns amount used. */
function growth_credit_use(int $userId, string $kind, int $max, string $reference): int
{
    if ($max <= 0) return 0;
    $remaining = $max;
    $used = 0;
    $stmt = db()->prepare('SELECT id, amount_kes, used_kes FROM credit_ledger WHERE user_id = ? AND kind = ? AND status = "available" AND amount_kes > used_kes ORDER BY created_at ASC, id ASC');
    $stmt->execute([$userId, $kind]);
    foreach ($stmt->fetchAll() as $row) {
        if ($remaining <= 0) break;
        $available = (int) $row['amount_kes'] - (int) $row['used_kes'];
        $take = min($available, $remaining);
        $newUsed = (int) $row['used_kes'] + $take;
        db()->prepare('UPDATE credit_ledger SET used_kes = ?, status = ?, used_at = IF(? >= amount_kes, NOW(), used_at), redeemed_reference = ? WHERE id = ?')
            ->execute([$newUsed, $newUsed >= (int) $row['amount_kes'] ? 'used' : 'available', $newUsed, $reference, (int) $row['id']]);
        $remaining -= $take;
        $used += $take;
    }
    return $used;
}

function growth_referral_summary(int $userId, string $program): array
{
    $code = growth_ensure_referral_code($userId, $program)['code'];
    $stmt = db()->prepare('SELECT COUNT(*) FROM referral_clicks WHERE code = ?');
    $stmt->execute([$code]);
    $clicks = (int) $stmt->fetchColumn();
    $stmt = db()->prepare('SELECT COUNT(*) FROM referrals WHERE referrer_user_id = ? AND program = ?');
    $stmt->execute([$userId, $program]);
    $signups = (int) $stmt->fetchColumn();
    $stmt = db()->prepare('SELECT COUNT(*) FROM referrals WHERE referrer_user_id = ? AND program = ? AND status = "qualified"');
    $stmt->execute([$userId, $program]);
    $activated = (int) $stmt->fetchColumn();
    $stmt = db()->prepare('SELECT COALESCE(SUM(amount_kes),0) FROM referral_rewards WHERE beneficiary_user_id = ? AND status = "paid"');
    $stmt->execute([$userId]);
    $earned = (int) $stmt->fetchColumn();
    return ['code' => $code, 'clicks' => $clicks, 'signups' => $signups, 'activated' => $activated, 'earned_kes' => $earned];
}

/* --------------------------------------------------------------------- coupons */
function coupon_lookup(string $code): ?array
{
    $stmt = db()->prepare('SELECT * FROM coupons WHERE UPPER(code) = ? LIMIT 1');
    $stmt->execute([strtoupper(trim($code))]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function coupon_validate(string $code, int $amountKes, string $context = 'delivery', ?int $userId = null): array
{
    $coupon = coupon_lookup($code);
    if (!$coupon) return ['ok' => false, 'error' => 'Coupon not found.'];
    if (empty($coupon['active'])) return ['ok' => false, 'error' => 'This coupon is not active.'];
    if (!in_array($coupon['applies_to'], [$context, 'all'], true)) return ['ok' => false, 'error' => 'This coupon does not apply to this checkout.'];
    if (!empty($coupon['starts_at']) && strtotime((string) $coupon['starts_at']) > time()) return ['ok' => false, 'error' => 'This coupon is not active yet.'];
    if (!empty($coupon['expires_at']) && strtotime((string) $coupon['expires_at']) < time()) return ['ok' => false, 'error' => 'This coupon has expired.'];
    if ($amountKes < (int) $coupon['min_spend_kes']) return ['ok' => false, 'error' => 'Minimum spend for this coupon is KES ' . (int) $coupon['min_spend_kes'] . '.'];
    if (!empty($coupon['usage_limit'])) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id = ?');
        $stmt->execute([(int) $coupon['id']]);
        if ((int) $stmt->fetchColumn() >= (int) $coupon['usage_limit']) return ['ok' => false, 'error' => 'This coupon has reached its usage limit.'];
    }
    if ($userId) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id = ? AND user_id = ?');
        $stmt->execute([(int) $coupon['id'], $userId]);
        if ((int) $stmt->fetchColumn() >= (int) $coupon['per_user_limit']) return ['ok' => false, 'error' => 'You have already used this coupon.'];
    }
    $discount = $coupon['type'] === 'percent'
        ? (int) floor($amountKes * ((int) $coupon['value'] / 100))
        : (int) $coupon['value'];
    if (!empty($coupon['max_discount_kes'])) $discount = min($discount, (int) $coupon['max_discount_kes']);
    $discount = min($discount, $amountKes);
    return ['ok' => true, 'coupon' => ['id' => (int) $coupon['id'], 'code' => $coupon['code'], 'name' => $coupon['name'], 'type' => $coupon['type'], 'value' => (int) $coupon['value']], 'discount_kes' => $discount, 'final_kes' => $amountKes - $discount];
}

function coupon_redeem(array $coupon, int $amountKes, int $discountKes, string $context, string $reference, ?int $userId): void
{
    try {
        db()->prepare('INSERT INTO coupon_redemptions (coupon_id, user_id, context, reference, original_kes, discount_kes, final_kes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())')
            ->execute([(int) $coupon['id'], $userId, $context, $reference, $amountKes, $discountKes, $amountKes - $discountKes]);
    } catch (Throwable $e) {
        error_log('coupon redeem failed: ' . $e->getMessage());
    }
}
