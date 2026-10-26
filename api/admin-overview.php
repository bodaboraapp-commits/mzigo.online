<?php
/**
 * /api/admin-overview.php — additive customer-operations overview.
 * Read-only. Existing admin analytics/API keys are untouched.
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_platform_admin();

$days = isset($_GET['days']) ? max(1, min(365, (int) $_GET['days'])) : 30;
$start = date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));
$end = date('Y-m-d H:i:s');
$pdo = db();

function ov_one(string $sql, array $params = [])
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function ov_rows(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function ov_median(array $values): float
{
    $values = array_values(array_filter($values, fn ($v) => is_numeric($v)));
    if (!$values) return 0.0;
    sort($values);
    $n = count($values);
    $mid = intdiv($n, 2);
    return $n % 2 ? (float) $values[$mid] : ((float) $values[$mid - 1] + (float) $values[$mid]) / 2;
}

/* ---------------------------------------------------------- promise + journey */
$range = [$start, $end];
$registered = (int) ov_one('SELECT COUNT(*) FROM parcels WHERE registered_at BETWEEN ? AND ?', $range);
$collectedRows = ov_rows(
    "SELECT p.id, p.registered_at, p.promised_delivery_at, p.payment_status, p.fee_total, p.fee_platform,
            (SELECT MIN(s.scanned_at) FROM scans s WHERE s.subject_type = 'parcel' AND s.subject_id = p.id AND s.status = 'COLLECTED_PAID') AS collected_at
       FROM parcels p WHERE p.registered_at BETWEEN ? AND ? AND p.status = 'COLLECTED_PAID'",
    $range
);
$onTime = 0;
$collectedWithPromise = 0;
$collectionHours = [];
foreach ($collectedRows as $row) {
    if (!empty($row['collected_at'])) {
        $collectionHours[] = (strtotime($row['collected_at']) - strtotime($row['registered_at'])) / 3600;
        if (!empty($row['promised_delivery_at'])) {
            $collectedWithPromise++;
            if (strtotime($row['collected_at']) <= strtotime($row['promised_delivery_at'])) $onTime++;
        }
    }
}
$dropped = (int) ov_one(
    "SELECT COUNT(DISTINCT p.id) FROM parcels p WHERE p.registered_at BETWEEN ? AND ?
      AND EXISTS (SELECT 1 FROM scans s WHERE s.subject_type='parcel' AND s.subject_id=p.id AND s.status IN ('AT_ORIGIN_POINT','HANDED_TO_TRANSPORTER','IN_TRANSIT','ARRIVED_DEST_POINT','READY_FOR_COLLECTION','COLLECTED_PAID'))",
    $range
);
$inTransit = (int) ov_one(
    "SELECT COUNT(DISTINCT p.id) FROM parcels p WHERE p.registered_at BETWEEN ? AND ?
      AND EXISTS (SELECT 1 FROM scans s WHERE s.subject_type='parcel' AND s.subject_id=p.id AND s.status IN ('HANDED_TO_TRANSPORTER','IN_TRANSIT','ARRIVED_DEST_POINT','READY_FOR_COLLECTION','COLLECTED_PAID'))",
    $range
);
$ready = (int) ov_one(
    "SELECT COUNT(DISTINCT p.id) FROM parcels p WHERE p.registered_at BETWEEN ? AND ?
      AND EXISTS (SELECT 1 FROM scans s WHERE s.subject_type='parcel' AND s.subject_id=p.id AND s.status IN ('READY_FOR_COLLECTION','COLLECTED_PAID'))",
    $range
);
$paid = (int) ov_one("SELECT COUNT(*) FROM parcels WHERE registered_at BETWEEN ? AND ? AND payment_status = 'paid'", $range);
$collected = count($collectedRows);
$readyReached = $ready;
$unpaidReady = ov_rows("SELECT COUNT(*) AS c, COALESCE(SUM(fee_total),0) AS amount FROM parcels WHERE status = 'READY_FOR_COLLECTION' AND payment_status = 'unpaid'");
$feeCollectionPct = $readyReached > 0 ? round(100 * $paid / $readyReached, 1) : 0.0;

$ratingRow = ov_rows("SELECT COUNT(*) AS rated, ROUND(AVG(rating),2) AS avg_rating FROM runner_jobs WHERE status='delivered' AND rating IS NOT NULL AND rated_at BETWEEN ? AND ?", $range);
$csatRow = ov_rows("SELECT COUNT(*) AS rated, ROUND(AVG(csat_rating),2) AS avg_rating FROM parcels WHERE csat_rating IS NOT NULL AND csat_at BETWEEN ? AND ?", $range);

