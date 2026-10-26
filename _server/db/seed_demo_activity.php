<?php
/**
 * Mzigo.online — demo activity seed for the admin analytics dashboard.
 *
 * Creates ~120 days of realistic parcel/scan/invoice/errand history for the two
 * seeded demo workspaces so the BI charts are meaningful on a fresh install.
 *
 *   php _server\db\seed_demo_activity.php
 *
 * Idempotent: rows created here are tagged (TRK-DEMO*, ERD-DEMO*, INV-DEMO-*) and
 * removed before re-seeding. Production installs should NOT run this script.
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

mt_srand(20261025); // deterministic demo data

function uuidv4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

function quote_for(string $size, int $valueKes): array
{
    $base = ['Envelope' => 120, 'Small' => 180, 'Medium' => 250, 'Large' => 330,
        'Extra Large' => 420, 'Extra Extra Large' => 520, 'Extra Extra Extra Large' => 650];
    $b = (int) (round((($base[$size] ?? 250) + 180) / 10) * 10);
    $holding = 30;
    $insurance = max(30, (int) (round(($valueKes * 0.015) / 10) * 10));
    $platform = 80;
    $total = (int) (ceil(($b + $holding + $insurance + $platform) / 10) * 10);
    return ['base' => $b, 'holding' => $holding, 'insurance' => $insurance, 'platform' => $platform, 'total' => $total];
}

function pick(array $items)
{
    return $items[array_rand($items)];
}

$tenants = [];
foreach ($pdo->query("SELECT id, slug, name FROM tenants WHERE slug IN ('amina-crafts','sokoni-traders')") as $row) {
    $tenants[$row['slug']] = $row;
}
if (count($tenants) < 2) {
    fwrite(STDERR, "Run seed_saas.php first (demo workspaces missing).\n");
    exit(1);
}

$partners = $pdo->query('SELECT slug, name FROM partners ORDER BY name')->fetchAll();
$destinations = [];
foreach ($pdo->query('SELECT partner_slug, name FROM destinations ORDER BY name') as $row) {
    $destinations[$row['partner_slug']][] = $row['name'];
}

/* ------------------------------------------------------------ clean slate */
$pdo->exec("DELETE s FROM scans s JOIN parcels p ON p.id = s.subject_id WHERE s.subject_type='parcel' AND p.tracking LIKE 'TRK-DEMO%'");
$pdo->exec("DELETE FROM parcels WHERE tracking LIKE 'TRK-DEMO%'");
$pdo->exec("DELETE s FROM scans s JOIN errands e ON e.id = s.subject_id WHERE s.subject_type='errand' AND e.ref LIKE 'ERD-DEMO%'");
$pdo->exec("DELETE FROM errands WHERE ref LIKE 'ERD-DEMO%'");
$pdo->exec("DELETE FROM invoices WHERE number LIKE 'INV-DEMO-%'");

/* --------------------------------------------------------- spread history */
$pdo->prepare("UPDATE tenants SET created_at = DATE_SUB(NOW(), INTERVAL 150 DAY) WHERE slug = 'amina-crafts'")->execute();
$pdo->prepare("UPDATE tenants SET created_at = DATE_SUB(NOW(), INTERVAL 35 DAY) WHERE slug = 'sokoni-traders'")->execute();

$statusForAge = function (int $daysAgo): string {
    if ($daysAgo > 21) return 'COLLECTED_PAID';
    if ($daysAgo > 9) return 'READY_FOR_COLLECTION';
    if ($daysAgo > 4) return pick(['IN_TRANSIT', 'ARRIVED_DEST_POINT']);
    if ($daysAgo > 1) return pick(['AT_ORIGIN_POINT', 'HANDED_TO_TRANSPORTER', 'IN_TRANSIT']);
    return pick(['CREATED', 'AT_ORIGIN_POINT']);
};

