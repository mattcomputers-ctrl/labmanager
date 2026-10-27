<?php
declare(strict_types=1);

namespace Chroma\Http\Wizard;

use Chroma\Core\Permissions;
use Chroma\Core\Request;
use Chroma\Domain\Users\GroupAdmin;
use Chroma\Domain\Users\UserAdmin;

/**
 * Easy-mode "Add a group": name, what it may do (tick boxes by area),
 * who is in it, review. Calls GroupAdmin::create like the Expert form.
 */
final class AddGroupWizard extends Wizard
{
    public function key(): string
    {
        return 'add-group';
    }

    public function title(): string
    {
        return 'Add a group';
    }

    public function permissions(): array
    {
        return [Permissions::ADMIN_USERS];
    }

    public function initialState(Request $request): array
    {
        return ['permissions' => [], 'member_ids' => []];
    }

    public function steps(array $state): array
    {
        $memberFields = [];
        foreach ($this->app->db->all('SELECT id, username, display_name FROM chroma_users WHERE active = 1 ORDER BY display_name, username') as $u) {
            $memberFields[] = [
                'name' => 'member_ids[' . (int) $u['id'] . ']', 'type' => 'checkbox', 'label' => $u['display_name'] ?: $u['username'], 'help' => $u['username'],
                'value' => in_array((int) $u['id'], (array) ($state['member_ids'] ?? []), true) ? 1 : 0,
            ];
        }
        return [
            new Step('name', 'What is the group called?', 'A group is a set of people who may do the same things, for example "Lab" or "Sales".', [
                ['name' => 'name', 'label' => 'Group name', 'required' => true, 'placeholder' => 'e.g. Press room', 'maxlength' => 100],
                ['name' => 'description', 'label' => 'What is it for?', 'optional_text' => true, 'placeholder' => 'One line that explains who belongs here', 'maxlength' => 255],
            ]),
            new Step('permissions', 'What may people in this group do?', 'Tick everything the group should be allowed to do. Someone in several groups gets everything each of their groups allows.', [], null, 'admin/groups/wizard-permissions'),
            new Step('members', 'Who is in it?', 'You can skip this and add people later from the group page.', $memberFields),
        ];
    }

    public function validateStep(Step $step, array $input, array $state): array
    {
        if ($step->key === 'permissions') {
            $keys = [];
            foreach ((array) ($input['permissions'] ?? []) as $k => $v) {
                $key = is_int($k) ? (string) $v : (string) $k;
                if (!is_int($k) && (string) $v !== '1') {
                    continue;
                }
                if (Permissions::exists($key)) {
                    $keys[] = $key;
                }
            }
            return ['permissions' => array_values(array_unique($keys))];
        }
        if ($step->key === 'members') {
            $ids = [];
            foreach ((array) ($input['member_ids'] ?? []) as $id => $on) {
                if (is_int($on)) {
                    $ids[] = $on;
                } elseif ((string) $on === '1') {
                    $ids[] = (int) $id;
                }
            }
            return ['member_ids' => array_values(array_unique($ids))];
        }
        $out = parent::validateStep($step, $input, $state);
        if ($this->app->db->value('SELECT id FROM chroma_groups WHERE name = ?', [$out['name']]) !== null) {
            $this->fail(['name' => 'There is already a group called "' . $out['name'] . '". Pick a different name.']);
        }
        return $out;
    }

    public function review(array $state): array
    {
        $cat = Permissions::catalogue();
        $labels = array_map(fn($k) => $cat[$k]['label'] ?? $k, (array) ($state['permissions'] ?? []));
        $members = [];
        foreach ((array) ($state['member_ids'] ?? []) as $id) {
            $u = $this->app->db->one('SELECT username, display_name FROM chroma_users WHERE id = ?', [(int) $id]);
            if ($u) {
                $members[] = $u['display_name'] ?: $u['username'];
            }
        }
        return [
            ['label' => 'Group name', 'value' => (string) ($state['name'] ?? ''), 'step' => 'name'],
            ['label' => 'What it is for', 'value' => (string) ($state['description'] ?? ''), 'step' => 'name'],
            ['label' => 'May do', 'value' => $labels ? implode(', ', $labels) : 'Nothing yet — tick at least one thing or the group has no effect', 'step' => 'permissions'],
            ['label' => 'Members', 'value' => $members ? implode(', ', $members) : 'Nobody yet', 'step' => 'members'],
        ];
    }

    public function commit(array $state): WizardResult
    {
        $users = new UserAdmin($this->app->db, $this->app->auth, $this->app->audit);
        $id = (new GroupAdmin($this->app->db, $this->app->audit, $users))->create($state);
        return new WizardResult(
            'Group added',
            '"' . $state['name'] . '" is ready. Everyone in it can do what you ticked from their next page load.',
            [],
            [],
            [
                ['label' => 'Open the group', 'href' => '/admin/groups/' . $id, 'primary' => true],
                ['label' => 'Add another group', 'href' => '/wizard/add-group'],
                ['label' => 'Back to groups', 'href' => '/admin/groups'],
            ]
        );
    }

    public function expertUrl(array $state): ?string
    {
        return url('/mode', ['set' => 'expert', 'return' => url('/admin/groups/new', ['name' => $state['name'] ?? null, 'description' => $state['description'] ?? null])]);
    }

    public function cancelUrl(array $state): string
    {
        return '/admin/groups';
    }

    public function commitLabel(): string
    {
        return 'Create the group';
    }
}
