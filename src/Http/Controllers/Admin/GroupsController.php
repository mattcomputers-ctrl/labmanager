<?php
declare(strict_types=1);

namespace Chroma\Http\Controllers\Admin;

use Chroma\Core\App;
use Chroma\Core\Request;
use Chroma\Core\Response;
use Chroma\Domain\Export\Exporter;
use Chroma\Domain\Users\GroupAdmin;
use Chroma\Domain\Users\UserAdmin;
use Chroma\Http\Controller;

/** Admin → Groups: what each group may do and who is in it. */
final class GroupsController extends Controller
{
    private GroupAdmin $groups;
    private UserAdmin $users;

    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->users = new UserAdmin($this->db, $this->auth, $app->audit);
        $this->groups = new GroupAdmin($this->db, $app->audit, $this->users);
    }

    public function index(Request $request): Response
    {
        $rows = $this->groups->list();
        $export = $request->str('export');
        if (in_array($export, ['csv', 'xlsx'], true)) {
            $cat = \Chroma\Core\Permissions::catalogue();
            $out = [];
            foreach ($rows as $g) {
                $full = $this->groups->find((int) $g['id']);
                $out[] = [
                    'name' => $g['name'], 'description' => $g['description'], 'system' => (int) $g['is_system'] === 1,
                    'members' => (int) $g['member_count'],
                    'permissions' => implode('; ', array_map(fn($k) => $cat[$k]['label'] ?? $k, $full['permissions'] ?? [])),
                ];
            }
            return Exporter::respond($export, 'groups', ['name' => 'Group', 'description' => 'Description', 'system' => 'Built in', 'members' => 'Active members', 'permissions' => 'Permissions'], $out);
        }
        return $this->view('admin/groups/index', ['title' => 'Groups', 'rows' => $rows]);
    }

    public function newForm(Request $request): Response
    {
        return $this->view('admin/groups/form', [
            'title' => 'Add a group',
            'group' => null,
            'areas' => GroupAdmin::catalogueByArea(),
            'easyUrl' => '/wizard/add-group',
        ]);
    }

    public function create(Request $request): Response
    {
        $id = $this->groups->create([
            'name' => $request->str('name'),
            'description' => $request->str('description'),
            'permissions' => array_keys(array_filter($request->arr('permissions'), fn($v) => $v !== '0' && $v !== '')),
        ]);
        $this->success('Group added.', 'Now add the people who belong in it.');
        return $this->done($request, '/admin/groups/' . $id);
    }

    public function edit(Request $request): Response
    {
        $group = $this->groups->find((int) $request->params['id']);
        if ($group === null) {
            $this->notFound('There is no group with that id.');
        }
        $memberIds = array_map(fn($m) => (int) $m['id'], $group['members']);
        $candidates = array_values(array_filter(
            $this->db->all('SELECT id, username, display_name FROM chroma_users WHERE active = 1 ORDER BY display_name, username'),
            fn($u) => !in_array((int) $u['id'], $memberIds, true)
        ));
        return $this->view('admin/groups/show', [
            'title' => $group['name'],
            'group' => $group,
            'areas' => GroupAdmin::catalogueByArea(),
            'candidates' => $candidates,
            'isAdministrators' => $this->groups->isAdministrators($group),
            'history' => $this->app->audit->forRow('chroma_groups', (int) $group['id'], 20),
        ]);
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $this->groups->update($id, ['name' => $request->str('name'), 'description' => $request->str('description')]);
        $this->success('Group saved.');
        return $this->done($request, '/admin/groups/' . $id);
    }

    public function permissions(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $this->groups->setPermissions($id, array_keys(array_filter($request->arr('permissions'), fn($v) => $v !== '0' && $v !== '')));
        $this->success('Permissions saved.', 'Everyone in the group gets the new set the next time they load a page.');
        return $this->done($request, '/admin/groups/' . $id . '#permissions');
    }

    public function addMember(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $userId = $request->int('user_id') ?? 0;
        if ($userId <= 0) {
            throw \Chroma\Core\ValidationException::single('user_id', 'Pick a person to add.');
        }
        $this->groups->addMember($id, $userId);
        $this->success('Added to the group.');
        return $this->done($request, '/admin/groups/' . $id . '#members');
    }

    public function removeMember(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $this->groups->removeMember($id, (int) $request->params['uid']);
        $this->success('Removed from the group.');
        return $this->done($request, '/admin/groups/' . $id . '#members');
    }

    public function delete(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $this->groups->delete($id);
        $this->success('Group deleted.', 'People who were in it keep the permissions of their other groups.');
        return $this->done($request, '/admin/groups');
    }
}
