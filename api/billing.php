<?php
/**
 * Mzigo.online — subscription billing.
 *
 * GET  -> current plan, usage, invoices, available plans
 * POST action=change_plan   { plan_code }
 * POST action=pay_invoice   { invoice_id }   (demo M-Pesa settlement)
 *
 * The payment provider is abstracted behind config['payments']['provider']; with
 * "demo" the settlement is simulated. Real M-Pesa (Daraja STK) plugs in where the
 * invoice is marked paid.
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_webhooks.php';
require __DIR__ . '/_notifications.php';
require_once __DIR__ . '/_growth.php';

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$user = require_user();
$tenant = current_tenant();
if (!$tenant) {
    fail_error('no_tenant', 'This account has no business workspace yet.', 403);
}
$ctx = ['tenant' => $tenant, 'user' => $user, 'role' => $tenant['role']];

function active_plans(): array
{
    $rows = db()->query(
        'SELECT id, code, name, monthly_price_kes, parcel_limit, staff_limit, features
         FROM plans WHERE is_active = 1 ORDER BY sort_order ASC'
    )->fetchAll();
    foreach ($rows as &$row) {
        $row['monthly_price_kes'] = (int) $row['monthly_price_kes'];
        $row['parcel_limit'] = (int) $row['parcel_limit'];
        $row['staff_limit'] = (int) $row['staff_limit'];
        $row['features'] = $row['features'] ? json_decode((string) $row['features'], true) : [];
    }
    return $rows;
}

function tenant_transactions(int $tenantId, int $limit = 20): array
{
    $stmt = db()->prepare(
        'SELECT id, reference, type, amount_kes, method, status, subject_type, subject_id, created_at, completed_at
         FROM transactions WHERE tenant_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . (int) $limit
    );
    $stmt->execute([$tenantId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['amount_kes'] = (int) $row['amount_kes'];
    }
    return $rows;
}

function tenant_invoices(int $tenantId, int $limit = 50): array
{
    $stmt = db()->prepare(
        'SELECT i.id, i.number, i.amount_kes, i.subtotal_kes, i.tax_kes, i.tax_rate, i.status, i.description,
                i.issued_at, i.due_at, i.paid_at, i.refunded_at, i.refund_ref, i.method, i.reference,
                p.code AS plan_code, p.name AS plan_name
         FROM invoices i JOIN plans p ON p.id = i.plan_id
         WHERE i.tenant_id = ? ORDER BY i.issued_at DESC, i.id DESC LIMIT ' . (int) $limit
    );
    $stmt->execute([$tenantId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['amount_kes'] = (int) $row['amount_kes'];
    }
    return $rows;
}

function billing_subscription(int $tenantId): ?array
{
    $stmt = db()->prepare(
        'SELECT s.*, p.code AS plan_code, p.name AS plan_name, p.monthly_price_kes,
                pp.code AS pending_plan_code, pp.name AS pending_plan_name
         FROM subscriptions s
         JOIN plans p ON p.id = s.plan_id
         LEFT JOIN plans pp ON pp.id = s.pending_plan_id
         WHERE s.tenant_id = ? LIMIT 1'
    );
    $stmt->execute([$tenantId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $row['monthly_price_kes'] = (int) $row['monthly_price_kes'];
    $row['cancel_at_period_end'] = (bool) (int) $row['cancel_at_period_end'];
    return $row;
}

function tenant_billing_details(int $tenantId): array
{
    $stmt = db()->prepare('SELECT billing_name, billing_email, billing_address, tax_id, tax_rate, name, email FROM tenants WHERE id = ? LIMIT 1');
    $stmt->execute([$tenantId]);
    $row = $stmt->fetch() ?: [];
    return [
        'name' => ($row['billing_name'] ?? '') ?: ($row['name'] ?? ''),
        'email' => ($row['billing_email'] ?? '') ?: ($row['email'] ?? ''),
        'address' => $row['billing_address'] ?? '',
        'tax_id' => $row['tax_id'] ?? '',
        'tax_rate' => (float) ($row['tax_rate'] ?? 16),
    ];
}

function invoice_tax_split(int $total, float $rate): array
{
    if ($rate <= 0) {
        return [null, null, null];
    }
    $subtotal = (int) round($total / (1 + $rate / 100));
    return [$subtotal, $total - $subtotal, $rate];
}

if ($method === 'GET') {
    json_out([
        'ok'       => true,
        'source'   => 'mysql',
        'csrf'     => csrf_token(),
        'tenant'   => $tenant,
        'role'     => $tenant['role'],
        'usage'    => tenant_usage((int) $tenant['id']),
        'plans'    => active_plans(),
        'invoices' => tenant_invoices((int) $tenant['id']),
        'pricing_mode' => pricing_mode(),
        'free' => pricing_mode() === 'free',
        'transactions' => tenant_transactions((int) $tenant['id']),
        'subscription' => billing_subscription((int) $tenant['id']),
        'billing' => tenant_billing_details((int) $tenant['id']),
        'bill_credit_kes' => growth_credit_balance((int) $user['id'], 'bill_credit'),
        'provider' => config()['payments']['provider'],
        'currency' => config()['payments']['currency'],
    ]);
}

require_post();
verify_csrf();
tenant_owner_or_admin($ctx);
$data = body_json();
$action = (string) ($data['action'] ?? $_GET['action'] ?? '');
$pdo = db();
$tenantId = (int) $tenant['id'];

switch ($action) {
    case 'change_plan':
        $code = param($data, 'plan_code', 2, 40, 'Plan');
        $platformFree = pricing_mode() === 'free';
        $stmt = $pdo->prepare('SELECT * FROM plans WHERE code = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$code]);
        $plan = $stmt->fetch();
        if (!$plan) {
            fail('Choose a valid plan.', 422, ['plan_code' => 'Unknown plan.']);
        }
        $current = plan_subscription($tenantId);
        if ($current && $current['plan_code'] === $plan['code']) {
            fail_error('same_plan', 'You are already on the ' . $plan['name'] . ' plan.', 422);
        }

        $invoice = null;
        if (!$platformFree && (int) $plan['monthly_price_kes'] > 0) {
            $number = 'INV-' . date('ymd') . '-' . random_code(4);
            $billingDetails = tenant_billing_details($tenantId);
            $rate = (float) $billingDetails['tax_rate'];
            [$subtotal, $tax, $taxRate] = invoice_tax_split((int) $plan['monthly_price_kes'], $rate);
            $snapshot = json_encode([
                'name' => $billingDetails['name'],
                'email' => $billingDetails['email'],
                'address' => $billingDetails['address'],
                'tax_id' => $billingDetails['tax_id'],
            ], JSON_UNESCAPED_SLASHES);
            $ins = $pdo->prepare(
                'INSERT INTO invoices (tenant_id, plan_id, number, amount_kes, subtotal_kes, tax_kes, tax_rate,
                    status, description, issued_at, due_at, billing_snapshot)
                 VALUES (?, ?, ?, ?, ?, ?, ?, "pending", ?, NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY), ?)'
            );
            $ins->execute([
                $tenantId, (int) $plan['id'], $number, (int) $plan['monthly_price_kes'],
                $subtotal, $tax, $taxRate, 'Plan change — ' . $plan['name'], $snapshot,
            ]);
            $invoiceId = (int) $pdo->lastInsertId();
            $invoice = ['id' => $invoiceId, 'number' => $number, 'amount_kes' => (int) $plan['monthly_price_kes'], 'status' => 'pending'];
        }

        $upd = $pdo->prepare('UPDATE subscriptions SET plan_id = ?, status = ?, pending_plan_id = NULL, pending_plan_at = NULL, cancel_at_period_end = 0 WHERE tenant_id = ?');
        $newStatus = $platformFree ? 'active' : ((int) $plan['monthly_price_kes'] > 0
            ? ($current['subscription_status'] === 'trialing' ? 'trialing' : 'past_due')
            : 'active');
        $upd->execute([(int) $plan['id'], $newStatus, $tenantId]);
        if ($platformFree) {
            $pdo->prepare('UPDATE tenants SET status = "active" WHERE id = ?')->execute([$tenantId]);
        }

        audit('billing.plan_changed', 'tenant', (string) $tenantId, ['plan' => $plan['code'], 'invoice' => $invoice]);
        json_out([
            'ok' => true, 'source' => 'mysql', 'csrf' => csrf_token(),
            'invoice' => $invoice,
            'usage'   => tenant_usage($tenantId),
            'message' => 'Plan updated. ' . ($invoice ? 'Settle invoice ' . $invoice['number'] . ' to activate it.' : ''),
        ]);
        // no break

    case 'pay_invoice':
        if (pricing_mode() === 'free') {
            fail_error('platform_free', 'The platform is currently free — invoices are not being collected.', 422);
        }
        $invoiceId = (int) param($data, 'invoice_id', 1, 20, 'Invoice');
        $stmt = $pdo->prepare('SELECT * FROM invoices WHERE id = ? AND tenant_id = ? LIMIT 1');
        $stmt->execute([$invoiceId, $tenantId]);
        $invoice = $stmt->fetch();
        if (!$invoice) {
            fail_error('not_found', 'Invoice not found.', 404);
        }
        if ($invoice['status'] !== 'pending') {
            fail_error('invoice_not_pending', 'That invoice is already ' . $invoice['status'] . '.', 422);
        }
        $reference = 'DEMO-MPESA-' . strtoupper(random_code(8));
        $couponCode = isset($data['coupon_code']) && is_scalar($data['coupon_code']) ? trim((string) $data['coupon_code']) : '';
        $coupon = null;
        $discountKes = 0;
        $originalAmount = (int) $invoice['amount_kes'];
        if ($couponCode !== '') {
            $couponResult = coupon_validate($couponCode, $originalAmount, 'subscription', (int) $user['id']);
            if (!$couponResult['ok']) {
                fail($couponResult['error'], 422, ['coupon_code' => $couponResult['error']]);
            }
            $coupon = $couponResult['coupon'];
            $discountKes = (int) $couponResult['discount_kes'];
        }
        $creditUsed = growth_credit_use((int) $user['id'], 'bill_credit', max(0, $originalAmount - $discountKes), $reference);
        $paidAmount = max(0, $originalAmount - $discountKes - $creditUsed);
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'UPDATE invoices SET status = "paid", paid_at = NOW(), method = ?, reference = ?, coupon_code = ?, discount_kes = ?, credit_used_kes = ?, paid_amount_kes = ? WHERE id = ?'
            )->execute(['M-PESA', $reference, $coupon['code'] ?? null, $discountKes, $creditUsed, $paidAmount, $invoiceId]);
            $pdo->prepare(
                'UPDATE subscriptions SET status = "active", current_period_start = NOW(), current_period_end = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE tenant_id = ?'
            )->execute([$tenantId]);
            $pdo->prepare('UPDATE tenants SET status = "active" WHERE id = ?')->execute([$tenantId]);
            record_transaction([
                'reference' => $reference, 'tenant_id' => $tenantId, 'user_id' => (int) $user['id'],
                'type' => 'subscription', 'amount_kes' => $paidAmount, 'method' => 'M-PESA',
                'subject_type' => 'invoice', 'subject_id' => (string) $invoiceId,
                'metadata' => ['number' => $invoice['number']],
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('pay_invoice failed: ' . $e->getMessage());
            fail_error('payment_failed', 'Could not record the payment. Please try again.', 500);
        }
        if ($coupon) {
            coupon_redeem($coupon, $originalAmount, $discountKes, 'subscription', $reference, (int) $user['id']);
        }
        audit('billing.invoice_paid', 'invoice', (string) $invoiceId, ['number' => $invoice['number'], 'amount' => $paidAmount, 'coupon' => $coupon['code'] ?? null, 'credit' => $creditUsed, 'reference' => $reference]);
        dispatch_webhooks($tenantId, 'invoice.paid', ['invoice' => $invoice['number'], 'amount_kes' => $paidAmount, 'discount_kes' => $discountKes, 'credit_used_kes' => $creditUsed, 'reference' => $reference]);
        $invoice['amount_kes'] = $paidAmount;
        notify_invoice_paid($invoice, $user);
        json_out([
            'ok' => true, 'source' => 'mysql', 'csrf' => csrf_token(),
            'reference' => $reference,
            'usage' => tenant_usage($tenantId),
            'invoices' => tenant_invoices($tenantId),
            'message' => 'Payment received. Your workspace is active.',
        ]);
        // no break

    case 'update_billing_details':
        $billingName = param($data, 'billing_name', 2, 160, 'Billing name');
        $billingEmail = param($data, 'billing_email', 5, 160, 'Billing email');
        if (!email_valid($billingEmail)) {
            fail('Enter a valid billing email address.', 422, ['billing_email' => 'Invalid email.']);
        }
        $address = isset($data['billing_address']) && is_scalar($data['billing_address']) ? mb_substr(trim((string) $data['billing_address']), 0, 255) : '';
        $taxId = isset($data['tax_id']) && is_scalar($data['tax_id']) ? mb_substr(trim((string) $data['tax_id']), 0, 40) : '';
        $taxRate = isset($data['tax_rate']) && is_numeric($data['tax_rate']) ? (float) $data['tax_rate'] : (float) ($tenant['tax_rate'] ?? 16);
        if ($taxRate < 0 || $taxRate > 30) {
            fail_error('invalid_tax_rate', 'VAT rate must be between 0 and 30%.', 422);
        }
        $pdo->prepare('UPDATE tenants SET billing_name = ?, billing_email = ?, billing_address = ?, tax_id = ?, tax_rate = ? WHERE id = ?')
            ->execute([$billingName, $billingEmail, $address, $taxId, $taxRate, $tenantId]);
        audit('billing.details_updated', 'tenant', (string) $tenantId, ['tax_id' => $taxId, 'tax_rate' => $taxRate], $tenantId);
        json_out(['ok' => true, 'source' => 'mysql', 'billing' => [
            'name' => $billingName, 'email' => $billingEmail, 'address' => $address, 'tax_id' => $taxId, 'tax_rate' => $taxRate,
        ], 'message' => 'Billing details saved.']);
        // no break

    case 'schedule_cancel':
        $sub = billing_subscription($tenantId);
        if (!$sub) {
            fail_error('no_subscription', 'No subscription to cancel.', 422);
        }
        if (in_array($sub['status'], ['cancelled'], true)) {
            fail_error('already_cancelled', 'This subscription is already cancelled.', 422);
        }
        $pdo->prepare('UPDATE subscriptions SET cancel_at_period_end = 1, pending_plan_id = NULL, pending_plan_at = NULL WHERE tenant_id = ?')
            ->execute([$tenantId]);
        audit('billing.cancel_scheduled', 'tenant', (string) $tenantId, ['period_end' => $sub['current_period_end']], $tenantId);
        json_out(['ok' => true, 'source' => 'mysql', 'subscription' => billing_subscription($tenantId),
            'message' => 'Subscription will cancel at the end of the current period.']);
        // no break

    case 'resume_subscription':
        $pdo->prepare('UPDATE subscriptions SET cancel_at_period_end = 0, status = CASE WHEN status = "cancelled" THEN "active" ELSE status END WHERE tenant_id = ?')
            ->execute([$tenantId]);
        $pdo->prepare('UPDATE tenants SET status = "active" WHERE id = ? AND status = "cancelled"')->execute([$tenantId]);
        audit('billing.subscription_resumed', 'tenant', (string) $tenantId, [], $tenantId);
        json_out(['ok' => true, 'source' => 'mysql', 'subscription' => billing_subscription($tenantId),
            'message' => 'Subscription resumed.']);
        // no break

    case 'schedule_plan_change':
        $code = param($data, 'plan_code', 2, 40, 'Plan');
        $stmt = $pdo->prepare('SELECT * FROM plans WHERE code = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$code]);
        $plan = $stmt->fetch();
        if (!$plan) {
            fail('Choose a valid plan.', 422, ['plan_code' => 'Unknown plan.']);
        }
        $sub = billing_subscription($tenantId);
        if ($sub && $sub['plan_code'] === $plan['code']) {
            fail_error('same_plan', 'You are already on the ' . $plan['name'] . ' plan.', 422);
        }
        if ($sub && (int) $plan['monthly_price_kes'] >= (int) $sub['monthly_price_kes']) {
            fail_error('not_a_downgrade', 'Upgrades apply immediately — use change_plan.', 422);
        }
        $at = $sub && $sub['current_period_end'] ? $sub['current_period_end'] : date('Y-m-d H:i:s', time() + 30 * 86400);
        $pdo->prepare('UPDATE subscriptions SET pending_plan_id = ?, pending_plan_at = ?, cancel_at_period_end = 0 WHERE tenant_id = ?')
            ->execute([(int) $plan['id'], $at, $tenantId]);
        audit('billing.downgrade_scheduled', 'tenant', (string) $tenantId, ['plan' => $plan['code'], 'effective_at' => $at], $tenantId);
        json_out(['ok' => true, 'source' => 'mysql', 'subscription' => billing_subscription($tenantId),
            'message' => 'Downgrade scheduled for ' . $at . '.']);
        // no break

    case 'cancel_scheduled_change':
        $pdo->prepare('UPDATE subscriptions SET pending_plan_id = NULL, pending_plan_at = NULL WHERE tenant_id = ?')->execute([$tenantId]);
        audit('billing.downgrade_cancelled', 'tenant', (string) $tenantId, [], $tenantId);
        json_out(['ok' => true, 'source' => 'mysql', 'subscription' => billing_subscription($tenantId),
            'message' => 'Scheduled plan change cancelled.']);
        // no break

    default:
        fail_error('unknown_action', 'Unknown billing action.', 400);
}
