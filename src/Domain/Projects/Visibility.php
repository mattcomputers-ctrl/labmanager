<?php
declare(strict_types=1);

namespace Chroma\Domain\Projects;

use Chroma\Core\Auth;
use Chroma\Core\Db;
use Chroma\Core\Permissions;

/**
 * Who may open a project. Confidential projects are visible only to the
 * lead, the team, the person who created it, members of the "R&D" group,
 * Administrators and anyone holding projects.view_confidential. Everyone
 * else gets a 404 so the project's existence is not leaked.
 */
final class Visibility
{
    public const RND_GROUP = 'R&D';

    public function __construct(private readonly Db $db, private readonly Auth $auth)
    {
    }

    /** True when the current user may see every project, confidential ones included. */
    public function seesEverything(): bool
    {
        return $this->auth->isAdmin()
            || $this->auth->can(Permissions::PROJECTS_VIEW_CONFIDENTIAL)
            || in_array(self::RND_GROUP, $this->auth->groups(), true);
    }

    /** @param array<string,mixed> $project a chroma_projects row */
    public function canView(array $project): bool
    {
        if ((int) ($project['confidential'] ?? 0) !== 1) {
            return true;
        }
        if ($this->seesEverything()) {
            return true;
        }
        $uid = $this->auth->id();
        if ($uid === null) {
            return false;
        }
        if ((int) ($project['assigned_user_id'] ?? 0) === $uid || (int) ($project['created_by'] ?? 0) === $uid) {
            return true;
        }
        return (bool) $this->db->value('SELECT COUNT(*) FROM chroma_project_team WHERE project_id = ? AND user_id = ?', [(int) $project['id'], $uid]);
    }

    /**
     * SQL fragment restricting a query to projects the current user may see.
     * @return array{0:string,1:list<mixed>} [sql, params]
     */
    public function sqlFilter(string $alias = 'p'): array
    {
        if ($this->seesEverything()) {
            return ['1 = 1', []];
        }
        $uid = (int) ($this->auth->id() ?? 0);
        return [
            "($alias.confidential = 0 OR $alias.assigned_user_id = ? OR $alias.created_by = ? OR $alias.id IN (SELECT t.project_id FROM chroma_project_team t WHERE t.user_id = ?))",
            [$uid, $uid, $uid],
        ];
    }
}
