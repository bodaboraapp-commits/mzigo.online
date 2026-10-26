<?php
/**
 * Mzigo.online — DB session handler.
 *
 * Stores every PHP session (including the serialized payload) in the `sessions`
 * table, with user/tenant context, IP, user-agent, idle/absolute expiry and a
 * revocation flag. Admin "revoke session" marks `revoked_at`; the handler then
 * rejects that session id so the user is signed out on their next request.
 */
declare(strict_types=1);

final class DbSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    public function __construct(private PDO $pdo, private int $ttl)
    {
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $stmt = $this->pdo->prepare(
            'SELECT data FROM sessions WHERE id = ? AND revoked_at IS NULL AND expires_at > NOW() LIMIT 1'
        );
        $stmt->execute([$id]);
        $data = $stmt->fetchColumn();
        if ($data === false) {
            return '';
        }
        $this->touch($id);
        return (string) $data;
    }

    public function write(string $id, string $data): bool
    {
        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        $tenantId = isset($_SESSION['tenant_id']) ? (int) $_SESSION['tenant_id'] : null;
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $agent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $ttl = $this->ttl;
        $stmt = $this->pdo->prepare(
            'INSERT INTO sessions (id, user_id, tenant_id, ip, user_agent, data, created_at, last_seen_at, expires_at)
             VALUES (?, ?, ?, NULLIF(?, ""), NULLIF(?, ""), ?, NOW(), NOW(), DATE_ADD(NOW(), INTERVAL ? SECOND))
             ON DUPLICATE KEY UPDATE
               user_id = VALUES(user_id), tenant_id = VALUES(tenant_id), ip = VALUES(ip),
               user_agent = VALUES(user_agent), data = VALUES(data),
               last_seen_at = NOW(), expires_at = VALUES(expires_at), revoked_at = NULL'
        );
        return $stmt->execute([$id, $userId, $tenantId, $ip, $agent, $data, $ttl]);
    }

    public function destroy(string $id): bool
    {
        return $this->pdo->prepare('DELETE FROM sessions WHERE id = ?')->execute([$id]);
    }

    public function gc(int $max_lifetime): int|false
    {
        $stmt = $this->pdo->prepare('DELETE FROM sessions WHERE expires_at < NOW() OR (revoked_at IS NOT NULL AND revoked_at < DATE_SUB(NOW(), INTERVAL 1 DAY))');
        $stmt->execute();
        return $stmt->rowCount();
    }

    public function validateId(string $id): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM sessions WHERE id = ? AND revoked_at IS NULL AND expires_at > NOW() LIMIT 1'
        );
        $stmt->execute([$id]);
        return (bool) $stmt->fetchColumn();
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        return $this->touch($id);
    }

    private function touch(string $id): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE sessions SET last_seen_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND)
             WHERE id = ? AND revoked_at IS NULL'
        );
        return $stmt->execute([$this->ttl, $id]);
    }
}
