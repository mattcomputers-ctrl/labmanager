<?php
declare(strict_types=1);

namespace Chroma\Http\Controllers\Codes;

use Chroma\Core\Request;
use Chroma\Core\Response;
use Chroma\Domain\Codes\CodeSearch;
use Chroma\Domain\Export\Exporter;
use Chroma\Http\Controller;

/** /codes/logs — the per-prefix logs (the replacement for the Security and UV log sheets) and the E number log. */
final class LogsController extends Controller
{
    /** @return array{q:string,show:string,from:string,to:string} */
    private function filters(Request $request): array
    {
        $show = $request->str('show', 'all');
        $date = fn(string $k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->str($k)) ? $request->str($k) : '';
        return [
            'q' => mb_substr($request->str('q'), 0, 100),
            'show' => in_array($show, ['all', 'active', 'voided'], true) ? $show : 'all',
            'from' => $date('from'),
            'to' => $date('to'),
        ];
    }

    public function index(Request $request): Response
    {
        $prefixes = (new CodeSearch($this->db))->logPrefixes();
        return $this->view('codes/logs-index', ['title' => 'Code logs', 'prefixes' => $prefixes, 'total' => array_sum(array_map(fn($p) => $p['count'], $prefixes))]);
    }

    public function show(Request $request): Response
    {
        $code = strtoupper((string) $request->params['prefix']);
        $filters = $this->filters($request);
        $paging = $this->paging($request, is_easy() ? 25 : 50);
        $search = new CodeSearch($this->db);
        $log = $search->log($code, $filters, $paging['page'], $paging['per_page']);
        if ($log['prefix'] === null) {
            $this->notFound('There is no code log called "' . $code . '". Pick one from the list of prefixes.');
        }
        return $this->view('codes/logs-show', [
            'title' => ($code === 'E' ? 'E numbers' : $code . ' numbers') . ' · Code logs',
            'code' => $code,
            'prefix' => $log['prefix'],
            'log' => $log,
            'filters' => $filters,
            'prefixes' => $search->logPrefixes(),
            'query' => array_filter($filters + ['per_page' => $request->int('per_page')]),
        ]);
    }

    public function export(Request $request): Response
    {
        $code = strtoupper((string) $request->params['prefix']);
        $filters = $this->filters($request);
        $format = $request->str('format') === 'xlsx' ? 'xlsx' : 'csv';
        $search = new CodeSearch($this->db);
        if ($search->log($code, [], 1, 1)['prefix'] === null) {
            $this->notFound('There is no code log called "' . $code . '".');
        }
        $rows = $search->logExportRows($code, $filters);
        $this->app->audit->record('code.export', '', null, null, ['log' => $code, 'rows' => count($rows), 'format' => $format] + $filters, 'Exported the ' . $code . ' code log');
        return Exporter::respond($format, 'code-log-' . strtolower($code), CodeSearch::logExportColumns($code), $rows);
    }
}
