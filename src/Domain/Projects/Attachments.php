<?php
declare(strict_types=1);

namespace Chroma\Domain\Projects;

use Chroma\Core\Db;
use Chroma\Core\Settings;
use Chroma\Core\ValidationException;
use Chroma\Domain\Audit\AuditLog;

/**
 * Files on projects and items: drawdown photos, iQC exports, artwork,
 * press-trial reports, formula-card PDFs. Stored under FILES_PATH/projects/{id}/
 * with a random name; the original name is kept in the row. Deleting is a
 * soft delete (deleted_at) so the file stays in the backup.
 */
final class Attachments
{
    public const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /** Extensions we refuse because a browser or the server could run them. */
    private const BLOCKED_EXT = ['php', 'phtml', 'phar', 'exe', 'bat', 'cmd', 'sh', 'js', 'html', 'htm', 'svg', 'msi', 'com', 'scr'];

    /** Detected content we refuse whatever the file is called (the extension check above is only the first line). */
    private const BLOCKED_MIME = ['text/html', 'application/xhtml+xml', 'image/svg+xml', 'application/x-httpd-php', 'text/x-php', 'application/x-php', 'application/javascript', 'text/javascript', 'application/ecmascript', 'text/ecmascript'];

    /** Image extensions must hold an image; a "photo.jpg" that is really a web page is refused. */
    private const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    private Activity $activity;

    public function __construct(private readonly Db $db, private readonly AuditLog $audit, private readonly Settings $settings, private readonly string $filesPath)
    {
        $this->activity = new Activity($db, $audit);
    }

    public function maxBytes(): int
    {
        return max(1, $this->settings->int('max_upload_mb', 25)) * 1024 * 1024;
    }

    public function maxMb(): int
    {
        return max(1, $this->settings->int('max_upload_mb', 25));
    }

