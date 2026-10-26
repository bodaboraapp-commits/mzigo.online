<?php
/** GET /api/claim-export.php — platform admin CSV export of claims. */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

require_platform_admin();
$rows = db()->query(
    'SELECT c.ref, c.claimant_role, c.type, c.status, c.risk_score, c.estimated_value_kes, c.payout_amount_kes,
            c.payout_ref, c.reversal_ref, c.recovery_status, c.recovery_ref, c.recovery_amount_kes,
            c.created_at, c.decided_at, t.name AS tenant_name, p.tracking
     FROM claims c LEFT JOIN tenants t ON t.id = c.tenant_id LEFT JOIN parcels p ON p.id = c.parcel_id
     ORDER BY c.id DESC'
)->fetchAll();
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="mzigo-claims-' . date('Ymd-Hi') . '.csv"');
header('Cache-Control: no-store');
$out = fopen('php://output', 'w');
fputcsv($out, ['Claim', 'Claimant', 'Type', 'Workspace', 'Parcel', 'Status', 'Risk', 'Estimated KES', 'Paid KES',
    'Payout ref', 'Reversal ref', 'Recovery status', 'Recovery ref', 'Recovered KES', 'Created', 'Decision']);
foreach ($rows as $r) {
    fputcsv($out, [$r['ref'], $r['claimant_role'], $r['type'], $r['tenant_name'] ?? '', $r['tracking'] ?? '',
        $r['status'], $r['risk_score'], $r['estimated_value_kes'], $r['payout_amount_kes'], $r['payout_ref'] ?? '',
        $r['reversal_ref'] ?? '', $r['recovery_status'], $r['recovery_ref'] ?? '', $r['recovery_amount_kes'],
        $r['created_at'], $r['decided_at']]);
}
fclose($out);
audit('admin.claims_exported', 'claim', null, ['rows' => count($rows)]);
