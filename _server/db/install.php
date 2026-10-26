<?php
/**
 * Mzigo.online — database installer / upgrader (CLI).
 *
 *   php _server\db\install.php
 *
 * 1. creates the database if missing
 * 2. applies _server/db/schema.sql on a fresh install
 * 3. runs every pending migration in _server/db/migrations
 * 4. seeds the courier catalog + demo parcels if empty
 * 5. seeds plans, platform admin and demo workspaces if empty
 *
 * Safe to run repeatedly (idempotent).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("CLI only\n");
}

$config = require dirname(__DIR__) . '/config.php';
$db = $config['db'];

/* 1. create the database if needed (connect without a schema first) */
$rootDsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $db['host'], $db['port']);
$root = new PDO($rootDsn, $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$root->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $db['database']) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
echo "database ready: {$db['database']}\n";

$pdo = new PDO($db['dsn'], $db['user'], $db['pass'], [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);

function apply_sql_file(PDO $pdo, string $file): void
{
    $sql = file_get_contents($file);
    if ($sql === false) {
        throw new RuntimeException('Cannot read ' . $file);
    }
    $clean = preg_replace('/^\s*--.*$/m', '', $sql);
    foreach (array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $clean))) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

/* 2. base schema for a fresh install */
$hasUsers = (bool) $pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn();
if (!$hasUsers) {
    echo "applying base schema ...\n";
    apply_sql_file($pdo, __DIR__ . '/schema.sql');
}

/* 3. migrations */
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        version VARCHAR(120) PRIMARY KEY,
        checksum CHAR(64) NOT NULL,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB'
);
$applied = [];
foreach ($pdo->query('SELECT version FROM schema_migrations') as $row) {
    $applied[$row['version']] = true;
}
$files = glob(__DIR__ . '/migrations/*.sql') ?: [];
sort($files);
foreach ($files as $file) {
    $version = basename($file);
    if (isset($applied[$version])) {
        continue;
    }
    echo "applying migration {$version} ...\n";
    apply_sql_file($pdo, $file);
    $pdo->prepare('INSERT INTO schema_migrations (version, checksum) VALUES (?, ?)')
        ->execute([$version, hash('sha256', (string) file_get_contents($file))]);
}

/* 4. catalog + demo parcels */
$partners = (int) $pdo->query('SELECT COUNT(*) FROM partners')->fetchColumn();
if ($partners === 0) {
    echo "seeding courier catalog + demo parcels ...\n";
    apply_sql_file($pdo, __DIR__ . '/seed.sql');
}

/* 5. SaaS seed (plans, platform admin, demo workspaces) */
$plans = (int) $pdo->query('SELECT COUNT(*) FROM plans')->fetchColumn();
if ($plans === 0) {
    echo "seeding plans / demo workspaces ...\n";
    require __DIR__ . '/seed_saas.php';
}

echo "install complete.\n";
echo "health: /api/health.php\n";
