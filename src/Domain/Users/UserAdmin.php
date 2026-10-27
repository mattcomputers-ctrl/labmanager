<?php
declare(strict_types=1);

namespace Chroma\Domain\Users;

use Chroma\Core\Auth;
use Chroma\Core\Db;
use Chroma\Core\ValidationException;
use Chroma\Core\Validator;
use Chroma\Domain\Audit\AuditLog;

/**
 * Creating, editing, deactivating users and resetting passwords. Both the
 * Easy-mode wizard and the Expert-mode form call these methods, so every
 * rule lives here and nowhere else.
 *
 * Protections (SPEC §6):
 *  - only an Administrator may add someone to, remove someone from, or
 *    deactivate a member of the Administrators group;
 *  - the last active Administrator can never be deactivated or removed;
 *  - nobody can deactivate themselves.
 *
 * Password hashes are never written to the audit log (AuditLog strips them).
 */
final class UserAdmin
{
    public const ADMIN_GROUP = 'Administrators';

    public function __construct(
        private readonly Db $db,
        private readonly Auth $auth,
        private readonly AuditLog $audit,
    ) {
    }

    // ------------------------------------------------------------------ reads

    /**
     * Paged list with group names. Filters: q (username/name prefix), status (active|inactive|all), group_id.
     * @param array<string,mixed> $filters
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function list(array $filters, int $limit = 50, int $offset = 0): array
    {
        $where = [];
        $params = [];
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(u.username LIKE ? OR u.display_name LIKE ? OR u.email LIKE ?)';
            $params[] = $q . '%';
            $params[] = $q . '%';
            $params[] = $q . '%';
        }
        $status = (string) ($filters['status'] ?? 'active');
        if ($status === 'active') {
            $where[] = 'u.active = 1';
        } elseif ($status === 'inactive') {
            $where[] = 'u.active = 0';
        }
        if (!empty($filters['group_id'])) {
            $where[] = 'EXISTS (SELECT 1 FROM chroma_user_groups f WHERE f.user_id = u.id AND f.group_id = ?)';
            $params[] = (int) $filters['group_id'];
        }
        $sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $total = (int) $this->db->value('SELECT COUNT(*) FROM chroma_users u' . $sql, $params);
        $rows = $this->db->all(
            'SELECT u.id, u.username, u.display_name, u.email, u.initials, u.active, u.must_change_password, u.last_login_at, u.created_at,
                    (SELECT GROUP_CONCAT(g.name ORDER BY g.name SEPARATOR ", ") FROM chroma_groups g JOIN chroma_user_groups ug ON ug.group_id = g.id WHERE ug.user_id = u.id) AS groups_text
             FROM chroma_users u' . $sql . ' ORDER BY u.active DESC, u.username LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset,
            $params
        );
        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array<string,mixed>|null user row (without password hash) plus group_ids and group_names */
    public function find(int $id): ?array
    {
        $u = $this->db->one('SELECT * FROM chroma_users WHERE id = ?', [$id]);
        if (!$u) {
            return null;
        }
        unset($u['password_hash']);
        $groups = $this->db->all('SELECT g.id, g.name FROM chroma_groups g JOIN chroma_user_groups ug ON ug.group_id = g.id WHERE ug.user_id = ? ORDER BY g.name', [$id]);
        $u['group_ids'] = array_map(fn($g) => (int) $g['id'], $groups);
        $u['group_names'] = array_map(fn($g) => (string) $g['name'], $groups);
        return $u;
    }

    /** @return list<array<string,mixed>> id, name, description, is_system */
    public function groups(): array
    {
        return $this->db->all('SELECT id, name, description, is_system FROM chroma_groups ORDER BY is_system DESC, name');
    }

    public function adminGroupId(): int
    {
        return (int) $this->db->value('SELECT id FROM chroma_groups WHERE name = ?', [self::ADMIN_GROUP]);
    }

    public function isAdministrator(int $userId): bool
    {
        return (bool) $this->db->value(
            'SELECT COUNT(*) FROM chroma_user_groups ug JOIN chroma_groups g ON g.id = ug.group_id WHERE ug.user_id = ? AND g.name = ?',
            [$userId, self::ADMIN_GROUP]
        );
    }

