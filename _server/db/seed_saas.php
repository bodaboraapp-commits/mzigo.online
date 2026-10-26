<?php
/**
 * Mzigo.online — SaaS seed.
 *
 * Idempotent: plans, the platform admin, two demo tenants with subscriptions and
 * invoices, and a hidden "sandbox" plan (1 parcel) used for quota tests.
 *
 *   php _server\db\seed_saas.php
 *
 * Demo credentials (local only):
 *   platform admin : admin@mzigo.online / Admin123!
 *   merchant owner : amina@example.com  / Demo1234!   (Amina Crafts, Growth, active)
 *   merchant owner : sokoni@example.com / Demo1234!   (Sokoni Traders, Starter, trial)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("CLI only\n");
}

$config = require dirname(__DIR__) . '/config.php';
$db = $config['db'];
$pdo = new PDO($db['dsn'], $db['user'], $db['pass'], [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$trialDays = (int) $config['app']['trial_days'];

/* ------------------------------------------------------------------- plans */
$plans = [
    ['starter', 'Starter', 1500, 50, 5, ['Saved senders', 'Package tracking', 'Email support'], 1, 1],
    ['growth',  'Growth',  3500, 300, 15, ['Everything in Starter', 'API access', 'Priority support', 'Team roles'], 2, 1],
    ['scale',   'Scale',   7500, 2000, 50, ['Everything in Growth', 'Dedicated onboarding', 'Custom rate cards', 'SLA support'], 3, 1],
    ['sandbox', 'Sandbox (limit 1)', 0, 1, 1, ['Internal test plan'], 99, 0],
];
$planStmt = $pdo->prepare(
    'INSERT INTO plans (code, name, monthly_price_kes, parcel_limit, staff_limit, features, sort_order, is_active)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE name = VALUES(name), monthly_price_kes = VALUES(monthly_price_kes),
       parcel_limit = VALUES(parcel_limit), staff_limit = VALUES(staff_limit), features = VALUES(features),
       sort_order = VALUES(sort_order), is_active = VALUES(is_active)'
);
foreach ($plans as $p) {
    $planStmt->execute([$p[0], $p[1], $p[2], $p[3], $p[4], json_encode($p[5]), $p[6], $p[7]]);
}
$planId = function (string $code) use ($pdo): int {
    $stmt = $pdo->prepare('SELECT id FROM plans WHERE code = ?');
    $stmt->execute([$code]);
    return (int) $stmt->fetchColumn();
};
echo "plans seeded\n";

/* -------------------------------------------------------------- users/seed */
function upsert_user(PDO $pdo, string $name, ?string $email, string $phone, string $password, string $role): int
{
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? OR phone = ? LIMIT 1');
    $stmt->execute([$email, $phone]);
    $id = $stmt->fetchColumn();
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($id !== false) {
        $upd = $pdo->prepare('UPDATE users SET name = ?, email = ?, phone = ?, password_hash = ?, role = ? WHERE id = ?');
        $upd->execute([$name, $email, $phone, $hash, $role, $id]);
        return (int) $id;
    }
    // Keep a unique phone/email: release legacy phone if another row owns it.
    $stmt = $pdo->prepare('INSERT INTO users (name, email, phone, password_hash, role) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$name, $email, $phone, $hash, $role]);
    return (int) $pdo->lastInsertId();
}

function ensure_tenant(PDO $pdo, string $slug, string $name, string $email, string $phone, string $status): int
{
    $stmt = $pdo->prepare('SELECT id FROM tenants WHERE slug = ? LIMIT 1');
    $stmt->execute([$slug]);
    $id = $stmt->fetchColumn();
    if ($id !== false) {
        $upd = $pdo->prepare('UPDATE tenants SET name = ?, email = ?, phone = ?, status = ? WHERE id = ?');
        $upd->execute([$name, $email, $phone, $status, $id]);
        return (int) $id;
    }
    $ins = $pdo->prepare('INSERT INTO tenants (slug, name, email, phone, status) VALUES (?, ?, ?, ?, ?)');
    $ins->execute([$slug, $name, $email, $phone, $status]);
    return (int) $pdo->lastInsertId();
}

function ensure_membership(PDO $pdo, int $tenantId, int $userId, string $role): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO tenant_members (tenant_id, user_id, role) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE role = VALUES(role)'
    );
    $stmt->execute([$tenantId, $userId, $role]);
}

