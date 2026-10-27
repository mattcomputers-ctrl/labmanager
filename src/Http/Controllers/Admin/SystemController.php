<?php
declare(strict_types=1);

namespace Chroma\Http\Controllers\Admin;

use Chroma\Core\Helper;
use Chroma\Core\Request;
use Chroma\Core\Response;
use Chroma\Core\ValidationException;
use Chroma\Domain\Api\Tokens;
use Chroma\Domain\System\Backups;
use Chroma\Domain\System\SystemInfo;
use Chroma\Domain\System\Updates;
use Chroma\Http\Controller;

/** Admin → System: version, updates, backups, logs, API tokens. */
final class SystemController extends Controller
{
    private function helper(): Helper
    {
        return new Helper($this->app->log);
    }

    private function backups(): Backups
    {
        return new Backups($this->helper(), (string) $this->app->config->get('BACKUP_PATH', Backups::DEFAULT_DIR));
    }

    private function updates(): Updates
    {
        return new Updates($this->helper(), dirname($this->app->log->path()) . '/jobs');
    }

    public function index(Request $request): Response
    {
        $info = new SystemInfo($this->app);
        $backups = $this->backups();
        $newToken = $this->app->session->pull('_new_api_token');
        return $this->view('admin/system/index', [
            'title' => 'System',
            'info' => $info->summary(),
            'disk' => $info->disk(),
            'logTail' => $info->logTail(100),
            'helperAvailable' => $this->helper()->available(),
            'backups' => $backups->list(),
            'backupDir' => $backups->dir(),
            'tokens' => (new Tokens($this->db))->all(),
            'newToken' => is_array($newToken) ? $newToken : null,
            'job' => preg_match('/^\d{8}-\d{6}-[0-9a-f]{8}$/', (string) $request->input('job', '')) ? (string) $request->input('job') : null,
        ]);
    }

    public function checkUpdate(Request $request): Response
    {
        $result = $this->updates()->check();
        $this->app->audit->record('system.check_update', '', null, null, ['state' => $result['state']], $result['message']);
        if ($request->wantsJson()) {
            return $this->json($result);
        }
        $result['ok'] ? $this->success($result['message']) : $this->failure($result['message']);
        return $this->redirect('/admin/system');
    }

    public function update(Request $request): Response
    {
        try {
            $job = $this->updates()->start($request->str('branch'));
        } catch (\RuntimeException $e) {
            $this->app->audit->record('system.update_refused', '', null, null, null, $e->getMessage());
            if ($request->wantsJson()) {
                return $this->json(['error' => $e->getMessage()], 422);
            }
            $this->failure($e->getMessage());
            return $this->redirect('/admin/system');
        }
        $this->app->audit->record('system.update_started', '', null, null, ['job' => $job, 'branch' => $request->str('branch')]);
        if ($request->wantsJson()) {
            return $this->json(['ok' => true, 'job' => $job, 'log_url' => '/admin/system/update/log?job=' . $job]);
        }
        return $this->redirect('/admin/system?job=' . $job . '#updates');
    }

    public function updateLog(Request $request): Response
    {
        try {
            $data = $this->updates()->read($request->str('job'), max(0, $request->int('from', 0) ?? 0));
        } catch (ValidationException $e) {
            return $this->json(['error' => $e->getMessage()], 404);
        }
        return $this->json($data);
    }

    public function backup(Request $request): Response
    {
        $r = $this->backups()->create();
        $this->app->audit->record($r['ok'] ? 'system.backup' : 'system.backup_failed', '', null, null, ['path' => $r['path']], $r['message']);
        $r['ok'] ? $this->success($r['message'], 'Download it from the list below.') : $this->failure('The backup could not be made.', $r['message']);
        return $this->redirect('/admin/system#backups');
    }

    public function downloadBackup(Request $request): Response
    {
        $path = $this->backups()->resolveDownload((string) $request->params['name']);
        $this->app->audit->record('system.backup_download', '', null, null, ['file' => basename($path)]);
        $size = (int) filesize($path);
        if ($size > self::STREAM_ABOVE_BYTES && !headers_sent()) {
            // Response::file() loads the whole file into memory. Once backups carry the
            // attachments tarball that can exceed PHP's memory_limit, so big files are
            // streamed straight from disk instead (headers and content identical).
            $this->streamFile($path, $size);
        }
        return Response::file($path, basename($path), 'application/octet-stream');
    }

    /** Backups bigger than this are streamed with readfile() rather than buffered. */
    public const STREAM_ABOVE_BYTES = 8 * 1024 * 1024;

    private function streamFile(string $path, int $size): never
    {
        http_response_code(200);
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . $size);
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', basename($path)) . '"');
        header('X-Content-Type-Options: nosniff');
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        session_write_close();
        readfile($path);
        exit;
    }

    public function log(Request $request): Response
    {
        $lines = (new SystemInfo($this->app))->logTail(min(1000, max(10, $request->int('lines', 100) ?? 100)));
        if ($request->wantsJson()) {
            return $this->json(['lines' => $lines]);
        }
        return Response::text(implode("\n", $lines));
    }

    public function createToken(Request $request): Response
    {
        [$id, $plain] = (new Tokens($this->db))->create($request->str('name'), $this->userId());
        $this->app->audit->record('api_token.create', 'chroma_api_tokens', $id, null, ['name' => $request->str('name')]);
        $this->app->session->set('_new_api_token', ['id' => $id, 'name' => $request->str('name'), 'token' => $plain]);
        $this->success('Token created. Copy it now: it is only shown once.');
        return $this->redirect('/admin/system#tokens');
    }

    public function revokeToken(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $row = $this->db->one('SELECT * FROM chroma_api_tokens WHERE id = ?', [$id]);
        if ($row === null) {
            $this->notFound('That token does not exist.');
        }
        if ($row['revoked_at'] === null) {
            (new Tokens($this->db))->revoke($id);
            $this->app->audit->record('api_token.revoke', 'chroma_api_tokens', $id, ['name' => $row['name'], 'revoked_at' => null], ['revoked_at' => $this->db->now()], (string) $request->str('reason'));
        }
        $this->success('Token "' . $row['name'] . '" was revoked. Anything still using it will be refused from now on.');
        return $this->redirect('/admin/system#tokens');
    }
}
