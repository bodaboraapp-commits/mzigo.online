<?php
/**
 * Mzigo.online — profile/workspace data (packages, errands, senders, plan usage).
 * Scoped to the signed-in workspace plus anything this browser created anonymously.
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$user = current_user();
$tenant = current_tenant();

json_out([
    'ok'       => true,
    'source'   => 'mysql',
    'csrf'     => csrf_token(),
    'user'     => $user,
    'tenant'   => $tenant,
    'role'     => $tenant['role'] ?? ($user['role'] ?? null),
    'usage'    => $tenant ? tenant_usage((int) $tenant['id']) : null,
    'packages' => parcels_for_scope(),
    'errands'  => errands_for_scope(),
    'senders'  => senders_for_scope(),
]);
