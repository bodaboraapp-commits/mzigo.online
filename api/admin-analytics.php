<?php
/**
 * Mzigo.online — platform analytics for the admin console (platform_admin only).
 *
 * BI model
 *  • SaaS health:     MRR, ARPA, trial → paid conversion, active/trial/suspended
 *  • Revenue momentum: subscription + platform-fee revenue vs previous period
 *  • Adoption:        parcels per active workspace, parcel GMV, avg parcel value
 *  • Mix:             revenue mix, carrier share, status mix, payment mix
 *  • Ranking:         top workspaces by parcels
 *
 * GET /api/admin-analytics.php?range=30|90|365
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

require_platform_admin();

$bucketByRange = [30 => 'day', 90 => 'week', 365 => 'month'];
$rangeDays = isset($_GET['range']) ? (int) $_GET['range'] : 90;
if (!isset($bucketByRange[$rangeDays])) {
    fail_error('invalid_range', 'Range must be one of 30, 90 or 365 days.', 422);
}
$bucket = $bucketByRange[$rangeDays];

/* ------------------------------------------------------- range + labels */
$today = new DateTimeImmutable('today');
switch ($bucket) {
    case 'day':
        $points = 30;
        $start = $today->modify('-29 days');
        break;
    case 'week':
        $points = 13;
        $start = $today->modify('-90 days'); // 13 × 7-day buckets, today inclusive
        break;
    default:
        $points = 12;
        $start = $today->modify('first day of this month')->modify('-11 months');
}

$labels = [];
for ($i = 0; $i < $points; $i++) {
    if ($bucket === 'day') {
        $labels[] = $start->modify("+{$i} days")->format('j M');
    } elseif ($bucket === 'week') {
        $labels[] = $start->modify('+' . ($i * 7) . ' days')->format('j M');
    } else {
        $labels[] = $start->modify("+{$i} months")->format('M Y');
    }
}

/** Normalised bucket index for a date/datetime string. */
$indexFor = function (string $dateTime) use ($bucket, $start): ?int {
    $date = substr($dateTime, 0, 10);
    $dt = new DateTimeImmutable($date);
    if ($bucket === 'day') {
        return (int) $start->diff($dt)->days;
    }
    if ($bucket === 'week') {
        return intdiv((int) $start->diff($dt)->days, 7);
    }
    return ((int) $dt->format('Y') - (int) $start->format('Y')) * 12
        + ((int) $dt->format('n') - (int) $start->format('n'));
};

/* --------------------------------------------------------------- queries */
$pdo = db();
$startDate = $start->format('Y-m-d 00:00:00');
$endDate = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
$rangeParams = [':start' => $startDate, ':end' => $endDate];
$inRange = fn (string $col) => "$col >= :start AND $col < :end";

/** Adds `$valueExpr` per date bucket into a zero-filled series. */
function bucket_sum(PDO $pdo, array $state, string $valueExpr, string $table, string $dateCol, string $where, array $params, callable $indexFor, int $points): array
{
    $stmt = $pdo->prepare(
        "SELECT DATE($dateCol) AS d, $valueExpr AS v FROM $table
         WHERE $where GROUP BY DATE($dateCol)"
    );
    $stmt->execute($params);
    $out = $state;
    foreach ($stmt->fetchAll() as $row) {
        $idx = $indexFor((string) $row['d']);
        if ($idx !== null && $idx >= 0 && $idx < $points) {
            $out[$idx] += (int) $row['v'];
        }
    }
    return $out;
}

$zeros = array_fill(0, $points, 0);
$series = [
    'labels'        => $labels,
    'subscriptions' => $zeros,
    'platform_fees' => $zeros,
    'parcels'       => $zeros,
    'errands'       => $zeros,
    'tenants'       => $zeros,
];

