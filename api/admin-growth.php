<?php
/**
 * Mzigo.online — admin growth console: coupons, incentive/referral config, referral activity.
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_growth.php';
require_once __DIR__ . '/_notifications.php';

$admin = require_platform_admin();
$pdo = db();

function admin_growth_payload(): array
{
    $pdo = db();
    $coupons = $pdo->query('SELECT * FROM coupons ORDER BY id DESC LIMIT 200')->fetchAll();
    foreach ($coupons as &$c) {
        $c['id'] = (int) $c['id'];
        $c['value'] = (int) $c['value'];
        $c['active'] = (bool) $c['active'];
        $c['min_spend_kes'] = (int) $c['min_spend_kes'];
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id = ?');
        $stmt->execute([$c['id']]);
        $c['redemptions'] = (int) $stmt->fetchColumn();
    }
    unset($c);
    $referrals = $pdo->query(
        "SELECT r.*, u.name AS referrer_name, u.phone AS referrer_phone
           FROM referrals r JOIN users u ON u.id = r.referrer_user_id
          ORDER BY r.id DESC LIMIT 60"
    )->fetchAll();
    $campaigns = $pdo->query('SELECT * FROM referral_campaigns ORDER BY id DESC LIMIT 50')->fetchAll();
    foreach ($campaigns as &$campaign) {
        $campaign['id'] = (int) $campaign['id'];
        $campaign['sent_count'] = (int) $campaign['sent_count'];
    }
    unset($campaign);
    $sources = $pdo->query("SELECT COALESCE(NULLIF(source,''),'direct') AS source, COUNT(*) AS clicks FROM referral_clicks GROUP BY source ORDER BY clicks DESC LIMIT 20")->fetchAll();
    $codes = $pdo->query("SELECT rc.code, rc.program, u.name FROM referral_codes rc JOIN users u ON u.id = rc.user_id WHERE rc.active = 1 ORDER BY rc.id DESC LIMIT 100")->fetchAll();
    $rewards = $pdo->query('SELECT COALESCE(SUM(amount_kes),0), COUNT(*) FROM referral_rewards WHERE status = "paid"')->fetch(PDO::FETCH_NUM);
    $clicks = (int) $pdo->query('SELECT COUNT(*) FROM referral_clicks')->fetchColumn();
    $signups = (int) $pdo->query('SELECT COUNT(*) FROM referrals')->fetchColumn();
    $qualified = (int) $pdo->query('SELECT COUNT(*) FROM referrals WHERE status = "qualified"')->fetchColumn();
    $inviters = (int) $pdo->query('SELECT COUNT(DISTINCT referrer_user_id) FROM referrals')->fetchColumn();
    $milestones = $pdo->query('SELECT COUNT(*) FROM referral_milestones')->fetchColumn();

    /* Source-level attribution: clicks come from referral_clicks, sign-ups/qualified
       from the referral source captured at attribution time. */
    $attr = [];
    foreach ($sources as $row) {
        $source = (string) ($row['source'] ?? 'direct');
        $attr[$source] = ['source' => $source, 'clicks' => (int) $row['clicks'], 'signups' => 0, 'qualified' => 0];
    }
    $refSources = $pdo->query(
        "SELECT COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.source')), ''), 'direct') AS source,
                COUNT(*) AS signups, SUM(status = 'qualified') AS qualified
           FROM referrals GROUP BY source"
    )->fetchAll();
    foreach ($refSources as $row) {
        $source = (string) ($row['source'] ?? 'direct');
        if (!isset($attr[$source])) $attr[$source] = ['source' => $source, 'clicks' => 0, 'signups' => 0, 'qualified' => 0];
        $attr[$source]['signups'] = (int) $row['signups'];
        $attr[$source]['qualified'] = (int) $row['qualified'];
    }
    foreach ($attr as &$row) {
        $row['conversion_rate'] = $row['signups'] > 0 ? round(($row['qualified'] / $row['signups']) * 100, 1) : 0;
    }
    unset($row);
    uasort($attr, function ($a, $b) { return ($b['clicks'] + $b['signups'] * 10) <=> ($a['clicks'] + $a['signups'] * 10); });
    $attribution = array_values($attr);

    /* A/B copy performance: exposures/shares from analytics events, clicks by
       viral-share/tiktok source suffix, sign-ups/qualified from referral meta. */
    $ab = referral_config()['ab_test'] ?? [];
    $abStats = [
        'A' => ['variant' => 'A', 'exposures' => 0, 'shares' => 0, 'clicks' => 0, 'signups' => 0, 'qualified' => 0, 'conversion_rate' => 0],
        'B' => ['variant' => 'B', 'exposures' => 0, 'shares' => 0, 'clicks' => 0, 'signups' => 0, 'qualified' => 0, 'conversion_rate' => 0],
    ];
    $variantFromSource = function (string $source): string {
        return preg_match('/([AB])$/', strtoupper($source), $m) ? $m[1] : '';
    };
    foreach ($pdo->query("SELECT COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.variant')), ''), 'A') AS variant, COUNT(*) AS n
        FROM analytics_events WHERE event = 'referral_variant_exposure' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY variant")->fetchAll() as $row) {
        $v = in_array($row['variant'], ['A', 'B'], true) ? $row['variant'] : 'A';
        $abStats[$v]['exposures'] = (int) $row['n'];
    }
    foreach ($pdo->query("SELECT COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.variant')), ''), 'A') AS variant, COUNT(*) AS n
        FROM analytics_events WHERE event = 'referral_share' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY variant")->fetchAll() as $row) {
        $v = in_array($row['variant'], ['A', 'B'], true) ? $row['variant'] : 'A';
        $abStats[$v]['shares'] = (int) $row['n'];
    }
    foreach ($sources as $row) {
        $v = $variantFromSource((string) ($row['source'] ?? ''));
        if ($v !== '') $abStats[$v]['clicks'] += (int) $row['clicks'];
    }
    foreach ($refSources as $row) {
        $v = $variantFromSource((string) ($row['source'] ?? ''));
        if ($v !== '') {
            $abStats[$v]['signups'] += (int) $row['signups'];
            $abStats[$v]['qualified'] += (int) $row['qualified'];
        }
    }
    foreach ($abStats as &$row) {
        $row['conversion_rate'] = $row['signups'] > 0 ? round(($row['qualified'] / $row['signups']) * 100, 1) : 0;
    }
    unset($row);

    $campaignSent = 0;
    foreach ($campaigns as $campaign) { $campaignSent += (int) $campaign['sent_count']; }
    $campaignSource = $attr['campaign'] ?? ['clicks' => 0, 'signups' => 0, 'qualified' => 0, 'conversion_rate' => 0];
    $kFactor = $inviters > 0 ? round($qualified / $inviters, 2) : 0;
    $conversionRate = $signups > 0 ? round(($qualified / $signups) * 100, 1) : 0;

    return [
        'ok' => true,
        'coupons' => $coupons,
        'referrals' => $referrals,
        'campaigns' => $campaigns,
        'click_sources' => $sources,
        'attribution_sources' => $attribution,
        'ab_stats' => array_values($abStats),
        'campaign_stats' => ['sent' => $campaignSent, 'clicks' => (int) ($campaignSource['clicks'] ?? 0),
            'signups' => (int) ($campaignSource['signups'] ?? 0), 'qualified' => (int) ($campaignSource['qualified'] ?? 0),
            'conversion_rate' => (float) ($campaignSource['conversion_rate'] ?? 0)],
        'codes' => $codes,
        'referral_stats' => ['earned_kes' => (int) ($rewards[0] ?? 0), 'rewards' => (int) ($rewards[1] ?? 0), 'clicks' => $clicks,
            'signups' => $signups, 'qualified' => $qualified, 'inviters' => $inviters,
            'k_factor' => $kFactor, 'conversion_rate' => $conversionRate,
            'milestones' => (int) $milestones],
        'incentive_config' => incentive_config(),
        'referral_config' => referral_config(),
    ];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_out(admin_growth_payload());
}

require_post();
verify_csrf();
$data = body_json();
$action = (string) ($data['action'] ?? '');

switch ($action) {
    case 'save_coupon': {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($data['code'] ?? '')) ?? '');
        if (strlen($code) < 3) fail('Enter a coupon code of at least 3 characters.', 422);
        $type = ($data['type'] ?? 'fixed') === 'percent' ? 'percent' : 'fixed';
        $value = max(1, (int) ($data['value'] ?? 0));
        if ($type === 'percent' && $value > 100) fail('Percentage coupons cannot exceed 100%.', 422);
        $applies = in_array(($data['applies_to'] ?? 'delivery'), ['delivery', 'subscription', 'all'], true) ? (string) $data['applies_to'] : 'delivery';
        $min = max(0, (int) ($data['min_spend_kes'] ?? 0));
        $max = isset($data['max_discount_kes']) && $data['max_discount_kes'] !== '' ? max(1, (int) $data['max_discount_kes']) : null;
        $usage = isset($data['usage_limit']) && $data['usage_limit'] !== '' ? max(1, (int) $data['usage_limit']) : null;
        $perUser = max(1, (int) ($data['per_user_limit'] ?? 1));
        $starts = !empty($data['starts_at']) ? (string) $data['starts_at'] : null;
        $expires = !empty($data['expires_at']) ? (string) $data['expires_at'] : null;
        $active = !empty($data['active']) ? 1 : 0;
        $id = (int) ($data['id'] ?? 0);
        if ($id) {
            $pdo->prepare('UPDATE coupons SET code=?, name=?, type=?, value=?, applies_to=?, min_spend_kes=?, max_discount_kes=?, usage_limit=?, per_user_limit=?, starts_at=?, expires_at=?, active=? WHERE id=?')
                ->execute([$code, (string) ($data['name'] ?? $code), $type, $value, $applies, $min, $max, $usage, $perUser, $starts, $expires, $active, $id]);
        } else {
            $pdo->prepare('INSERT INTO coupons (code, name, type, value, applies_to, min_spend_kes, max_discount_kes, usage_limit, per_user_limit, starts_at, expires_at, active, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())')
                ->execute([$code, (string) ($data['name'] ?? $code), $type, $value, $applies, $min, $max, $usage, $perUser, $starts, $expires, $active, (int) $admin['id']]);
        }
        audit('admin.coupon_saved', 'coupon', $code, ['active' => $active]);
        json_out(admin_growth_payload());
    }

    case 'delete_coupon': {
        $id = (int) ($data['id'] ?? 0);
        $pdo->prepare('DELETE FROM coupons WHERE id = ?')->execute([$id]);
        audit('admin.coupon_deleted', 'coupon', (string) $id);
        json_out(admin_growth_payload());
    }

    case 'save_campaign': {
        $name = param($data, 'name', 3, 120, 'Campaign name');
        $message = param($data, 'message', 10, 2000, 'Message');
        $schedule = isset($data['schedule_at']) && $data['schedule_at'] !== '' ? date('Y-m-d H:i:s', strtotime((string) $data['schedule_at'])) : date('Y-m-d H:i:s');
        $recurrence = in_array(($data['recurrence'] ?? 'once'), ['once', 'weekly', 'monthly'], true) ? (string) $data['recurrence'] : 'once';
        $pdo->prepare('INSERT INTO referral_campaigns (name, message, schedule_at, recurrence, status, next_run_at, created_by, created_at) VALUES (?, ?, ?, ?, "scheduled", ?, ?, NOW())')
            ->execute([$name, $message, $schedule, $recurrence, $schedule, (int) $admin['id']]);
        audit('admin.referral_campaign_created', 'referral_campaign', (string) $pdo->lastInsertId(), ['name' => $name]);
        json_out(admin_growth_payload());
    }

    case 'run_campaign': {
        $id = (int) ($data['id'] ?? 0);
        $result = growth_campaign_send($id);
        audit('admin.referral_campaign_run', 'referral_campaign', (string) $id, $result);
        json_out(['ok' => true, 'sent' => (int) ($result['sent'] ?? 0), 'campaigns' => admin_growth_payload()['campaigns']]);
    }

    case 'toggle_campaign': {
        $id = (int) ($data['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT status FROM referral_campaigns WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $status = (string) ($stmt->fetchColumn() ?: '');
        if ($status === '') fail_error('not_found', 'Campaign not found.', 404);
        $new = $status === 'paused' ? 'scheduled' : 'paused';
        $pdo->prepare('UPDATE referral_campaigns SET status = ?, next_run_at = IF(? = "scheduled", NOW(), next_run_at) WHERE id = ?')->execute([$new, $new, $id]);
        json_out(admin_growth_payload());
    }

    case 'delete_campaign': {
        $pdo->prepare('DELETE FROM referral_campaigns WHERE id = ?')->execute([(int) ($data['id'] ?? 0)]);
        json_out(admin_growth_payload());
    }

    case 'send_referral_reminders': {
        $stmt = $pdo->query(
            "SELECT u.id FROM users u JOIN referral_codes rc ON rc.user_id = u.id
              WHERE rc.active = 1
                AND NOT EXISTS (SELECT 1 FROM referrals r WHERE r.referrer_user_id = u.id AND r.attributed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY))
              ORDER BY u.id ASC LIMIT 50"
        );
        $sent = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $uid) {
            growth_notify_user((int) $uid, 'Your Mzigo referral link is waiting', 'Share your code and earn when a friend activates. Open /refer to copy your link.');
            $sent++;
        }
        audit('admin.referral_reminders_sent', 'platform', 'referral_campaign', ['users' => $sent]);
        json_out(['ok' => true, 'sent' => $sent, 'message' => $sent . ' referral reminder(s) queued through configured channels.']);
    }

    case 'save_incentive_config': {
        $config = incentive_config_save($data['config'] ?? []);
        audit('admin.incentive_config_saved', 'platform', 'incentive_config');
        json_out(['ok' => true, 'incentive_config' => $config]);
    }

    case 'save_referral_config': {
        $config = referral_config_save($data['config'] ?? []);
        audit('admin.referral_config_saved', 'platform', 'referral_config');
        json_out(['ok' => true, 'referral_config' => $config]);
    }

    default:
        fail_error('unknown_action', 'Unknown growth action.', 400);
}
