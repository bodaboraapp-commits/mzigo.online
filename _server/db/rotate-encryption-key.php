<?php
/**
 * Encryption-key rotation tool (CLI only).
 *
 *   php _server/db/rotate-encryption-key.php --new-key="<new secret>" --apply
 *   php _server/db/rotate-encryption-key.php --new-key="<new secret>" --apply --update-key
 *   php _server/db/rotate-encryption-key.php --new-key="<new secret>" --apply --code=email
 *
 * Re-encrypts encrypted communication-channel configs and webhook secrets from
 * the current key (or --old-key) to --new-key. Without --apply it is a dry run.
 * --update-key also rewrites _server/config-key.php (refused when APP_CONFIG_KEY
 * is set, because the environment key would override the file).
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit("CLI only\n"); }

$config = require __DIR__ . '/../config.php';
$db = $config['db'];
$pdo = new PDO($db['dsn'], $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
require __DIR__ . '/../../api/_crypto.php';

$options = getopt('', ['old-key::', 'new-key:', 'apply', 'update-key', 'code::', 'encrypt-plaintext']);
$oldKey = (string) ($options['old-key'] ?? (getenv('APP_CONFIG_KEY') ?: (string) ($config['app']['config_key'] ?? '')));
$newKey = (string) ($options['new-key'] ?? '');
$apply = array_key_exists('apply', $options);
$updateKey = array_key_exists('update-key', $options);
$onlyCode = isset($options['code']) ? trim((string) $options['code']) : '';
$encryptPlaintext = array_key_exists('encrypt-plaintext', $options);

if (strlen($newKey) < 32) {
    fwrite(STDERR, "Error: --new-key must be at least 32 characters.\n");
    exit(1);
}
if ($oldKey === '') {
    fwrite(STDERR, "Error: no old key available (set APP_CONFIG_KEY or pass --old-key).\n");
    exit(1);
}
if (hash_equals($oldKey, $newKey)) {
    fwrite(STDERR, "Error: the new key is identical to the old key.\n");
    exit(1);
}
if ($updateKey && getenv('APP_CONFIG_KEY')) {
    fwrite(STDERR, "Error: APP_CONFIG_KEY is set; update the environment secret manually instead of --update-key.\n");
    exit(1);
}

$channelSql = 'SELECT code, config FROM communication_channels';
$channelParams = [];
if ($onlyCode !== '') {
    $channelSql .= ' WHERE code = ?';
    $channelParams[] = $onlyCode;
}
$channels = $pdo->prepare($channelSql);
$channels->execute($channelParams);
$channelRows = $channels->fetchAll(PDO::FETCH_ASSOC);

$webhookRows = [];
if ($onlyCode === '') {
    $webhookRows = $pdo->query('SELECT id, secret FROM webhooks')->fetchAll(PDO::FETCH_ASSOC);
}

$stats = ['channels' => 0, 'webhooks' => 0, 'plaintext' => 0, 'errors' => 0, 'skipped' => 0];
$updatesChannel = $pdo->prepare('UPDATE communication_channels SET config = ? WHERE code = ?');
$updatesWebhook = $pdo->prepare('UPDATE webhooks SET secret = ? WHERE id = ?');

$pdo->beginTransaction();
try {
    foreach ($channelRows as $row) {
        $value = (string) ($row['config'] ?? '');
        if ($value === '') { continue; }
        $isEncrypted = str_starts_with($value, 'enc:v1:');
        if (!$isEncrypted && !$encryptPlaintext) { $stats['skipped']++; continue; }
        $plain = $isEncrypted ? secure_value_decrypt_with_key($value, $oldKey) : $value;
        if ($plain === null) {
            $stats['errors']++;
            fwrite(STDERR, "Cannot decrypt channel {$row['code']} with the old key.\n");
            continue;
        }
        $newValue = secure_value_encrypt_with_key($plain, $newKey);
        if ($apply) { $updatesChannel->execute([$newValue, (string) $row['code']]); }
        $stats['channels']++;
        if (!$isEncrypted) { $stats['plaintext']++; }
    }
    foreach ($webhookRows as $row) {
        $value = (string) ($row['secret'] ?? '');
        if ($value === '') { continue; }
        $isEncrypted = str_starts_with($value, 'enc:v1:');
        if (!$isEncrypted && !$encryptPlaintext) { $stats['skipped']++; continue; }
        $plain = $isEncrypted ? secure_value_decrypt_with_key($value, $oldKey) : $value;
        if ($plain === null) {
            $stats['errors']++;
            fwrite(STDERR, "Cannot decrypt webhook #{$row['id']} with the old key.\n");
            continue;
        }
        $newValue = secure_value_encrypt_with_key($plain, $newKey);
        if ($apply) { $updatesWebhook->execute([$newValue, (int) $row['id']]); }
        $stats['webhooks']++;
        if (!$isEncrypted) { $stats['plaintext']++; }
    }
    if ($apply) { $pdo->commit(); } else { $pdo->rollBack(); }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    fwrite(STDERR, 'Rotation failed: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($updateKey) {
    if (!$apply) {
        fwrite(STDERR, "Error: --update-key requires --apply.\n");
        exit(1);
    }
    $keyFile = __DIR__ . '/../config-key.php';
    $backup = $keyFile . '.bak.' . date('YmdHis');
    if (is_file($keyFile)) { @copy($keyFile, $backup); @chmod($backup, 0600); }
    $contents = "<?php\n/**\n * Mzigo.online application key (local secret).\n * Used to encrypt provider credentials at rest in MySQL.\n * Keep this file outside version control in production and deny web access to _server.\n */\nreturn " . var_export($newKey, true) . ";\n";
    $tmp = $keyFile . '.tmp';
    if (@file_put_contents($tmp, $contents) === false || !@rename($tmp, $keyFile)) {
        @unlink($tmp);
        fwrite(STDERR, "Rotation DB updates committed, but writing config-key.php failed. Update the key manually immediately.\n");
        exit(1);
    }
    @chmod($keyFile, 0600);
    fwrite(STDOUT, "Key file updated. Backup: " . basename($backup) . "\n");
}

fwrite(STDOUT, json_encode([
    'mode' => $apply ? 'applied' : 'dry-run',
    'channels' => $stats['channels'], 'webhooks' => $stats['webhooks'],
    'plaintext_encrypted' => $stats['plaintext'], 'skipped' => $stats['skipped'], 'errors' => $stats['errors'],
], JSON_PRETTY_PRINT) . "\n");
if ($stats['errors'] > 0) { exit(2); }