$series['subscriptions'] = bucket_sum(
    $pdo, $series['subscriptions'], 'COALESCE(SUM(amount_kes),0)', 'invoices', 'paid_at',
    "status = 'paid' AND paid_at >= :start AND paid_at < :end", $rangeParams, $indexFor, $points
);
$series['parcels'] = bucket_sum(
    $pdo, $series['parcels'], 'COUNT(*)', 'parcels', 'registered_at',
    $inRange('registered_at'), $rangeParams, $indexFor, $points
);
$series['platform_fees'] = bucket_sum(
    $pdo, $series['platform_fees'], 'COALESCE(SUM(fee_platform),0)', 'parcels', 'registered_at',
    $inRange('registered_at'), $rangeParams, $indexFor, $points
);
$series['errands'] = bucket_sum(
    $pdo, $series['errands'], 'COUNT(*)', 'errands', 'created_at',
    $inRange('created_at'), $rangeParams, $indexFor, $points
);
$series['tenants'] = bucket_sum(
    $pdo, $series['tenants'], 'COUNT(*)', 'tenants', 'created_at',
    $inRange('created_at'), $rangeParams, $indexFor, $points
);

function scalar(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/* ---------------------------------------------------------------- KPIs */
$mrr = scalar($pdo, 'SELECT COALESCE(SUM(p.monthly_price_kes),0)
    FROM subscriptions s JOIN plans p ON p.id = s.plan_id JOIN tenants t ON t.id = s.tenant_id
    WHERE s.status = "active" AND t.status = "active"');
$activeTenants = scalar($pdo, 'SELECT COUNT(*) FROM tenants WHERE status = "active"');
$trialTenants = scalar($pdo, 'SELECT COUNT(*) FROM tenants WHERE status = "trial"');
$suspendedTenants = scalar($pdo, 'SELECT COUNT(*) FROM tenants WHERE status = "suspended"');
$tenantsTotal = scalar($pdo, 'SELECT COUNT(*) FROM tenants');
$convertedTenants = scalar($pdo, 'SELECT COUNT(DISTINCT tenant_id) FROM invoices WHERE status = "paid"');

$parcelsRange = array_sum($series['parcels']);
$platformFeesRange = array_sum($series['platform_fees']);
$subscriptionsRange = array_sum($series['subscriptions']);
$revenueRange = $platformFeesRange + $subscriptionsRange;
$errandsRange = array_sum($series['errands']);

$gmvRange = scalar($pdo, 'SELECT COALESCE(SUM(fee_total),0) FROM parcels WHERE ' . $inRange('registered_at'), $rangeParams);
$avgValue = scalar($pdo, 'SELECT COALESCE(ROUND(AVG(value_kes)),0) FROM parcels WHERE ' . $inRange('registered_at'), $rangeParams);

$prevEnd = $start;
$prevStart = $start->modify('-' . $rangeDays . ' days');
$prevParams = [':start' => $prevStart->format('Y-m-d 00:00:00'), ':end' => $prevEnd->format('Y-m-d H:i:s')];
$prevParcels = scalar($pdo, 'SELECT COUNT(*) FROM parcels WHERE ' . $inRange('registered_at'), $prevParams);
$prevSubs = scalar($pdo, "SELECT COALESCE(SUM(amount_kes),0) FROM invoices WHERE status = 'paid' AND paid_at >= :start AND paid_at < :end", $prevParams);
$prevFees = scalar($pdo, 'SELECT COALESCE(SUM(fee_platform),0) FROM parcels WHERE ' . $inRange('registered_at'), $prevParams);
$prevRevenue = $prevSubs + $prevFees;
$prevGmv = scalar($pdo, 'SELECT COALESCE(SUM(fee_total),0) FROM parcels WHERE ' . $inRange('registered_at'), $prevParams);

$growth = function (int $current, int $previous): ?float {
    if ($previous === 0) {
        return $current === 0 ? 0.0 : null;
    }
    return round((($current - $previous) / $previous) * 100, 1);
};

/* ------------------------------------------------------- distributions */
$dist = ['carriers' => [], 'statuses' => [], 'payments' => [], 'invoices' => [], 'revenue_mix' => []];

$stmt = $pdo->prepare('SELECT carrier AS name, COUNT(*) AS parcels, COALESCE(SUM(fee_platform),0) AS fees
    FROM parcels WHERE ' . $inRange('registered_at') . ' GROUP BY carrier ORDER BY parcels DESC');
$stmt->execute($rangeParams);
foreach ($stmt->fetchAll() as $row) {
    $dist['carriers'][] = ['name' => $row['name'], 'parcels' => (int) $row['parcels'], 'fees' => (int) $row['fees']];
}

$stmt = $pdo->prepare('SELECT status, COUNT(*) AS c FROM parcels WHERE ' . $inRange('registered_at') . ' GROUP BY status ORDER BY c DESC');
$stmt->execute($rangeParams);
foreach ($stmt->fetchAll() as $row) {
    $dist['statuses'][] = ['status' => $row['status'], 'count' => (int) $row['c']];
}

$stmt = $pdo->prepare('SELECT payment_method AS method, COUNT(*) AS c FROM parcels WHERE ' . $inRange('registered_at') . ' GROUP BY payment_method ORDER BY c DESC');
$stmt->execute($rangeParams);
foreach ($stmt->fetchAll() as $row) {
    $dist['payments'][] = ['method' => $row['method'], 'count' => (int) $row['c']];
}

$stmt = $pdo->prepare('SELECT status, COUNT(*) AS c, COALESCE(SUM(amount_kes),0) AS amount
    FROM invoices WHERE ' . $inRange('issued_at') . ' GROUP BY status ORDER BY c DESC');
$stmt->execute($rangeParams);
foreach ($stmt->fetchAll() as $row) {
    $dist['invoices'][] = ['status' => $row['status'], 'count' => (int) $row['c'], 'amount' => (int) $row['amount']];
}

$insuranceShare = scalar($pdo, 'SELECT COALESCE(SUM(fee_insurance),0) FROM parcels WHERE ' . $inRange('registered_at'), $rangeParams);
$dist['revenue_mix'] = [
    ['label' => 'Subscriptions', 'amount' => $subscriptionsRange],
    ['label' => 'Platform fees', 'amount' => $platformFeesRange],
    ['label' => 'Insurance share', 'amount' => $insuranceShare],
];

$topTenants = [];
$stmt = $pdo->prepare('SELECT t.name, p.name AS plan, COUNT(x.id) AS parcels, COALESCE(SUM(x.fee_platform),0) AS fees
    FROM parcels x
    JOIN tenants t ON t.id = x.tenant_id
    LEFT JOIN subscriptions s ON s.tenant_id = t.id
    LEFT JOIN plans p ON p.id = s.plan_id
    WHERE ' . $inRange('x.registered_at') . '
    GROUP BY t.id, t.name, p.name ORDER BY parcels DESC LIMIT 6');
$stmt->execute($rangeParams);
foreach ($stmt->fetchAll() as $row) {
    $topTenants[] = ['name' => $row['name'], 'plan' => $row['plan'], 'parcels' => (int) $row['parcels'], 'fees' => (int) $row['fees']];
}

json_out([
    'ok'     => true,
    'source' => 'mysql',
    'range'  => [
        'days'   => $rangeDays,
        'bucket' => $bucket,
        'points' => $points,
        'start'  => $startDate,
        'end'    => (new DateTimeImmutable('now'))->format('c'),
    ],
    'kpis' => [
        'mrr_kes'              => $mrr,
        'arpa_kes'             => $activeTenants > 0 ? (int) round($mrr / $activeTenants) : 0,
        'conversion_pct'       => $tenantsTotal > 0 ? round(($convertedTenants / $tenantsTotal) * 100, 1) : 0.0,
        'revenue_kes'          => $revenueRange,
        'prev_revenue_kes'     => $prevRevenue,
        'revenue_growth_pct'   => $growth($revenueRange, $prevRevenue),
        'gmv_kes'              => $gmvRange,
        'prev_gmv_kes'         => $prevGmv,
        'gmv_growth_pct'       => $growth($gmvRange, $prevGmv),
        'parcels'              => $parcelsRange,
        'prev_parcels'         => $prevParcels,
        'parcels_growth_pct'   => $growth($parcelsRange, $prevParcels),
        'parcels_per_tenant'   => $activeTenants > 0 ? round($parcelsRange / $activeTenants, 1) : 0.0,
        'avg_parcel_value_kes' => $avgValue,
        'errands'              => $errandsRange,
        'active_tenants'       => $activeTenants,
        'trial_tenants'        => $trialTenants,
        'suspended_tenants'    => $suspendedTenants,
        'tenants_total'        => $tenantsTotal,
    ],
    'series'        => $series,
    'distributions' => $dist,
    'top_tenants'   => $topTenants,
]);
