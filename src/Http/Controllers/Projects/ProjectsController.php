<?php
declare(strict_types=1);

namespace Chroma\Http\Controllers\Projects;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Chroma\Core\Permissions;
use Chroma\Core\Request;
use Chroma\Core\Response;
use Chroma\Domain\Export\Exporter;
use Chroma\Domain\ProjectTypes\ProjectTypes;
use Chroma\Domain\Projects\Projects;

/** Projects list, new project (Expert page), project page, edit, status changes, print, notes. */
final class ProjectsController extends ProjectController
{
    // ------------------------------------------------------------------
    // List
    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function filters(Request $request): array
    {
        $status = $request->str('status', Projects::STATUS_OPEN);
        return [
            'status' => in_array($status, array_merge(array_keys(Projects::STATUSES), ['all']), true) ? $status : Projects::STATUS_OPEN,
            'customer_id' => $request->int('customer_id'),
            'tech' => $request->int('tech'),
            'type' => $request->str('type'),
            'from' => $request->str('from'),
            'to' => $request->str('to'),
            'mine' => $request->bool('mine') ? $this->userId() : null,
            'q' => $request->str('q'),
        ];
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        $paging = $this->paging($request, is_easy() ? 25 : 50);
        $result = $this->projects->list($filters, $this->visibility, $paging['per_page'], $paging['offset']);
        $query = array_filter([
            'status' => $filters['status'] !== Projects::STATUS_OPEN ? $filters['status'] : null,
            'customer_id' => $filters['customer_id'], 'tech' => $filters['tech'], 'type' => $filters['type'],
            'from' => $filters['from'], 'to' => $filters['to'], 'mine' => $filters['mine'] ? 1 : null, 'q' => $filters['q'],
            'per_page' => $paging['per_page'] !== (is_easy() ? 25 : 50) ? $paging['per_page'] : null,
        ], fn($v) => $v !== null && $v !== '');
        $filtered = (bool) array_diff_key($query, ['per_page' => 1]);
        return $this->view('projects/index', [
            'title' => 'Projects',
            'rows' => $result['rows'],
            'total' => $result['total'],
            'paging' => $paging,
            'filters' => $filters,
            'query' => $query,
            'filtered' => $filtered,
            'types' => $this->projects->types()->all(),
            'techs' => $this->db->all('SELECT DISTINCT u.id, u.display_name, u.username FROM chroma_users u JOIN chroma_projects p ON p.assigned_user_id = u.id ORDER BY u.display_name'),
            'customers' => $this->db->all('SELECT DISTINCT c.id, c.name FROM chroma_customers c JOIN chroma_projects p ON p.customer_id = c.id ORDER BY c.name LIMIT 500'),
            'anyProjects' => (int) $this->db->value('SELECT COUNT(*) FROM chroma_projects') > 0,
        ]);
    }

    public function export(Request $request): Response
    {
        $filters = $this->filters($request);
        // Page through the whole list in chunks so the file holds every matching project, not the first N.
        $rows = [];
        $chunk = 1000;
        for ($offset = 0; ; $offset += $chunk) {
            $page = $this->projects->list($filters, $this->visibility, $chunk, $offset);
            foreach ($page['rows'] as $r) {
                $rows[] = $r;
            }
            if (count($page['rows']) < $chunk || count($rows) >= (int) $page['total']) {
                break;
            }
        }
        $columns = [
            'project_number' => 'Project #', 'type_label' => 'Type', 'customer_name' => 'Customer', 'title' => 'Title', 'summary' => 'Description',
            'date_submitted' => 'Submitted', 'date_completed' => 'Completed', 'target_date' => 'Target', 'tech_name' => 'Tech',
            'items_done' => 'Items done', 'item_count' => 'Items', 'status' => 'Status', 'stage_label' => 'Stage', 'confidential' => 'Confidential',
        ];
        $rows = array_map(fn($r) => [
            'project_number' => (int) $r['project_number'], 'type_label' => $r['type_label'], 'customer_name' => $r['customer_name'] ?? '', 'title' => $r['title'], 'summary' => $r['summary'],
            'date_submitted' => fmt_date($r['date_submitted'], 'Y-m-d'), 'date_completed' => fmt_date($r['date_completed'], 'Y-m-d'), 'target_date' => fmt_date($r['target_date'], 'Y-m-d'),
            'tech_name' => $r['tech_name'] ?? '', 'items_done' => (int) $r['items_done'], 'item_count' => (int) $r['item_count'],
            'status' => Projects::STATUSES[$r['status']] ?? $r['status'], 'stage_label' => $r['stage_label'] ?? '', 'confidential' => (int) $r['confidential'] === 1,
        ], $rows);
        return Exporter::respond($request->str('format', 'csv') === 'xlsx' ? 'xlsx' : 'csv', 'projects', $columns, $rows);
    }

