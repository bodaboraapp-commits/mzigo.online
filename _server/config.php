<?php
/**
 * Mzigo.online — SaaS configuration.
 *
 * Local defaults point at XAMPP's MariaDB on port 3307. Every value can be
 * overridden with environment variables, which is what the Docker/deployment
 * examples use.
 */
$db = require __DIR__ . '/db-config.php';
$configKey = getenv('APP_CONFIG_KEY') ?: (is_file(__DIR__ . '/config-key.php') ? require __DIR__ . '/config-key.php' : '');
$appEnv = getenv('APP_ENV') ?: 'local';
$demoEnv = getenv('DEMO_MODE');
$demoMode = ($demoEnv === false || $demoEnv === '')
    ? $appEnv !== 'production'
    : (bool) preg_match('/^(1|true|yes|on)$/i', (string) $demoEnv);

return [
    'app' => [
        'name'                => 'Mzigo.online',
        'env'                 => $appEnv,
        'base_url'            => getenv('APP_BASE_URL') ?: 'http://localhost:8090',
        'demo_mode'           => $demoMode,
        'trial_days'          => 14,
        'platform_admin_email' => getenv('PLATFORM_ADMIN_EMAIL') ?: 'admin@mzigo.online',
        'support_email'       => getenv('SUPPORT_EMAIL') ?: 'hello@mzigo.online',
        'config_key'          => $configKey,
    ],
    'db' => $db,
    'payments' => [
        'provider' => getenv('PAYMENT_PROVIDER') ?: 'demo', // demo | mpesa
        'currency' => 'KES',
        'mpesa'    => [
            'shortcode'       => getenv('MPESA_SHORTCODE') ?: '',
            'consumer_key'    => getenv('MPESA_CONSUMER_KEY') ?: '',
            'consumer_secret' => getenv('MPESA_CONSUMER_SECRET') ?: '',
            'passkey'         => getenv('MPESA_PASSKEY') ?: '',
            'callback_url'    => getenv('MPESA_CALLBACK_URL') ?: '',
        ],
    ],
    'mail' => [
        'driver' => getenv('MAIL_DRIVER') ?: 'log', // log | smtp
        'from'   => getenv('MAIL_FROM') ?: 'no-reply@mzigo.online',
        'log'    => __DIR__ . '/logs/mail.log',
        'smtp'   => [
            'host' => getenv('SMTP_HOST') ?: '',
            'port' => (int) (getenv('SMTP_PORT') ?: 587),
            'user' => getenv('SMTP_USER') ?: '',
            'pass' => getenv('SMTP_PASS') ?: '',
            'encryption' => getenv('SMTP_ENCRYPTION') ?: 'tls', // tls | ssl | none
            'from' => getenv('SMTP_FROM') ?: (getenv('MAIL_FROM') ?: 'no-reply@mzigo.online'),
        ],
    ],
    'security' => [
        'session_name'         => 'mzigo_saas',
        'login_max_attempts'   => 5,
        'login_window_seconds' => 300,
        'api_rate_per_minute'  => 60,
    ],
];
