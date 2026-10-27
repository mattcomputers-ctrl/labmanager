<?php
declare(strict_types=1);

namespace Chroma\Http\Controllers\Admin;

use Chroma\Core\Request;
use Chroma\Core\Response;
use Chroma\Domain\Codes\PrefixAdmin;
use Chroma\Domain\Export\Exporter;
use Chroma\Http\Controller;

/** Admin → Suffixes: the master list (UV, ND, F, HS …). Which prefixes allow each one is set on the prefix. */
final class SuffixesController extends Controller
{
    private function admin(): PrefixAdmin
    {
        return new PrefixAdmin($this->db, $this->app->audit);
    }

    /** @return array{q:string,status:string} */
    private function filters(Request $request): array
    {
        $status = $request->str('status', 'all');
        return [
            'q' => mb_substr($request->str('q'), 0, 60),
            'status' => in_array($status, ['all', 'active', 'inactive'], true) ? $status : 'all',
        ];
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        $paging = $this->paging($request, 50);
        $list = $this->admin()->listSuffixes($filters, $paging['per_page'], $paging['offset']);
        $counts = [
            'all' => (int) $this->db->value('SELECT COUNT(*) FROM chroma_code_suffixes'),
            'active' => (int) $this->db->value('SELECT COUNT(*) FROM chroma_code_suffixes WHERE active = 1'),
            'inactive' => (int) $this->db->value('SELECT COUNT(*) FROM chroma_code_suffixes WHERE active = 0'),
        ];
        return $this->view('admin/suffixes/index', [
            'title' => 'Suffixes',
            'rows' => $list['rows'],
            'total' => $list['total'],
            'filters' => $filters,
            'counts' => $counts,
            'paging' => $paging,
        ]);
    }

    public function export(Request $request): Response
    {
        $format = $request->str('format') === 'xlsx' ? 'xlsx' : 'csv';
        $rows = [];
        foreach ($this->admin()->listSuffixes([], 10000, 0)['rows'] as $s) {
            $rows[] = [
                'code' => $s['code'],
                'label' => $s['label'],
                'status' => $s['active'] ? 'On' : 'Off',
                'prefixes' => implode(', ', array_map(fn($p) => $p['code'], $s['prefixes'])),
                'code_count' => $s['code_count'],
                'sort_order' => (int) $s['sort_order'],
            ];
        }
        return Exporter::respond($format, 'suffixes', [
            'code' => 'Suffix', 'label' => 'Name', 'status' => 'Status', 'prefixes' => 'Allowed on prefixes', 'code_count' => 'Codes using it', 'sort_order' => 'Sort order',
        ], $rows);
    }

    public function create(Request $request): Response
    {
        return $this->view('admin/suffixes/form', [
            'title' => 'Add a suffix',
            'suffix' => null,
            'history' => [],
        ]);
    }

    public function store(Request $request): Response
    {
        $admin = $this->admin();
        $id = $admin->createSuffix([
            'code' => $request->str('code'),
            'label' => $request->str('label'),
            'sort_order' => $request->str('sort_order', '100'),
            'active' => $request->input('active', '1'),
        ], $this->userId());
        $s = $admin->suffix($id);
        $this->success(sprintf('Suffix %s added.', $s['code']), 'Tick it on each prefix that may use it, under Admin → Prefixes.');
        return $this->redirect('/admin/suffixes/' . $id);
    }

    public function edit(Request $request): Response
    {
        $s = $this->admin()->suffix((int) $request->params['id']);
        if ($s === null) {
            $this->notFound('There is no suffix with that id.');
        }
        return $this->view('admin/suffixes/form', [
            'title' => 'Suffix ' . $s['code'],
            'suffix' => $s,
            'history' => $this->app->audit->forRow('chroma_code_suffixes', (int) $s['id'], 25),
        ]);
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $admin = $this->admin();
        if ($admin->suffix($id) === null) {
            $this->notFound('There is no suffix with that id.');
        }
        $admin->updateSuffix($id, [
            'code' => $request->str('code'),
            'label' => $request->str('label'),
            'sort_order' => $request->str('sort_order'),
            'active' => $request->input('active', '0'),
        ], $this->userId());
        $s = $admin->suffix($id);
        $this->success(sprintf('Suffix %s saved.', $s['code']));
        return $this->redirect('/admin/suffixes/' . $id);
    }

    /** One-click on/off from the list. */
    public function setActive(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $admin = $this->admin();
        if ($admin->suffix($id) === null) {
            $this->notFound('There is no suffix with that id.');
        }
        $on = $request->bool('active');
        $admin->setSuffixActive($id, $on, $this->userId());
        $s = $admin->suffix($id);
        $this->success(
            sprintf('Suffix %s switched %s.', $s['code'], $on ? 'on' : 'off'),
            $on ? 'Prefixes that allow it will offer it again.' : 'Existing codes keep it; new codes cannot use it until it is switched back on.'
        );
        $r = $request->str('return');
        $to = ($r !== '' && str_starts_with($r, '/admin/') && !str_starts_with($r, '//')) ? $r : '/admin/suffixes';
        return $this->redirect($to);
    }
}
