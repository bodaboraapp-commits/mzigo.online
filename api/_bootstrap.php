<?php
/**
 * Mzigo.online — SaaS API bootstrap.
 *
 * Multi-tenant core: config, PDO, hardened sessions, CSRF, rate limiting,
 * users/tenants/roles, subscription plans + quota enforcement, audit log and
 * API-key authentication. Every PHP endpoint includes this file.
 */

declare(strict_types=1);

header_remove('X-Powered-By');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('Africa/Nairobi');

$logDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . '_server' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
ini_set('error_log', $logDir . DIRECTORY_SEPARATOR . 'php-error.log');

/* ------------------------------------------------------------------ config */
function config(): array
{
    static $config = null;
    if ($config === null) {
        $config = require dirname(__DIR__) . DIRECTORY_SEPARATOR . '_server' . DIRECTORY_SEPARATOR . 'config.php';
    }
    return $config;
}

/* --------------------------------------------------------------- database */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $cfg = config()['db'];
    try {
        $pdo = new PDO($cfg['dsn'], $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        // Keep MySQL NOW()/DATETIME values in the same zone as PHP (Africa/Nairobi).
        // cPanel MariaDB often runs in UTC while PHP is set to +03:00, which made
        // 10-minute OTPs look expired immediately.
        try {
            $pdo->exec("SET time_zone = '+03:00'");
        } catch (Throwable $tzError) {
            error_log('Mzigo.online DB timezone alignment skipped: ' . $tzError->getMessage());
        }
    } catch (PDOException $e) {
        error_log('Mzigo.online DB connection failed: ' . $e->getMessage());
        json_out(['ok' => false, 'error' => ['code' => 'db_unavailable', 'message' => 'Database unavailable.']], 500);
    }
    return $pdo;
}

require __DIR__ . '/_session.php';

/* --------------------------------------------------------------- sessions */
function session_start_once(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $security = config()['security'];
    $ttl = (int) ($security['session_ttl'] ?? 43200);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string) $ttl);
    session_name($security['session_name']);
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $https,
    ]);
    // Sessions are stored, managed and logged in MySQL.
    session_set_save_handler(new DbSessionHandler(db(), $ttl), true);
    session_start();

    $now = time();
    if (!empty($_SESSION['user_id'])) {
        $idle = (int) ($security['session_idle'] ?? 7200);
        $absolute = (int) ($security['session_absolute'] ?? $ttl);
        $startedAt = (int) ($_SESSION['_session_started'] ?? $now);
        $lastSeen = (int) ($_SESSION['_last_activity'] ?? $now);
        if ($now - $lastSeen > $idle || $now - $startedAt > $absolute) {
            audit('auth.session_expired', 'user', (string) $_SESSION['user_id'], [
                'reason' => ($now - $lastSeen > $idle) ? 'idle' : 'absolute',
            ]);
            $_SESSION = [];
            session_regenerate_id(true);
        } else {
            $_SESSION['_last_activity'] = $now;
        }
    }
    if (empty($_SESSION['anon_id'])) {
        $_SESSION['anon_id'] = bin2hex(random_bytes(16));
    }
}
session_start_once();

/* ------------------------------------------------------------- json/input */
function platform_setting(string $key, ?string $default = null): ?string
{
    $stmt = db()->prepare('SELECT setting_value FROM platform_settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : (string) $value;
}

function pricing_mode(): string
{
    return platform_setting('pricing_mode', 'paid') === 'free' ? 'free' : 'paid';
}

function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $message, int $code = 422, array $fields = []): never
{
    json_out(['ok' => false, 'error' => ['message' => $message, 'fields' => $fields]], $code);
}

function fail_error(string $errorCode, string $message, int $httpCode = 422, array $meta = [], array $fields = []): never
{
    json_out([
        'ok'    => false,
        'error' => ['code' => $errorCode, 'message' => $message, 'fields' => $fields, 'meta' => $meta],
    ], $httpCode);
}

function body_json(): array
{
    $raw = file_get_contents('php://input');
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return $_POST;
}

