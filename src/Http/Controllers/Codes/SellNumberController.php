<?php
declare(strict_types=1);

namespace Chroma\Http\Controllers\Codes;

use Chroma\Core\ForbiddenException;
use Chroma\Core\Permissions;
use Chroma\Core\Request;
use Chroma\Core\Response;
use Chroma\Core\ValidationException;
use Chroma\Domain\Codes\CodeAssigner;
use Chroma\Domain\Codes\CodeDetails;
use Chroma\Domain\Codes\QuickAssign;
use Chroma\Http\Controller;

/** /codes/s/{id} — one sell number: fields, its E number and sibling sell numbers, origin, history, notes, void, link. */
final class SellNumberController extends Controller
{
    private function details(): CodeDetails
    {
        return new CodeDetails($this->db, $this->app->audit);
    }

    private function requireEditor(): void
    {
        if (!$this->auth->canAny(Permissions::CODES_ASSIGN_INTERNAL, Permissions::CODES_ASSIGN_SELL)) {
            throw new ForbiddenException();
        }
    }

    public function show(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $data = $this->details()->sell($id);
        if ($data === null) {
            $this->notFound('There is no sell number with that id. It may have been typed wrongly; try the code lookup.');
        }
        return $this->view('codes/sell', $data + [
            'title' => $data['code']['display'],
            'canEdit' => $this->auth->canAny(Permissions::CODES_ASSIGN_INTERNAL, Permissions::CODES_ASSIGN_SELL),
        ]);
    }

    public function notes(Request $request): Response
    {
        $this->requireEditor();
        $id = (int) $request->params['id'];
        $this->details()->updateNotes('sell', $id, $request->str('notes'), $this->userId());
        $this->success('Notes saved.');
        return $this->done($request, '/codes/s/' . $id);
    }

    public function description(Request $request): Response
    {
        $this->requireEditor();
        $id = (int) $request->params['id'];
        $this->details()->updateDescription('sell', $id, $request->str('description'), $this->userId());
        $this->success('Description saved.');
        return $this->done($request, '/codes/s/' . $id);
    }

    public function void(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $codes = new CodeAssigner($this->db, $this->app->audit);
        $codes->voidSell($id, $request->str('reason'), $this->userId());
        $this->success($codes->sellRow($id)['display'] . ' was voided.', 'It stays in the logs, greyed out, and the number will not be reused.');
        return $this->done($request, '/codes/s/' . $id);
    }

    /**
     * Link to an E number. "internal" is a typeahead reference (E12, or S34 to
     * mean "the same E number as that sell number"); "unlink" = 1 clears it.
     */
    public function link(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $codes = new CodeAssigner($this->db, $this->app->audit);
        if ($request->bool('unlink')) {
            $codes->linkSellToInternal($id, null, $this->userId());
            $this->success('The link to the E number was removed.');
            return $this->done($request, '/codes/s/' . $id);
        }
        $internalId = self::resolveInternal($this->db, $codes, $request->str('internal'));
        $sell = $codes->sellRow($id);
        if ((int) $sell['internal_code_id'] === $internalId) {
            $this->app->flash->info($sell['display'] . ' is already linked to ' . $codes->internalRow($internalId)['display'] . '. Nothing changed.');
            return $this->done($request, '/codes/s/' . $id);
        }
        $codes->linkSellToInternal($id, $internalId, $this->userId());
        $this->success($codes->sellRow($id)['display'] . ' is now linked to ' . $codes->internalRow($internalId)['display'] . '.');
        return $this->done($request, '/codes/s/' . $id);
    }

    /** Turn a typeahead reference into an E number id, with a plain-language refusal. */
    public static function resolveInternal(\Chroma\Core\Db $db, CodeAssigner $codes, string $ref): int
    {
        $product = (new QuickAssign($db, $codes))->product($ref);
        if ($product === null) {
            throw ValidationException::single('internal', 'Search for the E number and pick it from the list.');
        }
        if ($product['internal_code_id'] === null) {
            throw ValidationException::single('internal', $product['label'] . ' has no E number of its own yet, so nothing can be linked to it. Pick an E number, or a sell number that already has one.');
        }
        return (int) $product['internal_code_id'];
    }
}
