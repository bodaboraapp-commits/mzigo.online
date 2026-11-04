<?php
/**
 * POST /api/firebase-otp-login.php
 *
 * Verifies a Firebase phone-auth ID token and starts a Mzigo session.
 *
 * Body: { id_token: string, scope: business|customer|freelancer }
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require dirname(__DIR__) . '/_server/firebase.php';
require __DIR__ . '/_notifications.php';

require_post();
verify_csrf();

if (!firebase_ready()) {
    fail_error('firebase_disabled', 'SMS sign-in is not configured yet. Use your password or email code.', 503);
}

$data = body_json();
$idToken = trim((string) ($data['id_token'] ?? ''));
$scope = strtolower(trim((string) ($data['scope'] ?? 'business')));
if ($idToken === '' || strlen($idToken) > 4096) {
    fail_error('invalid_token', 'Missing or malformed sign-in token.', 422);
}
if (!in_array($scope, ['business', 'customer', 'freelancer'], true)) {
    fail_error('invalid_scope', 'Unknown sign-in type.', 422);
}

rate_limit('firebase-otp:' . client_ip(), 10, 300);

try {
    $payload = firebase_verify_id_token($idToken);
    $phone = firebase_local_phone((string) $payload['phone_number']);
} catch (Throwable $e) {
    audit('auth.firebase_otp_failed', 'user', null, [
        'phone' => null,
        'reason' => $e->getMessage(),
    ]);
    fail_error('invalid_token', 'That SMS code could not be verified. Request a new code.', 401);
}

$user = firebase_find_user_by_phone($phone, $scope);
if ($user && ($user['status'] ?? 'active') !== 'active') {
    fail_error('account_suspended', 'This account is suspended. Contact MziGO.Online support.', 403);
}

try {
    if ($scope === 'business') {
        if (!$user) {
            fail_error('no_account', 'No business account matches that phone number. Sign up or use your password.', 404);
        }
        $memberships = user_tenants((int) $user['id']);
        $tenantId = $memberships ? (int) $memberships[0]['id'] : null;
        set_session_user((int) $user['id'], $tenantId);
        $context = firebase_login_context();
        $context['redirect'] = (($context['user']['role'] ?? '') === 'platform_admin') ? '/admin' : '/profile';
        audit('auth.firebase_otp_login', 'user', (string) $user['id'], ['phone' => $phone, 'scope' => $scope]);
        json_out(['ok' => true, 'source' => 'mysql', 'provider' => 'firebase'] + $context);
    }

    if ($scope === 'customer') {
        if (!$user) {
            $stmt = db()->prepare('INSERT INTO users (name, phone, role) VALUES (?, ?, "customer")');
            $stmt->execute(['Customer ' . substr(phone_key($phone), -4), normalize_phone($phone)]);
            $userId = (int) db()->lastInsertId();
        } else {
            if (!user_has_role((int) $user['id'], 'customer') && ($user['role'] ?? '') !== 'customer') {
                fail_error('no_account', 'That number is registered as a business or runner account. Choose the matching sign-in type.', 409);
            }
            $userId = (int) $user['id'];
        }
        set_session_user($userId, null, 'customer');
        $context = firebase_login_context();
        $context['redirect'] = '/customer';
        audit('auth.firebase_otp_login', 'user', (string) $userId, ['phone' => $phone, 'scope' => $scope]);
        json_out(['ok' => true, 'source' => 'mysql', 'provider' => 'firebase'] + $context);
    }

    // Freelancer / runner
    if (!$user) {
        $stmt = db()->prepare('INSERT INTO users (name, phone, role) VALUES (?, ?, "freelancer")');
        $stmt->execute(['Runner ' . substr(phone_key($phone), -4), normalize_phone($phone)]);
        $userId = (int) db()->lastInsertId();
    } else {
        if (($user['role'] ?? '') === 'platform_admin') {
            fail_error('account_type_mismatch', 'Use the admin console for this account.', 409);
        }
        if (($user['role'] ?? '') !== 'freelancer' && !user_has_role((int) $user['id'], 'freelancer')) {
            user_grant_role((int) $user['id'], 'freelancer');
        }
        $userId = (int) $user['id'];
    }
    set_session_user($userId, null, 'freelancer');
    $context = firebase_login_context();
    $context['redirect'] = '/freelancer';
    audit('auth.firebase_otp_login', 'user', (string) $userId, ['phone' => $phone, 'scope' => $scope]);
    json_out(['ok' => true, 'source' => 'mysql', 'provider' => 'firebase'] + $context);
} catch (Throwable $e) {
    error_log('firebase otp login failed: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    fail_error('login_failed', 'Could not complete SMS sign-in. Please try again.', 500);
}