function require_post(): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        fail('Use POST for this endpoint.', 405);
    }
}

function param(array $data, string $key, int $min = 1, int $max = 255, string $label = ''): string
{
    $label = $label !== '' ? $label : ucwords(str_replace('_', ' ', $key));
    $value = isset($data[$key]) && is_scalar($data[$key]) ? trim((string) $data[$key]) : '';
    if (mb_strlen($value) < $min) {
        fail($label . ' is required.', 422, [$key => $label . ' is required.']);
    }
    if (mb_strlen($value) > $max) {
        fail($label . ' is too long (max ' . $max . ' characters).', 422, [$key => 'Too long.']);
    }
    return $value;
}

function email_valid(string $email): bool
{
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}

function phone_valid(string $phone): bool
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    return (bool) preg_match('/^(?:254|0)?7\d{8}$/', $digits);
}

function require_phone(array $data, string $key = 'phone'): string
{
    $phone = param($data, $key, 9, 20, 'Phone number');
    if (!phone_valid($phone)) {
        fail('Enter a valid Kenyan phone number, e.g. 0712 345 678.', 422, [$key => 'Invalid phone number.']);
    }
    return $phone;
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
}

/* ------------------------------------------------------------------- csrf */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf'];
}

/** Verify CSRF for cookie-authenticated state-changing requests. */
function verify_csrf(): void
{
    $sent = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($sent === '' && isset($_POST['_csrf'])) {
        $sent = (string) $_POST['_csrf'];
    }
    if ($sent === '') {
        $body = body_json();
        $sent = isset($body['_csrf']) ? (string) $body['_csrf'] : '';
    }
    if (empty($_SESSION['csrf']) || $sent === '' || !hash_equals($_SESSION['csrf'], $sent)) {
        fail_error('csrf_invalid', 'Your session expired. Refresh the page and try again.', 403);
    }
}

/* ----------------------------------------------------------- rate limiting */
function rate_limit(string $bucket, int $maxHits, int $windowSeconds): void
{
    $pdo = db();
    $key = substr(hash('sha256', $bucket), 0, 64);
    $now = new DateTimeImmutable('now');
    $expiry = $now->modify('+' . $windowSeconds . ' seconds');
    $pdo->prepare(
        'INSERT INTO rate_limits (bucket, hits, expires_at) VALUES (?, 1, ?)
         ON DUPLICATE KEY UPDATE hits = hits + 1'
    )->execute([$key, $expiry->format('Y-m-d H:i:s')]);
    $stmt = $pdo->prepare('SELECT hits, expires_at FROM rate_limits WHERE bucket = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    if ($row && strtotime((string) $row['expires_at']) < time()) {
        $pdo->prepare('UPDATE rate_limits SET hits = 1, expires_at = ? WHERE bucket = ?')
            ->execute([$expiry->format('Y-m-d H:i:s'), $key]);
        return;
    }
    if ($row && (int) $row['hits'] > $maxHits) {
        header('Retry-After: ' . $windowSeconds);
        fail_error('rate_limited', 'Too many attempts. Please try again shortly.', 429);
    }
    if (random_int(1, 50) === 1) {
        $pdo->exec('DELETE FROM rate_limits WHERE expires_at < NOW() - INTERVAL 1 DAY');
    }
}

