<?php
declare(strict_types=1);

namespace Chroma\Http\Controllers\Admin;

use Chroma\Core\Request;
use Chroma\Core\Response;
use Chroma\Core\ValidationException;
use Chroma\Domain\Export\Exporter;
use Chroma\Domain\Import\ImportIssues;
use Chroma\Domain\Import\ImportPlan;
use Chroma\Domain\Import\LegacyImporter;
use Chroma\Http\Controller;

/**
 * Admin → Import legacy codes.
 *
 * The import is a three-step flow in both Easy and Expert mode, because it
 * is sequential by nature: 1 upload, 2 dry run, 3 commit. The dry run is
 * recorded in chroma_imports so the review screen can be reloaded, and the
 * commit re-reads the stored files (nothing is kept in the session).
 * Plus the history of past runs and the issues list with resolve actions.
 */
final class ImportController extends Controller
{
    public const MAX_FILE_BYTES = 25 * 1024 * 1024;
    public const MAX_FILES = 3;

    private function importer(): LegacyImporter
    {
        return new LegacyImporter($this->db, $this->app->audit);
    }

    private function issueService(): ImportIssues
    {
        return new ImportIssues($this->db, $this->app->audit);
    }

    private function importsDir(): string
    {
        $base = (string) $this->app->config->get('FILES_PATH', $this->app->root . '/storage/files');
        return rtrim($base, '/') . '/imports';
    }

    // ------------------------------------------------------------------
    // Step 1: upload
    // ------------------------------------------------------------------

    public function index(Request $request): Response
    {
        $lastRun = $this->db->one("SELECT i.*, u.display_name FROM chroma_imports i LEFT JOIN chroma_users u ON u.id = i.started_by WHERE i.dry_run = 0 ORDER BY i.id DESC LIMIT 1");
        $pendingDryRun = $this->db->one("SELECT * FROM chroma_imports WHERE status = 'dry_run' AND started_by = ? ORDER BY id DESC LIMIT 1", [$this->userId()]);
        return $this->view('admin/import/index', [
            'title' => 'Import legacy codes',
            'step' => 1,
            'lastRun' => $lastRun,
            'pendingDryRun' => $pendingDryRun,
            'openIssues' => $this->issueService()->openCount(),
            'maxMb' => (int) (self::MAX_FILE_BYTES / 1048576),
            'phpLimit' => $this->phpUploadLimit(),
        ]);
    }

