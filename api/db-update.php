<?php
/**
 * Protected web schema updater.
 *
 * GET  /api/db-update.php            → status + confirmation form
 * GET  ?download=1                   → download pending migration SQL
 * POST /api/db-update.php            → apply pending migrations
 *
 * Requires a logged-in platform admin. Only pending migrations recorded in
 * schema_migrations are applied; no tables or existing rows are dropped.
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$admin = require_platform_admin();
$pdo = db();
$dir = dirname(__DIR__) . '/_server/db/migrations';
$files = glob($dir . '/*.sql') ?: [];
sort($files, SORT_STRING);

function db_update_applied(PDO $pdo): array
{
    try {
        $rows = $pdo->query('SELECT version, checksum FROM schema_migrations')->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $row) $out[(string) $row['version']] = (string) $row['checksum'];
    return $out;
}

$applied = db_update_applied($pdo);
$pending = [];
foreach ($files as $file) {
    $version = basename($file);
    if (!isset($applied[$version])) {
        $pending[] = ['version' => $version, 'file' => $file, 'sql' => (string) file_get_contents($file)];
    }
}
$hasCore = false;
try {
    $hasCore = (bool) $pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn();
} catch (Throwable $e) {}

$error = '';
$message = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();
    if ($hasCore && !$applied) {
        $error = 'schema_migrations is empty while the app schema already exists. Refusing to replay all migrations because that could corrupt the database. Restore schema_migrations from a backup first.';
    } elseif (!$pending) {
        $message = 'Database is already up to date.';
    } else {
        $destructive = [];
        foreach ($pending as $item) {
            $sqlForCheck = preg_replace('/^\s*--.*$/m', '', $item['sql']) ?? $item['sql'];
            if (preg_match('/\b(DELETE\s+FROM|TRUNCATE|DROP\s+TABLE|DROP\s+DATABASE|DROP\s+COLUMN|DROP\s+INDEX)\b/i', $sqlForCheck)) {
                $destructive[] = $item['version'];
            }
        }
        if ($destructive) {
            $error = 'Refusing to auto-apply migrations containing destructive statements: ' . implode(', ', $destructive) . '. Review/download the SQL and run it manually.';
        } else {
            $done = [];
            try {
                $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
                    version VARCHAR(120) PRIMARY KEY,
                    checksum CHAR(64) NOT NULL,
                    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB');
                foreach ($pending as $item) {
                    $clean = preg_replace('/^\s*--.*$/m', '', $item['sql']) ?? $item['sql'];
                    $statements = array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $clean)));
                    foreach ($statements as $statement) {
                        if ($statement === '') continue;
                        $pdo->exec($statement);
                    }
                    $pdo->prepare('INSERT INTO schema_migrations (version, checksum) VALUES (?, ?)')
                        ->execute([$item['version'], hash('sha256', $item['sql'])]);
                    $done[] = $item['version'];
                }
                $message = 'Applied ' . count($done) . ' migration(s): ' . implode(', ', $done);
                $applied = db_update_applied($pdo);
                $pending = [];
            } catch (Throwable $e) {
                $error = 'Migration failed: ' . $e->getMessage() . '. Applied before failure: ' . (empty($done) ? 'none' : implode(', ', $done));
            }
        }
    }
}

if (isset($_GET['download'])) {
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="mzigo-db-update-' . date('Ymd-His') . '.sql"');
    header('Cache-Control: no-store');
    echo "-- Mzigo.online pending schema migrations\n";
    echo "-- Generated " . date('c') . "\n";
    echo "-- Review before importing. This file does not drop existing tables.\n\n";
    foreach ($pending as $item) {
        echo "-- ===== " . $item['version'] . " =====\n" . $item['sql'] . "\n\n";
    }
    exit;
}

$e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mzigo database update</title>
<style>body{font-family:system-ui,sans-serif;max-width:900px;margin:40px auto;padding:0 20px;color:#1d2a13;background:#f7fbf0}.card{background:#fff;border:1px solid #d7eadb;border-radius:14px;padding:20px;margin-bottom:16px}code{background:#f2f6ee;padding:2px 5px;border-radius:4px}table{width:100%;border-collapse:collapse}td,th{text-align:left;padding:7px;border-bottom:1px solid #e6efe3}.err{background:#fff1f0;border-color:#f5c2c0}.ok{background:#f0fff4;border-color:#c6f6d5}.btn{background:#3c5223;color:#fff;border:0;border-radius:999px;padding:11px 18px;font-weight:700;cursor:pointer}.muted{color:#63705a;font-size:13px}</style>
</head>
<body>
<h1>Mzigo database update</h1>
<p class="muted">Signed in as <?= $e($admin['email'] ?? $admin['name'] ?? 'admin') ?>. Only pending migrations are applied; existing tables and data are not deleted.</p>
<?php if ($error): ?><div class="card err"><strong>Not applied:</strong><br><?= $e($error) ?></div><?php endif; ?>
<?php if ($message): ?><div class="card ok"><?= $e($message) ?></div><?php endif; ?>
<div class="card">
  <h2>Status</h2>
  <p>Applied migrations: <strong><?= count($applied) ?></strong><br>Pending migrations: <strong><?= count($pending) ?></strong></p>
  <?php if ($pending): ?>
    <ul><?php foreach ($pending as $item): ?><li><code><?= $e($item['version']) ?></code></li><?php endforeach; ?></ul>
    <?php if (!$hasCore || $applied): ?>
      <form method="post" onsubmit="return confirm('Apply the pending migrations now? Take a database backup first.');">
        <input type="hidden" name="_csrf" value="<?= $e(csrf_token()) ?>">
        <button class="btn" type="submit">Apply pending migrations</button>
        <a class="btn" style="background:#63705a;text-decoration:none;margin-left:8px" href="?download=1">Download SQL</a>
      </form>
    <?php endif; ?>
  <?php else: ?>
    <p>The database is up to date.</p>
    <a class="btn" style="background:#63705a;text-decoration:none" href="?download=1">Download current pending SQL</a>
  <?php endif; ?>
</div>
<div class="card">
  <h2>Safety</h2>
  <ul>
    <li>Automatic apply is blocked if pending migrations contain DELETE, TRUNCATE, DROP TABLE, DROP COLUMN or DROP INDEX.</li>
    <li>Take a cPanel database backup before applying.</li>
    <li>This page requires a platform-admin session and POST + CSRF.</li>
    <li>Delete or restrict this endpoint after the update if you do not want it available.</li>
  </ul>
</div>
</body></html>
