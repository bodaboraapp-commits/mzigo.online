<?php
/** Mzigo.online — public pricing catalog. */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$rows = db()->query(
    'SELECT code, name, monthly_price_kes, parcel_limit, staff_limit, features
     FROM plans WHERE is_active = 1 ORDER BY sort_order ASC'
)->fetchAll();

foreach ($rows as &$row) {
    $row['monthly_price_kes'] = (int) $row['monthly_price_kes'];
    $row['parcel_limit'] = (int) $row['parcel_limit'];
    $row['staff_limit'] = (int) $row['staff_limit'];
    $row['features'] = $row['features'] ? json_decode((string) $row['features'], true) : [];
}

json_out([
    'ok'       => true,
    'source'   => 'mysql',
    'pricing_mode' => pricing_mode(),
    'free'     => pricing_mode() === 'free',
    'currency' => config()['payments']['currency'],
    'trial_days' => (int) config()['app']['trial_days'],
    'plans'    => $rows,
]);
