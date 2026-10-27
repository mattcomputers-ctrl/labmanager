<?php
declare(strict_types=1);

namespace Chroma\Http\Controllers\Projects;

use Chroma\Core\ForbiddenException;
use Chroma\Core\Permissions;
use Chroma\Core\Request;
use Chroma\Core\Response;
use Chroma\Core\ValidationException;
use Chroma\Domain\ProjectTypes\ProjectTypes;

/** Colors / items on a project: add, edit, status, reorder, move, remove, link existing, assign codes, batch assign. */
final class ItemsController extends ProjectController
{
    /** Expert mode: inline new row (fetch) or a plain form post. */
    public function store(Request $request): Response
    {
        $project = $this->loadProject($request);
        $data = $request->body;
        unset($data['_token']);
        $item = $this->items->add((int) $project['id'], $data, $this->userId());
        $this->success(ucfirst((string) $project['item_label_singular']) . ' added: ' . $item['description']);
        return $this->finish($request, $this->projectUrl((int) $project['id'], '#items'), ['item' => ['id' => (int) $item['id'], 'description' => $item['description']]]);
    }

    public function edit(Request $request): Response
    {
        $project = $this->loadProject($request);
        $item = $this->loadItem($request, $project);
        return $this->view('projects/item-edit', [
            'title' => 'Edit ' . mb_strtolower((string) $project['item_label_singular']) . ' · Project ' . $project['project_number'],
            'project' => $project,
            'item' => $item,
            'statuses' => $this->items->statusesFor((int) $project['project_type_id']),
            'kinds' => ProjectTypes::ITEM_KINDS,
        ] + $this->referenceLists());
    }

    public function update(Request $request): Response
    {
        $project = $this->loadProject($request);
        $item = $this->loadItem($request, $project);
        $data = $request->body;
        unset($data['_token']);
        $after = $this->items->update((int) $item['id'], $data, $this->userId());
        $this->success('Saved ' . $after['description'] . '.');
        return $this->finish($request, $this->projectUrl((int) $project['id'], '#items'), ['item' => $this->itemJson($after)]);
    }

    public function status(Request $request): Response
    {
        $project = $this->loadProject($request);
        $item = $this->loadItem($request, $project);
        $after = $this->items->setStatus((int) $item['id'], (int) $request->int('status_id', 0), $this->userId());
        $fresh = $this->projects->get((int) $project['id']);
        $completed = $fresh['status'] === 'completed' && $project['status'] !== 'completed';
        $this->success('"' . $after['description'] . '" is now ' . mb_strtolower((string) $after['status_label']) . '.', $completed ? 'Every item is done, so the project was completed.' : null);
        return $this->finish($request, $this->projectUrl((int) $project['id'], '#items'), ['item' => $this->itemJson($after), 'progress' => $fresh['progress'], 'project_status' => $fresh['status'], 'completed' => $completed]);
    }

    /** POST direction=up|down for one item, or order[]=ids for the whole list. */
    public function reorder(Request $request): Response
    {
        $project = $this->loadProject($request);
        $order = $request->arr('order');
        if ($order !== []) {
            $this->items->reorder((int) $project['id'], array_map('intval', $order), null, null, $this->userId());
        } else {
            $item = $this->loadItem($request, $project);
            $dir = $request->str('direction') === 'up' ? 'up' : 'down';
            $this->items->reorder((int) $project['id'], null, (int) $item['id'], $dir, $this->userId());
        }
        if (!$request->wantsJson()) {
            $this->success('Order saved.');
        }
        return $this->finish($request, $this->projectUrl((int) $project['id'], '#items'));
    }

    public function moveForm(Request $request): Response
    {
        $project = $this->loadProject($request);
        $item = $this->loadItem($request, $project);
        return $this->view('projects/item-move', [
            'title' => 'Move ' . mb_strtolower((string) $project['item_label_singular']),
            'project' => $project,
            'item' => $item,
        ]);
    }

    public function move(Request $request): Response
    {
        $project = $this->loadProject($request);
        $item = $this->loadItem($request, $project);
        $toId = (int) $request->int('to_project_id', 0);
        $to = $toId > 0 ? $this->projects->find($toId) : null;
        if ($to === null || !$this->visibility->canView($to)) {
            throw ValidationException::single('to_project_id', 'Pick the project to move it to from the list.');
        }
        $moved = $this->items->move((int) $item['id'], (int) $to['id'], $this->userId());
        $this->success('"' . $moved['description'] . '" moved to project ' . $to['project_number'] . '.', 'It keeps its codes, status and notes.');
        return $this->finish($request, $this->projectUrl((int) $to['id'], '#items'));
    }

    public function remove(Request $request): Response
    {
        $project = $this->loadProject($request);
        $item = $this->loadItem($request, $project);
        $this->items->remove((int) $item['id'], $this->userId());
        $this->success('"' . $item['description'] . '" removed.');
        return $this->finish($request, $this->projectUrl((int) $project['id'], '#items'));
    }

    /** Link an existing E# / sell number picked with the code typeahead. */
    public function link(Request $request): Response
    {
        $project = $this->loadProject($request);
        $item = $this->loadItem($request, $project);
        $ref = $request->str('existing_ref');
        if ($ref === '') {
            throw ValidationException::single('existing_ref', 'Pick the existing E number or sell number from the list that appears as you type.');
        }
        $after = $this->items->linkExisting((int) $item['id'], $ref, $this->userId());
        $this->success('Linked ' . implode(' / ', array_filter([$after['internal_display'], $after['sell_display']])) . ' to "' . $after['description'] . '".');
        return $this->finish($request, $this->projectUrl((int) $project['id'], '#items'), ['item' => $this->itemJson($after)]);
    }

