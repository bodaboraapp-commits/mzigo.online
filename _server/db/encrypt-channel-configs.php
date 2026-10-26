<?php
declare(strict_types=1);
/** Encrypt legacy plaintext provider configs in communication_channels. */
$config = require __DIR__ . '/../config.php';
$db = $config['db'];
$pdo = new PDO($db['dsn'], $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
require __DIR__ . '/../../api/_crypto.php';
$rows = $pdo->query('SELECT code, config FROM communication_channels WHERE config IS NOT NULL AND config <> ""')->fetchAll(PDO::FETCH_ASSOC);
$updated = 0;
$update = $pdo->prepare('UPDATE communication_channels SET config = ? WHERE code = ?');
foreach ($rows as $row) {
    if (str_starts_with((string) $row['config'], 'enc:v1:')) { continue; }
    $decoded = json_decode((string) $row['config'], true);
    if (!is_array($decoded)) { continue; }
    $update->execute([channel_config_encode($decoded), $row['code']]);
    $updated++;
}
echo "encrypted {$updated} channel config(s)\n";
