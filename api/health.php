<?php
/** Mzigo.online — SaaS health/status probe (reads straight from MariaDB). */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

/* Public health probe returns only liveness. Detailed metrics require the
   monitoring key so customer counts, revenue and recent parcels are not exposed. */
$healthKey = (string) (getenv('HEALTH_CHECK_KEY') ?: '');
$providedKey = (string) ($_SERVER['HTTP_X_HEALTH_KEY'] ?? '');
if ($healthKey === '' || $providedKey === '' || !hash_equals($healthKey, $providedKey)) {
    json_out(['ok' => true, 'status' => 'healthy', 'time' => date('c')]);
}

$pdo = db();
$tables = ['users', 'tenants', 'tenant_members', 'plans', 'subscriptions', 'invoices',
    'api_keys', 'audit_log', 'partners', 'destinations', 'parcels', 'scans', 'errands', 'senders'];
$counts = [];
foreach ($tables as $table) {
    $counts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
}

$parcelsThisMonth = (int) $pdo->query(
    'SELECT COUNT(*) FROM parcels WHERE registered_at >= DATE_FORMAT(NOW(), "%Y-%m-01")'
)->fetchColumn();

$mrr = (int) $pdo->query(
    'SELECT COALESCE(SUM(p.monthly_price_kes), 0)
     FROM subscriptions s
     JOIN plans p ON p.id = s.plan_id
     JOIN tenants t ON t.id = s.tenant_id
     WHERE s.status = "active" AND t.status = "active"'
)->fetchColumn();

$paidRevenue = (int) $pdo->query('SELECT COALESCE(SUM(amount_kes), 0) FROM invoices WHERE status = "paid"')->fetchColumn();
$pendingInvoices = (int) $pdo->query('SELECT COUNT(*) FROM invoices WHERE status = "pending"')->fetchColumn();
$activeTenants = (int) $pdo->query('SELECT COUNT(*) FROM tenants WHERE status IN ("active","trial")')->fetchColumn();

$recent = [];
$stmt = $pdo->query(
    'SELECT tracking, locker, carrier, from_city, to_city, status, tenant_id, registered_at
     FROM parcels ORDER BY registered_at DESC, tracking DESC LIMIT 5'
);
foreach ($stmt->fetchAll() as $row) {
    $recent[] = $row;
}

json_out([
    'ok'              => true,
    'source'          => 'mysql',
    'environment'     => config()['app']['env'],
    'database'        => config()['db']['database'],
    'driver'          => $pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
    'server'          => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
    'php'             => PHP_VERSION,
    'counts'          => $counts,
    'parcels_month'   => $parcelsThisMonth,
    'active_tenants'  => $activeTenants,
    'mrr_kes'         => $mrr,
    'paid_revenue_kes' => $paidRevenue,
    'pending_invoices' => $pendingInvoices,
    'recent'          => $recent,
    'time'            => date('c'),
]);
