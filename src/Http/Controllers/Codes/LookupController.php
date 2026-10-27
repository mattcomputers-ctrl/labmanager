<?php
declare(strict_types=1);

namespace Chroma\Http\Controllers\Codes;

use Chroma\Core\Request;
use Chroma\Core\Response;
use Chroma\Domain\Codes\CodeSearch;
use Chroma\Domain\Export\Exporter;
use Chroma\Domain\Projects\Visibility;
use Chroma\Http\Controller;

/** /codes — one search box over every E number and sell number, grouped by product. */
final class LookupController extends Controller
{
    /** @return array{show:string,prefix:string} */
    private function filters(Request $request): array
    {
        $show = $request->str('show', 'all');
        return [
            'show' => in_array($show, ['all', 'active', 'voided'], true) ? $show : 'all',
            'prefix' => strtoupper(preg_replace('/[^A-Za-z]/', '', $request->str('prefix')) ?? ''),
        ];
    }

    public function index(Request $request): Response
    {
        $q = mb_substr($request->str('q'), 0, 200);
        $filters = $this->filters($request);
        $paging = $this->paging($request, is_easy() ? 20 : 50);
        $search = new CodeSearch($this->db);
        $result = ($q !== '' || $filters['prefix'] !== '') ? $search->search($q, $paging['page'], $paging['per_page'], $filters) : null;
        $prefixes = $this->db->all('SELECT code, label, retired FROM chroma_code_prefixes ORDER BY retired, sort_order, code');
        return $this->view('codes/lookup', [
            'title' => $q !== '' ? $q . ' · Code lookup' : 'Code lookup',
            'q' => $q,
            'filters' => $filters,
            'result' => $result,
            'prefixes' => $prefixes,
            'query' => array_filter(['q' => $q, 'show' => $filters['show'] !== 'all' ? $filters['show'] : null, 'prefix' => $filters['prefix'] ?: null, 'per_page' => $request->int('per_page')]),
        ]);
    }

    public function export(Request $request): Response
    {
        $q = mb_substr($request->str('q'), 0, 200);
        $filters = $this->filters($request);
        $format = $request->str('format') === 'xlsx' ? 'xlsx' : 'csv';
        $visibility = new Visibility($this->db, $this->auth);
        $rows = (new CodeSearch($this->db))->exportRows($q, $filters, fn(array $o) => $visibility->canViewOrigin($o));
        $this->app->audit->record('code.export', '', null, null, ['q' => $q, 'rows' => count($rows), 'format' => $format], 'Exported code lookup for "' . $q . '"');
        return Exporter::respond($format, 'code-lookup-' . slugify($q ?: 'all'), CodeSearch::exportColumns(), $rows);
    }
}