    public function assignInternal(Request $request): Response
    {
        $project = $this->loadProject($request);
        $item = $this->loadItem($request, $project);
        $row = $this->items->assignInternal((int) $item['id'], [
            'description' => $request->str('description'),
            'resale' => $request->bool('resale'),
            'notes' => $request->str('notes') ?: null,
        ], $this->userId(), $this->codes());
        $this->success($row['display'] . ' assigned to "' . $item['description'] . '".');
        return $this->finish($request, $this->projectUrl((int) $project['id'], '#items'), ['code' => $row['display'], 'id' => (int) $row['id'], 'url' => '/codes/e/' . (int) $row['id']]);
    }

    public function assignSell(Request $request): Response
    {
        $project = $this->loadProject($request);
        $item = $this->loadItem($request, $project);
        $row = $this->items->assignSell((int) $item['id'], [
            'prefix_id' => $request->int('prefix_id', 0),
            'suffix_id' => $request->int('suffix_id'),
            'description' => $request->str('description'),
            'reason' => $request->str('reason') ?: null,
            'reason_text' => $request->str('reason_text') ?: null,
            'notes' => $request->str('notes') ?: null,
        ], $this->userId(), $this->codes());
        $this->success($row['display'] . ' assigned to "' . $item['description'] . '".');
        return $this->finish($request, $this->projectUrl((int) $project['id'], '#items'), ['code' => $row['display'], 'id' => (int) $row['id'], 'url' => '/codes/s/' . (int) $row['id']]);
    }

    // ------------------------------------------------------------------
    // Batch assignment (Expert: one review page; Easy: /wizard/batch-assign)
    // ------------------------------------------------------------------

    private function requireAnyAssign(): void
    {
        if (!$this->auth->canAny(Permissions::CODES_ASSIGN_INTERNAL, Permissions::CODES_ASSIGN_SELL)) {
            throw new ForbiddenException();
        }
    }

    public function batchForm(Request $request): Response
    {
        $this->requireAnyAssign();
        $project = $this->loadProject($request);
        if (is_easy() && !$request->bool('expert')) {
            return $this->redirect(url('/wizard/batch-assign', ['project_id' => $project['id'], 'items' => implode(',', array_map('intval', (array) $request->input('items', []))) ?: null]));
        }
        $wanted = $request->input('items', []);
        $wanted = is_array($wanted) ? array_map('intval', $wanted) : array_map('intval', array_filter(explode(',', (string) $wanted)));
        $all = $this->items->forProject((int) $project['id']);
        $selected = $wanted === [] ? $all : array_values(array_filter($all, fn($i) => in_array((int) $i['id'], $wanted, true)));
        $selected = array_values(array_filter($selected, fn($i) => $i['internal_code_id'] === null || $i['sell_number_id'] === null));
        return $this->view('projects/batch', [
            'title' => 'Assign codes · Project ' . $project['project_number'],
            'project' => $project,
            'selected' => $selected,
            'allCount' => count($all),
            'canInternal' => $this->auth->can(Permissions::CODES_ASSIGN_INTERNAL),
            'canSell' => $this->auth->can(Permissions::CODES_ASSIGN_SELL),
        ] + $this->referenceLists());
    }

    public function batch(Request $request): Response
    {
        $this->requireAnyAssign();
        $project = $this->loadProject($request);
        $canInternal = $this->auth->can(Permissions::CODES_ASSIGN_INTERNAL);
        $canSell = $this->auth->can(Permissions::CODES_ASSIGN_SELL);
        $plan = [];
        foreach ($request->arr('rows') as $itemId => $row) {
            if (!is_array($row)) {
                continue;
            }
            $plan[] = [
                'item_id' => (int) $itemId,
                'internal' => $canInternal && !empty($row['internal']),
                'sell' => $canSell && !empty($row['sell']),
                'prefix_id' => (int) ($row['prefix_id'] ?? 0),
                'suffix_id' => isset($row['suffix_id']) && $row['suffix_id'] !== '' ? (int) $row['suffix_id'] : null,
                'description' => (string) ($row['description'] ?? ''),
            ];
        }
        $result = $this->items->batchAssign((int) $project['id'], $plan, $this->userId(), $this->codes());
        $this->app->session->set('_batch_result.' . $project['id'], $result);
        if ($result['errors'] === []) {
            $this->success(count($result['assigned']) . ' code(s) assigned.');
        } else {
            $this->app->flash->warning(count($result['assigned']) . ' code(s) assigned, ' . count($result['errors']) . ' could not be.', 'See the list below.');
        }
        return $this->finish($request, $this->projectUrl((int) $project['id'], '/batch/done'));
    }

    public function batchDone(Request $request): Response
    {
        $project = $this->loadProject($request);
        $result = $this->app->session->get('_batch_result.' . $project['id']);
        if (!is_array($result)) {
            return $this->redirect($this->projectUrl((int) $project['id']));
        }
        return $this->view('projects/batch-done', [
            'title' => 'Codes assigned · Project ' . $project['project_number'],
            'project' => $project,
            'assigned' => $result['assigned'],
            'errors' => $result['errors'],
        ]);
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function itemJson(array $item): array
    {
        return [
            'id' => (int) $item['id'], 'description' => $item['description'], 'status_id' => $item['status_id'], 'status_label' => $item['status_label'],
            'status_color' => $item['status_color'], 'internal' => $item['internal_display'], 'internal_code_id' => $item['internal_code_id'],
            'sell' => $item['sell_display'], 'sell_number_id' => $item['sell_number_id'], 'kind' => $item['kind'],
        ];
    }
}