$claimsOpened = (int) ov_one("SELECT COUNT(*) FROM claims WHERE created_at BETWEEN ? AND ?", $range);
$claimsResolved = (int) ov_one("SELECT COUNT(*) FROM claims WHERE created_at BETWEEN ? AND ? AND status IN ('approved','rejected','paid','reversed')", $range);
$claimDays = array_map(fn ($r) => ((strtotime($r['decided_at'] ?: $r['updated_at'])) - strtotime($r['created_at'])) / 86400,
    ov_rows("SELECT created_at, decided_at, updated_at FROM claims WHERE created_at BETWEEN ? AND ? AND status IN ('approved','rejected','paid','reversed')", $range));

$senderStats = ov_rows("SELECT sender_phone, COUNT(*) AS parcels FROM parcels WHERE registered_at BETWEEN ? AND ? AND sender_phone IS NOT NULL GROUP BY sender_phone", $range);
$senderTotal = count($senderStats);
$repeatSenders = count(array_filter($senderStats, fn ($r) => (int) $r['parcels'] > 1));

$contactRows = ov_rows(
    "SELECT m.id, m.created_at, (SELECT MIN(r.created_at) FROM contact_message_replies r WHERE r.message_id = m.id) AS first_reply
       FROM contact_messages m WHERE m.created_at BETWEEN ? AND ?",
    $range
);
$firstReplyHours = [];
$unanswered = 0;
foreach ($contactRows as $row) {
    if (empty($row['first_reply'])) { $unanswered++; continue; }
    $firstReplyHours[] = (strtotime($row['first_reply']) - strtotime($row['created_at'])) / 3600;
}

