<?php
/**
 * Mzigo.online — SaaS authentication.
 *
 * GET  ?action=me                        -> session context
 * POST action=signup                      -> create workspace + owner + 14-day trial
 * POST action=login                       -> email/phone + password
 * POST action=logout                      -> end session
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_growth.php';
require __DIR__ . '/_verification.php';
require __DIR__ . '/_totp.php';
require __DIR__ . '/_recovery.php';

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = isset($_GET['action']) ? (string) $_GET['action'] : '';

function auth_context(): array
{
    $user = current_user();
    $tenant = current_tenant();
    return [
        'user'    => $user,
        'tenant'  => $tenant,
        'tenants' => $user ? user_tenants((int) $user['id']) : [],
        'role'    => $tenant['role'] ?? ($user['role'] ?? null),
        'usage'   => $tenant ? tenant_usage((int) $tenant['id']) : null,
        'csrf'    => csrf_token(),
    ];
}

/**
 * Freelancer user class: phone + a system-generated 4-digit PIN.
 * In demo mode the PIN is always 1234; production generates a random PIN and
 * delivers it by SMS (integration seam in docs/PRODUCTION.md).
 */
function freelancer_pin(): string
{
    return config()['app']['demo_mode'] === true
        ? '1234'
        : str_pad((string) random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
}

function find_user_by_phone(string $phone): ?array
{
    $stmt = db()->prepare('SELECT id, name, phone, role, status FROM users WHERE phone = ? LIMIT 1');
    $stmt->execute([$phone]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function freelancer_user_for(string $phone): array
{
    $existing = find_user_by_phone($phone);
    if ($existing !== null) {
        if ($existing['role'] !== 'freelancer') {
            fail_error('account_type_mismatch', 'That phone number belongs to a business or admin account. Use the normal sign-in.', 409);
        }
        return $existing;
    }
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    $name = 'Freelancer ' . substr($digits, -4);
    $stmt = db()->prepare('INSERT INTO users (name, phone, role) VALUES (?, ?, "freelancer")');
    $stmt->execute([$name, $phone]);
    return ['id' => (int) db()->lastInsertId(), 'name' => $name, 'phone' => $phone, 'role' => 'freelancer'];
}

if ($method === 'GET' && ($action === '' || $action === 'me')) {
    json_out(['ok' => true, 'source' => 'mysql'] + auth_context());
}

require_post();
verify_csrf();

if ($action === '') {
    $body = body_json();
    $action = isset($body['action']) ? (string) $body['action'] : '';
}
$data = body_json();

switch ($action) {
    case 'signup':
        rate_limit('signup:' . client_ip(), 5, 3600);
        $business = param($data, 'business_name', 2, 160, 'Business name');
        $name = param($data, 'name', 3, 120, 'Your name');
        $email = param($data, 'email', 5, 160, 'Email');
        if (!email_valid($email)) {
            fail('Enter a valid email address.', 422, ['email' => 'Invalid email.']);
        }
        $phone = require_phone($data);
        $password = param($data, 'password', 8, 72, 'Password');
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            fail('Use at least one letter and one number in the password.', 422, ['password' => 'Password is too weak.']);
        }

        $pdo = db();
        $stmt = $pdo->prepare('SELECT 1 FROM users WHERE email = ? OR phone = ? LIMIT 1');
        $stmt->execute([$email, $phone]);
        if ($stmt->fetchColumn() !== false) {
            fail('An account with that email or phone already exists. Sign in instead.', 409, ['email' => 'Already registered.']);
        }

        $baseSlug = preg_replace('/[^a-z0-9]+/', '-', strtolower($business)) ?: 'workspace';
        $baseSlug = trim($baseSlug, '-');
        $baseSlug = substr($baseSlug, 0, 60);
        $slug = $baseSlug;
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $check = $pdo->prepare('SELECT 1 FROM tenants WHERE slug = ? LIMIT 1');
            $check->execute([$slug]);
            if ($check->fetchColumn() === false) {
                break;
            }
            $slug = $baseSlug . '-' . random_code(4, 'abcdefghijkmnpqrstuvwxyz23456789');
        }

        $starter = $pdo->prepare('SELECT id FROM plans WHERE code = ? LIMIT 1');
        $starter->execute(['starter']);
        $starterId = (int) $starter->fetchColumn();

        $trialDays = (int) config()['app']['trial_days'];
        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare('INSERT INTO tenants (slug, name, email, phone, status) VALUES (?, ?, ?, ?, ?)');
            $ins->execute([$slug, $business, $email, $phone, 'trial']);
            $tenantId = (int) $pdo->lastInsertId();

            $ins = $pdo->prepare('INSERT INTO users (name, email, phone, password_hash, role) VALUES (?, ?, ?, ?, ?)');
            $ins->execute([$name, $email, $phone, password_hash($password, PASSWORD_DEFAULT), 'user']);
            $userId = (int) $pdo->lastInsertId();

            $pdo->prepare('INSERT INTO tenant_members (tenant_id, user_id, role) VALUES (?, ?, "owner")')
                ->execute([$tenantId, $userId]);

            $trialEnds = date('Y-m-d H:i:s', time() + $trialDays * 86400);
            $pdo->prepare(
                'INSERT INTO subscriptions (tenant_id, plan_id, status, trial_ends_at, current_period_start, current_period_end)
                 VALUES (?, ?, "trialing", ?, NOW(), ?)'
            )->execute([$tenantId, $starterId, $trialEnds, $trialEnds]);

            if (pricing_mode() === 'free') {
                $pdo->prepare('UPDATE subscriptions SET status = "active", trial_ends_at = NULL WHERE tenant_id = ?')->execute([$tenantId]);
                $pdo->prepare('UPDATE tenants SET status = "active" WHERE id = ?')->execute([$tenantId]);
            } else {
                $pdo->prepare('UPDATE tenants SET status = "trial" WHERE id = ?')->execute([$tenantId]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('signup failed: ' . $e->getMessage());
            fail_error('signup_failed', 'Could not create the workspace. Please try again.', 500);
        }

        set_session_user($userId, $tenantId);
        growth_attribute_referral($userId, 'business', (string) ($_COOKIE['mz_ref'] ?? ($data['referral_code'] ?? '')), (string) ($_COOKIE['mz_ref_src'] ?? ''));
        $verification = email_verification_issue($userId, $email);
        audit('auth.signup', 'tenant', (string) $tenantId, ['business' => $business, 'email' => $email]);
        $context = auth_context();
        $context['redirect'] = '/profile';
        $context['verification_sent'] = $verification['sent'];
        $context['email_verification_link'] = $verification['demo_link'] ?? null;
        json_out(['ok' => true, 'source' => 'mysql'] + $context, 201);
        // no break (json_out exits)

    case 'login':
        $identifier = param($data, 'identifier', 3, 160, 'Email or phone');
        $password = param($data, 'password', 1, 72, 'Password');
        rate_limit('login:' . client_ip() . ':' . strtolower($identifier), (int) config()['security']['login_max_attempts'], (int) config()['security']['login_window_seconds']);

        $pdo = db();
        $stmt = $pdo->prepare('SELECT id, name, email, phone, password_hash, role, status FROM users WHERE email = ? OR phone = ? LIMIT 1');
        $stmt->execute([$identifier, $identifier]);
        $user = $stmt->fetch();
        if ($user && ($user['status'] ?? 'active') !== 'active') {
            fail_error('account_suspended', 'This account is suspended. Contact Mzigo.online support.', 403);
        }
        if (!$user || empty($user['password_hash']) || !password_verify($password, (string) $user['password_hash'])) {
            audit('auth.login_failed', 'user', null, ['identifier' => $identifier]);
            fail_error('invalid_credentials', 'Incorrect email/phone or password.', 401);
        }
        $twofaStmt = $pdo->prepare('SELECT secret FROM user_2fa WHERE user_id = ? AND enabled_at IS NOT NULL LIMIT 1');
        $twofaStmt->execute([(int) $user['id']]);
        $twofaSecret = $twofaStmt->fetchColumn();
        if ($twofaSecret) {
            $_SESSION['pending_2fa_user'] = (int) $user['id'];
            $_SESSION['pending_2fa_expires'] = time() + 300;
            audit('auth.2fa_pending', 'user', (string) $user['id'], []);
            json_out(['ok' => true, 'source' => 'mysql', 'twofa_required' => true,
                'message' => 'Enter the 6-digit code from your authenticator app.']);
        }
        $memberships = user_tenants((int) $user['id']);
        $tenantId = $memberships ? (int) $memberships[0]['id'] : null;
        set_session_user((int) $user['id'], $tenantId);
        audit('auth.login', 'user', (string) $user['id'], ['tenant_id' => $tenantId]);
        $context = auth_context();
        $context['redirect'] = (($context['user']['role'] ?? '') === 'platform_admin') ? '/admin' : '/profile';
        json_out(['ok' => true, 'source' => 'mysql'] + $context);
        // no break

    case 'verify_2fa':
        $pending = (int) ($_SESSION['pending_2fa_user'] ?? 0);
        $expires = (int) ($_SESSION['pending_2fa_expires'] ?? 0);
        if ($pending === 0 || $expires < time()) {
            unset($_SESSION['pending_2fa_user'], $_SESSION['pending_2fa_expires']);
            fail_error('twofa_expired', 'Your sign-in verification expired. Sign in again.', 401);
        }
        $rawCode = trim((string) ($data['code'] ?? ''));
        $digits = preg_replace('/\D/', '', $rawCode) ?? '';
        rate_limit('2fa_login:' . $pending . ':' . client_ip(), 6, 300);
        $stmt = db()->prepare('SELECT secret FROM user_2fa WHERE user_id = ? AND enabled_at IS NOT NULL LIMIT 1');
        $stmt->execute([$pending]);
        $secret = $stmt->fetchColumn();
        $verified = false;
        if ($secret) {
            if (strlen($digits) === 6 && totp_verify((string) $secret, $digits)) {
                $verified = true;
            } elseif (recovery_code_consume($pending, $rawCode)) {
                $verified = true;
                audit('auth.2fa_recovery_used', 'user', (string) $pending, ['remaining' => recovery_codes_remaining($pending)]);
            }
        }
        if (!$verified) {
            fail_error('invalid_2fa', 'That authenticator or recovery code is not valid.', 401);
        }
        $stmt = db()->prepare('SELECT id, status FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$pending]);
        $target = $stmt->fetch();
        if (!$target || $target['status'] !== 'active') {
            fail_error('account_suspended', 'This account is not active.', 403);
        }
        $memberships = user_tenants($pending);
        $tenantId = $memberships ? (int) $memberships[0]['id'] : null;
        set_session_user($pending, $tenantId);
        unset($_SESSION['pending_2fa_user'], $_SESSION['pending_2fa_expires']);
        audit('auth.login_2fa', 'user', (string) $pending, ['tenant_id' => $tenantId]);
        $context = auth_context();
        $context['redirect'] = (($context['user']['role'] ?? '') === 'platform_admin') ? '/admin' : '/profile';
        json_out(['ok' => true, 'source' => 'mysql'] + $context);
        // no break

    case 'customer-start':
        $phone = require_phone($data);
        rate_limit('customer-start:' . client_ip() . ':' . $phone, 10, 300);
        $existing = find_user_by_phone($phone);
        if ($existing !== null && $existing['role'] !== 'customer') {
            fail_error('account_type_mismatch', 'That phone number belongs to another account type. Use the appropriate sign-in.', 409);
        }
        if ($existing !== null && ($existing['status'] ?? 'active') !== 'active') {
            fail_error('account_suspended', 'This account is suspended. Contact Mzigo.online support.', 403);
        }
        if ($existing === null) {
            $stmt = db()->prepare('INSERT INTO users (name, phone, role) VALUES (?, ?, "customer")');
            $stmt->execute(['Customer ' . substr(phone_key($phone), -4), $phone]);
        }
        $code = config()['app']['demo_mode'] === true ? '1234' : str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        db()->prepare('UPDATE users SET otp_hash = ?, otp_expires_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE phone = ?')
            ->execute([password_hash($code, PASSWORD_DEFAULT), $phone]);
        audit('auth.customer_code_issued', 'user', $phone, ['phone' => $phone]);
        json_out([
            'ok'        => true,
            'source'    => 'mysql',
            'sent'      => true,
            'demo_code' => config()['app']['demo_mode'] === true ? $code : null,
            'phone'     => $phone,
            'csrf'      => csrf_token(),
        ]);
        // no break

    case 'customer-login':
        $phone = require_phone($data);
        $code = param($data, 'code', 4, 4, 'code');
        rate_limit('customer-login:' . client_ip() . ':' . $phone, 6, 300);
        $customer = find_user_by_phone($phone);
        if ($customer === null || $customer['role'] !== 'customer') {
            fail_error('invalid_credentials', 'No customer account matches that phone number.', 401);
        }
        if (($customer['status'] ?? 'active') !== 'active') {
            fail_error('account_suspended', 'This account is suspended. Contact Mzigo.online support.', 403);
        }
        $stmt = db()->prepare('SELECT otp_hash, otp_expires_at FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$customer['id']]);
        $otp = $stmt->fetch();
        $otpHash = (string) ($otp['otp_hash'] ?? '');
        $expires = (string) ($otp['otp_expires_at'] ?? '');
        if ($otpHash === '' || $expires === '' || strtotime($expires) < time() || !password_verify($code, $otpHash)) {
            audit('auth.customer_login_failed', 'user', $phone, ['phone' => $phone]);
            fail_error('invalid_credentials', 'That code is not correct or has expired.', 401);
        }
        db()->prepare('UPDATE users SET otp_hash = NULL, otp_expires_at = NULL WHERE id = ?')->execute([$customer['id']]);
        set_session_user((int) $customer['id'], null);
        growth_attribute_referral((int) $customer['id'], 'customer', (string) ($_COOKIE['mz_ref'] ?? ($data['referral_code'] ?? '')), (string) ($_COOKIE['mz_ref_src'] ?? ''));
        audit('auth.customer_login', 'user', (string) $customer['id'], ['phone' => $phone]);
        $context = auth_context();
        $context['redirect'] = '/customer';
        json_out(['ok' => true, 'source' => 'mysql'] + $context);
        // no break

    case 'freelancer-start':
        $phone = require_phone($data);
        rate_limit('freelancer-start:' . client_ip() . ':' . $phone, 10, 300);
        $freelancer = freelancer_user_for($phone);
        $pin = freelancer_pin();
        db()->prepare('UPDATE users SET pin_hash = ? WHERE id = ?')
            ->execute([password_hash($pin, PASSWORD_DEFAULT), $freelancer['id']]);
        audit('auth.freelancer_pin_issued', 'user', (string) $freelancer['id'], ['phone' => $phone]);
        json_out([
            'ok'        => true,
            'source'    => 'mysql',
            'sent'      => true,
            'new_user'  => (int) $freelancer['id'] > 0 && str_starts_with((string) $freelancer['name'], 'Freelancer '),
            'demo_pin'  => config()['app']['demo_mode'] === true ? $pin : null,
            'phone'     => $phone,
            'csrf'      => csrf_token(),
        ]);
        // no break

    case 'freelancer-login':
        $phone = require_phone($data);
        $pin = param($data, 'pin', 4, 4, 'PIN');
        rate_limit('freelancer-login:' . client_ip() . ':' . $phone, 6, 300);
        $freelancer = find_user_by_phone($phone);
        if ($freelancer === null || $freelancer['role'] !== 'freelancer') {
            fail_error('invalid_credentials', 'No freelancer account matches that phone number.', 401);
        }
        if (($freelancer['status'] ?? 'active') !== 'active') {
            fail_error('account_suspended', 'This account is suspended. Contact Mzigo.online support.', 403);
        }
        $stmt = db()->prepare('SELECT pin_hash FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$freelancer['id']]);
        $pinHash = (string) $stmt->fetchColumn();
        if ($pinHash === '' || !password_verify($pin, $pinHash)) {
            audit('auth.freelancer_login_failed', 'user', (string) $freelancer['id'], ['phone' => $phone]);
            fail_error('invalid_credentials', 'That PIN is not correct.', 401);
        }
        set_session_user((int) $freelancer['id'], null);
        growth_attribute_referral((int) $freelancer['id'], 'runner', (string) ($_COOKIE['mz_ref'] ?? ($data['referral_code'] ?? '')), (string) ($_COOKIE['mz_ref_src'] ?? ''));
        audit('auth.freelancer_login', 'user', (string) $freelancer['id'], ['phone' => $phone]);
        $context = auth_context();
        $context['redirect'] = '/freelancer';
        json_out(['ok' => true, 'source' => 'mysql'] + $context);
        // no break

    case 'logout':
        $userId = current_user()['id'] ?? null;
        $sessionId = session_id();
        audit('auth.logout', 'user', $userId ? (string) $userId : null);
        if ($sessionId !== '') {
            db()->prepare('DELETE FROM sessions WHERE id = ?')->execute([$sessionId]);
            audit('auth.session_ended', 'session', substr($sessionId, 0, 16), ['ip' => client_ip()]);
        }
        unset($_SESSION['user_id'], $_SESSION['tenant_id']);
        session_regenerate_id(true);
        json_out(['ok' => true, 'source' => 'mysql', 'csrf' => csrf_token()]);
        // no break

    case 'switch-tenant':
        $user = require_user();
        $tenantId = (int) param($data, 'tenant_id', 1, 20, 'Workspace');
        foreach (user_tenants((int) $user['id']) as $membership) {
            if ((int) $membership['id'] === $tenantId) {
                $_SESSION['tenant_id'] = $tenantId;
                audit('auth.switch_tenant', 'tenant', (string) $tenantId);
                json_out(['ok' => true, 'source' => 'mysql'] + auth_context());
            }
        }
        fail_error('forbidden', 'You are not a member of that workspace.', 403);

    default:
        fail_error('unknown_action', 'Unknown action.', 400);
}
