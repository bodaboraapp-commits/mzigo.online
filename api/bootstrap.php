<?php
/**
 * Mzigo.online — page bootstrap.
 * Returns the SaaS session context (user, workspace, role, plan usage), the MySQL
 * catalog, CSRF token, and this workspace/browser's parcels, errands and senders.
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_tenant_branding.php';

$user = current_user();
$tenant = current_tenant();
$usage = $tenant ? tenant_usage((int) $tenant['id']) : null;
$cat = catalog();

$demos = [];
$stmt = db()->prepare(
    'SELECT tracking, locker, carrier, from_city, to_city, status
     FROM parcels WHERE id IN (?, ?) ORDER BY registered_at DESC'
);
$stmt->execute(MZ_DEMO_PARCELS);
foreach ($stmt->fetchAll() as $row) {
    $demos[] = [
        'tracking' => $row['tracking'], 'locker' => $row['locker'], 'carrier' => $row['carrier'],
        'from' => $row['from_city'], 'to' => $row['to_city'], 'status' => $row['status'],
    ];
}

json_out([
    'ok'             => true,
    'source'         => 'mysql',
    'database'       => config()['db']['database'],
    'environment'    => config()['app']['env'],
    'csrf'           => csrf_token(),
    'user'           => $user,
    'tenant'         => $tenant,
    'branding'       => $tenant ? tenant_branding((int) $tenant['id']) : null,
    'tenants'        => $user ? user_tenants((int) $user['id']) : [],
    'role'           => $tenant['role'] ?? ($user['role'] ?? null),
    'usage'          => $usage,
    'pricing_mode'   => pricing_mode(),
    'show_demo_accounts' => platform_setting('show_demo_accounts', '1') === '1',
    'partners'       => $cat['partners'],
    'packageSizes'   => $cat['packageSizes'],
    'paymentMethods' => $cat['paymentMethods'],
    'insurancePricing' => insurance_pricing_public(),
    'feeConfig' => fee_config(),
    'packages'       => parcels_for_scope(),
    'errands'        => errands_for_scope(),
    'senders'        => senders_for_scope(),
    'demos'          => $demos,
]);
