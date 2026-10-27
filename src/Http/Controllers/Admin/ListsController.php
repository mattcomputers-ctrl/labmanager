<?php
declare(strict_types=1);

namespace Chroma\Http\Controllers\Admin;

use Chroma\Core\App;
use Chroma\Core\Request;
use Chroma\Core\Response;
use Chroma\Core\ValidationException;
use Chroma\Domain\Export\Exporter;
use Chroma\Domain\Lists\ListAdmin;
use Chroma\Http\Controller;

/** Admin → Lists: ink series, coatings and substrates (project types and customers have their own screens). */
final class ListsController extends Controller
{
    private ListAdmin $lists;

    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->lists = new ListAdmin($this->db, $app->audit);
    }

    public function index(Request $request): Response
    {
        $tab = $request->str('tab');
        if (!isset(ListAdmin::KINDS[$tab])) {
            $tab = 'ink-series';
        }
        $kind = ListAdmin::kind($tab);
        $entries = $this->lists->entries($tab);
        $showInactive = $request->bool('inactive');
        $export = $request->str('export');
        if (in_array($export, ['csv', 'xlsx'], true)) {
            return Exporter::respond($export, slugify($kind['plural']), ['name' => 'Name', 'active' => 'Active', 'sort_order' => 'Sort', 'notes' => 'Notes', 'usage_count' => 'Used by'],
                array_map(fn($r) => ['name' => $r['name'], 'active' => (int) $r['active'] === 1, 'sort_order' => (int) $r['sort_order'], 'notes' => $r['notes'], 'usage_count' => (int) $r['usage_count']], $entries));
        }
        $inactiveCount = count(array_filter($entries, fn($r) => (int) $r['active'] === 0));
        if (!$showInactive) {
            $entries = array_values(array_filter($entries, fn($r) => (int) $r['active'] === 1));
        }
        $typeCount = (int) $this->db->value('SELECT COUNT(*) FROM chroma_project_types WHERE active = 1');
        $customerCount = (int) $this->db->value('SELECT COUNT(*) FROM chroma_customers WHERE active = 1');
        return $this->view('admin/lists/index', [
            'title' => 'Lists',
            'tab' => $tab,
            'kind' => $kind,
            'entries' => $entries,
            'inactiveCount' => $inactiveCount,
            'showInactive' => $showInactive,
            'typeCount' => $typeCount,
            'customerCount' => $customerCount,
            'editId' => $request->int('edit'),
        ]);
    }

    public function add(Request $request): Response
    {
        $kind = $this->kindParam($request);
        $this->lists->add($kind, ['name' => $request->str('name'), 'notes' => $request->str('notes'), 'sort_order' => $request->str('sort_order')]);
        $this->success('Added to the list.');
        return $this->done($request, '/admin/lists?tab=' . $kind);
    }

    public function update(Request $request): Response
    {
        $kind = $this->kindParam($request);
        $id = (int) $request->params['id'];
        $back = '/admin/lists?tab=' . $kind . ($request->bool('active') ? '' : '&inactive=1');
        try {
            $this->lists->update($kind, $id, [
                'name' => $request->str('name'), 'notes' => $request->str('notes'), 'sort_order' => $request->str('sort_order'),
                'active' => $request->bool('active') ? '1' : '0',
            ]);
        } catch (ValidationException $e) {
            if ($request->wantsJson()) {
                throw $e;
            }
            // Reopen the same row with what was typed and the message under it, instead of landing in the "Add" box.
            $this->app->flash->withInput($request->body, $e->errors);
            $this->app->flash->error($e->getMessage());
            return $this->redirect($back . '&edit=' . $id . '#entry-' . $id);
        }
        $this->success('Saved.');
        return $this->done($request, $back);
    }

    public function deactivate(Request $request): Response
    {
        $kind = $this->kindParam($request);
        $this->lists->deactivate($kind, (int) $request->params['id']);
        $this->success('Deactivated.', 'It no longer appears in pickers. Old projects still show it.');
        return $this->done($request, '/admin/lists?tab=' . $kind);
    }

    public function reactivate(Request $request): Response
    {
        $kind = $this->kindParam($request);
        $this->lists->reactivate($kind, (int) $request->params['id']);
        $this->success('Reactivated.');
        return $this->done($request, '/admin/lists?tab=' . $kind);
    }

    public function delete(Request $request): Response
    {
        $kind = $this->kindParam($request);
        $this->lists->delete($kind, (int) $request->params['id']);
        $this->success('Deleted.');
        return $this->done($request, '/admin/lists?tab=' . $kind);
    }

    private function kindParam(Request $request): string
    {
        $kind = (string) ($request->params['kind'] ?? '');
        if (!isset(ListAdmin::KINDS[$kind])) {
            $this->notFound('There is no list called "' . $kind . '".');
        }
        return $kind;
    }
}
