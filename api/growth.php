<?php
/**
 * Mzigo.online — growth API: referral summary, ratings, coupon validation.
 *
 * GET  /api/growth.php                        public config + leaderboard; owner summary
 * POST actions: validate_coupon, rate_runner, referral_click
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_growth.php';
require __DIR__ . '/_notifications.php';

$user = current_user();
$pdo = db();

function growth_program_for_user(?array $user, ?array $tenant): string
{
    if (!$user) return 'customer';
    if (($user['role'] ?? '') === 'freelancer') return 'runner';
    if ($tenant) return 'business';
    if (($user['role'] ?? '') === 'customer') return 'customer';
    return 'business';
}

function growth_public_referrals(): array
{
    $cfg = referral_config();
    $rows = [];
    try {
        $rows = db()->query(
            "SELECT u.name, COUNT(*) AS referrals
               FROM referrals r JOIN users u ON u.id = r.referrer_user_id
              WHERE r.attributed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
              GROUP BY r.referrer_user_id, u.name
              ORDER BY referrals DESC LIMIT 10"
        )->fetchAll();
    } catch (Throwable $e) { error_log('leaderboard failed: ' . $e->getMessage()); }
    $leaderboard = array_map(function ($r) {
        $name = trim((string) $r['name']);
        $parts = preg_split('/\s+/', $name);
        $masked = $parts ? (mb_substr($parts[0], 0, 1) . str_repeat('•', max(2, mb_strlen($parts[0]) - 1))) : 'Mzigo sender';
        return ['name' => $masked, 'referrals' => (int) $r['referrals']];
    }, $rows);
    return ['rewards' => $cfg, 'leaderboard' => $leaderboard];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $tenant = current_tenant();
    $payload = ['ok' => true, 'public' => growth_public_referrals()];
    if ($user) {
        $program = growth_program_for_user($user, $tenant);
        $payload['referral'] = growth_referral_summary((int) $user['id'], $program);
        $payload['program'] = $program;
        $payload['credits'] = [
            'parcel' => growth_credit_balance((int) $user['id'], 'parcel_credit'),
            'bill' => growth_credit_balance((int) $user['id'], 'bill_credit'),
        ];
        if (($user['role'] ?? '') === 'freelancer') {
            $payload['tier'] = runner_tier((int) $user['id']);
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount_kes),0) FROM runner_bonus_ledger WHERE runner_id = ? AND status = 'pending'");
            $stmt->execute([(int) $user['id']]);
            $payload['bonus_pending_kes'] = (int) $stmt->fetchColumn();
        }
    }
    json_out($payload);
}

require_post();
verify_csrf();
$data = body_json();
$action = (string) ($data['action'] ?? '');

if ($action === 'validate_coupon') {
    $code = trim((string) ($data['code'] ?? ''));
    $amount = max(0, (int) ($data['amount_kes'] ?? 0));
    $context = in_array(($data['context'] ?? 'delivery'), ['delivery', 'subscription'], true) ? (string) $data['context'] : 'delivery';
    $result = coupon_validate($code, $amount, $context, $user ? (int) $user['id'] : null);
    json_out($result, $result['ok'] ? 200 : 422);
}

if ($action === 'referral_click') {
    $code = strtoupper(trim((string) ($data['code'] ?? '')));
    if ($code !== '') {
        try {
            $source = isset($data['source']) && is_scalar($data['source']) ? mb_substr(trim((string) $data['source']), 0, 40) : '';
            $pdo->prepare('INSERT INTO referral_clicks (code, ip_hash, user_agent, source, created_at) VALUES (?, ?, ?, ?, NOW())')
                ->execute([$code, hash('sha256', client_ip() . '|' . $code), mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), $source]);
        } catch (Throwable $e) { error_log('referral click failed: ' . $e->getMessage()); }
    }
    json_out(['ok' => true]);
}

if ($action === 'rate_runner') {
    $token = trim((string) ($data['token'] ?? ''));
    $rating = (int) ($data['rating'] ?? 0);
    $comment = isset($data['comment']) && is_scalar($data['comment']) ? mb_substr(trim((string) $data['comment']), 0, 500) : '';
    $result = runner_rate_job($token, $rating, $comment, $user ? (int) $user['id'] : null, $user ? 'customer' : 'customer');
    if (!$result['ok']) fail($result['error'], 422);
    json_out(['ok' => true, 'message' => 'Thanks — your rating was submitted.', 'job' => $result['job']]);
}

fail_error('unknown_action', 'Unknown growth action.', 400);