$timelineFor = function (string $status, string $registeredAt, string $carrier, string $from, string $to): array {
    $steps = [
        ['CREATED', 'Parcel registered', 'Mzigo.online', 'QR label and Locker ID issued to sender.'],
        ['AT_ORIGIN_POINT', 'Dropped off at origin', $carrier . ' office, ' . $from, 'Scanned in by the partner office.'],
        ['HANDED_TO_TRANSPORTER', 'Handed to transporter', $from . ' terminus', 'Loaded on the ' . $to . '-bound vehicle.'],
        ['IN_TRANSIT', 'In transit', 'En route', 'Scanned at a stop.'],
        ['ARRIVED_DEST_POINT', 'Arrived at destination', $carrier . ' office, ' . $to, 'Unloaded and shelved.'],
        ['READY_FOR_COLLECTION', 'Ready for collection', $carrier . ' office, ' . $to, 'Buyer notified by SMS/WhatsApp.'],
        ['COLLECTED_PAID', 'Collected & paid', $carrier . ' office, ' . $to, 'M-Pesa payment confirmed; parcel released.'],
    ];
    $reached = array_search($status, array_column($steps, 0), true);
    if ($reached === false) {
        $reached = count($steps) - 1;
    }
    $out = [];
    $base = strtotime($registeredAt);
    for ($i = 0; $i <= $reached; $i++) {
        $out[] = [
            'status' => $steps[$i][0],
            'label'  => $steps[$i][1],
            'where'  => $steps[$i][2],
            'note'   => $steps[$i][3],
            'at'     => date('Y-m-d H:i:s', $base + $i * random_int(4, 14) * 3600),
        ];
    }
    return $out;
};

$insertParcel = $pdo->prepare(
    'INSERT INTO parcels (id, tracking, locker, user_id, anon_id, tenant_id, carrier, carrier_slug, from_city, to_city,
      sender_name, sender_phone, receiver_name, receiver_phone, receiver_phone_key, description, size, value_kes, tier, payment_method,
      collect_code, status, fee_base, fee_holding, fee_insurance, fee_platform, fee_total, registered_at)
     VALUES (?, ?, ?, NULL, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$insertScan = $pdo->prepare(
    'INSERT INTO scans (subject_type, subject_id, status, label, location, note, scanned_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
);

$demoPlan = [
    ['slug' => 'amina-crafts', 'count' => 64, 'days' => 120],
    ['slug' => 'sokoni-traders', 'count' => 22, 'days' => 55],
];
$sizes = ['Small', 'Medium', 'Medium', 'Large', 'Large', 'Extra Large'];
$senderNames = [['Amina Yusuf', '0733111222'], ['Sokoni Traders', '0722000111'], ['Amina Crafts', '0733111222']];
$receiverNames = ['Mary Wanjiku', 'Peter Mutua', 'Grace Njeri', 'Brian Otieno', 'Fatuma Ali', 'Ken Mwangi'];
$platformPaid = 0;

foreach ($demoPlan as $plan) {
    $tenant = $tenants[$plan['slug']];
    for ($i = 0; $i < $plan['count']; $i++) {
        $daysAgo = random_int(0, $plan['days']);
        $registeredAt = date('Y-m-d H:i:s', strtotime("-{$daysAgo} days") - random_int(0, 20 * 3600));
        $partner = pick($partners);
        $dests = $destinations[$partner['slug']] ?? ['Nairobi'];
        $from = in_array('Nairobi', $dests, true) ? 'Nairobi' : pick($dests);
        $others = array_values(array_filter($dests, fn ($d) => $d !== $from));
        $to = $others ? pick($others) : $from;
        $size = pick($sizes);
        $value = random_int(60, 1500) * 10;
        $fee = quote_for($size, $value);
        $status = $statusForAge($daysAgo);
        $sender = pick($senderNames);
        $receiverPhone = '07' . random_int(10, 99) . random_int(100000, 999999);
        $receiverPhoneKey = substr(preg_replace('/\D+/', '', $receiverPhone), -9);
        $tracking = 'TRK-DEMO' . strtoupper(bin2hex(random_bytes(3)));
        $locker = 'LK-D' . strtoupper(bin2hex(random_bytes(3)));
        $id = uuidv4();
        $insertParcel->execute([
            $id, $tracking, substr($locker, 0, 12), $tenant['id'], $partner['name'], $partner['slug'], $from, $to,
            $sender[0], $sender[1], pick($receiverNames), $receiverPhone, $receiverPhoneKey,
            'Demo analytics parcel — ' . $size . ' shipment', $size, $value, $value > 10000 ? 'B' : 'A',
            mt_rand(1, 100) <= 82 ? 'M-PESA' : 'CASH',
            str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT), $status,
            $fee['base'], $fee['holding'], $fee['insurance'], $fee['platform'], $fee['total'], $registeredAt,
        ]);
        foreach ($timelineFor($status, $registeredAt, $partner['name'], $from, $to) as $scan) {
            $insertScan->execute(['parcel', $id, $scan['status'], $scan['label'], $scan['where'], $scan['note'], $scan['at']]);
        }
        $platformPaid += $fee['platform'];
    }
}

