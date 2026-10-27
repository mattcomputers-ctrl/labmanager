<?php
declare(strict_types=1);

namespace Chroma\Http\Controllers\Projects;

use Chroma\Core\Request;
use Chroma\Core\Response;
use Chroma\Core\ValidationException;

/** Upload, preview/download and (soft) delete attachments on a project or item. */
final class AttachmentsController extends ProjectController
{
    public function store(Request $request): Response
    {
        $project = $this->loadProject($request);
        $itemId = $request->int('item_id');
        if ($itemId !== null && !$this->db->value('SELECT COUNT(*) FROM chroma_project_items WHERE id = ? AND project_id = ?', [$itemId, (int) $project['id']])) {
            $itemId = null;
        }
        $files = $request->files['file'] ?? null;
        if (!is_array($files)) {
            throw ValidationException::single('file', 'Choose a file to attach first.');
        }
        // One file or several (file[]).
        $list = is_array($files['name'] ?? null)
            ? array_map(fn($i) => ['name' => $files['name'][$i], 'tmp_name' => $files['tmp_name'][$i], 'size' => $files['size'][$i], 'error' => $files['error'][$i], 'type' => $files['type'][$i] ?? ''], array_keys($files['name']))
            : [$files];
        $stored = [];
        foreach ($list as $f) {
            if ((int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE && count($list) > 1) {
                continue;
            }
            $stored[] = $this->attachments()->store((int) $project['id'], $f, $this->userId(), $itemId, $request->str('caption'));
        }
        $this->success(count($stored) === 1 ? 'Attached ' . $stored[0]['original_name'] . '.' : count($stored) . ' files attached.');
        return $this->finish($request, $this->projectUrl((int) $project['id'], '#attachments'), ['attachments' => $stored]);
    }

    public function download(Request $request): Response
    {
        $project = $this->loadProject($request);
        $row = $this->db->one('SELECT * FROM chroma_project_attachments WHERE id = ? AND project_id = ? AND deleted_at IS NULL', [(int) ($request->params['aid'] ?? 0), (int) $project['id']]);
        if ($row === null) {
            $this->notFound('That attachment is not on this project any more.');
        }
        $att = $this->attachments();
        $path = $att->path($row);
        if (!is_file($path)) {
            $this->notFound('The file for that attachment is missing on the server. Tell an administrator.');
        }
        $inline = $request->bool('inline') && in_array((string) $row['mime'], array_merge(\Chroma\Domain\Projects\Attachments::IMAGE_TYPES, ['application/pdf']), true);
        $mime = $inline ? (string) $row['mime'] : 'application/octet-stream';
        return Response::file($path, (string) $row['original_name'], $mime, $inline)->header('X-Content-Type-Options', 'nosniff');
    }

    public function remove(Request $request): Response
    {
        $project = $this->loadProject($request);
        $row = $this->db->one('SELECT * FROM chroma_project_attachments WHERE id = ? AND project_id = ?', [(int) ($request->params['aid'] ?? 0), (int) $project['id']]);
        if ($row === null) {
            $this->notFound('That attachment is not on this project any more.');
        }
        $this->attachments()->remove((int) $row['id'], $this->userId());
        $this->success('Removed ' . $row['original_name'] . '.');
        return $this->finish($request, $this->projectUrl((int) $project['id'], '#attachments'));
    }
}
