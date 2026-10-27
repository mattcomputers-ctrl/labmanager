<?php
declare(strict_types=1);

namespace Chroma\Http\Controllers\Admin;

use Chroma\Core\App;
use Chroma\Core\Request;
use Chroma\Core\Response;
use Chroma\Domain\Export\Exporter;
use Chroma\Domain\Users\UserAdmin;
use Chroma\Http\Controller;

/** Admin → Users. Expert-mode forms; the Easy-mode wizard is AddUserWizard (same domain calls). */
final class UsersController extends Controller
{
    private UserAdmin $users;

    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->users = new UserAdmin($this->db, $this->auth, $app->audit);
    }

    public function index(Request $request): Response
    {
        $filters = [
            'q' => $request->str('q'),
            'status' => in_array($request->str('status'), ['active', 'inactive', 'all'], true) ? $request->str('status') : 'active',
            'group_id' => $request->int('group_id'),
        ];
        $export = $request->str('export');
        if (in_array($export, ['csv', 'xlsx'], true)) {
            $all = $this->users->list($filters, 10000, 0)['rows'];
            $rows = array_map(fn($u) => [
                'username' => $u['username'], 'display_name' => $u['display_name'], 'email' => $u['email'] ?? '', 'initials' => $u['initials'] ?? '',
                'groups' => $u['groups_text'] ?? '', 'active' => (int) $u['active'] === 1, 'must_change' => (int) $u['must_change_password'] === 1,
                'last_login' => fmt_datetime($u['last_login_at']), 'created' => fmt_datetime($u['created_at']),
            ], $all);
            return Exporter::respond($export, 'users', [
                'username' => 'Username', 'display_name' => 'Name', 'email' => 'Email', 'initials' => 'Initials', 'groups' => 'Groups',
                'active' => 'Active', 'must_change' => 'Must change password', 'last_login' => 'Last sign-in', 'created' => 'Created',
            ], $rows);
        }
        $paging = $this->paging($request, is_easy() ? 25 : 50);
        $list = $this->users->list($filters, $paging['per_page'], $paging['offset']);
        return $this->view('admin/users/index', [
            'title' => 'Users',
            'rows' => $list['rows'],
            'total' => $list['total'],
            'paging' => $paging,
            'filters' => $filters,
            'groups' => $this->users->groups(),
            'adminCount' => $this->users->activeAdministratorCount(),
        ]);
    }

    public function newForm(Request $request): Response
    {
        return $this->view('admin/users/form', [
            'title' => 'Add a user',
            'user' => null,
            'groups' => $this->users->groups(),
            'easyUrl' => '/wizard/add-user',
        ]);
    }

    public function create(Request $request): Response
    {
        $result = $this->users->create($this->userInput($request));
        $this->rememberPassword($result['id'], $result['password']);
        $this->success('User added.', 'Hand them the temporary password shown on the next screen; they will be asked to choose their own.');
        return $this->done($request, '/admin/users/' . $result['id']);
    }

    public function edit(Request $request): Response
    {
        $user = $this->users->find((int) $request->params['id']);
        if ($user === null) {
            $this->notFound('There is no user with that id.');
        }
        return $this->view('admin/users/form', [
            'title' => $user['display_name'] ?: $user['username'],
            'user' => $user,
            'groups' => $this->users->groups(),
            'shownPassword' => $this->pullPassword((int) $user['id']),
            'isSelf' => (int) $user['id'] === $this->userId(),
            'isAdmin' => $this->users->isAdministrator((int) $user['id']),
            'lastAdmin' => $this->users->isAdministrator((int) $user['id']) && (int) $user['active'] === 1 && $this->users->activeAdministratorCount() <= 1,
            'history' => $this->app->audit->forRow('chroma_users', (int) $user['id'], 20),
        ]);
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $input = $this->userInput($request);
        unset($input['username'], $input['password'], $input['password_confirm'], $input['password_mode']);
        $this->users->update($id, $input);
        $this->success('Changes saved.');
        return $this->done($request, '/admin/users/' . $id);
    }

    public function passwordForm(Request $request): Response
    {
        $user = $this->users->find((int) $request->params['id']);
        if ($user === null) {
            $this->notFound('There is no user with that id.');
        }
        return $this->view('admin/users/password', ['title' => 'Reset password', 'user' => $user]);
    }

    public function resetPassword(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $password = $this->users->resetPassword($id, [
            'password_mode' => $request->str('password_mode') ?: 'generate',
            'password' => (string) $request->input('password', ''),
            'password_confirm' => (string) $request->input('password_confirm', ''),
        ]);
        $this->rememberPassword($id, $password);
        $this->success('Temporary password set.', 'It is shown once on this screen. The user must pick a new one when they next sign in.');
        return $this->done($request, '/admin/users/' . $id);
    }

    public function deactivate(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $this->users->deactivate($id);
        $this->success('Account deactivated.', 'They can no longer sign in. Their name stays on past projects and codes.');
        return $this->done($request, '/admin/users/' . $id);
    }

    public function reactivate(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $this->users->reactivate($id);
        $this->success('Account reactivated.', 'They can sign in again with their old password. Reset it if they have forgotten it.');
        return $this->done($request, '/admin/users/' . $id);
    }

    /** @return array<string,mixed> */
    private function userInput(Request $request): array
    {
        $input = [
            'username' => $request->str('username'),
            'display_name' => $request->str('display_name'),
            'email' => $request->str('email'),
            'initials' => $request->str('initials'),
            // The form posts group_ids[<id>] = '0'|'1' (hidden + checkbox); keep the ids whose box is ticked.
            'group_ids' => array_map('intval', array_keys(array_filter($request->arr('group_ids'), fn($v) => (string) $v === '1'))),
            'password_mode' => $request->str('password_mode') ?: 'generate',
            'password' => (string) $request->input('password', ''),
            'password_confirm' => (string) $request->input('password_confirm', ''),
        ];
        if ($request->input('active') !== null) {
            $input['active'] = $request->bool('active') ? '1' : '0';
        }
        return $input;
    }

    private function rememberPassword(int $userId, string $password): void
    {
        $this->app->session->set('_shown_password', ['id' => $userId, 'password' => $password]);
    }

    private function pullPassword(int $userId): ?string
    {
        $p = $this->app->session->pull('_shown_password');
        return is_array($p) && (int) ($p['id'] ?? 0) === $userId ? (string) $p['password'] : null;
    }
}