    /** Typeahead for "move to another project". */
    public function search(Request $request): Response
    {
        return $this->json(['results' => $this->projects->search($request->str('q'), $this->visibility, 15, $request->int('exclude'))]);
    }

    // ------------------------------------------------------------------
    // New project (Expert: one page). Easy mode uses /wizard/new-project.
    // ------------------------------------------------------------------

    public function create(Request $request): Response
    {
        if (is_easy() && !$request->bool('expert')) {
            return $this->redirect('/wizard/new-project');
        }
        $types = $this->projects->types()->all();
        $typeCode = $request->str('type') ?: array_key_first($types);
        $defaults = $request->query;
        $items = [];
        foreach ($request->arr('items') as $it) {
            if (is_array($it) && trim((string) ($it['description'] ?? '')) !== '') {
                $items[] = $it;
            }
        }
        $customerLabel = '';
        if ($cid = $request->int('customer_id')) {
            $customerLabel = (string) ($this->db->value('SELECT name FROM chroma_customers WHERE id = ?', [$cid]) ?? '');
        }
        return $this->view('projects/new', [
            'title' => 'New project',
            'types' => $types,
            'typeCode' => isset($types[$typeCode]) ? $typeCode : array_key_first($types),
            'fieldsByType' => array_map(fn($t) => $this->projects->types()->fields($t, false), $types),
            'defaults' => $defaults,
            'items' => $items,
            'customerLabel' => $customerLabel,
            'maxItems' => $this->projects->maxItems(),
            // The page's Easy link submits the form's values into the wizard (data-mode-carry in the view); this is the no-JS fallback.
            'easyUrl' => url('/mode', ['set' => 'easy', 'return' => url('/wizard/new-project', array_filter(array_diff_key($defaults, ['expert' => 1]), fn($v) => is_string($v) && $v !== '') + ['jump' => 1])]),
        ] + $this->referenceLists());
    }

    public function store(Request $request): Response
    {
        $data = $request->body;
        unset($data['_token']);
        $data['items'] = array_values($request->arr('items'));
        $data['team'] = $request->arr('team');
        // The lookup component posts a typed-in new customer as customer_id_text; the domain expects customer_text (same as the wizard).
        if (trim((string) ($data['customer_text'] ?? '')) === '' && trim((string) ($data['customer_id_text'] ?? '')) !== '') {
            $data['customer_text'] = trim((string) $data['customer_id_text']);
        }
        $project = $this->projects->create($data, $this->userId());
        $this->success('Project ' . $project['project_number'] . ' created.', count($data['items']) ? null : 'Add the first ' . mb_strtolower((string) $project['item_label_singular']) . ' below.');
        return $this->finish($request, $this->projectUrl((int) $project['id']), ['id' => (int) $project['id'], 'project_number' => (int) $project['project_number']]);
    }

    // ------------------------------------------------------------------
    // Project page
    // ------------------------------------------------------------------

    public function show(Request $request): Response
    {
        $project = $this->loadProject($request);
        $type = $this->projects->types()->byId((int) $project['project_type_id']) ?? [];
        $items = $this->items->forProject((int) $project['id']);
        $attachments = $this->attachments();
        return $this->view('projects/show', [
            'title' => 'Project ' . $project['project_number'] . ' · ' . $project['title'],
            'project' => $project,
            'type' => $type,
            'fields' => $this->headerFields($project, $type),
            'items' => $items,
            'statuses' => $this->items->statusesFor((int) $project['project_type_id']),
            'activity' => $this->projects->activity()->forProject((int) $project['id']),
            'tasks' => $this->tasks()->forProject((int) $project['id']),
            'attachments' => $attachments->forProject((int) $project['id']),
            'maxUploadMb' => $attachments->maxMb(),
            'team' => $this->projects->team((int) $project['id']),
            'maxItems' => $this->projects->maxItems(),
            'iqcHint' => $this->app->settings->bool('sell_number_is_iqc_standard'),
            'kinds' => ProjectTypes::ITEM_KINDS,
        ] + $this->referenceLists());
    }