function ensure_subscription(PDO $pdo, int $tenantId, int $planId, string $status, ?string $trialEnds): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO subscriptions (tenant_id, plan_id, status, trial_ends_at, current_period_start, current_period_end)
         VALUES (?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY))
         ON DUPLICATE KEY UPDATE plan_id = VALUES(plan_id), status = VALUES(status),
           trial_ends_at = VALUES(trial_ends_at), current_period_start = VALUES(current_period_start),
           current_period_end = VALUES(current_period_end)'
    );
    $stmt->execute([$tenantId, $planId, $status, $trialEnds]);
}

function ensure_invoice(PDO $pdo, int $tenantId, int $planId, string $number, int $amount, string $status, string $reference): void
{
    $paid = $status === 'paid';
    $issued = date('Y-m-d H:i:s', time() - ($paid ? 30 * 86400 : 0));
    $due = date('Y-m-d H:i:s', time() + ($paid ? -23 * 86400 : 7 * 86400));
    $stmt = $pdo->prepare(
        'INSERT INTO invoices (tenant_id, plan_id, number, amount_kes, status, description, issued_at, due_at, paid_at, method, reference)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE amount_kes = VALUES(amount_kes), status = VALUES(status),
           issued_at = VALUES(issued_at), due_at = VALUES(due_at), paid_at = VALUES(paid_at), reference = VALUES(reference)'
    );
    $stmt->execute([
        $tenantId, $planId, $number, $amount, $status,
        'Monthly subscription — ' . $number,
        $issued, $due,
        $paid ? date('Y-m-d H:i:s') : null,
        'M-PESA', $reference,
    ]);
}

/* platform admin */
$adminId = upsert_user($pdo, 'Platform Admin', 'admin@mzigo.online', '0700000000', 'Admin123!', 'platform_admin');
echo "platform admin user #{$adminId}\n";

/* Amina Crafts — Growth, active, two paid invoices */
$aminaId = upsert_user($pdo, 'Amina Yusuf', 'amina@example.com', '0733111222', 'Demo1234!', 'user');
$aminaTenant = ensure_tenant($pdo, 'amina-crafts', 'Amina Crafts', 'amina@example.com', '0733111222', 'active');
ensure_membership($pdo, $aminaTenant, $aminaId, 'owner');
ensure_subscription($pdo, $aminaTenant, $planId('growth'), 'active', null);
ensure_invoice($pdo, $aminaTenant, $planId('growth'), 'INV-1001', 3500, 'paid', 'MPESA-TEST-1001');
ensure_invoice($pdo, $aminaTenant, $planId('growth'), 'INV-1002', 3500, 'paid', 'MPESA-TEST-1002');
echo "tenant Amina Crafts #{$aminaTenant}\n";

/* Sokoni Traders — Starter, trialing */
$sokoniId = upsert_user($pdo, 'Sokoni Traders', 'sokoni@example.com', '0722000111', 'Demo1234!', 'user');
$sokoniTenant = ensure_tenant($pdo, 'sokoni-traders', 'Sokoni Traders', 'sokoni@example.com', '0722000111', 'trial');
ensure_membership($pdo, $sokoniTenant, $sokoniId, 'owner');
ensure_subscription($pdo, $sokoniTenant, $planId('starter'), 'trialing', date('Y-m-d H:i:s', time() + $trialDays * 86400));
echo "tenant Sokoni Traders #{$sokoniTenant}\n";

/* A starter invoice pending, so the admin billing view has something to act on. */
ensure_invoice($pdo, $sokoniTenant, $planId('starter'), 'INV-2001', 1500, 'pending', '');

/* Customer user class — the receiver who pays at collection. Phone + demo code 1234. */
$customerId = upsert_user($pdo, 'Mary Wanjiku', null, '0723456789', 'Demo1234!', 'customer');
echo "customer Mary Wanjiku #{$customerId} (demo code 1234)\n";
$customer2Id = upsert_user($pdo, 'Peter Mutua', null, '0744333444', 'Demo1234!', 'customer');
echo "customer Peter Mutua #{$customer2Id} (demo code 1234)\n";

/* Freelancer user class — phone + system-generated 4-digit PIN (demo PIN 1234). */
$freelancerId = upsert_user($pdo, 'Demo Freelancer', null, '0700111222', 'Demo1234!', 'freelancer');
$pdo->prepare('UPDATE users SET pin_hash = ? WHERE id = ?')
    ->execute([password_hash('1234', PASSWORD_DEFAULT), $freelancerId]);
echo "freelancer Demo Freelancer #{$freelancerId} (PIN 1234)\n";

echo "SaaS seed complete.\n";
