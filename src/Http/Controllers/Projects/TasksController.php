<?php
declare(strict_types=1);

namespace Chroma\Http\Controllers\Projects;

use Chroma\Core\ForbiddenException;
use Chroma\Core\Permissions;
use Chroma\Core\Request;
use Chroma\Core\Response;

/** Tasks / next actions on a project. POST /projects/{id}/tasks/{tid}/done is also used by the home page. */
final class TasksController extends ProjectController
{
    public function store(Request $request): Response
    {
        $project = $this->loadProject($request);
        $task = $this->tasks()->add((int) $project['id'], [
            'title' => $request->str('title'),
            'owner_user_id' => $request->int('owner_user_id'),
            'due_date' => $request->str('due_date'),
        ], $this->userId());
        $this->success('Task added: ' . $task['title']);
        return $this->finish($request, $this->projectUrl((int) $project['id'], '#tasks'), ['task' => $task]);
    }

    /** @return array<string,mixed> */
    private function loadTask(Request $request, array $project): array
    {
        $task = $this->db->one('SELECT * FROM chroma_project_tasks WHERE id = ? AND project_id = ?', [(int) ($request->params['tid'] ?? 0), (int) $project['id']]);
        if ($task === null) {
            $this->notFound('That task is not on this project any more.');
        }
        // Anyone who can see the project may tick off a task they own; others need projects.edit.
        if ((int) ($task['owner_user_id'] ?? 0) !== $this->userId() && !$this->auth->can(Permissions::PROJECTS_EDIT)) {
            throw new ForbiddenException();
        }
        return $task;
    }

    public function markDone(Request $request): Response
    {
        $project = $this->loadProject($request);
        $task = $this->loadTask($request, $project);
        $this->tasks()->setDone((int) $task['id'], true, $this->userId());
        $this->success('Done: ' . $task['title']);
        $back = $request->header('referer');
        $target = $back && preg_match('#^(https?://[^/]+)?/(?!login)#', $back) ? (string) parse_url($back, PHP_URL_PATH) : $this->projectUrl((int) $project['id'], '#tasks');
        return $this->finish($request, $target);
    }

    public function undo(Request $request): Response
    {
        $project = $this->loadProject($request);
        $task = $this->loadTask($request, $project);
        $this->tasks()->setDone((int) $task['id'], false, $this->userId());
        $this->success('Reopened: ' . $task['title']);
        return $this->finish($request, $this->projectUrl((int) $project['id'], '#tasks'));
    }

    public function remove(Request $request): Response
    {
        $project = $this->loadProject($request);
        $task = $this->loadTask($request, $project);
        $this->tasks()->remove((int) $task['id'], $this->userId());
        $this->success('Task removed.');
        return $this->finish($request, $this->projectUrl((int) $project['id'], '#tasks'));
    }
}