    /**
     * The header fields for the project page (Expert: inline editable) and the Edit details page (Easy).
     * @param array<string,mixed> $project @param array<string,mixed> $type
     * @return list<array<string,mixed>> name, label, type, value, display, options, help
     */
    private function headerFields(array $project, array $type): array
    {
        $out = [];
        if ((int) ($type['has_customer'] ?? 0) === 1) {
            $out[] = ['name' => 'customer_id', 'label' => 'Customer', 'type' => 'lookup', 'value' => $project['customer_id'], 'display' => (string) ($project['customer_name'] ?? ''), 'help' => 'Start typing to search; keep typing to add a new customer.'];
        }
        foreach ($this->projects->types()->fields($type) as $spec) {
            $name = $spec['name'];
            $f = ['name' => $name, 'label' => $spec['short'], 'long_label' => $spec['label'], 'type' => $spec['type'], 'help' => $spec['help'], 'tip' => $spec['tip'] ?? null, 'later' => $spec['later'], 'options' => []];
            switch ($spec['type']) {
                case 'textarea':
                    $f['value'] = (string) ($project[$name] ?? '');
                    $f['display'] = $f['value'];
                    break;
                case 'date':
                    $f['value'] = (string) ($project[$name] ?? '');
                    $f['display'] = fmt_date($project[$name] ?? null);
                    break;
                case 'user':
                    $f['value'] = $project[$name];
                    $f['display'] = (string) ($project['tech_name'] ?? '');
                    $f['type'] = 'lookup';
                    $f['lookup'] = '/api/users?q=';
                    break;
                case 'stage':
                    $f['value'] = $project['stage_id'];
                    $f['display'] = (string) ($project['stage_label'] ?? '');
                    $f['type'] = 'select';
                    $f['options'] = array_map(fn($s) => [(int) $s['id'], $s['label']], $type['stages'] ?? []);
                    break;
                case 'checkbox':
                    $f['value'] = (int) ($project[$name] ?? 0);
                    $f['display'] = $f['value'] ? 'Yes' : 'No';
                    break;
                case 'ink_series':
                    $f['value'] = $project['default_ink_series_id'];
                    $f['display'] = (string) ($project['default_ink_series_name'] ?? '');
                    $f['type'] = 'select';
                    $f['options'] = array_map(fn($r) => [(int) $r['id'], $r['name']], $this->db->all('SELECT id, name FROM chroma_ink_series WHERE active = 1 OR id = ? ORDER BY sort_order, name', [(int) ($project['default_ink_series_id'] ?? 0)]));
                    break;
                case 'coating':
                    $f['value'] = $project['default_coating_id'];
                    $f['display'] = (string) ($project['default_coating_name'] ?? '');
                    $f['type'] = 'select';
                    $f['options'] = array_map(fn($r) => [(int) $r['id'], $r['name']], $this->db->all('SELECT id, name FROM chroma_coatings WHERE active = 1 OR id = ? ORDER BY sort_order, name', [(int) ($project['default_coating_id'] ?? 0)]));
                    break;
                case 'substrate':
                    $f['name'] = 'default_substrate';
                    $f['value'] = (string) ($project['default_substrate_text'] ?? '');
                    $f['display'] = $f['value'];
                    $f['type'] = 'text';
                    $f['list'] = 'substrates';
                    break;
                default:
                    continue 2;
            }
            $out[] = $f;
        }
        return $out;
    }

    /** Save details. JSON (Expert inline, one field at a time) or a normal form post (Easy edit page). */
    public function update(Request $request): Response
    {
        $project = $this->loadProject($request);
        $data = $request->body;
        unset($data['_token'], $data['_method']);
        // The edit page always posts team_present=1; with nobody selected the browser sends no team[] at all,
        // which must still clear the team (otherwise a removed person keeps access to a confidential project).
        if (array_key_exists('team', $data) || $request->arr('team') !== [] || $request->bool('team_present')) {
            $data['team'] = $request->arr('team');
        }
        unset($data['team_present']);
        if (trim((string) ($data['customer_text'] ?? '')) === '' && trim((string) ($data['customer_id_text'] ?? '')) !== '') {
            $data['customer_text'] = trim((string) $data['customer_id_text']);
        }
        $after = $this->projects->update((int) $project['id'], $data, $this->userId());
        if ($request->wantsJson()) {
            $type = $this->projects->types()->byId((int) $after['project_type_id']) ?? [];
            $display = [];
            foreach ($this->headerFields($after, $type) as $f) {
                $display[$f['name']] = ['value' => $f['value'], 'display' => $f['display']];
            }
            return $this->json(['ok' => true, 'message' => 'Saved', 'fields' => $display, 'customer_name' => $after['customer_name'], 'title' => $after['title'], 'stage_label' => $after['stage_label']]);
        }
        $this->success('Project ' . $after['project_number'] . ' updated.');
        return $this->redirect($this->projectUrl((int) $project['id']));
    }

