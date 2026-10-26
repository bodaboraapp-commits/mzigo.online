<?php
/**
 * Mzigo.online — merchant orders API.
 *
 * GET /api/orders.php?tab=parcels|errands&q=&status=&from=&to=&page=&per_page=&format=json|csv
 * Tenant-scoped (owner/admin/staff) parcels/errand list with filters, paging and
 * CSV export for the business side.
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_webhooks.php';
require __DIR__ . '/_notifications.php';

$user = require_user();
$tenant = current_tenant();
if (!$tenant) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        fail_error('no_tenant', 'This account has no business workspace yet.', 403);
    }
    json_out([
        'ok' => true,
        'source' => 'mysql',
        'workspace_required' => true,
        'tab' => (($_GET['tab'] ?? 'parcels') === 'errands') ? 'errands' : 'parcels',
        'page' => 1, 'per_page' => 10, 'pages' => 1, 'total' => 0,
        'counts' => [], 'filters' => [], 'rows' => [],
    ]);
}
$tenantId = (int) $tenant['id'];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post();
    verify_csrf();
    if (!in_array($tenant['role'], ['owner', 'admin'], true)) {
        fail_error('forbidden', 'Only workspace owners and admins can change orders.', 403);
    }
    $data = body_json();
    $action = (string) ($data['action'] ?? '');
    $tracking = (string) ($data['tracking'] ?? '');
    $stmt = db()->prepare('SELECT * FROM parcels WHERE tenant_id = ? AND tracking = ? LIMIT 1');
    $stmt->execute([$tenantId, $tracking]);
    $parcel = $stmt->fetch();
    if (!$parcel) {
        fail_error('not_found', 'Parcel not found in this workspace.', 404);
    }
    $cancelable = ['CREATED', 'AT_ORIGIN_POINT'];
    $returnable = ['READY_FOR_COLLECTION', 'ARRIVED_DEST_POINT'];
    if ($action === 'cancel_parcel' && in_array($parcel['status'], $cancelable, true)) {
        db()->prepare('UPDATE parcels SET status = "CANCELLED" WHERE id = ?')->execute([$parcel['id']]);
        db()->prepare('INSERT INTO scans (subject_type, subject_id, status, label, location, note, scanned_at)
            VALUES ("parcel", ?, "CANCELLED", "Parcel cancelled", "Mzigo.online", ?, NOW())')
            ->execute([$parcel['id'], 'Cancelled by workspace; paid fees are refundable via support.']);
        audit('parcel.cancelled', 'parcel', (string) $parcel['id'], ['tracking' => $tracking], $tenantId);
        dispatch_webhooks($tenantId, 'parcel.cancelled', ['tracking' => $tracking]);
        json_out(['ok' => true, 'source' => 'mysql', 'status' => 'CANCELLED', 'message' => 'Parcel cancelled.']);
    }
    if ($action === 'request_return' && in_array($parcel['status'], $returnable, true)) {
        db()->prepare('UPDATE parcels SET status = "RETURN_IN_TRANSIT" WHERE id = ?')->execute([$parcel['id']]);
        db()->prepare('INSERT INTO scans (subject_type, subject_id, status, label, location, note, scanned_at)
            VALUES ("parcel", ?, "RETURN_IN_TRANSIT", "Return requested", "Mzigo.online", ?, NOW())')
            ->execute([$parcel['id'], 'Return requested by workspace; the parcel will be sent back to the origin office.']);
        audit('parcel.return_requested', 'parcel', (string) $parcel['id'], ['tracking' => $tracking], $tenantId);
        dispatch_webhooks($tenantId, 'parcel.returned', ['tracking' => $tracking]);
        json_out(['ok' => true, 'source' => 'mysql', 'status' => 'RETURN_IN_TRANSIT', 'message' => 'Return requested.']);
    }
    if ($action === 'advance_parcel') {
        $transitions = [
            'CREATED' => ['AT_ORIGIN_POINT', 'Dropped off at origin', 'Parcel dropped off at the origin office.'],
            'AT_ORIGIN_POINT' => ['HANDED_TO_TRANSPORTER', 'Handed to transporter', 'Parcel handed to the transporter.'],
            'HANDED_TO_TRANSPORTER' => ['IN_TRANSIT', 'In transit', 'Parcel is in transit to its destination.'],
            'IN_TRANSIT' => ['ARRIVED_DEST_POINT', 'Arrived at destination', 'Parcel arrived at the destination office.'],
            'ARRIVED_DEST_POINT' => ['READY_FOR_COLLECTION', 'Ready for collection', 'Parcel is ready for collection at the destination office.'],
        ];
        if (isset($transitions[$parcel['status']])) {
            [$nextStatus, $label, $note] = $transitions[$parcel['status']];
            db()->prepare('UPDATE parcels SET status = ? WHERE id = ?')->execute([$nextStatus, $parcel['id']]);
            db()->prepare('INSERT INTO scans (subject_type, subject_id, status, label, location, note, scanned_at)
                VALUES ("parcel", ?, ?, ?, "Mzigo.online", ?, NOW())')
                ->execute([$parcel['id'], $nextStatus, $label, $note]);
            audit('parcel.status_advanced', 'parcel', (string) $parcel['id'], ['from' => $parcel['status'], 'to' => $nextStatus], $tenantId);
            $template = $nextStatus === 'READY_FOR_COLLECTION' ? 'parcel_ready' : 'parcel_status';
            notify_customer_status($parcel, $template, $label, $note);
            notify_track_subscribers($parcel, $label, $note);
            dispatch_webhooks($tenantId, 'parcel.status_updated', ['tracking' => $tracking, 'status' => $nextStatus, 'label' => $label]);
            json_out(['ok' => true, 'source' => 'mysql', 'status' => $nextStatus, 'message' => 'Parcel advanced to ' . $label . '.']);
        }
    }

    fail_error('invalid_status_change', 'That action is not available for this parcel status.', 422);
}

$tab = (($_GET['tab'] ?? 'parcels') === 'errands') ? 'errands' : 'parcels';
$format = (($_GET['format'] ?? 'json') === 'csv') ? 'csv' : 'json';

$q = trim((string) ($_GET['q'] ?? ''));
$status = strtoupper(trim((string) ($_GET['status'] ?? '')));
$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(50, max(5, (int) ($_GET['per_page'] ?? 10)));

$params = [':tenant' => $tenantId];
$where = ['tenant_id = :tenant'];

if ($q !== '') {
    $like = '%' . $q . '%';
    $searchCols = $tab === 'parcels'
        ? ['tracking', 'locker', 'receiver_name', 'receiver_phone', 'carrier', 'to_city', 'from_city']
        : ['ref', 'recipient_name', 'recipient_phone', 'pickup', 'dropoff', 'carrier'];
    $parts = [];
    foreach ($searchCols as $i => $col) {
        $parts[] = $col . ' LIKE :q' . $i;
        $params[':q' . $i] = $like;
    }
    $where[] = '(' . implode(' OR ', $parts) . ')';
}
if ($status !== '') {
    $where[] = 'status = :status';
    $params[':status'] = $status;
}
$dateCol = $tab === 'parcels' ? 'registered_at' : 'created_at';
if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $where[] = $dateCol . ' >= :from';
    $params[':from'] = $from . ' 00:00:00';
}
if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $where[] = $dateCol . ' <= :to';
    $params[':to'] = $to . ' 23:59:59';
}
$whereSql = implode(' AND ', $where);
$table = $tab === 'parcels' ? 'parcels' : 'errands';

if ($format === 'csv') {
    $stmt = db()->prepare("SELECT * FROM $table WHERE $whereSql ORDER BY $dateCol DESC");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="mzigo-' . $tab . '-' . date('Ymd-Hi') . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    if ($tab === 'parcels') {
        fputcsv($out, ['Tracking', 'Locker', 'Carrier', 'From', 'To', 'Receiver', 'Receiver phone', 'Status', 'Payment', 'Payment status', 'Fee KES', 'Registered']);
        foreach ($rows as $r) {
            fputcsv($out, [$r['tracking'], $r['locker'], $r['carrier'], $r['from_city'], $r['to_city'],
                $r['receiver_name'], $r['receiver_phone'], $r['status'], $r['payment_method'], $r['payment_status'],
                (int) $r['fee_total'], $r['registered_at']]);
        }
    } else {
        fputcsv($out, ['Ref', 'Carrier', 'Pickup', 'Drop-off', 'Recipient', 'Recipient phone', 'Status', 'Fee KES', 'Created']);
        foreach ($rows as $r) {
            fputcsv($out, [$r['ref'], $r['carrier'], $r['pickup'], $r['dropoff'], $r['recipient_name'],
                $r['recipient_phone'], $r['status'], (int) $r['fee_total'], $r['created_at']]);
        }
    }
    fclose($out);
    exit;
}

$countStmt = db()->prepare("SELECT COUNT(*) FROM $table WHERE $whereSql");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;

$stmt = db()->prepare("SELECT * FROM $table WHERE $whereSql ORDER BY $dateCol DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$raw = $stmt->fetchAll();

$rows = [];
foreach ($raw as $r) {
    if ($tab === 'parcels') {
        $rows[] = [
            'id' => $r['id'], 'tracking' => $r['tracking'], 'locker' => $r['locker'],
            'carrier' => $r['carrier'], 'from' => $r['from_city'], 'to' => $r['to_city'],
            'receiver' => $r['receiver_name'], 'receiver_phone' => $r['receiver_phone'],
            'sender' => $r['sender_name'], 'status' => $r['status'],
            'fee_total' => (int) $r['fee_total'], 'fee' => [
                'base' => (int) $r['fee_base'], 'holding' => (int) $r['fee_holding'],
                'insurance' => (int) $r['fee_insurance'], 'platform' => (int) $r['fee_platform'],
                'total' => (int) $r['fee_total'],
            ],
            'payment' => $r['payment_method'], 'payment_status' => $r['payment_status'],
            'payment_ref' => $r['payment_ref'], 'paid_at' => $r['paid_at'],
            'tier' => $r['tier'], 'value' => (int) $r['value_kes'],
            'size' => $r['size'], 'collect_code' => $r['payment_status'] === 'paid' ? $r['collect_code'] : null,
            'registered_at' => $r['registered_at'],
        ];
    } else {
        $rows[] = [
            'id' => $r['id'], 'ref' => $r['ref'], 'carrier' => $r['carrier'],
            'pickup' => $r['pickup'], 'dropoff' => $r['dropoff'],
            'recipient' => $r['recipient_name'], 'recipient_phone' => $r['recipient_phone'],
            'sender' => $r['sender_name'], 'status' => $r['status'],
            'fee_total' => (int) $r['fee_total'],
            'payment' => $r['payment_method'], 'created_at' => $r['created_at'],
        ];
    }
}

$countsStmt = db()->prepare("SELECT status, COUNT(*) AS n FROM $table WHERE tenant_id = :tenant GROUP BY status ORDER BY n DESC");
$countsStmt->execute([':tenant' => $tenantId]);
$counts = [];
foreach ($countsStmt->fetchAll() as $c) {
    $counts[$c['status']] = (int) $c['n'];
}

json_out([
    'ok'       => true,
    'source'   => 'mysql',
    'tab'      => $tab,
    'page'     => $page,
    'per_page' => $perPage,
    'pages'    => $pages,
    'total'    => $total,
    'counts'   => $counts,
    'filters'  => ['q' => $q, 'status' => $status, 'from' => $from, 'to' => $to],
    'rows'     => $rows,
]);
