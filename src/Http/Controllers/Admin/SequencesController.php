<?php
declare(strict_types=1);

namespace Chroma\Http\Controllers\Admin;

use Chroma\Core\Request;
use Chroma\Core\Response;
use Chroma\Domain\Codes\PrefixAdmin;
use Chroma\Domain\Export\Exporter;
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
        $export = $request->str('export');
        if ($export === 'csv' || $export === 'xlsx') {
            $this->auth->requirePermission(\Chroma\Core\Permissions::CODES_EXPORT);
            $st = $this->admin()->sequenceStatus();
            $rows = [
                ['sequence' => 'E numbers', 'highest' => $st['internal']['highest_display'], 'next' => $st['internal']['next_display'], 'stored' => $st['internal']['stored']],
                ['sequence' => 'Project numbers', 'highest' => $st['project']['highest'] ?: '', 'next' => $st['project']['next'], 'stored' => $st['project']['stored']],
            ];
            foreach ($this->db->all("SELECT p.code, p.label, p.next_number, (SELECT MAX(s.number) FROM chroma_sell_numbers s WHERE s.prefix_id = p.id) AS highest FROM chroma_code_prefixes p ORDER BY p.retired, p.sort_order, p.code") as $p) {
                $rows[] = ['sequence' => 'Prefix ' . $p['code'] . ' — ' . $p['label'], 'highest' => $p['highest'] ?? '', 'next' => max((int) $p['next_number'], (int) ($p['highest'] ?? 0) + 1), 'stored' => (int) $p['next_number']];
            }
            return Exporter::respond($export, 'sequences', ['sequence' => 'Sequence', 'highest' => 'Highest used', 'next' => 'Next to be given out', 'stored' => 'Counter value'], $rows);
        }
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
