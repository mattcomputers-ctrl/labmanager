<?php
declare(strict_types=1);

namespace Chroma\Domain\Users;

use Chroma\Core\Db;
use Chroma\Core\Permissions;
use Chroma\Core\ValidationException;
use Chroma\Core\Validator;
use Chroma\Domain\Audit\AuditLog;

/**
 * Groups and what each group may do. A user's effective permissions are
 * the union of their groups' (see Core\Auth). System groups (is_system)
 * can be edited but never deleted; the Administrators group always holds
 * every permission so nobody can lock the lab out of the admin screens.
 */
final class GroupAdmin
{
    public function __construct(
        private readonly Db $db,
        private readonly AuditLog $audit,
        private readonly UserAdmin $users,
    ) {
    }

    // ------------------------------------------------------------------ reads

    /** @return list<array<string,mixed>> with member_count and permission_count */
    public function list(): array
    {
        return $this->db->all(
            'SELECT g.*, (SELECT COUNT(*) FROM chroma_user_groups ug JOIN chroma_users u ON u.id = ug.user_id WHERE ug.group_id = g.id AND u.active = 1) AS member_count,
                    (SELECT COUNT(*) FROM chroma_group_permissions gp WHERE gp.group_id = g.id) AS permission_count
             FROM chroma_groups g ORDER BY g.is_system DESC, g.name'
        );
    }

    /** @return array<string,mixed>|null group + permissions (list of keys) + members (id, username, display_name, active) */
    public function find(int $id): ?array
    {
        $g = $this->db->one('SELECT * FROM chroma_groups WHERE id = ?', [$id]);
        if (!$g) {
            return null;
        }
        $g['permissions'] = array_map('strval', $this->db->column('SELECT permission FROM chroma_group_permissions WHERE group_id = ? ORDER BY permission', [$id]));
        $g['members'] = $this->db->all(
            'SELECT u.id, u.username, u.display_name, u.active FROM chroma_users u JOIN chroma_user_groups ug ON ug.user_id = u.id WHERE ug.group_id = ? ORDER BY u.active DESC, u.display_name, u.username',
            [$id]
        );
        return $g;
    }

    /** Permission catalogue grouped by area, for tick-box screens. @return array<string,array<string,array{label:string,help:string}>> */
    public static function catalogueByArea(): array
    {
        $out = [];
        foreach (Permissions::catalogue() as $key => $p) {
            $out[$p['area']][$key] = ['label' => $p['label'], 'help' => $p['help']];
        }
        return $out;
    }

    public function isAdministrators(array $group): bool
    {
        return ($group['name'] ?? '') === UserAdmin::ADMIN_GROUP;
    }

    // ----------------------------------------------------------------- writes

    /**
     * @param array<string,mixed> $input name, description, permissions (list of keys), member_ids (list)
     */
    public function create(array $input): int
    {
        $this->validateNameDescription($input);
        $name = trim((string) $input['name']);
        if ($this->db->value('SELECT id FROM chroma_groups WHERE name = ?', [$name]) !== null) {
            throw ValidationException::single('name', "There is already a group called \"$name\". Pick a different name.");
        }
        $perms = $this->cleanPermissions($input['permissions'] ?? []);
        $members = $this->cleanUserIds($input['member_ids'] ?? []);
        return $this->db->transaction(function () use ($name, $input, $perms, $members) {
            $id = $this->db->insert('chroma_groups', [
                'name' => $name,
                'description' => trim((string) ($input['description'] ?? '')),
                'is_system' => 0,
                'created_at' => $this->db->now(),
            ]);
            foreach ($perms as $p) {
                $this->db->run('INSERT IGNORE INTO chroma_group_permissions (group_id, permission) VALUES (?, ?)', [$id, $p]);
            }
            foreach ($members as $uid) {
                $this->db->run('INSERT IGNORE INTO chroma_user_groups (user_id, group_id) VALUES (?, ?)', [$uid, $id]);
            }
            $this->audit->record('group.create', 'chroma_groups', $id, null, $this->find($id), 'Created group ' . $name);
            return $id;
        });
    }

    /** Rename / re-describe. @param array<string,mixed> $input name, description */
    public function update(int $id, array $input): void
    {
        $before = $this->find($id);
        if ($before === null) {
            throw ValidationException::general('That group no longer exists.');
        }
        $this->validateNameDescription($input);
        $name = trim((string) $input['name']);
        if ($this->isAdministrators($before) && $name !== UserAdmin::ADMIN_GROUP) {
            throw ValidationException::single('name', 'The Administrators group cannot be renamed; Chroma relies on that name.');
        }
        $dup = $this->db->value('SELECT id FROM chroma_groups WHERE name = ? AND id <> ?', [$name, $id]);
        if ($dup !== null) {
            throw ValidationException::single('name', "There is already a group called \"$name\". Pick a different name.");
        }
        $this->db->update('chroma_groups', ['name' => $name, 'description' => trim((string) ($input['description'] ?? ''))], ['id' => $id]);
        $after = $this->find($id);
        $this->audit->record('group.update', 'chroma_groups', $id, ['name' => $before['name'], 'description' => $before['description']], ['name' => $after['name'], 'description' => $after['description']], 'Edited group ' . $name);
    }

