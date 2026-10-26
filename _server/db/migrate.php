<?php
/**
 * Mzigo.online — CLI migration runner.
 *
 *   php _server\db\migrate.php            # apply pending migrations
 *   php _server\db\migrate.php --status   # show applied/pending
 *
 * Migrations live in _server/db/migrations/*.sql (sorted by filename) and are
 * recorded in schema_migrations with a checksum so edits are detected.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("CLI only\n");
}

$config = require dirname(__DIR__) . '/config.php';
$db = $config['db'];

try {
    $pdo = new PDO($db['dsn'], $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "Cannot connect to database: {$e->getMessage()}\n");
    exit(1);
}

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        version    VARCHAR(120) PRIMARY KEY,
        checksum   CHAR(64) NOT NULL,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB'
);

$dir = __DIR__ . '/migrations';
$files = glob($dir . '/*.sql') ?: [];
sort($files, SORT_STRING);

$statusOnly = in_array('--status', $argv, true);

$applied = [];
foreach ($pdo->query('SELECT version, checksum FROM schema_migrations') as $row) {
    $applied[$row['version']] = $row['checksum'];
}

$pending = 0;
foreach ($files as $file) {
    $version = basename($file);
    $sql = file_get_contents($file);
    $checksum = hash('sha256', $sql);
    if (isset($applied[$version])) {
        if ($applied[$version] !== $checksum) {
            fwrite(STDERR, "WARNING: {$version} changed since it was applied (checksum mismatch)\n");
        }
        if ($statusOnly) {
            echo "applied  {$version}\n";
        }
        continue;
    }
    $pending++;
    if ($statusOnly) {
        echo "pending  {$version}\n";
        continue;
    }
    echo "applying {$version} ... ";
    try {
        // Strip line comments, then split on statement terminators. The migrations
        // contain no ";" inside strings or comments.
        $clean = preg_replace('/^\s*--.*$/m', '', $sql);
        $statements = array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $clean)));
        foreach ($statements as $statement) {
            if ($statement === '') {
                continue;
            }
            try {
                $pdo->exec($statement);
            } catch (Throwable $stmtError) {
                fwrite(STDERR, "  statement failed: " . substr($statement, 0, 90) . " ...\n");
                throw $stmtError;
            }
        }
        $stmt = $pdo->prepare('INSERT INTO schema_migrations (version, checksum) VALUES (?, ?)');
        $stmt->execute([$version, $checksum]);
        echo "ok\n";
    } catch (Throwable $e) {
        echo "FAILED\n";
        fwrite(STDERR, "Migration {$version} failed: {$e->getMessage()}\n");
        exit(1);
    }
}

if ($statusOnly) {
    echo "\n" . ($pending === 0 ? 'Database is up to date.' : $pending . ' migration(s) pending.') . "\n";
} else {
    echo $pending === 0 ? "Nothing to migrate.\n" : "Applied {$pending} migration(s).\n";
}