    public function upload(Request $request): Response
    {
        $files = $this->uploadedFiles($request);
        if ($files === []) {
            throw ValidationException::single('files', 'Choose at least one spreadsheet to upload (.xls or .xlsx).');
        }
        if (count($files) > self::MAX_FILES) {
            throw ValidationException::single('files', 'Upload at most three files: the cross-reference, the security log and the UV log.');
        }
        $dir = $this->importsDir();
        $token = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $target = $dir . '/' . $token;
        if (!is_dir($target) && !mkdir($target, 0770, true) && !is_dir($target)) {
            throw ValidationException::general('The upload folder could not be created. Ask an administrator to check that ' . $dir . ' is writable.');
        }
        $stored = [];
        foreach ($files as $i => $f) {
            $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $f['name']) ?: 'file';
            $path = $target . '/' . ($i + 1) . '-' . $safe;
            $ok = is_uploaded_file($f['tmp_name']) ? move_uploaded_file($f['tmp_name'], $path) : rename($f['tmp_name'], $path);
            if (!$ok) {
                $this->removeDir($target);
                throw ValidationException::single('files', sprintf('"%s" could not be saved on the server. Try the upload again.', $f['name']));
            }
            $stored[] = ['path' => $path, 'name' => $f['name']];
        }
        try {
            $plan = $this->importer()->dryRun($stored, ['map_uvv' => $request->input('map_uvv') === null ? true : $request->bool('map_uvv')], $this->userId());
        } catch (ValidationException $e) {
            $this->removeDir($target);
            throw $e;
        }
        $this->app->flash->info('Dry run finished. Nothing has been written yet.', 'Check the report, then commit when it looks right.');
        return $this->redirect('/admin/import/review/' . $plan->importId);
    }

    // ------------------------------------------------------------------
    // Step 2: review the dry run
    // ------------------------------------------------------------------

    public function review(Request $request): Response
    {
        $run = $this->run((int) $request->params['id']);
        if ((int) $run['dry_run'] !== 1) {
            return $this->redirect('/admin/import/done/' . $run['id']);
        }
        $summary = $run['summary'];
        $filesOk = true;
        foreach ($run['files'] as $f) {
            if (!is_file((string) ($f['path'] ?? ''))) {
                $filesOk = false;
            }
        }
        return $this->view('admin/import/review', [
            'title' => 'Import legacy codes',
            'step' => 2,
            'run' => $run,
            'summary' => $summary,
            'filesOk' => $filesOk,
            'needsUvvConfirm' => !empty($summary['options']['map_uvv']) && (($summary['lists']['uvv_rows']['total'] ?? 0) > 0),
            'committedAs' => $run['status'] === 'committed' ? $this->db->one("SELECT id FROM chroma_imports WHERE dry_run = 0 AND files_json = ? ORDER BY id DESC LIMIT 1", [$run['files_json']]) : null,
        ]);
    }

    /** Re-run the dry run on the same files with a different UVV choice. */
    public function rerun(Request $request): Response
    {
        $run = $this->run((int) $request->params['id']);
        $this->requireFiles($run);
        $plan = $this->importer()->dryRun($run['files'], ['map_uvv' => $request->bool('map_uvv')], $this->userId());
        $this->db->update('chroma_imports', ['status' => 'superseded'], ['id' => (int) $run['id']]);
        return $this->redirect('/admin/import/review/' . $plan->importId);
    }

    // ------------------------------------------------------------------
    // Step 3: commit
    // ------------------------------------------------------------------

    public function commit(Request $request): Response
    {
        $run = $this->run((int) $request->params['id']);
        if ($run['status'] !== 'dry_run') {
            $this->failure('That dry run was already committed or replaced. Start again from the upload step if you need to import more.');
            return $this->redirect('/admin/import/history');
        }
        $this->requireFiles($run);
        $mapUvv = !empty($run['summary']['options']['map_uvv']);
        $uvvRows = (int) ($run['summary']['lists']['uvv_rows']['total'] ?? 0);
        if ($mapUvv && $uvvRows > 0 && !$request->bool('confirm_uvv')) {
            throw ValidationException::single('confirm_uvv', 'Tick the box to confirm that the UVV rows should be imported under UVVL, or switch the mapping off and run the dry run again.');
        }
        if (!$request->bool('confirm_commit')) {
            throw ValidationException::single('confirm_commit', 'Tick the box to confirm you have checked the dry run before committing.');
        }
        @set_time_limit(600);
        // The importer claims the dry-run row (row lock + status) and holds a server-wide
        // lock while it runs, so a second tab or a double click cannot commit it twice.
        $plan = $this->importer()->commit($run['files'], ['map_uvv' => $mapUvv], $this->userId(), (int) $run['id']);
        $a = $plan->applied;
        $this->success('Import finished.', sprintf('%s E numbers and %s sell numbers created, %s filled in, %s issues to look at.', number_format($a['create_internal']), number_format($a['create_sell']), number_format($a['update_internal'] + $a['update_sell']), number_format($a['issues'])));
        return $this->redirect('/admin/import/done/' . $plan->importId);
    }

    public function finished(Request $request): Response
    {
        $run = $this->run((int) $request->params['id']);
        return $this->view('admin/import/done', [
            'title' => 'Import legacy codes',
            'step' => 3,
            'run' => $run,
            'summary' => $run['summary'],
            'openIssues' => (int) $this->db->value("SELECT COUNT(*) FROM chroma_import_issues WHERE import_id = ? AND status = 'open'", [(int) $run['id']]),
        ]);
    }

    // ------------------------------------------------------------------
    // History
    // ------------------------------------------------------------------

    public function history(Request $request): Response
    {
        $status = $request->str('status', 'all');
        $cond = match ($status) {
            'done' => "WHERE i.dry_run = 0 AND i.status = 'done'",
            'dry_run' => 'WHERE i.dry_run = 1',
            'failed' => "WHERE i.status = 'failed'",
            default => '',
        };
        $sql = "FROM chroma_imports i LEFT JOIN chroma_users u ON u.id = i.started_by $cond";
        $format = $request->str('export');
        if ($format === 'csv' || $format === 'xlsx') {
            $rows = array_map(fn($r) => $this->historyRow($r), $this->db->all("SELECT i.*, u.display_name $sql ORDER BY i.id DESC"));
            return Exporter::respond($format, 'import-history', [
                'id' => 'Run', 'started' => 'Started', 'finished' => 'Finished', 'by' => 'By', 'kind' => 'Kind', 'status' => 'Status', 'files' => 'Files',
                'create_internal' => 'E numbers created', 'create_sell' => 'Sell numbers created', 'update_internal' => 'E numbers filled in', 'update_sell' => 'Sell numbers filled in', 'issues' => 'Issues',
            ], $rows);
        }
        $paging = $this->paging($request, 25);
        $total = (int) $this->db->value("SELECT COUNT(*) $sql");
        $runs = array_map(fn($r) => $this->historyRow($r), $this->db->all("SELECT i.*, u.display_name $sql ORDER BY i.id DESC LIMIT {$paging['per_page']} OFFSET {$paging['offset']}"));
        return $this->view('admin/import/history', [
            'title' => 'Import history', 'runs' => $runs, 'total' => $total, 'paging' => $paging, 'status' => $status,
            'query' => array_filter(['status' => $status !== 'all' ? $status : null]),
        ]);
    }

    public function show(Request $request): Response
    {
        $run = $this->run((int) $request->params['id']);
        return $this->view('admin/import/show', [
            'title' => 'Import run #' . $run['id'],
            'run' => $run,
            'summary' => $run['summary'],
            'issueCount' => (int) $this->db->value('SELECT COUNT(*) FROM chroma_import_issues WHERE import_id = ?', [(int) $run['id']]),
        ]);
    }

    // ------------------------------------------------------------------
    // Issues
    // ------------------------------------------------------------------

    public function issues(Request $request): Response
    {
        $filters = [
            'status' => in_array($request->str('status', 'open'), ['open', 'resolved', 'all'], true) ? $request->str('status', 'open') : 'open',
            'type' => $request->str('type'),
            'q' => $request->str('q'),
            'import_id' => $request->int('import_id'),
        ];
        $svc = $this->issueService();
        $format = $request->str('export');
        if ($format === 'csv' || $format === 'xlsx') {
            $rows = array_map(fn($i) => [
                'id' => $i['id'], 'status' => $i['status'], 'type' => $i['type_label'], 'file' => $i['source_file'], 'row' => $i['source_row'],
                'code' => $i['code'], 'internal' => $i['internal'], 'description' => $i['description'], 'message' => $i['message'],
                'resolution' => $i['resolution'], 'resolution_note' => $i['resolution_note'], 'resolved_by' => $i['resolved_by_name'], 'resolved_at' => fmt_datetime($i['resolved_at']),
            ], $svc->all($filters));
            return Exporter::respond($format, 'import-issues', [
                'id' => 'Issue', 'status' => 'Status', 'type' => 'Type', 'file' => 'Sheet', 'row' => 'Row', 'code' => 'Code in sheet', 'internal' => 'Internal code', 'description' => 'Description',
                'message' => 'What happened', 'resolution' => 'Resolution', 'resolution_note' => 'Note', 'resolved_by' => 'Resolved by', 'resolved_at' => 'Resolved',
            ], $rows);
        }
        $paging = $this->paging($request, 50);
        $result = $svc->search($filters, $paging['per_page'], $paging['offset']);
        return $this->view('admin/import/issues', [
            'title' => 'Import issues',
            'issues' => $result['rows'],
            'total' => $result['total'],
            'paging' => $paging,
            'filters' => $filters,
            'firstOpenId' => $this->firstOpenIssueId(null, $filters['import_id']),
            'query' => array_filter(['status' => $filters['status'] !== 'open' ? $filters['status'] : null, 'type' => $filters['type'], 'q' => $filters['q'], 'import_id' => $filters['import_id']]),
            'types' => ImportPlan::issueTypes(),
            'openCounts' => $svc->openCounts(),
            'openTotal' => $svc->openCount(),
        ]);
    }

    public function issue(Request $request): Response
    {
        $svc = $this->issueService();
        $issue = $svc->find((int) $request->params['id']);
        if ($issue === null) {
            $this->notFound('That import issue does not exist.');
        }
        // ?import_id=N means the person is working through one run's issues; the
        // resolve actions keep that filter and move on to the next issue of the same run.
        return $this->view('admin/import/issue', [
            'title' => 'Import issue #' . $issue['id'],
            'issue' => $issue,
            'target' => $issue['status'] === 'open' ? $svc->mergeTarget($issue) : null,
            'importId' => $request->int('import_id'),
        ]);
    }

    public function correct(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $row = $this->issueService()->correct($id, $request->str('code'), $this->userId(), $request->str('note'));
        $this->success($row['display'] . ' was entered.', 'The issue is resolved and the code is in the cross-reference.');
        return $this->done($request, $this->nextIssueUrl($id, $request->int('import_id')), ['code' => $row['display']]);
    }

    public function merge(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $target = $this->issueService()->merge($id, $this->userId(), $request->str('note'));
        $this->success('Merged into ' . $target['display'] . '.', 'The description and comments were added to its notes.');
        return $this->done($request, $this->nextIssueUrl($id, $request->int('import_id')));
    }

    public function discard(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $this->issueService()->discard($id, $request->str('reason'), $this->userId());
        $this->success('Row left out.', 'The issue is marked resolved with your reason.');
        return $this->done($request, $this->nextIssueUrl($id, $request->int('import_id')));
    }

    public function reopen(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $this->issueService()->reopen($id, $this->userId());
        $this->success('Issue reopened.');
        return $this->done($request, '/admin/import/issues/' . $id);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** After resolving, go to the next open issue (of the same import run when one is being worked through), else back to the list. */
    private function nextIssueUrl(int $afterId, ?int $importId = null): string
    {
        $next = $this->firstOpenIssueId($afterId, $importId);
        $query = $importId ? ['import_id' => $importId] : [];
        return $next ? url('/admin/import/issues/' . $next, $query) : url('/admin/import/issues', $query);
    }

    /** The first open issue after $afterId (wrapping round to the start), optionally within one import run. */
    private function firstOpenIssueId(?int $afterId, ?int $importId): ?int
    {
        $cond = "status = 'open'" . ($importId ? ' AND import_id = ' . (int) $importId : '');
        $next = null;
        if ($afterId !== null) {
            $next = $this->db->value("SELECT id FROM chroma_import_issues WHERE $cond AND id > ? ORDER BY id LIMIT 1", [$afterId]);
        }
        $next ??= $this->db->value("SELECT id FROM chroma_import_issues WHERE $cond ORDER BY id LIMIT 1");
        return $next === null ? null : (int) $next;
    }

    /** @return array<string,mixed> the chroma_imports row with files and summary decoded */
    private function run(int $id): array
    {
        $run = $this->db->one('SELECT i.*, u.display_name FROM chroma_imports i LEFT JOIN chroma_users u ON u.id = i.started_by WHERE i.id = ?', [$id]);
        if ($run === null) {
            $this->notFound('That import run does not exist.');
        }
        $files = json_decode((string) $run['files_json'], true);
        $summary = json_decode((string) $run['summary_json'], true);
        $run['files'] = is_array($files) ? $files : [];
        $run['summary'] = is_array($summary) ? $summary : [];
        return $run;
    }

    /** @param array<string,mixed> $run */
    private function requireFiles(array $run): void
    {
        foreach ($run['files'] as $f) {
            if (!is_file((string) ($f['path'] ?? ''))) {
                throw ValidationException::general(sprintf('The uploaded file "%s" is no longer on the server, so this dry run cannot be used. Upload the files again.', $f['name'] ?? 'file'));
            }
        }
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private function historyRow(array $r): array
    {
        $s = json_decode((string) $r['summary_json'], true) ?: [];
        $files = json_decode((string) $r['files_json'], true) ?: [];
        $a = $s['applied'] ?? null;
        $t = $s['totals'] ?? [];
        return [
            'id' => (int) $r['id'],
            'started' => fmt_datetime($r['started_at']),
            'finished' => fmt_datetime($r['finished_at']),
            'by' => (string) ($r['display_name'] ?? ''),
            'kind' => (int) $r['dry_run'] === 1 ? 'Dry run' : 'Import',
            'status' => (string) $r['status'],
            'files' => implode(', ', array_map(fn($f) => (string) ($f['name'] ?? ''), $files)),
            'create_internal' => $a['create_internal'] ?? ($t['create_internal'] ?? 0),
            'create_sell' => $a['create_sell'] ?? ($t['create_sell'] ?? 0),
            'update_internal' => $a['update_internal'] ?? ($t['update_internal'] ?? 0),
            'update_sell' => $a['update_sell'] ?? ($t['update_sell'] ?? 0),
            'issues' => $a['issues'] ?? ($t['issues'] ?? 0),
            'dry_run' => (int) $r['dry_run'],
        ];
    }

    /**
     * Normalise $_FILES['files'] (multi-upload) into a list and validate each entry.
     * @return list<array{name:string,tmp_name:string,size:int}>
     */
    private function uploadedFiles(Request $request): array
    {
        $raw = $request->files['files'] ?? null;
        if (!is_array($raw) || !isset($raw['name'])) {
            return [];
        }
        $names = is_array($raw['name']) ? $raw['name'] : [$raw['name']];
        $tmps = is_array($raw['tmp_name']) ? $raw['tmp_name'] : [$raw['tmp_name']];
        $errs = is_array($raw['error']) ? $raw['error'] : [$raw['error']];
        $sizes = is_array($raw['size']) ? $raw['size'] : [$raw['size']];
        $out = [];
        foreach ($names as $i => $name) {
            $err = (int) ($errs[$i] ?? UPLOAD_ERR_NO_FILE);
            if ($err === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $name = basename((string) $name);
            if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                throw ValidationException::single('files', sprintf('"%s" is bigger than the server allows (%s). Save the sheet as .xlsx, which is much smaller, or ask an administrator to raise the upload limit.', $name, $this->phpUploadLimit()));
            }
            if ($err !== UPLOAD_ERR_OK) {
                throw ValidationException::single('files', sprintf('"%s" did not upload completely. Try again.', $name));
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, ['xls', 'xlsx'], true)) {
                throw ValidationException::single('files', sprintf('"%s" is not an Excel file. Chroma reads .xls and .xlsx only. In Excel use File → Save As and pick "Excel Workbook (.xlsx)".', $name));
            }
            $size = (int) ($sizes[$i] ?? 0);
            if ($size > self::MAX_FILE_BYTES) {
                throw ValidationException::single('files', sprintf('"%s" is %s, over the %d MB limit. Save it as .xlsx (smaller) or remove unused sheets and try again.', $name, self::human($size), (int) (self::MAX_FILE_BYTES / 1048576)));
            }
            $out[] = ['name' => $name, 'tmp_name' => (string) $tmps[$i], 'size' => $size];
        }
        return $out;
    }

    /** The lower of upload_max_filesize and post_max_size, as text. */
    private function phpUploadLimit(): string
    {
        $bytes = fn(string $v): int => match (strtolower(substr($v, -1))) {
            'g' => (int) $v * 1073741824, 'm' => (int) $v * 1048576, 'k' => (int) $v * 1024, default => (int) $v,
        };
        $u = $bytes((string) ini_get('upload_max_filesize'));
        $p = $bytes((string) ini_get('post_max_size'));
        return self::human(min($u ?: PHP_INT_MAX, $p ?: PHP_INT_MAX));
    }

    public static function human(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, $bytes >= 10485760 ? 0 : 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024) . ' KB';
        }
        return $bytes . ' bytes';
    }

    private function removeDir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }
}
