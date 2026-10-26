<?php
/**
 * Mzigo.online security regression suite.
 *
 * Usage:
 *   php tests/security/run.php
 *
 * Environment:
 *   MZIGO_TEST_BASE_URL   default http://localhost:8090
 *
 * Runs against the local Apache instance and the seeded demo workspace. It
 * creates only transient rate-limit/temp-file artifacts and removes them.
 * Exits non-zero if any check fails.
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__, 2);
$config = require $ROOT . '/_server/config.php';
$BASE = rtrim(getenv('MZIGO_TEST_BASE_URL') ?: 'http://localhost:8090', '/');

require_once $ROOT . '/api/_webhooks.php';
require_once $ROOT . '/api/_malware.php';

$pdo = new PDO($config['db']['dsn'], $config['db']['user'], $config['db']['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$passed = 0;
$failed = 0;
$failures = [];
$cookieJars = [];
$tempFiles = [];

function sec_http(string $method, string $path, ?array $body = null, ?string $cookieJar = null, ?string $csrf = null, array $extraHeaders = []): array
{
    global $BASE;
    $url = $BASE . $path;
    $ch = curl_init($url);
    $headers = array_merge(['Accept: application/json', 'User-Agent: Mzigo-Security-Suite/1.0'], $extraHeaders);
    if ($csrf !== null && $csrf !== '') { $headers[] = 'X-CSRF-Token: ' . $csrf; }
    $responseHeaders = [];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => function ($curl, string $header) use (&$responseHeaders): int {
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) { $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]); }
            return strlen($header);
        },
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_HTTPHEADER] = $headers;
    }
    if ($cookieJar !== null) {
        $opts[CURLOPT_COOKIEJAR] = $cookieJar;
        $opts[CURLOPT_COOKIEFILE] = $cookieJar;
    }
    curl_setopt_array($ch, $opts);
    $response = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    $json = json_decode($response, true);
    return ['status' => $status, 'body' => $response, 'json' => is_array($json) ? $json : null,
        'headers' => $responseHeaders, 'error' => $error];
}

function sec_session(?string $identifier = null, ?string $password = null): array
{
    global $cookieJars;
    $jar = tempnam(sys_get_temp_dir(), 'mzigo-sec-');
    if ($jar === false) { throw new RuntimeException('Could not create cookie jar.'); }
    $cookieJars[] = $jar;
    $boot = sec_http('GET', '/api/bootstrap.php', null, $jar);
    if ($boot['status'] !== 200 || !$boot['json']) {
        throw new RuntimeException('Bootstrap failed with HTTP ' . $boot['status'] . ': ' . substr($boot['body'], 0, 200));
    }
    $csrf = (string) ($boot['json']['csrf'] ?? '');
    $login = null;
    if ($identifier !== null && $password !== null) {
        $login = sec_http('POST', '/api/auth.php', ['action' => 'login', 'identifier' => $identifier, 'password' => $password], $jar, $csrf);
        if ($login['status'] !== 200 || empty($login['json']['ok'])) {
            throw new RuntimeException('Login failed for ' . $identifier . ' (HTTP ' . $login['status'] . '): ' . substr($login['body'], 0, 200));
        }
        $csrf = (string) ($login['json']['csrf'] ?? $csrf);
    }
    return ['jar' => $jar, 'csrf' => $csrf, 'login' => $login];
}

function sec_expect(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function sec_status(array $response, array $allowed, string $label): void
{
    if (!in_array($response['status'], $allowed, true)) {
        throw new RuntimeException($label . ' returned HTTP ' . $response['status'] . ' (expected ' . implode('/', $allowed) . '): ' . substr($response['body'], 0, 240));
    }
}

function sec_run(string $name, callable $test): void
{
    global $passed, $failed, $failures;
    try {
        $test();
        $passed++;
        echo "[PASS] {$name}\n";
    } catch (Throwable $e) {
        $failed++;
        $failures[] = $name . ': ' . $e->getMessage();
        echo "[FAIL] {$name} — " . $e->getMessage() . "\n";
    }
}

function sec_cleanup_rate_limit(string $identifier): void
{
    global $pdo;
    foreach (['127.0.0.1', '::1', 'unknown'] as $ip) {
        $bucket = substr(hash('sha256', 'login:' . $ip . ':' . strtolower($identifier)), 0, 64);
        $pdo->prepare('DELETE FROM rate_limits WHERE bucket = ?')->execute([$bucket]);
    }
}

try {
    echo "Mzigo.online security regression suite\n";
    echo "Target: {$BASE}\n\n";

    foreach (['amina@example.com', 'sokoni@example.com', 'admin@mzigo.online'] as $testAccount) {
        sec_cleanup_rate_limit($testAccount);
    }
    $guest = sec_session();
    $amina = sec_session('amina@example.com', 'Demo1234!');
    $sokoni = sec_session('sokoni@example.com', 'Demo1234!');
    $admin = sec_session('admin@mzigo.online', 'Admin123!');

    sec_run('Security headers on API responses', function () use ($guest): void {
        $health = sec_http('GET', '/api/health.php', null, $guest['jar']);
        sec_status($health, [200], 'health');
        foreach (['x-content-type-options', 'x-frame-options', 'content-security-policy'] as $header) {
            sec_expect(!empty($health['headers'][$header]), 'Missing security header: ' . $header);
        }
        sec_expect(strtolower($health['headers']['x-content-type-options']) === 'nosniff', 'X-Content-Type-Options must be nosniff.');
    });

    sec_run('Health probe hides sensitive metrics without key', function () use ($guest): void {
        $health = sec_http('GET', '/api/health.php', null, $guest['jar']);
        sec_status($health, [200], 'health');
        sec_expect(is_array($health['json']) && ($health['json']['status'] ?? '') === 'healthy', 'Health response missing healthy status.');
        foreach (['counts', 'mrr_kes', 'paid_revenue_kes', 'recent', 'database', 'server', 'php'] as $sensitive) {
            sec_expect(!array_key_exists($sensitive, $health['json']), 'Public health exposed sensitive field: ' . $sensitive);
        }
        $withWrongKey = sec_http('GET', '/api/health.php', null, $guest['jar'], null, ['X-Health-Key: deliberately-wrong']);
        sec_status($withWrongKey, [200], 'health with wrong key');
        sec_expect(!array_key_exists('counts', $withWrongKey['json'] ?? []), 'Wrong health key exposed metrics.');
    });

    sec_run('Unauthenticated workspace settings are rejected', function () use ($guest): void {
        $response = sec_http('GET', '/api/settings.php', null, $guest['jar']);
        sec_status($response, [401, 403], 'settings without auth');
        sec_expect(!isset($response['json']['tenant']), 'Guest response contained tenant data.');
    });

    sec_run('Merchant cannot access platform admin API', function () use ($amina): void {
        $response = sec_http('GET', '/api/admin.php', null, $amina['jar']);
        sec_status($response, [403], 'merchant admin access');
        $post = sec_http('POST', '/api/admin.php', ['action' => '__security_probe__'], $amina['jar'], $amina['csrf']);
        sec_status($post, [403], 'merchant admin POST access');
    });

    sec_run('Platform admin can access admin API', function () use ($admin): void {
        $response = sec_http('GET', '/api/admin.php', null, $admin['jar']);
        sec_status($response, [200], 'platform admin access');
        sec_expect(!empty($response['json']['ok']), 'Admin API did not return ok=true.');
    });

    sec_run('Cross-tenant invoice isolation', function () use ($pdo, $amina, $sokoni): void {
        $own = $pdo->query('SELECT id FROM invoices WHERE tenant_id = 1 ORDER BY id LIMIT 1')->fetchColumn();
        $cross = $pdo->query('SELECT id FROM invoices WHERE tenant_id = 2 ORDER BY id LIMIT 1')->fetchColumn();
        sec_expect($own !== false && $cross !== false, 'Demo invoices for tenant isolation are missing.');
        $ownResponse = sec_http('GET', '/api/invoice-doc.php?invoice=' . (int) $own, null, $amina['jar']);
        sec_status($ownResponse, [200], 'own invoice');
        $crossResponse = sec_http('GET', '/api/invoice-doc.php?invoice=' . (int) $cross, null, $amina['jar']);
        sec_status($crossResponse, [403, 404], 'cross-tenant invoice');
        $crossOwn = sec_http('GET', '/api/invoice-doc.php?invoice=' . (int) $cross, null, $sokoni['jar']);
        sec_status($crossOwn, [200], 'Sokoni own invoice');
    });

    sec_run('Tenant order list does not leak another workspace', function () use ($pdo, $amina): void {
        $crossTracking = $pdo->query('SELECT tracking FROM parcels WHERE tenant_id = 2 LIMIT 1')->fetchColumn();
        sec_expect($crossTracking !== false, 'No tenant-2 parcel fixture found.');
        $response = sec_http('GET', '/api/orders.php', null, $amina['jar']);
        sec_status($response, [200], 'orders list');
        sec_expect(!str_contains($response['body'], (string) $crossTracking), 'Amina order list leaked Sokoni tracking ' . $crossTracking . '.');
    });

    sec_run('Workspace orders exclude unassigned parcels', function () use ($pdo, $amina): void {
        $orphan = $pdo->query('SELECT tracking FROM parcels WHERE tenant_id IS NULL ORDER BY registered_at DESC LIMIT 1')->fetchColumn();
        sec_expect($orphan !== false, 'No unassigned parcel fixture found.');
        $response = sec_http('GET', '/api/orders.php?q=' . rawurlencode((string) $orphan), null, $amina['jar']);
        sec_status($response, [200], 'workspace orders search');
        sec_expect(count($response['json']['rows'] ?? []) === 0, 'Workspace orders leaked unassigned tracking ' . $orphan . '.');
        sec_expect((int) ($response['json']['total'] ?? -1) === 0, 'Unassigned tracking search returned a workspace order.');
    });

    sec_run('Platform admin Orders requires a workspace', function () use ($admin): void {
        $response = sec_http('GET', '/api/orders.php', null, $admin['jar']);
        sec_status($response, [200], 'platform admin orders');
        sec_expect(!empty($response['json']['workspace_required']), 'Platform admin orders response did not mark workspace_required.');
        sec_expect((int) ($response['json']['total'] ?? -1) === 0, 'Platform admin orders returned workspace rows without a workspace.');
    });

    sec_run('Newsletter subscribe requires CSRF and explicit consent', function () use ($guest): void {
        $email = 'security-newsletter-' . bin2hex(random_bytes(4)) . '@example.test';
        $payload = ['action' => 'subscribe', 'channel' => 'email', 'email' => $email, 'consent' => 0];
        $without = sec_http('POST', '/api/newsletter.php', $payload, $guest['jar']);
        sec_status($without, [403], 'newsletter POST without CSRF');
        $with = sec_http('POST', '/api/newsletter.php', $payload, $guest['jar'], $guest['csrf']);
        sec_status($with, [422], 'newsletter POST without consent');
    });

    sec_run('Newsletter subscriber export is admin-only', function () use ($amina): void {
        $response = sec_http('GET', '/api/admin-newsletter-export.php', null, $amina['jar']);
        sec_status($response, [403], 'newsletter export as merchant');
    });

    sec_run('Marketing campaigns are admin-only', function () use ($amina): void {
        $response = sec_http('GET', '/api/admin-campaigns.php', null, $amina['jar']);
        sec_status($response, [403], 'marketing campaigns as merchant');
    });

    sec_run('Admin workspace logo updates are platform-admin only', function () use ($amina): void {
        $response = sec_http('GET', '/api/admin-tenant-logo.php', null, $amina['jar']);
        sec_status($response, [403], 'workspace logo update as merchant');
    });

    sec_run('CSRF is required on workspace mutations', function () use ($amina): void {
        $without = sec_http('POST', '/api/settings.php', ['action' => '__csrf_probe__'], $amina['jar']);
        sec_status($without, [403], 'settings POST without CSRF');
        $invalid = sec_http('POST', '/api/settings.php', ['action' => '__csrf_probe__'], $amina['jar'], 'invalid-token');
        sec_status($invalid, [403], 'settings POST with invalid CSRF');
        $valid = sec_http('POST', '/api/settings.php', ['action' => '__csrf_probe__'], $amina['jar'], $amina['csrf']);
        sec_status($valid, [400], 'settings POST with valid CSRF');
        sec_expect(($valid['json']['error']['code'] ?? '') === 'unknown_action', 'Valid CSRF did not reach the action dispatcher.');
    });

    sec_run('CSRF is required on admin mutations', function () use ($admin): void {
        $without = sec_http('POST', '/api/admin.php', ['action' => '__csrf_probe__'], $admin['jar']);
        sec_status($without, [403], 'admin POST without CSRF');
    });

    sec_run('Login rate limit returns 429', function () use ($config, $guest): void {
        $identifier = 'security-probe-' . bin2hex(random_bytes(5)) . '@example.test';
        $max = max(1, (int) ($config['security']['login_max_attempts'] ?? 5));
        $last = null;
        for ($i = 0; $i <= $max; $i++) {
            $last = sec_http('POST', '/api/auth.php', ['action' => 'login', 'identifier' => $identifier, 'password' => 'wrong-password'], $guest['jar'], $guest['csrf']);
        }
        sec_expect($last !== null && $last['status'] === 429, 'Expected HTTP 429 after ' . ($max + 1) . ' login attempts; got ' . ($last['status'] ?? 'none') . '.');
        sec_cleanup_rate_limit($identifier);
    });

    sec_run('Tracking data is redacted publicly', function () use ($pdo, $guest): void {
        $tracking = $pdo->query('SELECT tracking FROM parcels WHERE receiver_phone IS NOT NULL AND receiver_phone <> "" ORDER BY registered_at DESC LIMIT 1')->fetchColumn();
        sec_expect($tracking !== false, 'No parcel fixture found for tracking redaction test.');
        $response = sec_http('GET', '/api/track.php?q=' . rawurlencode((string) $tracking), null, $guest['jar']);
        sec_status($response, [200], 'public tracking');
        $record = $response['json']['record'] ?? null;
        sec_expect(is_array($record), 'Tracking response missing record.');
        sec_expect(($response['json']['found'] ?? false) === true, 'Tracking fixture was not found.');
        foreach (['sender_phone', 'receiver_phone'] as $phoneField) {
            $value = $record[$phoneField] ?? null;
            sec_expect($value === null || str_contains((string) $value, '*'), 'Public tracking did not mask ' . $phoneField . '.');
        }
        foreach (['collect_code', 'description', 'value', 'payment_ref'] as $privateField) {
            sec_expect(!array_key_exists($privateField, $record), 'Public tracking exposed ' . $privateField . '.');
        }
    });

    sec_run('Attachment scanner blocks malware markers and active content', function () use ($ROOT, &$tempFiles): void {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mzigo-security-' . bin2hex(random_bytes(5));
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) { throw new RuntimeException('Could not create scanner temp dir.'); }
        $files = [
            'eicar.txt' => 'Harmless security test marker: eicar',
            'clean.txt' => 'This is a harmless security test document.',
            'script.php' => '<?php echo "test";',
            'active.pdf' => "%PDF-1.4\n1 0 obj\n/JavaScript 2 0 R\nendobj\n",
        ];
        foreach ($files as $name => $content) {
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            file_put_contents($path, $content);
            $tempFiles[] = $path;
        }
        $eicar = malware_scan_file($dir . DIRECTORY_SEPARATOR . 'eicar.txt', 'text/plain', 'eicar.txt');
        sec_expect($eicar['clean'] === false && $eicar['result'] === 'blocked', 'EICAR was not blocked.');
        $clean = malware_scan_file($dir . DIRECTORY_SEPARATOR . 'clean.txt', 'text/plain', 'clean.txt');
        sec_expect($clean['clean'] === true, 'Benign text file was incorrectly blocked.');
        $script = malware_scan_file($dir . DIRECTORY_SEPARATOR . 'script.php', 'text/plain', 'script.php');
        sec_expect($script['clean'] === false, 'Executable filename was not blocked.');
        $pdf = malware_scan_file($dir . DIRECTORY_SEPARATOR . 'active.pdf', 'application/pdf', 'active.pdf');
        sec_expect($pdf['clean'] === false && $pdf['result'] === 'suspicious', 'PDF with JavaScript was not flagged suspicious.');
        foreach ($tempFiles as $file) { @unlink($file); }
        @rmdir($dir);
    });

    sec_run('Webhook SSRF guard rejects private destinations in production', function (): void {
        sec_expect(webhook_url_is_safe('http://127.0.0.1/hook', true) === false, 'Loopback URL passed production SSRF guard.');
        sec_expect(webhook_url_is_safe('http://localhost/hook', true) === false, 'localhost URL passed production SSRF guard.');
        sec_expect(webhook_url_is_safe('http://169.254.169.254/latest/meta-data', true) === false, 'Link-local metadata URL passed production SSRF guard.');
        sec_expect(webhook_url_is_safe('http://10.0.0.5/hook', true) === false, 'Private IP URL passed production SSRF guard.');
        sec_expect(webhook_url_is_safe('file:///etc/passwd', true) === false, 'Non-HTTP URL passed production SSRF guard.');
        sec_expect(webhook_url_is_safe('https://1.1.1.1/hook', true) === true, 'Public IP URL was rejected by production SSRF guard.');
        sec_expect(webhook_url_is_safe('http://127.0.0.1/hook', false) === true, 'Local test URL was rejected in local mode.');
    });

    sec_run('Email event webhook rejects unsigned payloads', function () use ($guest): void {
        $response = sec_http('POST', '/api/email-events.php', ['event' => 'bounce', 'email' => 'security-probe@example.test'], $guest['jar']);
        sec_status($response, [403, 503], 'unsigned email event');
    });

    sec_run('Unauthenticated claim upload is rejected', function () use ($guest): void {
        $response = sec_http('POST', '/api/claim-upload.php', [], $guest['jar']);
        sec_status($response, [401, 403, 405, 422], 'unauthenticated claim upload');
    });

    echo "\n";
} catch (Throwable $e) {
    $failed++;
    $failures[] = 'Suite setup: ' . $e->getMessage();
    echo "[FAIL] Suite setup — " . $e->getMessage() . "\n";
} finally {
    foreach (['amina@example.com', 'sokoni@example.com', 'admin@mzigo.online'] as $testAccount) {
        try { sec_cleanup_rate_limit($testAccount); } catch (Throwable $e) { /* best effort */ }
    }
    try {
        $pdo->exec("DELETE FROM sessions WHERE user_agent = 'Mzigo-Security-Suite/1.0'");
        $pdo->exec("DELETE FROM audit_log WHERE meta LIKE '%security-probe-%'");
    } catch (Throwable $e) { /* best effort */ }
    foreach ($cookieJars as $jar) { @unlink($jar); }
    foreach ($tempFiles as $file) { @unlink($file); }
}

echo "Result: {$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    echo "Failures:\n";
    foreach ($failures as $failure) { echo " - {$failure}\n"; }
    exit(1);
}
exit(0);
