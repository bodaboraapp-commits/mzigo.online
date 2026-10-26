<?php
/** One-time 2FA recovery codes (hashed at rest). */
declare(strict_types=1);

function recovery_code_alphabet(): string
{
    return 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
}

function recovery_code_normalize(string $code): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
}

function recovery_codes_generate(int $userId, int $count = 8): array
{
    db()->prepare('DELETE FROM user_2fa_recovery_codes WHERE user_id = ?')->execute([$userId]);
    $alphabet = recovery_code_alphabet();
    $max = strlen($alphabet) - 1;
    $ins = db()->prepare('INSERT INTO user_2fa_recovery_codes (user_id, code_hash, created_at) VALUES (?, ?, NOW())');
    $plain = [];
    for ($i = 0; $i < $count; $i++) {
        $raw = '';
        for ($j = 0; $j < 10; $j++) {
            $raw .= $alphabet[random_int(0, $max)];
        }
        $plain[] = substr($raw, 0, 5) . '-' . substr($raw, 5);
        $ins->execute([$userId, hash('sha256', $raw)]);
    }
    return $plain;
}

function recovery_codes_remaining(int $userId): int
{
    try {
        $stmt = db()->prepare('SELECT COUNT(*) FROM user_2fa_recovery_codes WHERE user_id = ? AND used_at IS NULL');
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function recovery_code_consume(int $userId, string $code): bool
{
    $normalized = recovery_code_normalize($code);
    if (strlen($normalized) !== 10) { return false; }
    $hash = hash('sha256', $normalized);
    $stmt = db()->prepare('SELECT id FROM user_2fa_recovery_codes WHERE user_id = ? AND code_hash = ? AND used_at IS NULL LIMIT 1');
    $stmt->execute([$userId, $hash]);
    $id = (int) ($stmt->fetchColumn() ?: 0);
    if (!$id) { return false; }
    $update = db()->prepare('UPDATE user_2fa_recovery_codes SET used_at = NOW() WHERE id = ? AND used_at IS NULL');
    $update->execute([$id]);
    return $update->rowCount() > 0;
}
