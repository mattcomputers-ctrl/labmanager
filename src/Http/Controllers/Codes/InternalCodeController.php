<?php
declare(strict_types=1);

namespace Chroma\Http\Controllers\Codes;

use Chroma\Core\ForbiddenException;
use Chroma\Core\Permissions;
use Chroma\Core\Request;
use Chroma\Core\Response;
use Chroma\Domain\Codes\CodeAssigner;
use Chroma\Domain\Codes\CodeDetails;
use Chroma\Http\Controller;

/** /codes/e/{id} — one E number: fields, its sell numbers, where it came from, history, notes, void. */
final class InternalCodeController extends Controller
{
    private function details(): CodeDetails
    {
        return new CodeDetails($this->db, $this->app->audit);
    }

    /** Notes and descriptions may be edited by anyone who can assign codes. */
    private function requireEditor(): void
    {
        if (!$this->auth->canAny(Permissions::CODES_ASSIGN_INTERNAL, Permissions::CODES_ASSIGN_SELL)) {
            throw new ForbiddenException();
        }
    }

    public function show(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $data = $this->details()->internal($id, $this->app->settings->get('sds_base_url'));
        if ($data === null) {
            $this->notFound('There is no E number with that id. It may have been typed wrongly; try the code lookup.');
        }
        return $this->view('codes/internal', $data + [
            'title' => $data['code']['display'],
            'canEdit' => $this->auth->canAny(Permissions::CODES_ASSIGN_INTERNAL, Permissions::CODES_ASSIGN_SELL),
        ]);
    }

    public function notes(Request $request): Response
    {
        $this->requireEditor();
        $id = (int) $request->params['id'];
        $this->details()->updateNotes('internal', $id, $request->str('notes'), $this->userId());
        $this->success('Notes saved.');
        return $this->done($request, '/codes/e/' . $id);
    }

    public function description(Request $request): Response
    {
        $this->requireEditor();
        $id = (int) $request->params['id'];
        $this->details()->updateDescription('internal', $id, $request->str('description'), $this->userId());
        $this->success('Description saved.');
        return $this->done($request, '/codes/e/' . $id);
    }

    public function void(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $codes = new CodeAssigner($this->db, $this->app->audit);
        $codes->voidInternal($id, $request->str('reason'), $this->userId());
        $this->success($codes->internalRow($id)['display'] . ' was voided.', 'It stays in the logs, greyed out, and the number will not be reused.');
        return $this->done($request, '/codes/e/' . $id);
    }
}
