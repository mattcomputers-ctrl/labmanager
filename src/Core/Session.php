<?php
declare(strict_types=1);

namespace Chroma\Core;

/**
 * Database-backed PHP session handler (chroma_sessions) so sessions survive
 * PHP-FPM restarts and can be listed/invalidated per user by an admin.
 */
final class DbSessionHandler implements \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface
{
    public function __construct(private readonly Db $db, private readonly int $lifetime)
    {
    }

    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }

    public function read(string $id): string
    {
        $row = $this->db->one('SELECT data FROM chroma_sessions WHERE id = ? AND expires_at > UTC_TIMESTAMP()', [$id]);
        return $row ? (string) $row['data'] : '';
    }

    public function write(string $id, string $data): bool
    {
        $userId = null;
        if (preg_match('/user_id\|i:(\d+);/', $data, $m)) {
            $userId = (int) $m[1];
        }
        $expires = gmdate('Y-m-d H:i:s', time() + $this->lifetime);
        $this->db->run(
            'INSERT INTO chroma_sessions (id, user_id, data, ip, user_agent, created_at, last_seen_at, expires_at)
             VALUES (:id, :uid, :data, :ip, :ua, UTC_TIMESTAMP(), UTC_TIMESTAMP(), :exp)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), data = VALUES(data), ip = VALUES(ip),
                user_agent = VALUES(user_agent), last_seen_at = UTC_TIMESTAMP(), expires_at = VALUES(expires_at)',
            [
                'id' => $id,
                'uid' => $userId,
                'data' => $data,
                'ip' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                'ua' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                'exp' => $expires,
            ]
        );
        return true;
    }

    public function destroy(string $id): bool
    {
        $this->db->exec('DELETE FROM chroma_sessions WHERE id = ?', [$id]);
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return $this->db->exec('DELETE FROM chroma_sessions WHERE expires_at < UTC_TIMESTAMP()');
    }

    public function validateId(string $id): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9,-]{22,250}$/', $id);
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        $expires = gmdate('Y-m-d H:i:s', time() + $this->lifetime);
        $this->db->exec('UPDATE chroma_sessions SET last_seen_at = UTC_TIMESTAMP(), expires_at = ? WHERE id = ?', [$expires, $id]);
        return true;
    }
}

final class Session
{
    private bool $started = false;

    public function __construct(private readonly Db $db, private readonly bool $secure = false, private readonly int $lifetime = 43200)
    {
    }

    public function start(): void
    {
        if ($this->started || PHP_SAPI === 'cli') {
            $this->started = true;
            if (!isset($_SESSION)) {
                $_SESSION = [];
            }
            return;
        }
        session_set_save_handler(new DbSessionHandler($this->db, $this->lifetime), true);
        session_name('chroma_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $this->secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', (string) $this->lifetime);
        session_start();
        $this->started = true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $v = $_SESSION[$key] ?? $default;
        unset($_SESSION[$key]);
        return $v;
    }

    public function regenerate(): void
    {
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function destroy(): void
    {
        $_SESSION = [];
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public function id(): string
    {
        return PHP_SAPI === 'cli' ? 'cli' : session_id();
    }
}
