<?php
declare(strict_types=1);

namespace Chroma\Http\Controllers;

use Chroma\Core\Permissions;
use Chroma\Core\Request;
use Chroma\Core\Response;
use Chroma\Domain\Export\Exporter;
use Chroma\Domain\Reports\Reports;
use Chroma\Http\Controller;

/**
 * Reports home and one page per report. Every page is read-only: the
 * numbers come from Domain\Reports\Reports and the same data feeds the
 * CSV/XLSX export, so the file always matches the screen.
 */
final class ReportsController extends Controller
{
    private function reports(): Reports
    {
        return new Reports($this->db, today(), $this->auth->can(Permissions::PROJECTS_VIEW_CONFIDENTIAL), $this->auth->id());
    }

    public function index(Request $request): Response
    {
        $reports = $this->reports();
        return $this->view('reports/index', [
            'title' => 'Reports',
            'catalogue' => Reports::catalogue(),
            'headlines' => $reports->headlines(),
        ]);
    }

    public function show(Request $request): Response
    {
        $name = (string) ($request->params['name'] ?? '');
        if (!in_array($name, Reports::NAMES, true)) {
            $this->notFound('There is no report with that name. Pick one from the Reports page.');
        }
        $reports = $this->reports();
        $parsed = $reports->parseFilters($request->query, $this->defaults($name));
        $filters = $parsed['filters'];
        $data = $this->run($reports, $name, $filters, $request);
        $catalogue = Reports::catalogue();
        return $this->view('reports/' . $name, [
            'title' => $catalogue[$name]['title'],
            'lede' => $catalogue[$name]['text'],
            'name' => $name,
            'filters' => $filters,
            'problems' => $parsed['problems'],
            'options' => $reports->filterOptions(),
            'data' => $data,
            'query' => array_filter($request->query, fn($v) => is_scalar($v) && $v !== ''),
        ]);
    }

    /** CSV / XLSX of one of the report's tables (?table=key&format=csv|xlsx). */
    public function export(Request $request): Response
    {
        $name = (string) ($request->params['name'] ?? '');
        if (!in_array($name, Reports::NAMES, true)) {
            $this->notFound('There is no report with that name. Pick one from the Reports page.');
        }
        $reports = $this->reports();
        $filters = $reports->parseFilters($request->query, $this->defaults($name))['filters'];
        $data = $this->run($reports, $name, $filters, $request, true);
        $tables = $data['tables'];
        $key = $request->str('table');
        if (!isset($tables[$key])) {
            $key = (string) array_key_first($tables);
        }
        $table = $tables[$key];
        $rows = [];
        foreach ($table['rows'] as $row) {
            foreach (['date_submitted', 'date_completed', 'target_date'] as $d) {
                if (array_key_exists($d, $row)) {
                    $row[$d] = fmt_date($row[$d]);
                }
            }
            $rows[] = $row;
        }
        $format = $request->str('format') === 'xlsx' ? 'xlsx' : 'csv';
        $this->app->audit->record('report.export', '', null, null, ['report' => $name, 'table' => $key, 'format' => $format, 'filters' => array_filter($filters)], 'Exported report ' . $name . ' (' . $key . ') as ' . strtoupper($format));
        return Exporter::respond($format, 'report-' . $name . '-' . $key, $table['columns'], $rows);
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    private function run(Reports $reports, string $name, array $filters, Request $request, bool $forExport = false): array
    {
        switch ($name) {
            case 'turnaround':
                return $reports->turnaround($filters);
            case 'aging':
                if ($forExport) {
                    $data = $reports->aging($filters, 1, 10);
                    $data['tables']['list']['rows'] = $reports->agingAll($filters);
                    return $data;
                }
                $paging = $this->paging($request, is_easy() ? 25 : 50);
                return $reports->aging($filters, $paging['page'], $paging['per_page']);
            case 'codes':
                return $reports->codesPerMonth($filters);
            case 'proofs':
                return $reports->proofs($filters);
            case 'rnd':
                return $reports->rndByStage($filters);
            default:
                return $reports->itemsDone($filters);
        }
    }

    /** Default filters per report: a sensible date window so the page is never empty by accident. @return array<string,mixed> */
    private function defaults(string $name): array
    {
        $today = today();
        $yearAgo = (new \DateTimeImmutable($today))->modify('-1 year')->modify('+1 day')->format('Y-m-d');
        return match ($name) {
            'turnaround' => ['from' => $yearAgo, 'to' => $today, 'by' => 'customer'],
            'proofs' => ['from' => (new \DateTimeImmutable($today))->modify('-11 months')->format('Y-m-01'), 'to' => $today],
            'rnd' => ['status' => 'open'],
            'items' => ['status' => 'open', 'by' => 'type'],
            default => [],
        };
    }
}