    public function activeAdministratorCount(): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM chroma_user_groups ug JOIN chroma_groups g ON g.id = ug.group_id JOIN chroma_users u ON u.id = ug.user_id
             WHERE g.name = ? AND u.active = 1',
            [self::ADMIN_GROUP]
        );
    }

    // ----------------------------------------------------------------- writes

    /**
     * Create a user. Input: username, display_name, email, initials, password (optional: generated when blank),
     * password_confirm (required when password typed), group_ids (list). New users must change their password at first sign-in.
     * @param array<string,mixed> $input
     * @return array{id:int,password:string}
     */
    public function create(array $input): array
    {
        $v = new Validator($input);
        $v->required('username', 'Choose a username. Short and lowercase works best, for example "jsmith".')
            ->maxLength('username', 100)
            ->regex('username', '/^[a-z0-9][a-z0-9._-]*$/i', 'Usernames can only contain letters, numbers, dots, dashes and underscores, with no spaces.');
        $v->required('display_name', 'Enter the name people will see, for example "Jane Smith".')->maxLength('display_name', 150);
        $v->maxLength('email', 190)->custom('email', fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL) !== false, 'That does not look like an email address. Check it or leave it blank.');
        $v->maxLength('initials', 5, 'Initials are at most 5 letters.');
        $v->fail();

        $username = mb_strtolower(trim((string) $input['username']));
        if ($this->db->value('SELECT id FROM chroma_users WHERE username = ?', [$username]) !== null) {
            throw ValidationException::single('username', "The username \"$username\" is already taken. Pick a different one.");
        }

        $password = $this->passwordFromInput($input, true);
        $groupIds = $this->cleanGroupIds($input['group_ids'] ?? []);
        $this->assertMayChangeAdminMembership($groupIds, []);

        $now = $this->db->now();
        $id = $this->db->transaction(function () use ($username, $input, $password, $groupIds, $now) {
            $id = $this->db->insert('chroma_users', [
                'username' => $username,
                'display_name' => trim((string) $input['display_name']),
                'email' => trim((string) ($input['email'] ?? '')) ?: null,
                'initials' => mb_strtoupper(trim((string) ($input['initials'] ?? ''))) ?: null,
                'password_hash' => Auth::hash($password),
                'active' => 1,
                'must_change_password' => 1,
                'ui_mode' => 'easy',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->setGroups($id, $groupIds);
            $after = $this->find($id);
            $this->audit->record('user.create', 'chroma_users', $id, null, $after, 'Created user ' . $username . ' in ' . ($after['group_names'] ? implode(', ', $after['group_names']) : 'no groups'));
            return $id;
        });
        return ['id' => $id, 'password' => $password];
    }

    /**
     * Edit name, email, initials, active flag and groups.
     * @param array<string,mixed> $input display_name, email, initials, active (optional), group_ids
     */
    public function update(int $id, array $input): void
    {
        $before = $this->find($id);
        if ($before === null) {
            throw ValidationException::general('That user no longer exists.');
        }
        $v = new Validator($input);
        $v->required('display_name', 'Enter the name people will see.')->maxLength('display_name', 150);
        $v->maxLength('email', 190)->custom('email', fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL) !== false, 'That does not look like an email address. Check it or leave it blank.');
        $v->maxLength('initials', 5, 'Initials are at most 5 letters.');
        $v->fail();

        $groupIds = array_key_exists('group_ids', $input) ? $this->cleanGroupIds($input['group_ids']) : $before['group_ids'];
        $this->assertMayChangeAdminMembership($groupIds, $before['group_ids'], $id);

        $data = [
            'display_name' => trim((string) $input['display_name']),
            'email' => trim((string) ($input['email'] ?? '')) ?: null,
            'initials' => mb_strtoupper(trim((string) ($input['initials'] ?? ''))) ?: null,
            'updated_at' => $this->db->now(),
        ];
        if (array_key_exists('active', $input)) {
            $wantActive = (int) ((string) $input['active'] === '1' || $input['active'] === true || $input['active'] === 1);
            if ($wantActive !== (int) $before['active']) {
                if ($wantActive === 0) {
                    $this->assertMayDeactivate($id);
                }
                $data['active'] = $wantActive;
            }
        }
        $this->db->transaction(function () use ($id, $data, $groupIds, $before) {
            $this->db->update('chroma_users', $data, ['id' => $id]);
            $this->setGroups($id, $groupIds);
            $after = $this->find($id);
            $this->audit->record('user.update', 'chroma_users', $id, $before, $after, 'Edited user ' . $before['username']);
            if (isset($data['active']) && $data['active'] === 0) {
                $this->db->exec('DELETE FROM chroma_sessions WHERE user_id = ?', [$id]);
            }
        });
    }

    /**
     * Set a temporary password (typed twice, or generated when blank) and force a change at next sign-in.
     * @param array<string,mixed> $input password, password_confirm
     * @return string the temporary password, to show once
     */
    public function resetPassword(int $id, array $input = []): string
    {
        $user = $this->find($id);
        if ($user === null) {
            throw ValidationException::general('That user no longer exists.');
        }
        if ((int) $user['active'] !== 1) {
            throw ValidationException::general('This account is deactivated. Reactivate it first, then reset the password.');
        }
        $password = $this->passwordFromInput($input, true);
        $this->db->transaction(function () use ($id, $password, $user) {
            $this->db->update('chroma_users', ['password_hash' => Auth::hash($password), 'must_change_password' => 1, 'updated_at' => $this->db->now()], ['id' => $id]);
            // Sign the user out everywhere except the person doing the reset (if resetting their own).
            $this->db->exec('DELETE FROM chroma_sessions WHERE user_id = ? AND id <> ?', [$id, $this->currentSessionId()]);
            $this->audit->record('user.reset_password', 'chroma_users', $id, null, ['must_change_password' => 1], 'Set a temporary password for ' . $user['username']);
        });
        return $password;
    }

    public function deactivate(int $id): void
    {
        $user = $this->find($id);
        if ($user === null) {
            throw ValidationException::general('That user no longer exists.');
        }
        if ((int) $user['active'] === 0) {
            return;
        }
        $this->assertMayDeactivate($id);
        $this->db->transaction(function () use ($id, $user) {
            $this->db->update('chroma_users', ['active' => 0, 'updated_at' => $this->db->now()], ['id' => $id]);
            $this->db->exec('DELETE FROM chroma_sessions WHERE user_id = ?', [$id]);
            $this->audit->record('user.deactivate', 'chroma_users', $id, ['active' => 1], ['active' => 0], 'Deactivated ' . $user['username']);
        });
    }

    public function reactivate(int $id): void
    {
        $user = $this->find($id);
        if ($user === null) {
            throw ValidationException::general('That user no longer exists.');
        }
        if ((int) $user['active'] === 1) {
            return;
        }
        $this->db->update('chroma_users', ['active' => 1, 'updated_at' => $this->db->now()], ['id' => $id]);
        $this->audit->record('user.reactivate', 'chroma_users', $id, ['active' => 0], ['active' => 1], 'Reactivated ' . $user['username']);
    }

    // ------------------------------------------------------------------ rules

    /** Throws with a plain explanation when the acting user may not deactivate this account. */
    public function assertMayDeactivate(int $id): void
    {
        if ($id === $this->auth->id()) {
            throw ValidationException::general('You cannot deactivate your own account. Ask another administrator to do it.');
        }
        if ($this->isAdministrator($id)) {
            if (!$this->auth->isAdmin()) {
                throw ValidationException::general('Only an Administrator can deactivate another Administrator.');
            }
            if ($this->activeAdministratorCount() <= 1) {
                throw ValidationException::general('This is the last active Administrator, so the account cannot be deactivated. Make someone else an Administrator first.');
            }
        }
    }

    /**
     * Compare the wanted group list with the current one and refuse Administrators changes the actor may not make.
     * @param list<int> $wanted
     * @param list<int> $current
     */
    public function assertMayChangeAdminMembership(array $wanted, array $current, ?int $userId = null): void
    {
        $adminId = $this->adminGroupId();
        $adding = in_array($adminId, $wanted, true) && !in_array($adminId, $current, true);
        $removing = !in_array($adminId, $wanted, true) && in_array($adminId, $current, true);
        if (($adding || $removing) && !$this->auth->isAdmin()) {
            throw ValidationException::single('group_ids', $adding
                ? 'Only an Administrator can make someone an Administrator.'
                : 'Only an Administrator can remove someone from the Administrators group.');
        }
        if ($removing && $userId !== null) {
            $isActive = (int) $this->db->value('SELECT active FROM chroma_users WHERE id = ?', [$userId]) === 1;
            if ($isActive && $this->activeAdministratorCount() <= 1) {
                throw ValidationException::single('group_ids', 'This is the last active Administrator, so they cannot leave the Administrators group. Make someone else an Administrator first.');
            }
            if ($userId === (int) $this->auth->id()) {
                // Leaving the group yourself would lock you out of every admin screen the moment you press Save.
                throw ValidationException::single('group_ids', 'You cannot remove yourself from the Administrators group. Ask another Administrator to do it.');
            }
        }
    }

    // ---------------------------------------------------------------- helpers

    /** A memorable temporary password: three words and a number, e.g. "Amber-Harbor-Pilot-42". */
    public static function generatePassword(): string
    {
        $words = ['amber', 'birch', 'cedar', 'delta', 'ember', 'fable', 'grove', 'harbor', 'ivory', 'jasper', 'kestrel', 'lumen',
            'maple', 'north', 'ochre', 'pilot', 'quartz', 'river', 'sable', 'tulip', 'umber', 'violet', 'willow', 'yarrow', 'zephyr',
            'cobalt', 'indigo', 'saffron', 'sienna', 'slate', 'coral', 'onyx', 'pearl', 'ruby', 'topaz', 'opal', 'olive', 'rust', 'teal',
            'crimson', 'magenta', 'scarlet', 'lemon', 'mint', 'plum', 'peach', 'rose', 'sand', 'moss', 'pine'];
        $pick = [];
        for ($i = 0; $i < 3; $i++) {
            $pick[] = ucfirst($words[random_int(0, count($words) - 1)]);
        }
        return implode('-', $pick) . '-' . random_int(10, 99);
    }

    /** @param array<string,mixed> $input */
    private function passwordFromInput(array $input, bool $allowGenerate): string
    {
        $typed = (string) ($input['password'] ?? '');
        $mode = (string) ($input['password_mode'] ?? ($typed === '' ? 'generate' : 'typed'));
        if ($mode === 'generate' && $allowGenerate) {
            return self::generatePassword();
        }
        if ($p = Auth::passwordProblem($typed)) {
            throw ValidationException::single('password', $p);
        }
        if (array_key_exists('password_confirm', $input) && (string) $input['password_confirm'] !== $typed) {
            throw ValidationException::single('password_confirm', 'The two passwords do not match. Type the same password in both boxes.');
        }
        return $typed;
    }

    /** @param mixed $ids @return list<int> */
    private function cleanGroupIds(mixed $ids): array
    {
        if (!is_array($ids)) {
            return [];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $valid = array_map('intval', $this->db->column('SELECT id FROM chroma_groups WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids));
        return array_values(array_intersect($ids, $valid));
    }

    /** @param list<int> $groupIds */
    private function setGroups(int $userId, array $groupIds): void
    {
        $this->db->exec('DELETE FROM chroma_user_groups WHERE user_id = ?', [$userId]);
        foreach ($groupIds as $gid) {
            $this->db->run('INSERT IGNORE INTO chroma_user_groups (user_id, group_id) VALUES (?, ?)', [$userId, $gid]);
        }
    }

    private function currentSessionId(): string
    {
        return PHP_SAPI === 'cli' ? '' : (session_id() ?: '');
    }
}
