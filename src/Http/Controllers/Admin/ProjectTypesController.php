<?php
declare(strict_types=1);

namespace Chroma\Http\Controllers\Admin;

use Chroma\Core\App;
use Chroma\Core\Request;
use Chroma\Core\Response;
use Chroma\Core\ValidationException;
use Chroma\Domain\Export\Exporter;
use Chroma\Domain\Lists\ProjectTypeAdmin;
use Chroma\Domain\ProjectTypes\ProjectTypes;
use Chroma\Http\Controller;

/** Admin → Lists → Project types, with per-type stages and item statuses. */
final class ProjectTypesController extends Controller
{
    private ProjectTypeAdmin $types;

    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->types = new ProjectTypeAdmin($this->db, $app->audit);
    }

    public function index(Request $request): Response
    {
        $rows = $this->types->list();
        $export = $request->str('export');
        if (in_array($export, ['csv', 'xlsx'], true)) {
            return Exporter::respond($export, 'project-types', ['code' => 'Code', 'label' => 'Name', 'description' => 'Description', 'item_label' => 'Items called', 'has_customer' => 'Has customer', 'customer_required' => 'Customer required', 'has_stages' => 'Has stages', 'active' => 'Active', 'project_count' => 'Projects'],
                array_map(fn($t) => ['code' => $t['code'], 'label' => $t['label'], 'description' => $t['description'], 'item_label' => $t['item_label'], 'has_customer' => (int) $t['has_customer'] === 1, 'customer_required' => (int) $t['customer_required'] === 1, 'has_stages' => (int) $t['has_stages'] === 1, 'active' => (int) $t['active'] === 1, 'project_count' => (int) $t['project_count']], $rows));
        }
        return $this->view('admin/project-types/index', ['title' => 'Project types', 'rows' => $rows, 'globalStatuses' => $this->types->globalStatuses()]);
    }

    public function newForm(Request $request): Response
    {
        return $this->view('admin/project-types/form', ['title' => 'Add a project type', 'type' => null, 'catalogue' => ProjectTypes::FIELD_CATALOGUE, 'colors' => ProjectTypeAdmin::COLORS]);
    }

    public function create(Request $request): Response
    {
        $id = $this->types->create($this->typeInput($request, true));
        $this->success('Project type added.', 'Add its stages and statuses below if it needs them.');
        return $this->done($request, '/admin/project-types/' . $id);
    }

    public function edit(Request $request): Response
    {
        $type = $this->types->find((int) $request->params['id']);
        if ($type === null) {
            $this->notFound('There is no project type with that id.');
        }
        return $this->view('admin/project-types/edit', [
            'title' => $type['label'],
            'type' => $type,
            'catalogue' => ProjectTypes::FIELD_CATALOGUE,
            'colors' => ProjectTypeAdmin::COLORS,
            'history' => $this->app->audit->forRow('chroma_project_types', (int) $type['id'], 15),
            'editStage' => $request->int('edit_stage'),
            'editStatus' => $request->int('edit_status'),
        ]);
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $this->types->update($id, $this->typeInput($request, false));
        $this->success('Project type saved.');
        return $this->done($request, '/admin/project-types/' . $id);
    }

    // ---- stages

    public function addStage(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $this->types->addStage($id, ['label' => $request->str('label'), 'is_terminal' => $request->bool('is_terminal') ? '1' : '0']);
        $this->success('Stage added.');
        return $this->done($request, '/admin/project-types/' . $id . '#stages');
    }

    public function updateStage(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $sid = (int) $request->params['sid'];
        try {
            $this->types->updateStage($sid, ['label' => $request->str('label'), 'is_terminal' => $request->bool('is_terminal') ? '1' : '0', 'active' => $request->bool('active') ? '1' : '0']);
        } catch (ValidationException $e) {
            return $this->reopenRow($request, $e, '/admin/project-types/' . $id . '?edit_stage=' . $sid . '#stages');
        }
        $this->success('Stage saved.');
        return $this->done($request, '/admin/project-types/' . $id . '#stages');
    }

    public function moveStage(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $this->types->moveStage((int) $request->params['sid'], $request->str('direction') === 'up' ? -1 : 1);
        return $this->done($request, '/admin/project-types/' . $id . '#stages');
    }

    public function deleteStage(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $this->types->deleteStage((int) $request->params['sid']);
        $this->success('Stage removed.');
        return $this->done($request, '/admin/project-types/' . $id . '#stages');
    }

    // ---- item statuses

    /** The shared status list used by every type without its own. */
    public function globalStatuses(Request $request): Response
    {
        return $this->view('admin/project-types/statuses', [
            'title' => 'Shared item statuses',
            'statuses' => $this->types->globalStatuses(),
            'colors' => ProjectTypeAdmin::COLORS,
            'typesUsing' => $this->db->all('SELECT t.label FROM chroma_project_types t WHERE t.active = 1 AND NOT EXISTS (SELECT 1 FROM chroma_item_statuses s WHERE s.project_type_id = t.id) ORDER BY t.sort_order'),
            'editStatus' => $request->int('edit_status'),
        ]);
    }

    public function customizeStatuses(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $this->types->customizeStatuses($id);
        $this->success('This type now has its own statuses.', 'They started as a copy of the shared list; change them as you like.');
        return $this->done($request, '/admin/project-types/' . $id . '#statuses');
    }

    public function revertStatuses(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $this->types->revertStatuses($id);
        $this->success('Back to the shared statuses.');
        return $this->done($request, '/admin/project-types/' . $id . '#statuses');
    }

    public function addStatus(Request $request): Response
    {
        $typeId = isset($request->params['id']) ? (int) $request->params['id'] : null;
        $this->types->addStatus($typeId, $this->statusInput($request));
        $this->success('Status added.');
        return $this->done($request, $typeId === null ? '/admin/item-statuses' : '/admin/project-types/' . $typeId . '#statuses');
    }

    public function updateStatus(Request $request): Response
    {
        $sid = (int) $request->params['sid'];
        try {
            $this->types->updateStatus($sid, $this->statusInput($request) + ['active' => $request->bool('active') ? '1' : '0']);
        } catch (ValidationException $e) {
            $back = $this->statusReturn($sid);
            return $this->reopenRow($request, $e, str_replace('#statuses', '', $back) . '?edit_status=' . $sid . (str_contains($back, '#') ? '#statuses' : ''));
        }
        $this->success('Status saved.');
        return $this->done($request, $this->statusReturn($sid));
    }

    public function moveStatus(Request $request): Response
    {
        $sid = (int) $request->params['sid'];
        $back = $this->statusReturn($sid);
        $this->types->moveStatus($sid, $request->str('direction') === 'up' ? -1 : 1);
        return $this->done($request, $back);
    }

    public function deleteStatus(Request $request): Response
    {
        $sid = (int) $request->params['sid'];
        $back = $this->statusReturn($sid);
        $this->types->deleteStatus($sid);
        $this->success('Status removed.');
        return $this->done($request, $back);
    }

    // ---- helpers

    /** @return array<string,mixed> */
    private function typeInput(Request $request, bool $withCode): array
    {
        $input = [
            'label' => $request->str('label'),
            'description' => $request->str('description'),
            'item_label' => $request->str('item_label'),
            'item_label_singular' => $request->str('item_label_singular'),
            'has_customer' => $request->bool('has_customer') ? '1' : '0',
            'customer_required' => $request->bool('customer_required') ? '1' : '0',
            'has_stages' => $request->bool('has_stages') ? '1' : '0',
            'has_team' => $request->bool('has_team') ? '1' : '0',
            'can_be_confidential' => $request->bool('can_be_confidential') ? '1' : '0',
            'color' => $request->str('color'),
            'sort_order' => $request->str('sort_order'),
            'fields' => array_keys(array_filter($request->arr('fields'), fn($v) => $v !== '0' && $v !== '')),
        ];
        if ($withCode) {
            $input['code'] = strtolower($request->str('code'));
        } elseif ($request->input('active') !== null) {
            $input['active'] = $request->bool('active') ? '1' : '0';
        }
        return $input;
    }

    /** @return array<string,mixed> */
    private function statusInput(Request $request): array
    {
        return [
            'label' => $request->str('label'),
            'is_done' => $request->bool('is_done') ? '1' : '0',
            'is_success' => $request->bool('is_success') ? '1' : '0',
            'color' => $request->str('color'),
        ];
    }

    /** A refused inline save comes back with the same row open, the typed values in it and the message under it. */
    private function reopenRow(Request $request, ValidationException $e, string $to): Response
    {
        if ($request->wantsJson()) {
            throw $e;
        }
        $this->app->flash->withInput($request->body, $e->errors);
        $this->app->flash->error($e->getMessage());
        return $this->redirect($to);
    }

    private function statusReturn(int $statusId): string
    {
        $typeId = $this->db->value('SELECT project_type_id FROM chroma_item_statuses WHERE id = ?', [$statusId]);
        return $typeId === null ? '/admin/item-statuses' : '/admin/project-types/' . (int) $typeId . '#statuses';
    }
}
