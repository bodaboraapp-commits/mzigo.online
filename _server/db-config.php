<?php
/**
 * Mzigo.online — local database credentials.
 *
 * Kept inside _server/ (denied over HTTP) and included by api/_bootstrap.php.
 * On this machine XAMPP's MariaDB listens on 3307; container deployments set
 * DB_HOST/DB_PORT/etc. through the environment.
 */
return [
    'dsn'      => sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        getenv('DB_HOST') ?: '127.0.0.1',
        getenv('DB_PORT') ?: '3307',
        getenv('DB_NAME') ?: 'mzigo_online'
    ),
    'user'     => getenv('DB_USER') ?: 'root',
    'pass'     => getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '',
    'database' => getenv('DB_NAME') ?: 'mzigo_online',
    'host'     => getenv('DB_HOST') ?: '127.0.0.1',
    'port'     => (int) (getenv('DB_PORT') ?: 3307),
];
