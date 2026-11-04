<?php
/**
 * GET /api/push-config.php — public Firebase Cloud Messaging web config.
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require dirname(__DIR__) . '/_server/firebase.php';
require __DIR__ . '/_notifications.php';

header('Cache-Control: no-store');
$ready = firebase_messaging_ready();
json_out([
    'ok' => true,
    'enabled' => $ready,
    'config' => $ready ? firebase_messaging_public_config()['config'] : null,
    'vapidKey' => $ready ? firebase_messaging_public_config()['vapidKey'] : '',
]);
