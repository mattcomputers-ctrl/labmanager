<?php
declare(strict_types=1);

namespace Chroma\Http\Controllers\Api;

use Chroma\Core\Permissions;
use Chroma\Core\Request;
use Chroma\Core\Response;
use Chroma\Domain\Codes\CodeAssigner;
use Chroma\Domain\Codes\CodeFormat;
use Chroma\Http\Controller;

/**
 * Session-authenticated JSON endpoints used by Expert-mode pages (inline
 * assignment) and by bin/stress-assign. Permissions are enforced by the
 * routes; the domain service does the work.
 */
final class CodeApiController extends Controller
{
    private function codes(): CodeAssigner
    {
        return new CodeAssigner($this->db, $this->app->audit);
    }

    public function assignSell(Request $request): Response
    {
        $row = $this->codes()->assignSell([
            'prefix_id' => $request->int('prefix_id', 0),
            'suffix_id' => $request->int('suffix_id'),
            'description' => $request->str('description'),
            'internal_code_id' => $request->int('internal_code_id'),
            'original_customer_id' => $request->int('original_customer_id'),
            'reason' => $request->str('reason') ?: null,
            'reason_text' => $request->str('reason_text') ?: null,
            'notes' => $request->str('notes') ?: null,
            'project_item_id' => $request->int('project_item_id'),
        ], $this->userId());
        return $this->json(['ok' => true, 'id' => (int) $row['id'], 'code' => $row['display'], 'url' => '/codes/s/' . (int) $row['id'], 'message' => $row['display'] . ' reserved']);
    }

    public function assignInternal(Request $request): Response
    {
        $row = $this->codes()->assignInternal([
            'description' => $request->str('description'),
            'resale' => $request->bool('resale'),
            'vendor_name' => $request->str('vendor_name') ?: null,
            'vendor_code' => $request->str('vendor_code') ?: null,
            'original_customer_id' => $request->int('original_customer_id'),
            'notes' => $request->str('notes') ?: null,
            'project_item_id' => $request->int('project_item_id'),
        ], $this->userId());
        return $this->json(['ok' => true, 'id' => (int) $row['id'], 'code' => $row['display'], 'url' => '/codes/e/' . (int) $row['id'], 'message' => $row['display'] . ' reserved']);
    }

    /** Next numbers that would be given out (informational). */
    public function preview(Request $request): Response
    {
        $codes = $this->codes();
        $out = ['internal' => 'E' . $codes->previewInternal()];
        if ($pid = $request->int('prefix_id')) {
            $p = $this->db->one('SELECT code, min_digits, retired FROM chroma_code_prefixes WHERE id = ?', [$pid]);
            if ($p) {
                $out['sell'] = CodeFormat::sell($p['code'], $codes->previewSell($pid), (int) $p['min_digits']);
                $out['retired'] = (int) $p['retired'] === 1;
            }
        }
        return $this->json($out);
    }

    /** Active prefixes with their allowed suffixes, for Assign dropdowns. */
    public function prefixes(Request $request): Response
    {
        $codes = $this->codes();
        $rows = $this->db->all('SELECT id, code, label, description, min_digits, tag, retired FROM chroma_code_prefixes WHERE retired = 0 ORDER BY sort_order, code');
        $suffixes = $this->db->all('SELECT ps.prefix_id, s.id, s.code, s.label FROM chroma_code_prefix_suffixes ps JOIN chroma_code_suffixes s ON s.id = ps.suffix_id WHERE s.active = 1 ORDER BY s.sort_order, s.code');
        $byPrefix = [];
        foreach ($suffixes as $s) {
            $byPrefix[(int) $s['prefix_id']][] = ['id' => (int) $s['id'], 'code' => $s['code'], 'label' => $s['label']];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int) $r['id'], 'code' => $r['code'], 'label' => $r['label'], 'description' => $r['description'], 'tag' => $r['tag'],
                'next' => CodeFormat::sell($r['code'], $codes->previewSell((int) $r['id']), (int) $r['min_digits']),
                'suffixes' => $byPrefix[(int) $r['id']] ?? [],
            ];
        }
        return $this->json(['prefixes' => $out]);
    }
}