    /** Replace the permission set. @param list<string> $permissions */
    public function setPermissions(int $id, array $permissions): void
    {
        $before = $this->find($id);
        if ($before === null) {
            throw ValidationException::general('That group no longer exists.');
        }
        if ($this->isAdministrators($before)) {
            throw ValidationException::general('Administrators always have every permission, so there is nothing to change here. Create a separate group for people who should have less.');
        }
        $perms = $this->cleanPermissions($permissions);
        $this->db->transaction(function () use ($id, $perms, $before) {
            $this->db->exec('DELETE FROM chroma_group_permissions WHERE group_id = ?', [$id]);
            foreach ($perms as $p) {
                $this->db->run('INSERT IGNORE INTO chroma_group_permissions (group_id, permission) VALUES (?, ?)', [$id, $p]);
            }
            $this->audit->record('group.set_permissions', 'chroma_groups', $id, ['permissions' => $before['permissions']], ['permissions' => $perms], 'Changed what ' . $before['name'] . ' may do');
        });
    }

    public function addMember(int $groupId, int $userId): void
    {
        $group = $this->find($groupId);
        $user = $this->users->find($userId);
        if ($group === null || $user === null) {
            throw ValidationException::general('That group or user no longer exists.');
        }
        if (in_array($groupId, $user['group_ids'], true)) {
            return;
        }
        $this->users->assertMayChangeAdminMembership(array_merge($user['group_ids'], [$groupId]), $user['group_ids'], $userId);
        $this->db->run('INSERT IGNORE INTO chroma_user_groups (user_id, group_id) VALUES (?, ?)', [$userId, $groupId]);
        $this->audit->record('group.add_member', 'chroma_groups', $groupId, null, ['user_id' => $userId, 'username' => $user['username']], 'Added ' . $user['username'] . ' to ' . $group['name']);
    }

    public function removeMember(int $groupId, int $userId): void
    {
        $group = $this->find($groupId);
        $user = $this->users->find($userId);
        if ($group === null || $user === null) {
            throw ValidationException::general('That group or user no longer exists.');
        }
        if (!in_array($groupId, $user['group_ids'], true)) {
            return;
        }
        $this->users->assertMayChangeAdminMembership(array_values(array_diff($user['group_ids'], [$groupId])), $user['group_ids'], $userId);
        $this->db->exec('DELETE FROM chroma_user_groups WHERE user_id = ? AND group_id = ?', [$userId, $groupId]);
        $this->audit->record('group.remove_member', 'chroma_groups', $groupId, ['user_id' => $userId, 'username' => $user['username']], null, 'Removed ' . $user['username'] . ' from ' . $group['name']);
    }

    /** Delete a non-system group. Members simply lose that group's permissions. */
    public function delete(int $id): void
    {
        $group = $this->find($id);
        if ($group === null) {
            throw ValidationException::general('That group no longer exists.');
        }
        if ((int) $group['is_system'] === 1) {
            throw ValidationException::general('"' . $group['name'] . '" is a built-in group and cannot be deleted. You can change its description or who is in it.');
        }
        $this->db->transaction(function () use ($id, $group) {
            $this->db->exec('DELETE FROM chroma_user_groups WHERE group_id = ?', [$id]);
            $this->db->exec('DELETE FROM chroma_group_permissions WHERE group_id = ?', [$id]);
            $this->db->exec('DELETE FROM chroma_groups WHERE id = ?', [$id]);
            $this->audit->record('group.delete', 'chroma_groups', $id, $group, null, 'Deleted group ' . $group['name'] . ' (' . count($group['members']) . ' members)');
        });
    }

    // ---------------------------------------------------------------- helpers

    /** @param array<string,mixed> $input */
    private function validateNameDescription(array $input): void
    {
        $v = new Validator($input);
        $v->required('name', 'Give the group a name, for example "Lab" or "Sales".')->maxLength('name', 100);
        $v->maxLength('description', 255);
        $v->fail();
    }

    /** @param mixed $keys @return list<string> */
    private function cleanPermissions(mixed $keys): array
    {
        if (!is_array($keys)) {
            return [];
        }
        $out = [];
        foreach ($keys as $k => $v) {
            // Accept either ['codes.view', ...] or ['codes.view' => '1', ...]
            $key = is_string($k) && !is_int($k) ? $k : (string) $v;
            if (is_string($k) && ($v === '0' || $v === 0 || $v === false || $v === '')) {
                continue;
            }
            if (Permissions::exists($key)) {
                $out[$key] = true;
            }
        }
        return array_keys($out);
    }

    /** @param mixed $ids @return list<int> */
    private function cleanUserIds(mixed $ids): array
    {
        if (!is_array($ids)) {
            return [];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
        if ($ids === []) {
            return [];
        }
        return array_map('intval', $this->db->column('SELECT id FROM chroma_users WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids));
    }
}