/* ------------------------------------------------------------------ attention */
function ov_attention(): array
{
    $items = [];
    $unpaid = db()->query("SELECT COUNT(*) c, COALESCE(SUM(fee_total),0) amount, MIN(registered_at) oldest FROM parcels WHERE status='READY_FOR_COLLECTION' AND payment_status='unpaid'")->fetch();
    $items[] = ['key'=>'unpaid_ready','label'=>'Delivery fee unpaid at collection','count'=>(int)$unpaid['c'],'amount_kes'=>(int)$unpaid['amount'],'oldest'=>$unpaid['oldest'],'severity'=>'high','tab'=>'payments','owner'=>'Finance / support','sla_hours'=>48,'href'=>'/admin#unassigned'];
    $stuck = db()->query("SELECT COUNT(*) c, MIN(registered_at) oldest FROM parcels p WHERE p.status NOT IN ('COLLECTED_PAID','CANCELLED','RETURN_IN_TRANSIT') AND NOT EXISTS (SELECT 1 FROM scans s WHERE s.subject_id=p.id AND s.scanned_at > DATE_SUB(NOW(), INTERVAL 48 HOUR))")->fetch();
    $items[] = ['key'=>'stuck_parcels','label'=>'Parcels with no scan movement over 48h','count'=>(int)$stuck['c'],'amount_kes'=>0,'oldest'=>$stuck['oldest'],'severity'=>'high','tab'=>'delivery','owner'=>'Operations','sla_hours'=>48,'href'=>'/admin#unassigned'];
    $contact = db()->query("SELECT COUNT(*) c, MIN(m.created_at) oldest FROM contact_messages m WHERE m.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND NOT EXISTS (SELECT 1 FROM contact_message_replies r WHERE r.message_id=m.id)")->fetch();
    $items[] = ['key'=>'contact_unanswered','label'=>'Contact messages awaiting first reply','count'=>(int)$contact['c'],'amount_kes'=>0,'oldest'=>$contact['oldest'],'severity'=>'medium','tab'=>'support','owner'=>'Support','sla_hours'=>4,'href'=>'/admin#messages'];
    $claims = db()->query("SELECT COUNT(*) c, COALESCE(SUM(estimated_value_kes),0) amount, MIN(created_at) oldest FROM claims WHERE status IN ('open','under_review') AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetch();
    $items[] = ['key'=>'claims_aging','label'=>'Claims awaiting action over 24h','count'=>(int)$claims['c'],'amount_kes'=>(int)$claims['amount'],'oldest'=>$claims['oldest'],'severity'=>'high','tab'=>'trust','owner'=>'Claims','sla_hours'=>24,'href'=>'/admin#claims'];
    $kyc = db()->query("SELECT COUNT(*) c, MIN(created_at) oldest FROM kyc_documents WHERE status='pending'")->fetch();
    $items[] = ['key'=>'kyc_pending','label'=>'KYC approvals blocking activation','count'=>(int)$kyc['c'],'amount_kes'=>0,'oldest'=>$kyc['oldest'],'severity'=>'medium','tab'=>'trust','owner'=>'Trust & safety','sla_hours'=>24,'href'=>'/admin#kyc'];
    $policy = db()->query("SELECT COUNT(*) c, MIN(registered_at) oldest FROM parcels WHERE insurance_status = 'failed' AND insurance_policy_no IS NULL")->fetch();
    $items[] = ['key'=>'insurance_pending','label'=>'Insurance policies pending/failed','count'=>(int)$policy['c'],'amount_kes'=>0,'oldest'=>$policy['oldest'],'severity'=>'high','tab'=>'trust','owner'=>'Insurance ops','sla_hours'=>2,'href'=>'/admin#insurance'];
    $notify = db()->query("SELECT COUNT(*) c FROM notifications WHERE status='failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();
    $items[] = ['key'=>'failed_notifications','label'=>'Failed notifications in last 24h','count'=>(int)$notify,'amount_kes'=>0,'oldest'=>null,'severity'=>'medium','tab'=>'support','owner'=>'Engineering','sla_hours'=>4,'href'=>'/admin#communications'];
    $payout = db()->query("SELECT COUNT(*) c, COALESCE(SUM(amount_kes),0) amount, MIN(requested_at) oldest FROM runner_payouts WHERE status='pending'")->fetch();
    $items[] = ['key'=>'payouts_pending','label'=>'Runner payouts pending','count'=>(int)$payout['c'],'amount_kes'=>(int)$payout['amount'],'oldest'=>$payout['oldest'],'severity'=>'medium','tab'=>'delivery','owner'=>'Runner ops','sla_hours'=>72,'href'=>'/admin#settings'];
    foreach ($items as &$item) {
        if (!empty($item['oldest'])) {
            $item['age_hours'] = round((time() - strtotime($item['oldest'])) / 3600);
        } else { $item['age_hours'] = 0; }
    }
    unset($item);
    return $items;
}

/* ---------------------------------------------------------------------- today */
$today = date('Y-m-d');
$todayData = [
    'registered' => (int) ov_one('SELECT COUNT(*) FROM parcels WHERE DATE(registered_at) = ?', [$today]),
    'collected' => (int) ov_one("SELECT COUNT(*) FROM scans WHERE status='COLLECTED_PAID' AND DATE(scanned_at)=?", [$today]),
    'errands_active' => (int) ov_one("SELECT COUNT(*) FROM errands WHERE status NOT IN ('delivered','cancelled')"),
    'runners_online' => (int) ov_one('SELECT COUNT(*) FROM freelancer_profiles WHERE available_now = 1'),
    'new_receivers' => (int) ov_one("SELECT COUNT(*) FROM users WHERE role='customer' AND DATE(created_at)=?", [$today]),
];
$todayData['delivered'] = $todayData['collected'];

/* ---------------------------------------------------------------------- money */
$money = [
    'fees_collected_kes' => (int) ov_one("SELECT COALESCE(SUM(COALESCE(paid_amount_kes, fee_total)),0) FROM parcels WHERE payment_status='paid' AND paid_at BETWEEN ? AND ?", $range),
    'outstanding_kes' => (int) $unpaidReady[0]['amount'],
    'payouts_pending_kes' => (int) ov_one("SELECT COALESCE(SUM(amount_kes),0) FROM runner_payouts WHERE status='pending'"),
    'discounts_kes' => (int) ov_one("SELECT COALESCE(SUM(discount_kes),0) FROM parcels WHERE registered_at BETWEEN ? AND ?", $range),
    'mrr_kes' => (int) ov_one('SELECT COALESCE(SUM(p.monthly_price_kes),0) FROM subscriptions s JOIN plans p ON p.id=s.plan_id JOIN tenants t ON t.id=s.tenant_id WHERE s.status="active" AND t.status="active"'),
];

/* ----------------------------------------------------------------- experience */
$experience = [
    'rating' => (float) ($ratingRow[0]['avg_rating'] ?? 0),
    'rated_jobs' => (int) ($ratingRow[0]['rated'] ?? 0),
    'csat' => (float) ($csatRow[0]['avg_rating'] ?? 0),
    'csat_count' => (int) ($csatRow[0]['rated'] ?? 0),
    'claims_per_100' => $registered > 0 ? round(100 * $claimsOpened / $registered, 1) : 0.0,
    'support_first_reply_hours' => round(ov_median($firstReplyHours), 1),
    'contact_unanswered' => $unanswered,
    'failed_notifications' => (int) ov_one("SELECT COUNT(*) FROM notifications WHERE status='failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"),
];

/* --------------------------------------------------------------------- growth */
$referrals = (int) ov_one('SELECT COUNT(*) FROM referrals WHERE attributed_at BETWEEN ? AND ?', $range);
$qualified = (int) ov_one("SELECT COUNT(*) FROM referrals WHERE status='qualified' AND attributed_at BETWEEN ? AND ?", $range);
$growth = [
    'new_senders' => count(array_filter($senderStats, fn ($r) => (int) $r['parcels'] > 0)),
    'new_receivers' => (int) ov_one("SELECT COUNT(*) FROM users WHERE role='customer' AND created_at BETWEEN ? AND ?", $range),
    'referrals' => $referrals,
    'qualified' => $qualified,
    'k_factor' => $referrals > 0 ? round($qualified / $referrals, 2) : 0.0,
    'rewards_kes' => (int) ov_one("SELECT COALESCE(SUM(amount_kes),0) FROM referral_rewards WHERE status='paid' AND created_at BETWEEN ? AND ?", $range),
    'coupon_redemptions' => (int) ov_one('SELECT COUNT(*) FROM coupon_redemptions WHERE created_at BETWEEN ? AND ?', $range),
];

/* --------------------------------------------------------------- runner quality */
$runner = [
    'completed_jobs' => (int) ov_one("SELECT COUNT(*) FROM runner_jobs WHERE status='delivered' AND delivered_at BETWEEN ? AND ?", $range),
    'accepted_jobs' => (int) ov_one('SELECT COUNT(*) FROM runner_jobs WHERE accepted_at BETWEEN ? AND ?', $range),
    'cancelled_jobs' => (int) ov_one("SELECT COUNT(*) FROM runner_jobs WHERE status='cancelled' AND accepted_at BETWEEN ? AND ?", $range),
    'rating' => (float) ($ratingRow[0]['avg_rating'] ?? 0),
    'kyc_pending' => (int) ov_one("SELECT COUNT(*) FROM kyc_documents WHERE status='pending'"),
    'payouts_pending' => (int) ov_one("SELECT COUNT(*) FROM runner_payouts WHERE status='pending'"),
];
$runner['completion_pct'] = $runner['accepted_jobs'] > 0 ? round(100 * $runner['completed_jobs'] / $runner['accepted_jobs'], 1) : 0.0;

/* ------------------------------------------------------------------- platform */
$platform = [
    'mrr_kes' => $money['mrr_kes'],
    'arpa_kes' => (int) ov_one('SELECT COALESCE(ROUND(AVG(p.monthly_price_kes)),0) FROM subscriptions s JOIN plans p ON p.id=s.plan_id WHERE s.status="active"'),
    'parcels' => $registered,
    'errands' => (int) ov_one('SELECT COUNT(*) FROM errands WHERE created_at BETWEEN ? AND ?', $range),
    'carriers' => (int) ov_one('SELECT COUNT(DISTINCT carrier) FROM parcels WHERE registered_at BETWEEN ? AND ?', $range),
    'api_requests' => 0,
];
try { $platform['api_requests'] = (int) ov_one('SELECT COUNT(*) FROM api_requests WHERE created_at BETWEEN ? AND ?', $range); } catch (Throwable $e) {}
$attention = ov_attention();
$platform['attention_groups'] = count(array_filter($attention, fn ($i) => $i['count'] > 0));

/* ------------------------------------------------------------------------ P2 */
$cohorts = ov_rows(
    "SELECT DATE_FORMAT(first_at, '%Y-%m') cohort, COUNT(*) senders, ROUND(AVG(parcels),1) avg_parcels,
            ROUND(AVG(fees),0) avg_platform_fees, SUM(parcels > 1) repeat_senders
       FROM (SELECT sender_phone, MIN(registered_at) first_at, COUNT(*) parcels, COALESCE(SUM(fee_platform),0) fees
               FROM parcels WHERE sender_phone IS NOT NULL GROUP BY sender_phone) s
      GROUP BY DATE_FORMAT(first_at, '%Y-%m') ORDER BY cohort DESC LIMIT 6"
);
$carriers = ov_rows(
    "SELECT carrier, COUNT(*) volume, ROUND(100*AVG(payment_status='paid'),1) paid_pct,
            ROUND(AVG(TIMESTAMPDIFF(HOUR, registered_at, COALESCE((SELECT MIN(s.scanned_at) FROM scans s WHERE s.subject_type='parcel' AND s.subject_id=p.id AND s.status='COLLECTED_PAID'), NOW()))),1) avg_hours
       FROM parcels p WHERE registered_at BETWEEN ? AND ? GROUP BY carrier ORDER BY volume DESC LIMIT 5",
    $range
);
foreach ($carriers as &$carrier) {
    $carrier['claims'] = (int) ov_one("SELECT COUNT(*) FROM claims c JOIN parcels p ON p.id=c.parcel_id WHERE p.carrier = ? AND c.created_at BETWEEN ? AND ?", [$carrier['carrier'], $start, $end]);
    $carrier['volume'] = (int) $carrier['volume'];
    $carrier['paid_pct'] = (float) $carrier['paid_pct'];
    $carrier['avg_hours'] = (float) $carrier['avg_hours'];
}
unset($carrier);
$cities = ov_rows(
    "SELECT from_city, to_city, COUNT(*) parcels, COALESCE(SUM(fee_total),0) gmv
       FROM parcels WHERE registered_at BETWEEN ? AND ? GROUP BY from_city, to_city ORDER BY parcels DESC LIMIT 5",
    $range
);

json_out([
    'ok' => true,
    'range' => ['days' => $days, 'start' => $start, 'end' => $end],
    'config' => ['promise_hours' => (int) platform_setting('delivery_promise_hours', '48')],
    'promise' => [
        'on_time_pct' => $collectedWithPromise > 0 ? round(100 * $onTime / $collectedWithPromise, 1) : null,
        'on_time_count' => $onTime,
        'on_time_sample' => $collectedWithPromise,
        'fee_collection_pct' => $feeCollectionPct,
        'unpaid_ready_count' => (int) $unpaidReady[0]['c'],
        'unpaid_ready_kes' => (int) $unpaidReady[0]['amount'],
        'claims_resolved_pct' => $claimsOpened > 0 ? round(100 * $claimsResolved / $claimsOpened, 1) : null,
        'claims_median_days' => round(ov_median($claimDays), 1),
        'rating' => (float) ($ratingRow[0]['avg_rating'] ?? 0),
        'rated_jobs' => (int) ($ratingRow[0]['rated'] ?? 0),
        'repeat_sender_pct' => $senderTotal > 0 ? round(100 * $repeatSenders / $senderTotal, 1) : 0.0,
        'repeat_senders' => $repeatSenders,
        'total_senders' => $senderTotal,
    ],
    'attention' => $attention,
    'today' => $todayData,
    'money' => $money,
    'journey' => [
        'stages' => [
            ['key'=>'registered','label'=>'Registered','count'=>$registered],
            ['key'=>'dropped','label'=>'Dropped off','count'=>$dropped],
            ['key'=>'transit','label'=>'In transit','count'=>$inTransit],
            ['key'=>'ready','label'=>'Ready','count'=>$ready],
            ['key'=>'paid','label'=>'Paid','count'=>$paid],
            ['key'=>'collected','label'=>'Collected','count'=>$collected],
        ],
        'drop_off_rate' => $registered > 0 ? round(100 * $dropped / $registered, 1) : 0.0,
        'collection_rate' => $readyReached > 0 ? round(100 * $collected / $readyReached, 1) : 0.0,
        'unpaid_ready_rate' => $readyReached > 0 ? round(100 * (int)$unpaidReady[0]['c'] / $readyReached, 1) : 0.0,
        'collection_hours_p50' => round(ov_median($collectionHours), 1),
        'collection_hours_p90' => (function (array $h) { if (!$h) return 0.0; sort($h); $i = min(count($h)-1, (int) floor(count($h)*0.9)); return round($h[$i], 1); })($collectionHours),
    ],
    'experience' => $experience,
    'growth' => $growth,
    'runner' => $runner,
    'platform' => $platform,
    'cohorts' => $cohorts,
    'carriers' => $carriers,
    'cities' => $cities,
]);