    /** Easy mode: the "Edit details" page. */
    public function edit(Request $request): Response
    {
        $project = $this->loadProject($request);
        $type = $this->projects->types()->byId((int) $project['project_type_id']) ?? [];
        return $this->view('projects/edit', [
            'title' => 'Edit project ' . $project['project_number'],
            'project' => $project,
            'type' => $type,
            'fields' => $this->headerFields($project, $type),
            'team' => $this->projects->team((int) $project['id']),
        ] + $this->referenceLists());
    }

    public function stage(Request $request): Response
    {
        $project = $this->loadProject($request);
        $after = $this->projects->setStage((int) $project['id'], $request->int('stage_id'), $this->userId());
        $this->success('Stage set to ' . ($after['stage_label'] ?: 'none') . '.');
        return $this->finish($request, $this->projectUrl((int) $project['id']), ['stage_label' => $after['stage_label']]);
    }

    public function complete(Request $request): Response
    {
        $project = $this->loadProject($request);
        $after = $this->projects->complete((int) $project['id'], $this->userId());
        $this->success('Project ' . $after['project_number'] . ' marked completed.', 'Completed ' . fmt_date($after['date_completed']) . '. You can reopen it if needed.');
        return $this->finish($request, $this->projectUrl((int) $project['id']));
    }

    public function cancel(Request $request): Response
    {
        $project = $this->loadProject($request);
        $after = $this->projects->cancel((int) $project['id'], $request->str('reason'), $this->userId());
        $this->success('Project ' . $after['project_number'] . ' cancelled.', 'It stays in the list under Cancelled and can be reopened.');
        return $this->finish($request, $this->projectUrl((int) $project['id']));
    }

    public function reopen(Request $request): Response
    {
        $project = $this->loadProject($request);
        $after = $this->projects->reopen((int) $project['id'], $this->userId());
        $this->success('Project ' . $after['project_number'] . ' reopened.');
        return $this->finish($request, $this->projectUrl((int) $project['id']));
    }

    public function note(Request $request): Response
    {
        $project = $this->loadProject($request);
        $itemId = $request->int('item_id');
        if ($itemId !== null && !$this->db->value('SELECT COUNT(*) FROM chroma_project_items WHERE id = ? AND project_id = ?', [$itemId, (int) $project['id']])) {
            $itemId = null;
        }
        $this->projects->activity()->note((int) $project['id'], $request->str('body'), $this->userId(), $itemId);
        $this->success('Note added.');
        return $this->finish($request, $this->projectUrl((int) $project['id'], '#activity'));
    }

    // ------------------------------------------------------------------
    // Print
    // ------------------------------------------------------------------

    public function print(Request $request): Response
    {
        $project = $this->loadProject($request);
        $items = $this->items->forProject((int) $project['id']);
        $base = rtrim((string) ($this->app->config->get('APP_URL') ?: ''), '/');
        $url = $base . $this->projectUrl((int) $project['id']);
        $qr = '';
        try {
            $qr = (string) (new QRCode(new QROptions([
                'outputBase64' => false,
                'svgAddXmlHeader' => false,
                'addQuietzone' => true,
                'quietzoneSize' => 2,
                'scale' => 4,
            ])))->render($url);
        } catch (\Throwable $e) {
            $this->app->log->error('QR code failed: ' . $e->getMessage());
        }
        return $this->view('projects/print', [
            'title' => 'Project ' . $project['project_number'],
            'project' => $project,
            'items' => $items,
            'team' => $this->projects->team((int) $project['id']),
            'qr' => $qr,
            'url' => $url,
            'fields' => $this->headerFields($project, $this->projects->types()->byId((int) $project['project_type_id']) ?? []),
            'companyName' => (string) $this->app->settings->get('company_name', ''),
        ], 'layouts/plain');
    }
}
