<?php
declare(strict_types=1);

namespace Chroma\Http\Controllers\Codes;

use Chroma\Core\Request;
use Chroma\Core\Response;
use Chroma\Core\ValidationException;
use Chroma\Core\Validator;
use Chroma\Domain\Codes\CodeAssigner;
use Chroma\Domain\Codes\ManualEntry;
use Chroma\Http\Controller;

/**
 * /codes/manual — enter a specific E number or sell number by hand (legacy
 * items, odd cases). Permission codes.manual_entry; every entry is logged.
 * Easy mode is the manual-entry wizard; both call ManualEntry::sell/internal
 * (plain-language limit and duplicate checks, then CodeAssigner::manual*).
 */
final class ManualEntryController extends Controller
{
    public function form(Request $request): Response
    {
        if (is_easy() && !$request->bool('expert')) {
            return $this->redirect(url('/wizard/manual-entry', array_filter(['type' => $request->str('type') ?: null, 'code' => $request->str('code') ?: null])));
        }
        $type = $request->str('type') === 'internal' ? 'internal' : 'sell';
        return $this->view('codes/manual', [
            'title' => 'Enter a code by hand',
            'type' => $type,
            'reasons' => CodeAssigner::REASONS,
            'defaults' => [
                'code' => $request->str('code'),
                'description' => $request->str('description'),
                'internal' => $request->str('internal'),
                'internal_label' => $request->str('internal_label'),
                'original_customer_id' => $request->int('original_customer_id'),
                'original_customer_label' => $request->str('original_customer_label'),
                'date_assigned' => $request->str('date_assigned') ?: today(),
                'reason' => $request->str('reason'),
                'reason_text' => $request->str('reason_text'),
                'resale' => $request->bool('resale'),
                'vendor_name' => $request->str('vendor_name'),
                'vendor_code' => $request->str('vendor_code'),
                'notes' => $request->str('notes'),
            ],
        ]);
    }

    public function enter(Request $request): Response
    {
        $type = $request->str('type') === 'internal' ? 'internal' : 'sell';
        $v = new Validator($request->body);
        $v->required('code', 'Type the code you want to enter.');
        $v->required('description', 'Give the code a short description so people can find it later.');
        $v->date('date_assigned');
        $v->fail();
        $codes = new CodeAssigner($this->db, $this->app->audit);
        $entry = new ManualEntry($this->db, $codes);
        $data = [
            'description' => $request->str('description'),
            'original_customer_id' => $request->int('original_customer_id'),
            'date_assigned' => $request->str('date_assigned') ?: today(),
            'notes' => $request->str('notes') ?: null,
        ];
        if ($type === 'internal') {
            $row = $entry->internal($request->str('code'), $data + [
                'resale' => $request->bool('resale'),
                'vendor_name' => $request->str('vendor_name') ?: null,
                'vendor_code' => $request->str('vendor_code') ?: null,
            ], $this->userId());
            $url = '/codes/e/' . (int) $row['id'];
        } else {
            $internalId = null;
            if ($request->str('internal') !== '') {
                $internalId = SellNumberController::resolveInternal($this->db, $codes, $request->str('internal'));
            }
            $row = $entry->sell($request->str('code'), $data + [
                'internal_code_id' => $internalId,
                'reason' => $request->str('reason') ?: null,
                'reason_text' => $request->str('reason_text') ?: null,
            ], $this->userId());
            $url = '/codes/s/' . (int) $row['id'];
        }
        $this->success($row['display'] . ' was entered.', 'This manual entry is recorded in the log with your name.');
        if ($request->wantsJson()) {
            return $this->json(['ok' => true, 'code' => $row['display'], 'url' => $url, 'redirect' => $url]);
        }
        return $this->redirect($url);
    }
}
