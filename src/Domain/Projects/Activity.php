<?php
declare(strict_types=1);

namespace Chroma\Domain\Projects;

use Chroma\Core\Db;
use Chroma\Core\ValidationException;
use Chroma\Domain\Audit\AuditLog;

/**
 * The project's diary: dated, attributed notes written by people plus
 * automatic entries (code assigned, stage changed, item added …).
 * Notes support simple markdown and @mentions; rendering happens in the
 * view with the markdown() helper.
 */
final class Activity
{
    public const KIND_NOTE = 'note';

    public function __construct(private readonly Db $db, private readonly AuditLog $audit)
    {
    }

    /** Write a note by a person. @return int the activity id */
    public function note(int $projectId, string $body, ?int $actorId, ?int $itemId = null): int
    {
        $body = trim($body);
        if ($body === '') {
            throw ValidationException::single('body', 'Type the note first, then press Add note.');
        }
        if (mb_strlen($body) > 20000) {
            throw ValidationException::single('body', 'That note is very long. Keep it under 20,000 characters, or attach a file instead.');
        }
        $id = $this->db->insert('chroma_project_activity', [
            'project_id' => $projectId,
            'item_id' => $itemId,
            'user_id' => $actorId,
            'kind' => self::KIND_NOTE,
            'body' => $body,
            'meta_json' => json_encode(['mentions' => self::mentions($body)]),
            'created_at' => $this->db->now(),
        ]);
        $this->audit->record('project.note', 'chroma_project_activity', $id, null, ['project_id' => $projectId, 'body' => mb_substr($body, 0, 200)], 'Note added to project', $actorId);
        return $id;
    }

    /**
     * Automatic entry (stage changed, item added, proof count changed …).
     * @param array<string,mixed> $meta
     */
    public function system(int $projectId, string $kind, string $body, ?int $actorId, ?int $itemId = null, array $meta = []): int
    {
        return $this->db->insert('chroma_project_activity', [
            'project_id' => $projectId,
            'item_id' => $itemId,
            'user_id' => $actorId,
            'kind' => mb_substr($kind, 0, 30),
            'body' => mb_substr($body, 0, 2000),
            'meta_json' => $meta ? json_encode($meta) : null,
            'created_at' => $this->db->now(),
        ]);
    }

    /** Newest first, with the author's name and the item's description. @return list<array<string,mixed>> */
    public function forProject(int $projectId, int $limit = 200): array
    {
        return $this->db->all(
            'SELECT a.*, u.display_name, u.username, i.description AS item_description, i.sequence AS item_sequence
             FROM chroma_project_activity a
             LEFT JOIN chroma_users u ON u.id = a.user_id
             LEFT JOIN chroma_project_items i ON i.id = a.item_id
             WHERE a.project_id = ? ORDER BY a.id DESC LIMIT ' . (int) $limit,
            [$projectId]
        );
    }

    /** Usernames mentioned as @name in a note. @return list<string> */
    public static function mentions(string $body): array
    {
        preg_match_all('/(?:^|\s)@([A-Za-z0-9_.-]+)/', $body, $m);
        return array_values(array_unique($m[1] ?? []));
    }
}
