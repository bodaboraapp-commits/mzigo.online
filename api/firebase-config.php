<?php
/**
 * GET /api/firebase-config.php — public Firebase web configuration.
 *
 * Returns no secrets beyond the Firebase web app config, which is designed to
 * be public. The endpoint is used by the login page to decide whether to show
 * the SMS OTP option.
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require dirname(__DIR__) . '/_server/firebase.php';
require __DIR__ . '/_notifications.php';

header('Cache-Control: no-store');
json_out([
    'ok' => true,
    'enabled' => firebase_ready(),
    'config' => firebase_ready() ? firebase_web_config() : null,
]);
