<?php
declare(strict_types=1);

namespace Chroma\Domain\Projects;

use Chroma\Core\Db;
use Chroma\Core\ValidationException;
use Chroma\Core\Validator;
use Chroma\Domain\Audit\AuditLog;

/** Next actions on a project: owner, due date, done. Open ones show on the owner's home page. */
final class Tasks
{
    public function __construct(private readonly Db $db, private readonly AuditLog $audit)
    {
    }

    /** @param array<string,mixed> $data title, owner_user_id, due_date */
    public function add(int $projectId, array $data, ?int $actorId): array
    {
        $v = new Validator($data);
        $v->required('title', 'Say what needs doing, in a few words.')->maxLength('title', 255, 'Keep the task to 255 characters; put the detail in a note.');
        $v->date('due_date');
        $v->fail();
        $owner = isset($data['owner_user_id']) && (int) $data['owner_user_id'] > 0 ? (int) $data['owner_user_id'] : null;
        if ($owner !== null && !$this->db->value('SELECT COUNT(*) FROM chroma_users WHERE id = ? AND active = 1', [$owner])) {
            throw ValidationException::single('owner_user_id', 'That person is not an active user. Pick someone from the list.');
        }
        $now = $this->db->now();
        $id = $this->db->insert('chroma_project_tasks', [
            'project_id' => $projectId,
            'title' => trim((string) $data['title']),
            'owner_user_id' => $owner,
            'due_date' => !empty($data['due_date']) ? $data['due_date'] : null,
            'done' => 0,
            'created_by' => $actorId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $row = $this->find($id);
        $this->audit->record('task.add', 'chroma_project_tasks', $id, null, $row, 'Task added', $actorId);
        return $row;
    }

    public function setDone(int $taskId, bool $done, ?int $actorId): array
    {
        $before = $this->find($taskId);
        $this->db->update('chroma_project_tasks', [
            'done' => $done ? 1 : 0,
            'done_at' => $done ? $this->db->now() : null,
            'done_by' => $done ? $actorId : null,
            'updated_at' => $this->db->now(),
        ], ['id' => $taskId]);
        $after = $this->find($taskId);
        $this->audit->record($done ? 'task.done' : 'task.reopen', 'chroma_project_tasks', $taskId, $before, $after, $done ? 'Task marked done' : 'Task reopened', $actorId);
        return $after;
    }

    public function remove(int $taskId, ?int $actorId): void
    {
        $before = $this->find($taskId);
        $this->db->exec('DELETE FROM chroma_project_tasks WHERE id = ?', [$taskId]);
        $this->audit->record('task.remove', 'chroma_project_tasks', $taskId, $before, null, 'Task removed', $actorId);
    }

    /** @return array<string,mixed> */
    public function find(int $id): array
    {
        $row = $this->db->one('SELECT * FROM chroma_project_tasks WHERE id = ?', [$id]);
        if ($row === null) {
            throw ValidationException::general('That task no longer exists. It may have been removed by someone else.');
        }
        return $row;
    }

    /** Open first, then done; with owner names. @return list<array<string,mixed>> */
    public function forProject(int $projectId): array
    {
        return $this->db->all(
            'SELECT t.*, u.display_name AS owner_name, u.username AS owner_username FROM chroma_project_tasks t LEFT JOIN chroma_users u ON u.id = t.owner_user_id
             WHERE t.project_id = ? ORDER BY t.done, t.due_date IS NULL, t.due_date, t.id',
            [$projectId]
        );
    }
}