/* ------------------------------------------------------------- invoices */
$invoice = $pdo->prepare(
    'INSERT INTO invoices (tenant_id, plan_id, number, amount_kes, status, description, issued_at, due_at, paid_at, method, reference)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$planIds = [];
foreach ($pdo->query('SELECT id, code FROM plans') as $row) {
    $planIds[$row['code']] = (int) $row['id'];
}
for ($monthsAgo = 5; $monthsAgo >= 0; $monthsAgo--) {
    $issued = new DateTimeImmutable('first day of this month');
    $issued = $issued->modify("-{$monthsAgo} months")->modify('+2 days');
    $number = 'INV-DEMO-A-' . $issued->format('ym');
    $invoice->execute([
        $tenants['amina-crafts']['id'], $planIds['growth'], $number, 3500, 'paid',
        'Demo subscription — Growth', $issued->format('Y-m-d H:i:s'), $issued->modify('+7 days')->format('Y-m-d H:i:s'),
        $issued->format('Y-m-d H:i:s'), 'M-PESA', 'DEMO-MPESA-' . strtoupper(bin2hex(random_bytes(3))),
    ]);
}
$issued = (new DateTimeImmutable('first day of this month'))->modify('+1 day');
$invoice->execute([
    $tenants['sokoni-traders']['id'], $planIds['starter'], 'INV-DEMO-S-' . $issued->format('ym'), 1500, 'pending',
    'Demo subscription — Starter', $issued->format('Y-m-d H:i:s'), $issued->modify('+7 days')->format('Y-m-d H:i:s'),
    null, null, null,
]);

/* --------------------------------------------------------------- errands */
$insertErrand = $pdo->prepare(
    'INSERT INTO errands (id, ref, user_id, anon_id, tenant_id, carrier, pickup, dropoff, sender_name, sender_phone,
      recipient_name, recipient_phone, description, instructions, payment_method, status,
      fee_base, fee_platform, fee_total, created_at)
     VALUES (?, ?, NULL, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 250, 50, 300, ?)'
);
$errandCities = ['Westlands, Nairobi', 'Kilimani, Nairobi', 'Nairobi CBD', 'Karen, Nairobi', 'Mombasa CBD'];
for ($i = 0; $i < 9; $i++) {
    $daysAgo = random_int(0, 70);
    $createdAt = date('Y-m-d H:i:s', strtotime("-{$daysAgo} days"));
    $status = $daysAgo > 14 ? 'COLLECTED_PAID' : ($daysAgo > 5 ? 'READY_FOR_COLLECTION' : 'CREATED');
    $id = uuidv4();
    $ref = 'ERD-DEMO' . strtoupper(bin2hex(random_bytes(2)));
    $insertErrand->execute([
        $id, $ref, $tenants[pick(['amina-crafts', 'sokoni-traders'])]['id'],
        'mzigo runners', pick($errandCities), pick($errandCities),
        pick($senderNames)[0], '07' . random_int(10, 99) . random_int(100000, 999999),
        pick($receiverNames), '07' . random_int(10, 99) . random_int(100000, 999999),
        'Demo errand — collect documents', 'Call on arrival', 'M-PESA', $status, $createdAt,
    ]);
    $insertScan->execute(['errand', $id, 'CREATED', 'Errand requested', 'Mzigo.online', 'Runner assigned.', $createdAt]);
}

echo "demo activity seeded: " . count($demoPlan) . " workspaces, demo platform fees KES " . $platformPaid . "\n";