    /**
     * Store one uploaded file (an entry from $_FILES).
     * @param array{name?:string,tmp_name?:string,size?:int,error?:int,type?:string} $file
     * @return array<string,mixed> the attachment row
     */
    public function store(int $projectId, array $file, ?int $actorId, ?int $itemId = null, string $caption = ''): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            throw ValidationException::single('file', 'Choose a file to attach first.');
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw ValidationException::single('file', sprintf('That file is too big. The limit is %d MB; shrink the image or split the document.', $this->maxMb()));
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw ValidationException::single('file', 'The upload did not finish. Try again; if it keeps failing, tell an administrator.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        if ($tmp === '' || !is_file($tmp)) {
            throw ValidationException::single('file', 'The upload did not finish. Try again.');
        }
        if ($size > $this->maxBytes()) {
            throw ValidationException::single('file', sprintf('That file is %s, over the %d MB limit. Shrink the image or split the document.', self::human($size), $this->maxMb()));
        }
        if ($size === 0) {
            throw ValidationException::single('file', 'That file is empty. Pick a different file.');
        }
        $original = self::cleanName((string) ($file['name'] ?? 'file'));
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (in_array($ext, self::BLOCKED_EXT, true)) {
            throw ValidationException::single('file', 'Files of that type cannot be attached. Attach images, PDFs, spreadsheets or documents.');
        }
        $mime = (string) (mime_content_type($tmp) ?: 'application/octet-stream');
        if (self::dangerousMime($mime)) {
            throw ValidationException::single('file', 'That file holds a web page or a program, so it cannot be attached. Attach images, PDFs, spreadsheets or documents.');
        }
        if (in_array($ext, self::IMAGE_EXT, true) && !str_starts_with($mime, 'image/')) {
            throw ValidationException::single('file', sprintf('That file is called .%s but it is not an image. Attach the real photo, or save the file with the right name first.', $ext));
        }
        $dir = $this->dir($projectId);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw ValidationException::general('The server could not create the folder for attachments. Tell an administrator (check FILES_PATH permissions).');
        }
        $stored = bin2hex(random_bytes(16)) . ($ext !== '' ? '.' . $ext : '');
        $dest = $dir . '/' . $stored;
        $moved = is_uploaded_file($tmp) ? move_uploaded_file($tmp, $dest) : rename($tmp, $dest);
        if (!$moved) {
            throw ValidationException::general('The server could not save the file. Tell an administrator (check FILES_PATH permissions).');
        }
        @chmod($dest, 0664);
        $id = $this->db->insert('chroma_project_attachments', [
            'project_id' => $projectId,
            'item_id' => $itemId,
            'original_name' => mb_substr($original, 0, 255),
            'stored_name' => $stored,
            'mime' => mb_substr($mime, 0, 100),
            'size_bytes' => $size,
            'caption' => mb_substr(trim($caption), 0, 255),
            'uploaded_by' => $actorId,
            'created_at' => $this->db->now(),
        ]);
        $row = $this->get($id);
        $this->audit->record('attachment.add', 'chroma_project_attachments', $id, null, $row, 'Attached ' . $original, $actorId);
        $this->activity->system($projectId, 'attachment_added', 'Attached ' . $original . ($caption !== '' ? ' — ' . $caption : ''), $actorId, $itemId, ['attachment_id' => $id]);
        return $row;
    }

    /** Soft delete: the row and the file stay; it disappears from the project. */
    public function remove(int $id, ?int $actorId): void
    {
        $row = $this->get($id);
        if ($row['deleted_at'] !== null) {
            return;
        }
        $this->db->update('chroma_project_attachments', ['deleted_at' => $this->db->now(), 'deleted_by' => $actorId], ['id' => $id]);
        $this->audit->record('attachment.remove', 'chroma_project_attachments', $id, $row, $this->get($id), 'Removed attachment ' . $row['original_name'], $actorId);
        $this->activity->system((int) $row['project_id'], 'attachment_removed', 'Removed attachment ' . $row['original_name'], $actorId, $row['item_id'] ? (int) $row['item_id'] : null);
    }

    /** @return array<string,mixed> */
    public function get(int $id): array
    {
        $row = $this->db->one('SELECT a.*, u.display_name AS uploaded_by_name FROM chroma_project_attachments a LEFT JOIN chroma_users u ON u.id = a.uploaded_by WHERE a.id = ?', [$id]);
        if ($row === null) {
            throw ValidationException::general('That attachment no longer exists.');
        }
        return self::decorate($row);
    }

    /** Live attachments of a project, newest first. @return list<array<string,mixed>> */
    public function forProject(int $projectId): array
    {
        return array_map([self::class, 'decorate'], $this->db->all(
            'SELECT a.*, u.display_name AS uploaded_by_name, i.description AS item_description FROM chroma_project_attachments a
             LEFT JOIN chroma_users u ON u.id = a.uploaded_by LEFT JOIN chroma_project_items i ON i.id = a.item_id
             WHERE a.project_id = ? AND a.deleted_at IS NULL ORDER BY a.id DESC',
            [$projectId]
        ));
    }

    /** Absolute path of the stored file. */
    public function path(array $row): string
    {
        return $this->dir((int) $row['project_id']) . '/' . basename((string) $row['stored_name']);
    }

    public function dir(int $projectId): string
    {
        return rtrim($this->filesPath, '/') . '/projects/' . $projectId;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    public static function decorate(array $row): array
    {
        $row['is_image'] = in_array((string) $row['mime'], self::IMAGE_TYPES, true);
        $row['size_human'] = self::human((int) $row['size_bytes']);
        return $row;
    }

    public static function human(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024) . ' KB';
        }
        return $bytes . ' B';
    }

    /** Web pages, scripts and executables, by detected type rather than by name. */
    public static function dangerousMime(string $mime): bool
    {
        $mime = strtolower(trim(explode(';', $mime)[0]));
        if (in_array($mime, self::BLOCKED_MIME, true)) {
            return true;
        }
        // application/x-… executables and scripts (x-sh, x-shellscript, x-perl, x-python, x-dosexec, x-executable, x-msdownload, x-elf …)
        return (bool) preg_match('#^application/x-(httpd-|)(php|sh|shellscript|csh|perl|python|ruby|javascript|msdownload|msi|ms-installer|dosexec|executable|pie-executable|sharedlib|elf|object|mach-binary|shockwave-flash|bat|java-archive)#', $mime);
    }

    private static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? $name;
        return trim($name) === '' ? 'file' : trim($name);
    }
}
