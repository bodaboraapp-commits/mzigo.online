<?php
/**
 * Mzigo.online — merchant settlement statements (monthly fee & collection summary).
 * GET /api/settlements.php[?format=csv]
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$user = require_user();
$tenant = current_tenant();
if (!$tenant) {
    fail_error('no_tenant', 'This account has no business workspace yet.', 403);
}
$tenantId = (int) $tenant['id'];

$rows = [];
$stmt = db()->prepare(
    "SELECT DATE_FORMAT(registered_at, '%Y-%m') AS month,
            COUNT(*) AS parcels,
            COALESCE(SUM(fee_total),0) AS delivery_fees,
            COALESCE(SUM(fee_platform),0) AS platform_fees,
            COALESCE(SUM(fee_insurance),0) AS insurance_fees,
            COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN fee_total ELSE 0 END),0) AS collected,
            COALESCE(SUM(CASE WHEN payment_status <> 'paid' THEN fee_total ELSE 0 END),0) AS pending
     FROM parcels WHERE tenant_id = :tenant
     GROUP BY DATE_FORMAT(registered_at, '%Y-%m') ORDER BY month DESC LIMIT 12"
);
$stmt->execute([':tenant' => $tenantId]);
foreach ($stmt->fetchAll() as $row) {
    $row['parcels'] = (int) $row['parcels'];
    foreach (['delivery_fees', 'platform_fees', 'insurance_fees', 'collected', 'pending'] as $k) {
        $row[$k] = (int) $row[$k];
    }
    $rows[] = $row;
}

$invStmt = db()->prepare(
    "SELECT DATE_FORMAT(COALESCE(paid_at, issued_at), '%Y-%m') AS month,
            COALESCE(SUM(CASE WHEN status IN ('paid','refunded') THEN amount_kes ELSE 0 END),0) AS subscription_paid,
            COALESCE(SUM(CASE WHEN status = 'refunded' THEN amount_kes ELSE 0 END),0) AS refunded
     FROM invoices WHERE tenant_id = :tenant
     GROUP BY DATE_FORMAT(COALESCE(paid_at, issued_at), '%Y-%m')"
);
$invStmt->execute([':tenant' => $tenantId]);
$invoices = [];
foreach ($invStmt->fetchAll() as $row) {
    $invoices[$row['month']] = ['subscription_paid' => (int) $row['subscription_paid'], 'refunded' => (int) $row['refunded']];
}
foreach ($rows as &$row) {
    $row['subscription_paid'] = $invoices[$row['month']]['subscription_paid'] ?? 0;
    $row['refunded'] = $invoices[$row['month']]['refunded'] ?? 0;
}
unset($row);

$totals = ['parcels' => 0, 'delivery_fees' => 0, 'platform_fees' => 0, 'insurance_fees' => 0, 'collected' => 0, 'pending' => 0, 'subscription_paid' => 0, 'refunded' => 0];
foreach ($rows as $row) {
    foreach ($totals as $k => $v) {
        $totals[$k] += (int) $row[$k];
    }
}

if (($_GET['format'] ?? 'json') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="mzigo-statements-' . date('Ymd-Hi') . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Month', 'Parcels', 'Delivery fees KES', 'Platform fees KES', 'Insurance fees KES', 'Collected from customers KES', 'Pending KES', 'Subscription paid KES', 'Refunded KES']);
    foreach ($rows as $row) {
        fputcsv($out, [$row['month'], $row['parcels'], $row['delivery_fees'], $row['platform_fees'], $row['insurance_fees'], $row['collected'], $row['pending'], $row['subscription_paid'], $row['refunded']]);
    }
    fputcsv($out, ['TOTAL', $totals['parcels'], $totals['delivery_fees'], $totals['platform_fees'], $totals['insurance_fees'], $totals['collected'], $totals['pending'], $totals['subscription_paid'], $totals['refunded']]);
    fclose($out);
    exit;
}

json_out(['ok' => true, 'source' => 'mysql', 'tenant' => $tenant['name'], 'rows' => $rows, 'totals' => $totals]);
