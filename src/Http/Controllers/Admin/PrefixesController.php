<?php
declare(strict_types=1);

namespace Chroma\Http\Controllers\Admin;

use Chroma\Core\Permissions;
use Chroma\Core\Request;
use Chroma\Core\Response;
use Chroma\Domain\Codes\PrefixAdmin;
use Chroma\Domain\Export\Exporter;
use Chroma\Http\Controller;

/**
 * Admin → Prefixes. Expert-mode forms; the Easy-mode "add prefix" wizard
 * (/wizard/add-prefix) calls the same PrefixAdmin operations.
 */
final class PrefixesController extends Controller
{
    private function admin(): PrefixAdmin
    {
        return new PrefixAdmin($this->db, $this->app->audit);
    }

    /** Where to go after a one-click action: the page the button was on, if it is one of ours. */
    private function returnTo(Request $request, string $default): string
    {
        $r = $request->str('return');
        return ($r !== '' && str_starts_with($r, '/admin/') && !str_starts_with($r, '//')) ? $r : $default;
    }

    /** @return array{q:string,status:string} */
    private function filters(Request $request): array
    {
        $status = $request->str('status', 'all');
        return [
            'q' => mb_substr($request->str('q'), 0, 60),
            'status' => in_array($status, ['all', 'active', 'retired'], true) ? $status : 'all',
        ];
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        $paging = $this->paging($request, 50);
        $list = $this->admin()->listPrefixes($filters, $paging['per_page'], $paging['offset']);
        $counts = [
            'all' => (int) $this->db->value('SELECT COUNT(*) FROM chroma_code_prefixes'),
            'active' => (int) $this->db->value('SELECT COUNT(*) FROM chroma_code_prefixes WHERE retired = 0'),
            'retired' => (int) $this->db->value('SELECT COUNT(*) FROM chroma_code_prefixes WHERE retired = 1'),
        ];
        return $this->view('admin/prefixes/index', [
            'title' => 'Prefixes',
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
        foreach ($this->admin()->allPrefixes() as $p) {
            $rows[] = [
                'code' => $p['code'],
                'label' => $p['label'],
                'description' => $p['description'],
                'status' => $p['retired'] ? 'Retired' : 'Active',
                'tag' => $p['tag'] !== '' ? ucfirst((string) $p['tag']) : '',
                'min_digits' => (int) $p['min_digits'],
                'last_used' => $p['last_used_display'],
                'next' => $p['next_display'],
                'code_count' => $p['code_count'],
                'suffixes' => implode(', ', $p['suffix_codes']),
                'sort_order' => (int) $p['sort_order'],
                'retired_at' => fmt_datetime($p['retired_at']),
            ];
        }
        return Exporter::respond($format, 'prefixes', [
            'code' => 'Prefix', 'label' => 'Name', 'description' => 'Description', 'status' => 'Status', 'tag' => 'Tag',
            'min_digits' => 'Digits', 'last_used' => 'Last used', 'next' => 'Next number', 'code_count' => 'Codes',
            'suffixes' => 'Allowed suffixes', 'sort_order' => 'Sort order', 'retired_at' => 'Retired on',
        ], $rows);
    }

    public function create(Request $request): Response
    {
        $defaults = [
            'code' => strtoupper($request->str('code')),
            'label' => $request->str('label'),
            'description' => $request->str('description'),
            'min_digits' => $request->str('min_digits', '4'),
            'starting_number' => $request->str('starting_number', '1'),
            'sort_order' => $request->str('sort_order', '100'),
            'suffix_ids' => array_map('intval', $request->arr('suffix_ids')),
        ];
        return $this->view('admin/prefixes/new', [
            'title' => 'Add a prefix',
            'suffixes' => $this->admin()->activeSuffixes(),
            'defaults' => $defaults,
        ]);
    }

    public function store(Request $request): Response
    {
        $admin = $this->admin();
        $id = $admin->createPrefix([
            'code' => $request->str('code'),
            'label' => $request->str('label'),
            'description' => $request->str('description'),
            'min_digits' => $request->str('min_digits'),
            'starting_number' => $request->str('starting_number'),
            'sort_order' => $request->str('sort_order', '100'),
            'suffix_ids' => $request->arr('suffix_ids'),
        ], $this->userId());
        $p = $admin->prefix($id);
        $this->success(sprintf('Prefix %s added.', $p['code']), sprintf('The first %s number given out will be %s.', $p['code'], $p['next_display']));
        return $this->redirect('/admin/prefixes/' . $id);
    }

    public function edit(Request $request): Response
    {
        $admin = $this->admin();
        $p = $admin->prefix((int) $request->params['id']);
        if ($p === null) {
            $this->notFound('That prefix could not be found. The link may be out of date: go back to the prefixes list and open it from there.');
        }
        return $this->view('admin/prefixes/edit', [
            'title' => 'Prefix ' . $p['code'],
            'prefix' => $p,
            'suffixes' => $admin->activeSuffixes(),
            'history' => $this->app->audit->forRow('chroma_code_prefixes', (int) $p['id'], 25),
            'canSetNext' => $this->auth->can(Permissions::ADMIN_SEQUENCES),
        ]);
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $admin = $this->admin();
        if ($admin->prefix($id) === null) {
            $this->notFound('That prefix could not be found. Go back to the prefixes list and open it from there.');
        }
        $admin->updatePrefix($id, [
            'code' => $request->str('code'),
            'label' => $request->str('label'),
            'description' => $request->str('description'),
            'min_digits' => $request->str('min_digits'),
            'sort_order' => $request->str('sort_order'),
            'suffix_ids' => $request->arr('suffix_ids'),
        ], $this->userId());
        $p = $admin->prefix($id);
        $this->success(sprintf('Prefix %s saved.', $p['code']));
        return $this->redirect('/admin/prefixes/' . $id);
    }

    public function setNextNumber(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $admin = $this->admin();
        $p = $admin->prefix($id);
        if ($p === null) {
            $this->notFound('That prefix could not be found. Go back to the prefixes list and open it from there.');
        }
        $admin->setNextNumber($id, $request->str('next_number'), $this->userId());
        $after = $admin->prefix($id);
        $this->success(sprintf('The next %s number is now %s.', $after['code'], $after['next_display']));
        return $this->redirect('/admin/prefixes/' . $id);
    }

    public function retire(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $admin = $this->admin();
        $admin->retirePrefix($id, $this->userId());
        $p = $admin->prefix($id);
        $this->success(sprintf('Prefix %s retired.', $p['code']), 'It no longer appears when assigning codes. Existing codes are untouched.');
        return $this->redirect($this->returnTo($request, '/admin/prefixes/' . $id));
    }

    public function unretire(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $admin = $this->admin();
        $admin->unretirePrefix($id, $this->userId());
        $p = $admin->prefix($id);
        $this->success(sprintf('Prefix %s is active again.', $p['code']), sprintf('The next %s number will be %s.', $p['code'], $p['next_display']));
        return $this->redirect($this->returnTo($request, '/admin/prefixes/' . $id));
    }
}
