<?php
declare(strict_types=1);

namespace Chroma\Core;

final class Auth
{
    /** @var array<string,mixed>|null|false */
    private array|null|false $user = false;
    /** @var array<string,true>|null */
    private ?array $perms = null;
    /** @var list<string>|null */
    private ?array $groups = null;

    public const MAX_ATTEMPTS = 6;
    public const LOCK_MINUTES = 15;

    public function __construct(private readonly Db $db, private readonly Session $session)
    {
    }

    /** @return array<string,mixed>|null */
    public function user(): ?array
    {
        if ($this->user === false) {
            $id = $this->session->get('user_id');
            $this->user = null;
            if (is_int($id) && $id > 0) {
                $u = $this->db->one('SELECT * FROM chroma_users WHERE id = ? AND active = 1', [$id]);
                $this->user = $u ?: null;
                if ($this->user === null) {
                    $this->session->forget('user_id');
                }
            }
        }
        return $this->user;
    }

    /** Impersonate a user for CLI tools and tests (no session cookie). */
    public function actAs(int $userId): void
    {
        $this->session->set('user_id', $userId);
        $this->user = false;
        $this->perms = null;
        $this->groups = null;
    }

    public function id(): ?int
    {
        $u = $this->user();
        return $u ? (int) $u['id'] : null;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function displayName(): string
    {
        $u = $this->user();
        return $u ? (string) ($u['display_name'] ?: $u['username']) : 'Someone';
    }

    /** @return list<string> */
    public function groups(): array
    {
        if ($this->groups === null) {
            $id = $this->id();
            $this->groups = $id === null ? [] : array_map('strval', $this->db->column(
                'SELECT g.name FROM chroma_groups g JOIN chroma_user_groups ug ON ug.group_id = g.id WHERE ug.user_id = ? ORDER BY g.name',
                [$id]
            ));
        }
        return $this->groups;
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return array_keys($this->permissionMap());
    }

    /** @return array<string,true> */
    private function permissionMap(): array
    {
        if ($this->perms === null) {
            $id = $this->id();
            $this->perms = [];
            if ($id !== null) {
                $keys = $this->db->column(
                    'SELECT DISTINCT gp.permission FROM chroma_group_permissions gp
                     JOIN chroma_user_groups ug ON ug.group_id = gp.group_id WHERE ug.user_id = ?',
                    [$id]
                );
                foreach ($keys as $k) {
                    $this->perms[(string) $k] = true;
                }
            }
        }
        return $this->perms;
    }

    public function can(string $permission): bool
    {
        return isset($this->permissionMap()[$permission]);
    }

    public function canAny(string ...$permissions): bool
    {
        foreach ($permissions as $p) {
            if ($this->can($p)) {
                return true;
            }
        }
        return false;
    }

    public function isAdmin(): bool
    {
        return in_array('Administrators', $this->groups(), true);
    }

    public function requirePermission(string ...$permissions): void
    {
        foreach ($permissions as $p) {
            if (!$this->can($p)) {
                throw new ForbiddenException();
            }
        }
    }

    /**
     * Try to log in. Returns null on success or a plain-language message.
     * Rate-limited per username and per IP address.
     */
    public function attempt(string $username, string $password, string $ip): ?string
    {
        $username = trim($username);
        $window = gmdate('Y-m-d H:i:s', time() - self::LOCK_MINUTES * 60);
        $recent = (int) $this->db->value(
            'SELECT COUNT(*) FROM chroma_login_attempts WHERE (username = ? OR ip = ?) AND succeeded = 0 AND attempted_at > ?',
            [$username, $ip, $window]
        );
        if ($recent >= self::MAX_ATTEMPTS) {
            return sprintf('Too many sign-in attempts. Please wait %d minutes and try again.', self::LOCK_MINUTES);
        }
        $user = $this->db->one('SELECT * FROM chroma_users WHERE username = ?', [$username]);
        $ok = $user && (int) $user['active'] === 1 && password_verify($password, (string) $user['password_hash']);
        $this->db->insert('chroma_login_attempts', [
            'username' => mb_substr($username, 0, 100),
            'ip' => substr($ip, 0, 45),
            'succeeded' => $ok ? 1 : 0,
            'attempted_at' => $this->db->now(),
        ]);
        if (!$ok) {
            return 'That username and password do not match. Check both and try again.';
        }
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_ARGON2ID)) {
            $this->db->update('chroma_users', ['password_hash' => self::hash($password)], ['id' => (int) $user['id']]);
        }
        $this->login((int) $user['id']);
        $this->db->exec('DELETE FROM chroma_login_attempts WHERE attempted_at < ?', [gmdate('Y-m-d H:i:s', time() - 86400)]);
        return null;
    }

    public function login(int $userId): void
    {
        $this->session->regenerate();
        $this->session->set('user_id', $userId);
        $this->db->update('chroma_users', ['last_login_at' => $this->db->now()], ['id' => $userId]);
        $this->user = false;
        $this->perms = null;
        $this->groups = null;
    }

    public function logout(): void
    {
        $this->session->destroy();
        $this->user = null;
        $this->perms = null;
        $this->groups = null;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID);
    }

    /** Password policy shared by the installer, admin screens and self-service. */
    public static function passwordProblem(string $password): ?string
    {
        if (mb_strlen($password) < 10) {
            return 'Use at least 10 characters. A short sentence works well.';
        }
        return null;
    }
}
