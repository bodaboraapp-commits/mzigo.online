<?php
/**
 * Mzigo.online — printable invoice / receipt document.
 * GET /api/invoice-doc.php?invoice=ID[&download=1]
 * Owner/admin/staff of the invoice's workspace only.
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_tenant_branding.php';

$user = require_user();
$tenant = current_tenant();
if (!$tenant) {
    http_response_code(403);
    exit('No workspace');
}
$invoiceId = (int) ($_GET['invoice'] ?? 0);
$stmt = db()->prepare(
    'SELECT i.*, p.name AS plan_name, p.code AS plan_code, t.name AS tenant_name,
            t.billing_name, t.billing_email, t.billing_address, t.tax_id, t.tax_rate AS tenant_tax_rate
     FROM invoices i
     JOIN tenants t ON t.id = i.tenant_id
     JOIN plans p ON p.id = i.plan_id
     WHERE i.id = ? AND i.tenant_id = ? LIMIT 1'
);
$stmt->execute([$invoiceId, (int) $tenant['id']]);
$inv = $stmt->fetch();
if (!$inv) {
    http_response_code(404);
    exit('Invoice not found');
}

$snapshot = [];
if (!empty($inv['billing_snapshot'])) {
    $decoded = json_decode((string) $inv['billing_snapshot'], true);
    if (is_array($decoded)) {
        $snapshot = $decoded;
    }
}
$billing = [
    'name' => $snapshot['name'] ?? ($inv['billing_name'] ?: $inv['tenant_name']),
    'email' => $snapshot['email'] ?? $inv['billing_email'],
    'address' => $snapshot['address'] ?? $inv['billing_address'],
    'tax_id' => $snapshot['tax_id'] ?? $inv['tax_id'],
];
$branding = tenant_branding((int) $inv['tenant_id']);
$brandColor = tenant_branding_color((string) ($branding['primary_color'] ?? ''), '#3c5223');
$brandLogo = (string) ($branding['logo_url'] ?? '');
$brandName = (string) $inv['tenant_name'];
$brandLogoHtml = $brandLogo !== ''
    ? '<img src="' . htmlspecialchars($brandLogo, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8') . '" style="max-height:54px;max-width:220px;display:block;margin-bottom:6px;">'
    : '<div style="font-size:24px;font-weight:800;letter-spacing:-.03em;margin-bottom:4px;">' . htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8') . '</div>';
$brandSupport = trim((string) ($branding['support_email'] ?? '')) ?: 'hello@mzigo.online';
$brandPhone = trim((string) ($branding['support_phone'] ?? ''));
$brandFooter = trim((string) ($branding['document_footer'] ?? '')) ?: 'Plan prices are VAT-inclusive; VAT is shown for transparency.';
$amount = (int) $inv['amount_kes'];
$subtotal = $inv['subtotal_kes'] !== null ? (int) $inv['subtotal_kes'] : null;
$tax = $inv['tax_kes'] !== null ? (int) $inv['tax_kes'] : null;
$rate = $inv['tax_rate'] !== null ? (float) $inv['tax_rate'] : null;
$paid = $inv['status'] === 'paid';
$refunded = $inv['status'] === 'refunded';
$title = $refunded ? 'Credit note' : ($paid ? 'Receipt' : 'Invoice');

$e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$money = fn (int $v) => 'KES ' . number_format($v);
$lines = '';
$lines .= '<tr><th>Description</th><td>' . $e('Mzigo.online — ' . $inv['plan_name'] . ' plan (' . $inv['description'] . ')') . '</td></tr>';
$lines .= '<tr><th>Invoice number</th><td>' . $e($inv['number']) . '</td></tr>';
$lines .= '<tr><th>Issued / due</th><td>' . $e($inv['issued_at']) . ' / ' . $e($inv['due_at'] ?: '—') . '</td></tr>';
if ($paid) {
    $lines .= '<tr><th>Paid</th><td>' . $e($inv['paid_at']) . ' · ' . $e($inv['method'] ?: '') . ' · ' . $e($inv['reference'] ?: '') . '</td></tr>';
}
if ($refunded) {
    $lines .= '<tr><th>Refunded</th><td>' . $e($inv['refunded_at']) . ' · ' . $e($inv['refund_ref'] ?: '') . '</td></tr>';
}
$lines .= '<tr><th>Total</th><td><strong>' . $e($money($amount)) . '</strong></td></tr>';
if ($subtotal !== null) {
    $lines .= '<tr><th>Subtotal (excl. VAT)</th><td>' . $e($money($subtotal)) . '</td></tr>';
}
if ($tax !== null) {
    $lines .= '<tr><th>VAT' . ($rate !== null ? ' @ ' . $e(number_format($rate, 2)) . '%' : '') . ' (included)</th><td>' . $e($money($tax)) . '</td></tr>';
}

$html = '<!doctype html><html><head><meta charset="utf-8"><title>' . $e($brandName . ' ' . $title . ' ' . $inv['number']) . '</title>' .
'<style>body{font-family:Outfit,Segoe UI,sans-serif;color:#1f2a12;margin:32px;max-width:760px;font-size:14px;}' .
'h1{font-size:24px;margin:0 0 2px;}h2{font-size:16px;margin:18px 0 6px;color:' . $brandColor . ';}' .
'.muted{color:#5b6b45;font-size:12px;}table{width:100%;border-collapse:collapse;margin-top:14px;}' .
'th,td{border-bottom:1px solid #e3ead9;padding:9px 4px;text-align:left;vertical-align:top;}th{width:34%;color:#5b6b45;font-weight:600;}' .
'.badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;background:#e8f5e4;color:#2f4d1f;}' .
'.box{border:2px solid ' . $brandColor . ';border-radius:12px;padding:18px;margin-top:16px;}' .
'@media print{button{display:none;}}button{margin-top:18px;padding:9px 16px;border-radius:9px;border:0;background:' . $brandColor . ';color:#fff;cursor:pointer;font:inherit;}' .
'</style></head><body>' .
'<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:20px;flex-wrap:wrap;">' .
'<div>' . $brandLogoHtml .
'<div style="font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#6b7a5c;">Powered By Mzigo.online</div>' .
'<div class="muted" style="margin-top:4px;">' . $e($brandSupport) . ($brandPhone !== '' ? ' &middot; ' . $e($brandPhone) : '') . '</div></div>' .
'<div style="text-align:right;"><h1 style="margin:0;font-size:22px;">' . $e($title) . '</h1><div class="muted">' . $e($inv['number']) . ' &middot; ' . ($refunded ? 'Refunded' : ($paid ? 'Paid' : 'Pending payment')) . '</div></div></div>' .
'<h2 style="margin:18px 0 4px;">' . $e($brandName) . '</h2>' .
'<div class="muted">' . $e($title) . ' &middot; ' . $e($inv['number']) . '</div>' .
'<div class="box"><h2>Billed to</h2>' .
'<div><strong>' . $e($billing['name']) . '</strong></div>' .
($billing['email'] ? '<div class="muted">' . $e($billing['email']) . '</div>' : '') .
($billing['address'] ? '<div class="muted">' . $e($billing['address']) . '</div>' : '') .
($billing['tax_id'] ? '<div class="muted">KRA PIN / Tax ID: ' . $e($billing['tax_id']) . '</div>' : '') .
'</div><table>' . $lines . '</table>' .
'<div style="margin-top:18px;padding-top:10px;border-top:1px solid #e3ead9;color:#5b6b45;font-size:11px;line-height:1.6;">' .
'<div>' . $e($brandFooter) . '</div>' .
'<div>Payment terms: 7 days &middot; ' . $e($brandSupport) . ' &middot; Powered By Mzigo.online</div></div>' .
'<button onclick="window.print()">Print / Save as PDF</button></body></html>';

if (!empty($_GET['download'])) {
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="mzigo-' . ($paid ? 'receipt' : ($refunded ? 'credit-note' : 'invoice')) . '-' . preg_replace('/[^A-Za-z0-9-]/', '', $inv['number']) . '.html"');
} else {
    header('Content-Type: text/html; charset=utf-8');
}
header('Cache-Control: no-store');
echo $html;
