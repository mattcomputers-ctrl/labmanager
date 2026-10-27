<?php
declare(strict_types=1);

namespace Chroma\Http\Wizard;

use Chroma\Core\Auth;
use Chroma\Core\Permissions;
use Chroma\Core\Request;
use Chroma\Domain\Users\UserAdmin;

/**
 * Easy-mode "Add a user": who, password, groups, review, then the
 * temporary password shown once with a Copy button. Calls UserAdmin::create,
 * exactly like the Expert form at /admin/users/new.
 */
final class AddUserWizard extends Wizard
{
    public function key(): string
    {
        return 'add-user';
    }

    public function title(): string
    {
        return 'Add a user';
    }

    public function permissions(): array
    {
        return [Permissions::ADMIN_USERS];
    }

    public function initialState(Request $request): array
    {
        return ['password_mode' => 'generate', 'group_ids' => []];
    }

    public function steps(array $state): array
    {
        $groupFields = [];
        foreach ($this->users()->groups() as $g) {
            $groupFields[] = [
                'name' => 'group_ids[' . (int) $g['id'] . ']', 'type' => 'checkbox', 'label' => $g['name'], 'help' => $g['description'],
                'value' => in_array((int) $g['id'], (array) ($state['group_ids'] ?? []), true) ? 1 : 0,
            ];
        }
        $steps = [
            new Step('who', 'Who is this account for?', 'The username is what they type to sign in. The name is what everyone else sees.', [
                ['name' => 'username', 'label' => 'Username', 'required' => true, 'placeholder' => 'e.g. jsmith', 'help' => 'Lowercase, no spaces. Short and easy to type.', 'maxlength' => 100, 'attrs' => ['autocapitalize' => 'off', 'spellcheck' => 'false']],
                ['name' => 'display_name', 'label' => 'Full name', 'required' => true, 'placeholder' => 'e.g. Jane Smith', 'maxlength' => 150],
                ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'optional_text' => true, 'maxlength' => 190],
                ['name' => 'initials', 'label' => 'Initials', 'optional_text' => true, 'help' => 'Shown on compact lists. Leave blank to use the first letters of the name.', 'maxlength' => 5],
            ]),
            new Step('password', 'How should the first password be set?', 'Either way it is temporary: they will be asked to choose their own the first time they sign in.', [
                ['name' => 'password_mode', 'type' => 'radio', 'label' => 'Temporary password', 'required' => true, 'default' => 'generate', 'options' => [
                    ['generate', 'Make one up for me', 'Chroma picks a memorable password and shows it once at the end.'],
                    ['typed', 'I will type one', 'At least 10 characters. A short sentence works well.'],
                ]],
            ]),
        ];
        if (($state['password_mode'] ?? 'generate') === 'typed') {
            // Only asked when they chose to type one; disappears again if they go back and change their mind.
            $steps[] = new Step('password-typed', 'Type the temporary password', 'At least 10 characters. A short sentence works well, for example "blue ink on tuesday".', [
                ['name' => 'password', 'type' => 'password', 'label' => 'Temporary password', 'required' => true, 'value' => '', 'required_message' => 'Type the temporary password.', 'attrs' => ['autocomplete' => 'new-password']],
                ['name' => 'password_confirm', 'type' => 'password', 'label' => 'Type it again', 'required' => true, 'value' => '', 'required_message' => 'Type the same password again so we know it was typed correctly.', 'attrs' => ['autocomplete' => 'new-password']],
            ]);
        }
        $steps[] = new Step('groups', 'Which groups should they be in?', 'Groups decide what a person can do. Someone can be in more than one; they get everything each group allows.', $groupFields,
            'Not sure? Lab technicians go in Lab, sales staff in Sales. You can change this at any time from Admin → Users.');
        return $steps;
    }

    public function validateStep(Step $step, array $input, array $state): array
    {
        if ($step->key === 'groups') {
            // From the form: [id => '0'|'1']; from saved state (review re-check): a plain list of ids.
            $ids = [];
            foreach ((array) ($input['group_ids'] ?? []) as $id => $on) {
                if (is_int($on)) {
                    $ids[] = $on;
                } elseif ((string) $on === '1') {
                    $ids[] = (int) $id;
                }
            }
            return ['group_ids' => array_values(array_unique($ids))];
        }
        if ($step->key === 'password') {
            $mode = ($input['password_mode'] ?? 'generate') === 'typed' ? 'typed' : 'generate';
            // Keep an already-typed password when the choice is unchanged; clear it when switching to "make one up".
            $keep = $mode === 'typed' ? (string) ($state['password'] ?? '') : '';
            return ['password_mode' => $mode, 'password' => $keep, 'password_confirm' => $keep];
        }
        if ($step->key === 'password-typed') {
            $typed = (string) ($input['password'] ?? '');
            if ($p = Auth::passwordProblem($typed)) {
                $this->fail(['password' => $p]);
            }
            if ($typed !== (string) ($input['password_confirm'] ?? '')) {
                $this->fail(['password_confirm' => 'The two passwords do not match. Type the same password in both boxes.']);
            }
            return ['password' => $typed, 'password_confirm' => $typed];
        }
        $out = parent::validateStep($step, $input, $state);
        if ($step->key === 'who') {
            $out['username'] = mb_strtolower((string) $out['username']);
            if (!preg_match('/^[a-z0-9][a-z0-9._-]*$/', $out['username'])) {
                $this->fail(['username' => 'Usernames can only contain letters, numbers, dots, dashes and underscores, with no spaces.']);
            }
            if ($this->app->db->value('SELECT id FROM chroma_users WHERE username = ?', [$out['username']]) !== null) {
                $this->fail(['username' => 'The username "' . $out['username'] . '" is already taken. Pick a different one.']);
            }
            if ($out['email'] !== '' && filter_var($out['email'], FILTER_VALIDATE_EMAIL) === false) {
                $this->fail(['email' => 'That does not look like an email address. Check it or leave it blank.']);
            }
        }
        return $out;
    }

    public function review(array $state): array
    {
        $names = [];
        foreach ($this->users()->groups() as $g) {
            if (in_array((int) $g['id'], (array) ($state['group_ids'] ?? []), true)) {
                $names[] = $g['name'];
            }
        }
        return [
            ['label' => 'Username', 'value' => (string) ($state['username'] ?? ''), 'step' => 'who'],
            ['label' => 'Name', 'value' => (string) ($state['display_name'] ?? ''), 'step' => 'who'],
            ['label' => 'Email', 'value' => (string) ($state['email'] ?? ''), 'step' => 'who'],
            ['label' => 'Temporary password', 'value' => ($state['password_mode'] ?? 'generate') === 'typed' ? 'The one you typed (shown again at the end)' : 'Chroma will make one up and show it at the end', 'step' => 'password'],
            ['label' => 'Groups', 'value' => $names ? implode(', ', $names) : 'None yet — they will not be able to do anything until added to a group', 'step' => 'groups'],
        ];
    }

    public function commit(array $state): WizardResult
    {
        $result = $this->users()->create($state);
        $user = $this->users()->find($result['id']);
        return new WizardResult(
            'User added',
            'Give ' . ($user['display_name'] ?? '') . ' this temporary password. It is shown only once; they will choose their own when they first sign in.',
            [['code' => $result['password'], 'label' => 'Temporary password for ' . $state['username'], 'description' => 'Sign in at ' . (string) ($this->app->config->get('APP_URL') ?: 'Chroma') . ' with username "' . $state['username'] . '".']],
            [['Groups', $user['group_names'] ? implode(', ', $user['group_names']) : 'None']],
            [
                ['label' => 'Open ' . $state['username'], 'href' => '/admin/users/' . $result['id'], 'primary' => true],
                ['label' => 'Add another user', 'href' => '/wizard/add-user'],
                ['label' => 'Back to users', 'href' => '/admin/users'],
            ]
        );
    }

    public function expertUrl(array $state): ?string
    {
        return url('/mode', ['set' => 'expert', 'return' => url('/admin/users/new', ['username' => $state['username'] ?? null, 'display_name' => $state['display_name'] ?? null, 'email' => $state['email'] ?? null])]);
    }

    public function cancelUrl(array $state): string
    {
        return '/admin/users';
    }

    public function commitLabel(): string
    {
        return 'Create the account';
    }

    public function commitExplanation(): string
    {
        return 'The account is created straight away and the temporary password is shown on the next screen.';
    }

    private function users(): UserAdmin
    {
        return new UserAdmin($this->app->db, $this->app->auth, $this->app->audit);
    }
}