/* --------------------------------------------------------------- identity */
function user_has_role(int $userId, string $role): bool
{
    if (!in_array($role, ['customer', 'freelancer'], true)) return false;
    try {
        $stmt = db()->prepare('SELECT 1 FROM user_roles WHERE user_id = ? AND role = ? LIMIT 1');
        $stmt->execute([$userId, $role]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function user_grant_role(int $userId, string $role): void
{
    if (!in_array($role, ['customer', 'freelancer'], true)) return;
    try {
        db()->prepare('INSERT IGNORE INTO user_roles (user_id, role, created_at) VALUES (?, ?, NOW())')->execute([$userId, $role]);
    } catch (Throwable $e) {
        error_log('user_grant_role failed: ' . $e->getMessage());
    }
}

function current_user(): ?array
{
    static $cached = false;
    static $user = null;
    if ($cached) {
        return $user;
    }
    $cached = true;
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $stmt = db()->prepare('SELECT id, name, email, email_verified_at, phone, business, role, status, created_at, last_login_at FROM users WHERE id = ? AND status = "active" LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $row = $stmt->fetch();
    $user = $row ?: null;
    if (!$user) {
        unset($_SESSION['user_id'], $_SESSION['tenant_id'], $_SESSION['active_role']);
        return null;
    }
    $activeRole = (string) ($_SESSION['active_role'] ?? '');
    if (in_array($activeRole, ['customer', 'freelancer'], true) && user_has_role((int) $user['id'], $activeRole)) {
        $user['base_role'] = $user['role'];
        $user['active_role'] = $activeRole;
        $user['role'] = $activeRole;
    }
    return $user;
}

function is_platform_admin(): bool
{
    $u = current_user();
    return $u !== null && $u['role'] === 'platform_admin';
}

function user_tenants(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT t.id, t.slug, t.name, t.status, m.role
         FROM tenant_members m JOIN tenants t ON t.id = m.tenant_id
         WHERE m.user_id = ? ORDER BY t.name ASC'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/** The tenant this session is acting as (first membership by default). */
function current_tenant(): ?array
{
    static $cached = false;
    static $tenant = null;
    if ($cached) {
        return $tenant;
    }
    $cached = true;
    $user = current_user();
    if (!$user) {
        return null;
    }
    if (in_array((string) ($_SESSION['active_role'] ?? ''), ['customer', 'freelancer'], true)) {
        return null;
    }
    $memberships = user_tenants((int) $user['id']);
    if (!$memberships) {
        return null;
    }
    $chosen = null;
    $sessionTenant = (int) ($_SESSION['tenant_id'] ?? 0);
    foreach ($memberships as $m) {
        if ((int) $m['id'] === $sessionTenant) {
            $chosen = $m;
            break;
        }
    }
    if ($chosen === null) {
        $chosen = $memberships[0];
    }
    $_SESSION['tenant_id'] = (int) $chosen['id'];
    $tenant = $chosen;
    return $tenant;
}

function set_session_user(int $userId, ?int $tenantId = null, ?string $activeRole = null): void
{
    session_regenerate_id(true);
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
    $_SESSION['user_id'] = $userId;
    $_SESSION['_session_started'] = time();
    $_SESSION['_last_activity'] = time();
    if ($activeRole !== null && in_array($activeRole, ['customer', 'freelancer'], true)) {
        $_SESSION['active_role'] = $activeRole;
    } else {
        unset($_SESSION['active_role']);
    }
    if ($tenantId !== null) {
        $_SESSION['tenant_id'] = $tenantId;
    } else {
        unset($_SESSION['tenant_id']);
    }
    audit('auth.session_started', 'session', substr(session_id(), 0, 16), [
        'ip' => client_ip(),
        'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120),
    ]);
    try {
        db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$userId]);
    } catch (Throwable $e) {
        error_log('last_login update failed: ' . $e->getMessage());
    }
}

function require_user(): array
{
    $user = current_user();
    if (!$user) {
        fail_error('unauthenticated', 'Sign in to continue.', 401);
    }
    return $user;
}

function require_platform_admin(): array
{
    $user = require_user();
    if ($user['role'] !== 'platform_admin') {
        fail_error('forbidden', 'Platform admin access required.', 403);
    }
    return $user;
}

/** Tenant context for merchant operations; rejects suspended/cancelled tenants. */
function require_tenant(): array
{
    $user = require_user();
    $tenant = current_tenant();
    if (!$tenant) {
        fail_error('no_tenant', 'This account has no business workspace yet.', 403);
    }
    if (in_array($tenant['status'], ['suspended', 'cancelled'], true)) {
        fail_error('tenant_' . $tenant['status'], 'This workspace is ' . $tenant['status'] . '. Contact support.', 403);
    }
    $role = (string) $tenant['role'];
    return ['tenant' => $tenant, 'user' => $user, 'role' => $role];
}

function tenant_owner_or_admin(array $ctx): void
{
    if (!in_array($ctx['role'], ['owner', 'admin'], true)) {
        fail_error('forbidden', 'Only the workspace owner or an admin can do that.', 403);
    }
}

/* --------------------------------------------------------------- plans */
function plan_subscription(int $tenantId): ?array
{
    $stmt = db()->prepare(
        'SELECT s.id AS subscription_id, s.status AS subscription_status, s.trial_ends_at,
                s.current_period_start, s.current_period_end,
                p.id AS plan_id, p.code AS plan_code, p.name AS plan_name,
                p.monthly_price_kes, p.parcel_limit, p.staff_limit, p.features
         FROM subscriptions s JOIN plans p ON p.id = s.plan_id
         WHERE s.tenant_id = ? LIMIT 1'
    );
    $stmt->execute([$tenantId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $row['features'] = $row['features'] ? json_decode((string) $row['features'], true) : [];
    $row['monthly_price_kes'] = (int) $row['monthly_price_kes'];
    $row['parcel_limit'] = (int) $row['parcel_limit'];
    $row['staff_limit'] = (int) $row['staff_limit'];
    return $row;
}

function usage_this_month(int $tenantId): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM parcels
         WHERE tenant_id = ? AND registered_at >= DATE_FORMAT(NOW(), "%Y-%m-01")'
    );
    $stmt->execute([$tenantId]);
    return (int) $stmt->fetchColumn();
}

function tenant_usage(int $tenantId): array
{
    $sub = plan_subscription($tenantId);
    $used = usage_this_month($tenantId);
    $staff = db()->prepare('SELECT COUNT(*) FROM tenant_members WHERE tenant_id = ?');
    $staff->execute([$tenantId]);
    $claimsOpen = db()->prepare('SELECT COUNT(*) FROM claims WHERE tenant_id = ? AND status IN ("open", "under_review", "approved")');
    $claimsOpen->execute([$tenantId]);
    $claimsTotal = db()->prepare('SELECT COUNT(*) FROM claims WHERE tenant_id = ?');
    $claimsTotal->execute([$tenantId]);
    $limit = $sub['parcel_limit'] ?? 0;
    $trialDaysLeft = null;
    if ($sub && $sub['subscription_status'] === 'trialing' && $sub['trial_ends_at']) {
        $trialDaysLeft = max(0, (int) ceil((strtotime((string) $sub['trial_ends_at']) - time()) / 86400));
    }
    return [
        'subscription'    => $sub,
        'plan'            => $sub ? [
            'code' => $sub['plan_code'], 'name' => $sub['plan_name'],
            'monthly_price_kes' => $sub['monthly_price_kes'],
            'parcel_limit' => $sub['parcel_limit'], 'staff_limit' => $sub['staff_limit'],
            'features' => $sub['features'],
        ] : null,
        'parcels_used'    => $used,
        'parcels_limit'   => $limit,
        'parcels_remaining' => max(0, $limit - $used),
        'staff_used'      => (int) $staff->fetchColumn(),
        'claims_open'     => (int) $claimsOpen->fetchColumn(),
        'claims_total'    => (int) $claimsTotal->fetchColumn(),
        'trial_days_left' => $trialDaysLeft,
    ];
}

/** Block parcel creation when the workspace is over its monthly plan quota. */
function enforce_parcel_quota(int $tenantId): void
{
    $usage = tenant_usage($tenantId);
    if ($usage['subscription'] === null) {
        return;
    }
    if ($usage['parcels_used'] >= $usage['parcels_limit']) {
        fail_error(
            'plan_limit',
            'You have reached the ' . $usage['plan']['name'] . ' limit of ' . $usage['parcels_limit'] . ' parcels this month. Upgrade to keep sending.',
            402,
            [
                'plan'        => $usage['plan']['code'],
                'used'        => $usage['parcels_used'],
                'limit'       => $usage['parcels_limit'],
                'upgrade_url' => '/billing',
            ]
        );
    }
}

/* ---------------------------------------------------------------- audit */
/**
 * Record a money movement in the central transactions ledger. Call this inside
 * the same PDO transaction as the domain update so money and state commit
 * atomically (or roll back together).
 */
function record_transaction(array $fields): int
{
    $row = array_merge([
        'reference'    => 'TX-' . strtoupper(bin2hex(random_bytes(6))),
        'tenant_id'    => null,
        'user_id'      => null,
        'type'         => 'subscription',
        'amount_kes'   => 0,
        'method'       => 'DEMO_MPESA',
        'status'       => 'succeeded',
        'subject_type' => null,
        'subject_id'   => null,
        'metadata'     => null,
    ], $fields);
    $stmt = db()->prepare(
        'INSERT INTO transactions (reference, tenant_id, user_id, type, amount_kes, method, status,
          subject_type, subject_id, metadata, created_at, completed_at)
         VALUES (:reference, :tenant_id, :user_id, :type, :amount_kes, :method, :status,
          :subject_type, :subject_id, :metadata, NOW(), NOW())'
    );
    $stmt->execute([
        ':reference'    => (string) $row['reference'],
        ':tenant_id'    => $row['tenant_id'] !== null ? (int) $row['tenant_id'] : null,
        ':user_id'      => $row['user_id'] !== null ? (int) $row['user_id'] : null,
        ':type'         => (string) $row['type'],
        ':amount_kes'   => (int) $row['amount_kes'],
        ':method'       => (string) $row['method'],
        ':status'       => (string) $row['status'],
        ':subject_type' => $row['subject_type'],
        ':subject_id'   => $row['subject_id'] !== null ? (string) $row['subject_id'] : null,
        ':metadata'     => $row['metadata'] !== null ? json_encode($row['metadata']) : null,
    ]);
    return (int) db()->lastInsertId();
}

function audit(
    string $action,
    ?string $entity = null,
    ?string $entityId = null,
    array $meta = [],
    ?int $tenantId = null,
    ?int $actorUserId = null
): void {
    try {
        $user = current_user();
        $tenant = current_tenant();
        $stmt = db()->prepare(
            'INSERT INTO audit_log (tenant_id, actor_user_id, action, entity, entity_id, meta, ip)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $tenantId ?? ($tenant['id'] ?? null),
            $actorUserId ?? ($user['id'] ?? null),
            $action,
            $entity,
            $entityId,
            $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            client_ip(),
        ]);
    } catch (Throwable $e) {
        error_log('audit write failed: ' . $e->getMessage());
    }
}

/* -------------------------------------------------------------- api keys */
function record_api_usage(int $tenantId, int $apiKeyId = 0): void
{
    try {
        db()->prepare('INSERT INTO api_usage_daily (tenant_id, api_key_id, day, requests) VALUES (?, ?, CURDATE(), 1)
            ON DUPLICATE KEY UPDATE requests = requests + 1')
            ->execute([$tenantId, $apiKeyId]);
    } catch (Throwable $e) {
        error_log('api usage record failed: ' . $e->getMessage());
    }
}

function api_key_context(): array
{
    $raw = (string) ($_SERVER['HTTP_X_API_KEY'] ?? '');
    if ($raw === '' || strlen($raw) < 20) {
        fail_error('api_key_required', 'Provide a valid API key in the X-API-Key header.', 401);
    }
    $prefix = substr($raw, 0, 12);
    $stmt = db()->prepare(
        'SELECT k.id AS key_id, k.tenant_id, k.key_hash, k.scopes, t.name AS tenant_name, t.status AS tenant_status
         FROM api_keys k JOIN tenants t ON t.id = k.tenant_id
         WHERE k.prefix = ? AND k.revoked_at IS NULL LIMIT 1'
    );
    $stmt->execute([$prefix]);
    $row = $stmt->fetch();
    if (!$row || !hash_equals((string) $row['key_hash'], hash('sha256', $raw))) {
        rate_limit('api_auth_fail:' . client_ip(), 30, 300);
        fail_error('api_key_invalid', 'Invalid API key.', 401);
    }
    if (in_array($row['tenant_status'], ['suspended', 'cancelled'], true)) {
        fail_error('tenant_suspended', 'This workspace is not active.', 403);
    }
    db()->prepare('UPDATE api_keys SET last_used_at = NOW() WHERE id = ?')->execute([$row['key_id']]);
    rate_limit('api:' . $row['key_id'], (int) config()['security']['api_rate_per_minute'], 60);
    return [
        'key_id'      => (int) $row['key_id'],
        'tenant_id'   => (int) $row['tenant_id'],
        'tenant_name' => $row['tenant_name'],
        'scopes'      => (string) ($row['scopes'] ?? 'read,write'),
    ];
}

/* -------------------------------------------------------------- utilities */
function uuid4(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

function random_code(int $length, string $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'): string
{
    $out = '';
    $max = strlen($alphabet) - 1;
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
}

function unique_token(string $table, string $column, string $prefix, int $length): string
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT 1 FROM ' . $table . ' WHERE ' . $column . ' = ? LIMIT 1');
    for ($attempt = 0; $attempt < 60; $attempt++) {
        $candidate = $prefix . random_code($length);
        $stmt->execute([$candidate]);
        if ($stmt->fetchColumn() === false) {
            return $candidate;
        }
    }
    fail_error('allocation_failed', 'Could not allocate a unique reference, please try again.', 500);
}

function unique_locker(): string
{
    $stmt = db()->prepare('SELECT 1 FROM parcels WHERE locker = ? LIMIT 1');
    for ($attempt = 0; $attempt < 60; $attempt++) {
        $candidate = 'LK-' . random_code(4) . '-' . str_pad((string) random_int(10, 99), 2, '0', STR_PAD_LEFT);
        $stmt->execute([$candidate]);
        if ($stmt->fetchColumn() === false) {
            return $candidate;
        }
    }
    fail_error('allocation_failed', 'Could not allocate a Locker ID, please try again.', 500);
}

const MZ_PACKAGE_SIZES = [
    'Envelope', 'Small', 'Medium', 'Large',
    'Extra Large', 'Extra Extra Large', 'Extra Extra Extra Large',
];
const MZ_PAYMENT_METHODS = ['CASH', 'M-PESA'];
const MZ_DEMO_PARCELS = ['11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222'];

function quote_for(string $size, int $valueKes): array
{
    $sizeBase = [
        'Envelope' => 120, 'Small' => 180, 'Medium' => 250, 'Large' => 330,
        'Extra Large' => 420, 'Extra Extra Large' => 520, 'Extra Extra Extra Large' => 650,
    ];
    $base = (int) (round((($sizeBase[$size] ?? 250) + 180) / 10) * 10);
    $holding = 30;
    $insurance = max(30, (int) (round(($valueKes * 0.015) / 10) * 10));
    $platform = 80;
    $total = (int) (ceil(($base + $holding + $insurance + $platform) / 10) * 10);
    return ['base' => $base, 'holding' => $holding, 'insurance' => $insurance, 'platform' => $platform, 'total' => $total];
}

/* ------------------------------------------------------------- data views */
function scans_for(string $subjectType, string $subjectId): array
{
    $stmt = db()->prepare(
        'SELECT status, label, location, note, scanned_at FROM scans
         WHERE subject_type = ? AND subject_id = ? ORDER BY scanned_at ASC, id ASC'
    );
    $stmt->execute([$subjectType, $subjectId]);
    $scans = [];
    foreach ($stmt->fetchAll() as $row) {
        $scans[] = [
            'status' => $row['status'],
            'label'  => $row['label'],
            'where'  => $row['location'],
            'note'   => $row['note'],
            'at'     => str_replace(' ', 'T', (string) $row['scanned_at']),
        ];
    }
    return $scans;
}

function phone_key(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    return substr($digits, -9);
}

function require_customer(): array
{
    $user = require_user();
    if (($user['role'] ?? '') !== 'customer') {
        fail_error('forbidden', 'A customer account is required for this action.', 403);
    }
    return $user;
}

function parcel_insurance_ref(string $tracking): string
{
    $normalized = strtoupper(trim($tracking));
    if ($normalized === '') { return ''; }
    return 'INS-' . strtoupper(substr(hash('sha256', 'mzigo-insurance:' . $normalized), 0, 10));
}

function parcel_api(array $row): array
{
    return [
        'id'             => $row['id'],
        'kind'           => 'parcel',
        'tracking'       => $row['tracking'],
        'locker'         => $row['locker'],
        'carrier'        => $row['carrier'],
        'carrier_slug'   => $row['carrier_slug'],
        'from'           => $row['from_city'],
        'to'             => $row['to_city'],
        'sender'         => $row['sender_name'],
        'sender_phone'   => $row['sender_phone'],
        'receiver'       => $row['receiver_name'],
        'receiver_phone' => $row['receiver_phone'],
        'description'    => $row['description'],
        'size'           => $row['size'],
        'value'          => (int) $row['value_kes'],
        'insurance_ref'  => trim((string) ($row['insurance_policy_no'] ?? '')) !== '' ? $row['insurance_policy_no'] : parcel_insurance_ref((string) $row['tracking']),
        'insurance_policy_no' => $row['insurance_policy_no'] ?? null,
        'insurance_status' => $row['insurance_status'] ?? null,
        'insurance_provider' => $row['insurance_provider'] ?? null,
        'insurance_cover_kes' => (int) $row['value_kes'],
        'tier'           => $row['tier'],
        'payment'        => $row['payment_method'],
        'status'         => $row['status'],
        'collect_code'   => $row['collect_code'],
        'payment_status' => $row['payment_status'] ?? 'unpaid',
        'paid_at'        => !empty($row['paid_at']) ? str_replace(' ', 'T', (string) $row['paid_at']) : null,
        'payment_ref'    => $row['payment_ref'] ?? null,
        'fee'            => [
            'base' => (int) $row['fee_base'], 'holding' => (int) $row['fee_holding'],
            'insurance' => (int) $row['fee_insurance'], 'platform' => (int) $row['fee_platform'],
            'total' => (int) $row['fee_total'],
        ],
        'registered_at' => str_replace(' ', 'T', (string) $row['registered_at']),
        'promised_delivery_at' => !empty($row['promised_delivery_at']) ? str_replace(' ', 'T', (string) $row['promised_delivery_at']) : null,
        'csat_rating' => isset($row['csat_rating']) && $row['csat_rating'] !== null ? (int) $row['csat_rating'] : null,
        'csat_comment' => $row['csat_comment'] ?? null,
        'scans'         => scans_for('parcel', (string) $row['id']),
        'photos'        => parcel_photo_urls((string) $row['id']),
    ];
}

function errand_api(array $row): array
{
    return [
        'id'             => $row['id'],
        'kind'           => 'errand',
        'ref'            => $row['ref'],
        'carrier'        => $row['carrier'],
        'pickup'         => $row['pickup'],
        'dropoff'        => $row['dropoff'],
        'sender'         => $row['sender_name'],
        'sender_phone'   => $row['sender_phone'],
        'receiver'       => $row['recipient_name'],
        'receiver_phone' => $row['recipient_phone'],
        'description'    => $row['description'],
        'size'           => $row['size'] ?? null,
        'distance_km'    => isset($row['distance_km']) && $row['distance_km'] !== null ? (float) $row['distance_km'] : null,
        'instructions'   => $row['instructions'],
        'payment'        => $row['payment_method'],
        'status'         => $row['status'],
        'fee'            => [
            'base' => (int) $row['fee_base'], 'platform' => (int) $row['fee_platform'],
            'total' => (int) $row['fee_total'],
        ],
        'registered_at'  => str_replace(' ', 'T', (string) $row['created_at']),
        'scans'          => scans_for('errand', (string) $row['id']),
    ];
}

/**
 * Rows visible to this request: the current tenant's rows, plus rows created
 * anonymously from this browser (walk-in senders who are not signed in).
 */
function visible_scope(): array
{
    $tenant = current_tenant();
    $user = current_user();
    $anon = (string) ($_SESSION['anon_id'] ?? '');
    $parts = [];
    $params = [];
    if ($tenant) {
        $parts[] = 'tenant_id = :tenant_id';
        $params[':tenant_id'] = $tenant['id'];
    }
    if ($user) {
        $parts[] = 'user_id = :user_id';
        $params[':user_id'] = $user['id'];
    }
    if ($anon !== '') {
        $parts[] = 'anon_id = :anon_id';
        $params[':anon_id'] = $anon;
    }
    return ['sql' => $parts ? '(' . implode(' OR ', $parts) . ')' : '0=1', 'params' => $params];
}

/**
 * Strict workspace order scope. Workspace order surfaces must never fall back
 * to user/anon ownership: a parcel/errand belongs to a workspace only when its
 * tenant_id matches the active workspace.
 */
function workspace_order_scope(): array
{
    $tenant = current_tenant();
    if (!$tenant) {
        return ['sql' => '0=1', 'params' => []];
    }
    return ['sql' => 'tenant_id = :workspace_tenant_id', 'params' => [':workspace_tenant_id' => (int) $tenant['id']]];
}

function parcels_for_scope(): array
{
    $scope = workspace_order_scope();
    $stmt = db()->prepare('SELECT * FROM parcels WHERE ' . $scope['sql'] . ' ORDER BY registered_at DESC LIMIT 50');
    $stmt->execute($scope['params']);
    return array_map('parcel_api', $stmt->fetchAll());
}

function errands_for_scope(): array
{
    $scope = workspace_order_scope();
    $stmt = db()->prepare('SELECT * FROM errands WHERE ' . $scope['sql'] . ' ORDER BY created_at DESC LIMIT 50');
    $stmt->execute($scope['params']);
    return array_map('errand_api', $stmt->fetchAll());
}

function senders_for_scope(): array
{
    $scope = workspace_order_scope();
    $sql = 'SELECT sender_name AS name, sender_phone AS phone, MAX(registered_at) AS last_at
            FROM parcels WHERE ' . $scope['sql'] . '
            GROUP BY sender_name, sender_phone ORDER BY last_at DESC LIMIT 8';
    $stmt = db()->prepare($sql);
    $stmt->execute($scope['params']);
    $senders = [];
    foreach ($stmt->fetchAll() as $row) {
        $senders[$row['phone']] = ['name' => $row['name'], 'phone' => $row['phone']];
    }
    $tenant = current_tenant();
    if ($tenant) {
        $stmt = db()->prepare(
            'SELECT name, phone FROM senders WHERE tenant_id = ?
             ORDER BY created_at DESC LIMIT 8'
        );
        $stmt->execute([$tenant['id']]);
        foreach ($stmt->fetchAll() as $row) {
            $senders[$row['phone']] = ['name' => $row['name'], 'phone' => $row['phone']];
        }
    }
    return array_values($senders);
}

function catalog(): array
{
    $pdo = db();
    $partners = [];
    foreach ($pdo->query('SELECT slug, name, is_pickup FROM partners ORDER BY name ASC')->fetchAll() as $row) {
        $partners[$row['slug']] = [
            'slug' => $row['slug'], 'name' => $row['name'],
            'is_pickup' => (bool) $row['is_pickup'], 'destinations' => [],
        ];
    }
    foreach ($pdo->query('SELECT partner_slug, name FROM destinations ORDER BY name ASC')->fetchAll() as $row) {
        if (isset($partners[$row['partner_slug']])) {
            $partners[$row['partner_slug']]['destinations'][] = $row['name'];
        }
    }
    return [
        'partners'       => array_values($partners),
        'packageSizes'   => MZ_PACKAGE_SIZES,
        'paymentMethods' => MZ_PAYMENT_METHODS,
    ];
}

require_once __DIR__ . '/_parcel_photos.php';
