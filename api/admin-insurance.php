<?php
/** Admin insurance provider configuration, test, retry and recent policies. */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_insurance.php';

$admin = require_platform_admin();
$pdo = db();

function insurance_admin_payload(): array
{
    $pdo = db();
    $stats = ['pending' => 0, 'issued' => 0, 'failed' => 0];
    foreach ($pdo->query('SELECT insurance_status, COUNT(*) AS n FROM parcels GROUP BY insurance_status')->fetchAll() as $row) {
        $stats[$row['insurance_status']] = (int) $row['n'];
    }
    $rows = $pdo->query("SELECT id, tracking, value_kes, insurance_policy_no, insurance_status, insurance_provider, insurance_issued_at
                           FROM parcels ORDER BY registered_at DESC LIMIT 30")->fetchAll();
    return [
        'ok' => true,
        'config' => insurance_public_config(),
        'demo' => insurance_config()['mode'] === 'demo',
        'stats' => $stats,
        'policies' => $rows,
    ];
}

function insurance_test_connection(array $config): array
{
    if ($config['mode'] === 'demo') return ['ok' => true, 'message' => 'Demo mode — no external insurer call is needed.'];
    $missing = insurance_validate_live_config($config);
    if ($missing !== null) return ['ok' => false, 'error' => $missing];
    $base = rtrim((string) $config['api_base_url'], '/');
    $ch = curl_init($base);
    $headers = ['Accept: application/json'];
    if ($config['auth_type'] === 'basic') {
        $headers[] = 'Authorization: Basic ' . base64_encode($config['api_key'] . ':' . $config['api_secret']);
    } elseif ($config['auth_type'] === 'api_key') {
        $headers[] = 'X-API-Key: ' . $config['api_key'];
    } else {
        $headers[] = 'Authorization: Bearer ' . $config['api_key'];
    }
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => (int) $config['timeout_seconds'], CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT => 5]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($code === 0) return ['ok' => false, 'error' => 'Could not reach the insurer API: ' . $error];
    if ($code >= 500) return ['ok' => false, 'error' => 'Insurer API returned HTTP ' . $code . '.'];
    return ['ok' => true, 'message' => 'Insurer endpoint reachable (HTTP ' . $code . ').'];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_out(insurance_admin_payload());
}

require_post();
verify_csrf();
$data = body_json();
$action = (string) ($data['action'] ?? '');

if ($action === 'save_config') {
    $saved = insurance_config_save($data['config'] ?? []);
    audit('admin.insurance_config_saved', 'platform', 'insurance_config', ['mode' => $saved['mode']]);
    json_out(insurance_admin_payload());
}

if ($action === 'test_connection') {
    json_out(insurance_test_connection(insurance_config()));
}

if ($action === 'retry_pending') {
    $results = insurance_retry_pending((int) ($data['limit'] ?? 20));
    $issued = count(array_filter($results, fn ($r) => !empty($r['ok'])));
    audit('admin.insurance_policies_retried', 'platform', 'insurance', ['attempted' => count($results), 'issued' => $issued]);
    json_out(['ok' => true, 'message' => $issued . ' of ' . count($results) . ' pending policy(ies) issued.', 'results' => $results] + insurance_admin_payload());
}

fail_error('unknown_action', 'Unknown insurance action.', 400);
