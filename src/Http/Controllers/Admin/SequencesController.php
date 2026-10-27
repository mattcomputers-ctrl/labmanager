<?php
declare(strict_types=1);

namespace Chroma\Http\Controllers\Admin;

use Chroma\Core\Request;
use Chroma\Core\Response;
use Chroma\Domain\Codes\PrefixAdmin;
use Chroma\Http\Controller;

/** Admin → Sequences: the next E number and the next project number. Forward-only. */
final class SequencesController extends Controller
{
    private function admin(): PrefixAdmin
    {
        return new PrefixAdmin($this->db, $this->app->audit);
    }

    public function index(Request $request): Response
    {
        $history = $this->db->all(
            "SELECT * FROM chroma_audit_log WHERE action IN ('sequence.set_internal', 'sequence.set_project') ORDER BY id DESC LIMIT 20"
        );
        return $this->view('admin/sequences/index', [
            'title' => 'Sequences',
            'status' => $this->admin()->sequenceStatus(),
            'history' => $history,
        ]);
    }

    public function setInternal(Request $request): Response
    {
        $this->admin()->setInternalNext($request->str('next_value'), $this->userId());
        $s = $this->admin()->sequenceStatus();
        $this->success('The next E number is now ' . $s['internal']['next_display'] . '.');
        return $this->redirect('/admin/sequences');
    }

    public function setProject(Request $request): Response
    {
        $this->admin()->setProjectNext($request->str('project_next'), $this->userId());
        $s = $this->admin()->sequenceStatus();
        $this->success('The next project number is now ' . $s['project']['next'] . '.');
        return $this->redirect('/admin/sequences');
    }
}
