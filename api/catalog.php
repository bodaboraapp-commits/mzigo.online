<?php
/** Mzigo.online — courier catalog straight from MySQL. */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$cat = catalog();
json_out([
    'ok'             => true,
    'source'         => 'mysql',
    'partners'       => $cat['partners'],
    'packageSizes'   => $cat['packageSizes'],
    'paymentMethods' => $cat['paymentMethods'],
]);
