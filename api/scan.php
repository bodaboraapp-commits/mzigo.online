<?php
/**
 * POST /api/scan.php — idempotent partner-point scan ingestion.
 * GET  /api/scan.php — authenticate a point token and return point/action info.
 *
 * Authentication: X-Point-Token: mzp_<random> generated in Admin → Scan points.
 * Nothing here uses a logged-in session or CSRF token.
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_notifications.php';
require __DIR__ . '/_webhooks.php';
require __DIR__ . '/_scan_actions.php';

const SCAN_POINT_ACTIONS = ['dropoff', 'handover', 'arrival', 'collection'];

function scan_point_context(): array
{
    $raw = trim((string) ($_SERVER['HTTP_X_POINT_TOKEN'] ?? ''));
    if ($raw === '' || strlen($raw) < 24) {
        fail_error('point_token_required', 'Enter the point access token.', 401);
    }
    $prefix = substr($raw, 0, 12);
    $stmt = db()->prepare('SELECT * FROM partner_points WHERE access_token_prefix = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$prefix]);
    $point = $stmt->fetch();
    if (!$point || !hash_equals((string) $point['access_token_hash'], hash('sha256', $raw))) {
        rate_limit('scan_auth_fail:' . client_ip(), 30, 300);
        fail_error('point_token_invalid', 'That point access token is not valid.', 401);
    }
    return $point;
}

function scan_point_actions(array $point): array
{
    return $point['point_type'] === 'multi' ? SCAN_POINT_ACTIONS : [(string) $point['point_type']];
}

function scan_point_short(array $point): array
{
    $allowed = scan_point_actions($point);
    $actions = [];
    foreach (scan_action_config() as $action) {
        if (in_array($action['key'], $allowed, true)) {
            $actions[] = [
                'key' => $action['key'],
                'label' => $action['label'],
                'description' => $action['description'],
            ];
        }
    }
    return [
        'id' => (int) $point['id'], 'name' => $point['name'], 'code' => $point['code'],
        'partner_slug' => $point['partner_slug'], 'point_type' => $point['point_type'],
        'location' => $point['location'], 'last_scan_at' => $point['last_scan_at'],
        'manual_entry_enabled' => isset($point['manual_entry_enabled']) ? (int) $point['manual_entry_enabled'] : 1,
        'actions' => $actions,
        'allowed_actions' => $allowed,
    ];
}

function scan_point_respond(array $response, bool $idempotent = false): never
{
    $response['idempotent'] = $idempotent;
    json_out($response);
}

$point = scan_point_context();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET') {
    $stmt = db()->prepare('SELECT s.status, s.label, s.location, s.note, s.scanned_at, p.tracking
        FROM scans s LEFT JOIN parcels p ON p.id = s.subject_id
        WHERE s.point_id = ? ORDER BY s.id DESC LIMIT 10');
    $stmt->execute([(int) $point['id']]);
    $statsStmt = db()->prepare('SELECT COUNT(*) AS total, COALESCE(SUM(DATE(scanned_at) = CURDATE()), 0) AS today
        FROM scans WHERE point_id = ?');
    $statsStmt->execute([(int) $point['id']]);
    $pointStats = $statsStmt->fetch() ?: ['total' => 0, 'today' => 0];
    json_out(['ok' => true, 'source' => 'mysql', 'point' => scan_point_short($point),
        'point_stats' => ['today' => (int) $pointStats['today'], 'total' => (int) $pointStats['total'],
            'last_scan_at' => $point['last_scan_at']],
        'recent_scans' => $stmt->fetchAll()]);
}

require_post();
$data = body_json();
rate_limit('scan_point:' . $point['id'] . ':' . client_ip(), 90, 60);

$tracking = strtoupper(trim((string) ($data['tracking'] ?? '')));
if ($tracking === '' || mb_strlen($tracking) > 20) {
    fail_error('invalid_tracking', 'Enter a valid tracking number.', 422);
}
$action = strtolower(trim((string) ($data['action'] ?? '')));
if (!in_array($action, SCAN_POINT_ACTIONS, true)) {
    fail_error('invalid_action', 'Choose drop-off, handover, arrival or collection.', 422);
}
if (!in_array($action, scan_point_actions($point), true)) {
    fail_error('action_not_allowed', 'This point is not enabled for that scan action.', 403);
}
$idempotencyKey = trim((string) ($data['idempotency_key'] ?? ''));
if (!preg_match('/^[A-Za-z0-9._:-]{8,80}$/', $idempotencyKey)) {
    fail_error('idempotency_required', 'A valid idempotency key is required for scans.', 422);
}
$note = mb_substr(trim((string) ($data['note'] ?? '')), 0, 255);
$location = mb_substr(trim((string) ($data['location'] ?? '')), 0, 160);
if ($location === '') { $location = (string) ($point['location'] ?? $point['name']); }
$collectCode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($data['collect_code'] ?? '')) ?? '');

$existing = db()->prepare('SELECT tracking, action, response_json FROM scan_ingestions WHERE point_id = ? AND idempotency_key = ? LIMIT 1');
$existing->execute([(int) $point['id'], $idempotencyKey]);
$previous = $existing->fetch();
if ($previous) {
    if ($previous['tracking'] !== $tracking || $previous['action'] !== $action) {
        fail_error('idempotency_conflict', 'That idempotency key was already used for a different scan.', 409);
    }
    $response = json_decode((string) $previous['response_json'], true);
    scan_point_respond(is_array($response) ? $response : ['ok' => true, 'source' => 'mysql'], true);
}

$pdo = db();
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('SELECT * FROM parcels WHERE tracking = ? LIMIT 1 FOR UPDATE');
    $stmt->execute([$tracking]);
    $parcel = $stmt->fetch();
    if (!$parcel) {
        $pdo->rollBack();
        fail_error('parcel_not_found', 'No parcel found for that tracking number.', 404);
    }
    if (strcasecmp((string) $parcel['carrier_slug'], (string) $point['partner_slug']) !== 0) {
        $pdo->rollBack();
        fail_error('wrong_carrier', 'This parcel does not belong to your carrier.', 403);
    }
    // Drop-off is open to the receiving point; handover and all downstream
    // network scans must belong to the point's workspace.
    $restrictWorkspace = in_array($action, ['handover', 'arrival', 'collection'], true);
    if ($restrictWorkspace && $point['tenant_id'] !== null
        && (int) $point['tenant_id'] !== (int) ($parcel['tenant_id'] ?? 0)) {
        $pdo->rollBack();
        fail_error('wrong_workspace', 'This parcel does not belong to this point workspace.', 403);
    }

    $from = (string) $parcel['status'];
    $updates = [];
    if ($action === 'dropoff') {
        if ($from !== 'CREATED') { $pdo->rollBack(); fail_error('invalid_status_change', 'This parcel has already been dropped off.', 422); }
        $updates[] = ['AT_ORIGIN_POINT', 'Dropped off at origin', $note !== '' ? $note : 'Scanned in at the origin point.'];
        $newStatus = 'AT_ORIGIN_POINT';
        $template = 'parcel_status';
        $notifyLabel = 'Dropped off at origin';
    } elseif ($action === 'handover') {
        if ($from !== 'AT_ORIGIN_POINT') { $pdo->rollBack(); fail_error('invalid_status_change', 'The parcel must be dropped off before handover.', 422); }
        $updates[] = ['HANDED_TO_TRANSPORTER', 'Handed to transporter', $note !== '' ? $note : 'Parcel handed to the transporter.'];
        $newStatus = 'HANDED_TO_TRANSPORTER';
        $template = 'parcel_status';
        $notifyLabel = 'Handed to transporter';
    } elseif ($action === 'arrival') {
        if (!in_array($from, ['HANDED_TO_TRANSPORTER', 'IN_TRANSIT', 'ARRIVED_DEST_POINT'], true)) {
            $pdo->rollBack();
            fail_error('invalid_status_change', 'The parcel has not been handed to a transporter yet.', 422);
        }
        if ($from !== 'ARRIVED_DEST_POINT') {
            $updates[] = ['ARRIVED_DEST_POINT', 'Arrived at destination', $note !== '' ? $note : 'Parcel arrived at the destination point.'];
        }
        $updates[] = ['READY_FOR_COLLECTION', 'Ready for collection', 'Parcel is ready for collection at the destination point.'];
        $newStatus = 'READY_FOR_COLLECTION';
        $template = 'parcel_ready';
        $notifyLabel = 'Ready for collection';
    } else { // collection
        if ($from !== 'READY_FOR_COLLECTION') { $pdo->rollBack(); fail_error('invalid_status_change', 'The parcel is not ready for collection.', 422); }
        if (($parcel['payment_status'] ?? '') !== 'paid') { $pdo->rollBack(); fail_error('payment_required', 'The delivery fee has not been paid yet.', 422); }
        if ($collectCode === '' || !hash_equals(strtoupper((string) $parcel['collect_code']), $collectCode)) {
            $pdo->rollBack();
            fail_error('invalid_collection_code', 'The collection code does not match this parcel.', 422);
        }
        $updates[] = ['COLLECTED_PAID', 'Collected', $note !== '' ? $note : 'Parcel collected by the customer at the destination point.'];
        $newStatus = 'COLLECTED_PAID';
        $template = 'parcel_collected';
        $notifyLabel = 'Collected';
    }

    $insertScan = $pdo->prepare('INSERT INTO scans (subject_type, subject_id, status, label, location, note, scanned_at, source, point_id, actor_user_id, idempotency_key)
        VALUES ("parcel", ?, ?, ?, ?, ?, NOW(), "partner_point", ?, NULL, ?)');
    $scans = [];
    foreach ($updates as $index => $scan) {
        $insertScan->execute([$parcel['id'], $scan[0], $scan[1], $location, $scan[2], (int) $point['id'],
            $index === 0 ? $idempotencyKey : null]);
        $scans[] = ['status' => $scan[0], 'label' => $scan[1], 'note' => $scan[2]];
    }
    $pdo->prepare('UPDATE parcels SET status = ? WHERE id = ?')->execute([$newStatus, $parcel['id']]);
    $pdo->prepare('UPDATE partner_points SET last_scan_at = NOW() WHERE id = ?')->execute([(int) $point['id']]);
    $pdo->prepare('INSERT INTO scan_ingestions (point_id, idempotency_key, tracking, action, parcel_id, response_json)
        VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([(int) $point['id'], $idempotencyKey, $tracking, $action, $parcel['id'], '{}']);
    $deliveryId = (int) $pdo->lastInsertId();
    $response = [
        'ok' => true, 'source' => 'mysql', 'tracking' => $tracking, 'status' => $newStatus,
        'label' => $notifyLabel, 'scans' => $scans, 'point' => scan_point_short($point), 'parcel_status' => $newStatus,
    ];
    $pdo->prepare('UPDATE scan_ingestions SET response_json = ? WHERE id = ?')->execute([json_encode($response, JSON_UNESCAPED_SLASHES), $deliveryId]);
    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    if ((string) $e->getCode() === '23000') {
        $existing->execute([(int) $point['id'], $idempotencyKey]);
        $previous = $existing->fetch();
        if ($previous) {
            $response = json_decode((string) $previous['response_json'], true);
            scan_point_respond(is_array($response) ? $response : ['ok' => true, 'source' => 'mysql'], true);
        }
    }
    error_log('scan ingestion failed: ' . $e->getMessage());
    fail_error('scan_failed', 'The scan could not be recorded. Try again.', 500);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('scan ingestion failed: ' . $e->getMessage());
    fail_error('scan_failed', 'The scan could not be recorded. Try again.', 500);
}

audit('point.scan', 'parcel', (string) $parcel['id'], [
    'point_id' => (int) $point['id'], 'point_code' => $point['code'], 'action' => $action,
    'from' => $from, 'to' => $newStatus, 'idempotency_key' => $idempotencyKey,
], $parcel['tenant_id'] !== null ? (int) $parcel['tenant_id'] : null);

$parcel['status'] = $newStatus;
try {
    notify_customer_status($parcel, $template, $notifyLabel, $updates[count($updates) - 1][2]);
} catch (Throwable $e) {
    error_log('point scan customer notification failed: ' . $e->getMessage());
}
if ($parcel['tenant_id'] !== null) {
    try {
        dispatch_webhooks((int) $parcel['tenant_id'], 'parcel.status_updated', [
            'tracking' => $tracking, 'status' => $newStatus, 'label' => $notifyLabel,
            'point' => ['code' => $point['code'], 'name' => $point['name']], 'action' => $action,
        ]);
    } catch (Throwable $e) {
        error_log('point scan webhook dispatch failed: ' . $e->getMessage());
    }
}
scan_point_respond($response);
