<?php
declare(strict_types=1);

namespace Chroma\Http\Controllers\Projects;

use Chroma\Core\App;
use Chroma\Core\Request;
use Chroma\Domain\Codes\CodeAssigner;
use Chroma\Domain\Projects\Attachments;
use Chroma\Domain\Projects\Items;
use Chroma\Domain\Projects\Projects;
use Chroma\Domain\Projects\Tasks;
use Chroma\Domain\Projects\Visibility;
use Chroma\Http\Controller;

/**
 * Shared plumbing for the project controllers: the domain services and
 * the visibility check. A confidential project the user may not see is a
 * 404, never a 403, so its existence is not leaked.
 */
abstract class ProjectController extends Controller
{
    protected Projects $projects;
    protected Items $items;
    protected Visibility $visibility;

    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->projects = new Projects($this->db, $app->audit, $app->settings);
        $this->items = $this->projects->items();
        $this->visibility = new Visibility($this->db, $this->auth);
    }

    protected function tasks(): Tasks
    {
        return new Tasks($this->db, $this->app->audit);
    }

    protected function attachments(): Attachments
    {
        $path = (string) ($this->app->config->get('FILES_PATH') ?: $this->app->root . '/storage/files');
        return new Attachments($this->db, $this->app->audit, $this->app->settings, $path);
    }

    protected function codes(): CodeAssigner
    {
        return new CodeAssigner($this->db, $this->app->audit);
    }

    /** Load the project from {id}, or 404 when it does not exist or the user may not see it. @return array<string,mixed> */
    protected function loadProject(Request $request, string $param = 'id'): array
    {
        $id = (int) ($request->params[$param] ?? 0);
        $project = $id > 0 ? $this->projects->find($id) : null;
        if ($project === null || !$this->visibility->canView($project)) {
            $this->notFound('There is no project with that number, or it is not one you can open.');
        }
        return $project;
    }

    /** Load an item that belongs to the loaded project, or 404. @param array<string,mixed> $project @return array<string,mixed> */
    protected function loadItem(Request $request, array $project, string $param = 'iid'): array
    {
        $iid = (int) ($request->params[$param] ?? 0);
        $item = $iid > 0 ? $this->items->find($iid) : null;
        if ($item === null || (int) $item['project_id'] !== (int) $project['id']) {
            $this->notFound('That color or item is not on this project. It may have been moved or removed.');
        }
        return $item;
    }

    /** Reference lists the forms need. @return array<string,mixed> */
    protected function referenceLists(): array
    {
        return [
            'inkSeries' => $this->db->all('SELECT id, name FROM chroma_ink_series WHERE active = 1 ORDER BY sort_order, name'),
            'coatings' => $this->db->all('SELECT id, name FROM chroma_coatings WHERE active = 1 ORDER BY sort_order, name'),
            'substrates' => $this->db->all('SELECT id, name FROM chroma_substrates WHERE active = 1 ORDER BY sort_order, name'),
            'users' => $this->db->all('SELECT id, username, display_name FROM chroma_users WHERE active = 1 ORDER BY display_name, username'),
            'prefixes' => $this->db->all('SELECT id, code, label, min_digits FROM chroma_code_prefixes WHERE retired = 0 ORDER BY sort_order, code'),
            'prefixSuffixes' => $this->prefixSuffixes(),
        ];
    }

    /** Allowed suffixes per prefix id. @return array<int,list<array{id:int,code:string,label:string}>> */
    protected function prefixSuffixes(): array
    {
        $out = [];
        foreach ($this->db->all('SELECT ps.prefix_id, s.id, s.code, s.label FROM chroma_code_prefix_suffixes ps JOIN chroma_code_suffixes s ON s.id = ps.suffix_id WHERE s.active = 1 ORDER BY s.sort_order, s.code') as $r) {
            $out[(int) $r['prefix_id']][] = ['id' => (int) $r['id'], 'code' => $r['code'], 'label' => $r['label']];
        }
        return $out;
    }

    /**
     * Finish a write: redirect (HTML) or JSON (fetch). Own helper so this module does not depend on the base
     * Controller::done(), which currently clashes with WizardController::done() (see docs/requests/projects.md).
     * @param array<string,mixed> $payload
     */
    protected function finish(Request $request, string $redirectTo, array $payload = []): \Chroma\Core\Response
    {
        if ($request->wantsJson()) {
            return $this->json(['ok' => true, 'redirect' => $redirectTo] + $payload);
        }
        return $this->redirect($redirectTo);
    }

    /** Project page URL. */
    protected function projectUrl(int $id, string $suffix = ''): string
    {
        return '/projects/' . $id . $suffix;
    }
}
