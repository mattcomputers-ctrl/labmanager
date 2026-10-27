<?php
declare(strict_types=1);

namespace Chroma\Domain\Reports;

use Chroma\Core\Db;
use Chroma\Core\Validator;

/**
 * The numbers behind the Reports page. Pure queries and arithmetic: no HTTP,
 * no HTML. Every report returns the same shape so the controller can render
 * and export it without knowing which report it is:
 *
 *   [
 *     'tables' => [ key => ['title' => ..., 'columns' => [key => heading], 'rows' => [...]] ],
 *     ...report-specific extras (buckets, months, list, pagination)...
 *   ]
 *
 * Calendar dates (date_submitted, date_completed, date_assigned) are plain
 * DATE columns, so all date maths happens on them with DATEDIFF / DATE_FORMAT
 * and "today" is the date in the lab's timezone, passed in by the caller.
 */
final class Reports
{
    public const NAMES = ['turnaround', 'aging', 'codes', 'proofs', 'rnd', 'items'];

    /** Aging buckets: label => [min days, max days or null]. */
    public const AGE_BUCKETS = [
        '0-7' => [0, 7],
        '8-14' => [8, 14],
        '15-30' => [15, 30],
        '31-60' => [31, 60],
        '60+' => [61, null],
    ];

    public function __construct(
        private readonly Db $db,
        private readonly string $today,
        private readonly bool $includeConfidential = false,
        private readonly ?int $viewerId = null,
    ) {
    }

    /** Plain-language card copy for the reports home. @return array<string,array{title:string,text:string}> */
    public static function catalogue(): array
    {
        return [
            'turnaround' => ['title' => 'Turnaround time', 'text' => 'How many days a project takes from submitted to completed, by customer, tech or type. Count, average and median.'],
            'aging' => ['title' => 'Open projects aging', 'text' => 'How long the open projects have been waiting, in buckets of 0-7, 8-14, 15-30, 31-60 and 60+ days, with the list.'],
            'codes' => ['title' => 'Codes assigned per month', 'text' => 'Sell numbers given out under each prefix in each of the last 12 months, plus E numbers.'],
            'proofs' => ['title' => 'Proofs generated', 'text' => 'Proofs made each month and by each tech: total proof count and the number of items that had proofs.'],
            'rnd' => ['title' => 'R&D projects by stage', 'text' => 'Where the R&D work sits: idea, bench, scale-up, press trial, released or shelved.'],
            'items' => ['title' => 'Items done vs total', 'text' => 'Progress on the open projects: how many colors or items are done out of the total, by type, tech or customer.'],
        ];
    }

    // ------------------------------------------------------------------
    // Filters
    // ------------------------------------------------------------------

    /**
     * Clean the query string into filters. Bad values are dropped and
     * explained in 'problems' (plain language) rather than thrown, so a
     * mistyped date never locks someone out of the page.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $defaults
     * @return array{filters:array<string,mixed>,problems:list<string>}
     */
    public function parseFilters(array $input, array $defaults = []): array
    {
        $problems = [];
        $v = new Validator($input);
        $v->date('from')->date('to');
        $f = [
            'from' => $defaults['from'] ?? null,
            'to' => $defaults['to'] ?? null,
            'customer_id' => null,
            'user_id' => null,
            'type_id' => null,
            'by' => $defaults['by'] ?? null,
            'status' => $defaults['status'] ?? null,
            'bucket' => null,
            'prefix_id' => null,
        ];
        foreach (['from', 'to'] as $k) {
            $val = trim((string) ($input[$k] ?? ''));
            if ($val === '') {
                continue;
            }
            if (isset($v->errors()[$k])) {
                $problems[] = ($k === 'from' ? 'The "from" date' : 'The "to" date') . ' was not understood, so it was ignored. Enter it as YYYY-MM-DD or pick it from the calendar.';
                continue;
            }
            $f[$k] = $val;
        }
        if ($f['from'] !== null && $f['to'] !== null && $f['from'] > $f['to']) {
            $problems[] = 'The "from" date was after the "to" date, so the two were swapped.';
            [$f['from'], $f['to']] = [$f['to'], $f['from']];
        }
        foreach (['customer_id', 'user_id', 'type_id', 'prefix_id'] as $k) {
            $val = $input[$k] ?? null;
            if (is_scalar($val) && $val !== '' && is_numeric($val) && (int) $val > 0) {
                $f[$k] = (int) $val;
            }
        }
        $by = (string) ($input['by'] ?? '');
        if (in_array($by, ['customer', 'tech', 'type'], true)) {
            $f['by'] = $by;
        }
        $status = (string) ($input['status'] ?? '');
        if (in_array($status, ['open', 'completed', 'cancelled', 'all'], true)) {
            $f['status'] = $status;
        }
        $bucket = (string) ($input['bucket'] ?? '');
        if (isset(self::AGE_BUCKETS[$bucket])) {
            $f['bucket'] = $bucket;
        }
        return ['filters' => $f, 'problems' => $problems];
    }

    /** Options for the filter dropdowns. @return array{customers:list<array<string,mixed>>,techs:list<array<string,mixed>>,types:list<array<string,mixed>>,prefixes:list<array<string,mixed>>} */
    public function filterOptions(): array
    {
        return [
            'customers' => $this->db->all('SELECT c.id, c.name FROM chroma_customers c WHERE EXISTS (SELECT 1 FROM chroma_projects p WHERE p.customer_id = c.id) ORDER BY c.name'),
            'techs' => $this->db->all('SELECT id, display_name, username FROM chroma_users WHERE active = 1 ORDER BY display_name, username'),
            'types' => $this->db->all('SELECT id, code, label, color FROM chroma_project_types WHERE active = 1 ORDER BY sort_order, label'),
            'prefixes' => $this->db->all('SELECT id, code, label FROM chroma_code_prefixes ORDER BY sort_order, code'),
        ];
    }

    // ------------------------------------------------------------------
    // Headline numbers for the reports home
    // ------------------------------------------------------------------

    /** @return array<string,array<string,mixed>> keyed by report name */
    public function headlines(): array
    {
        $conf = $this->confidentialClause('p');
        $open = (int) $this->db->value("SELECT COUNT(*) FROM chroma_projects p WHERE p.status = 'open' $conf");
        $oldest = $this->db->value("SELECT MAX(DATEDIFF(?, p.date_submitted)) FROM chroma_projects p WHERE p.status = 'open' AND p.date_submitted IS NOT NULL $conf", [$this->today]);
        $ninety = $this->shiftDate($this->today, '-90 days');
        $turn = $this->db->one("SELECT COUNT(*) AS n, AVG(DATEDIFF(p.date_completed, p.date_submitted)) AS avg_days FROM chroma_projects p WHERE p.status = 'completed' AND p.date_completed >= ? AND p.date_submitted IS NOT NULL AND p.date_completed IS NOT NULL $conf", [$ninety]);
        $monthStart = substr($this->today, 0, 7) . '-01';
        $codesMonth = (int) $this->db->value('SELECT COUNT(*) FROM chroma_sell_numbers WHERE date_assigned >= ?', [$monthStart]);
        $eMonth = (int) $this->db->value('SELECT COUNT(*) FROM chroma_internal_codes WHERE date_assigned >= ?', [$monthStart]);
        $proofs = (int) $this->db->value("SELECT COALESCE(SUM(i.proof_count), 0) FROM chroma_project_items i JOIN chroma_projects p ON p.id = i.project_id WHERE COALESCE(i.date_completed, i.date_started, p.date_submitted, DATE(i.created_at)) >= ? $conf", [$monthStart]);
        $rnd = (int) $this->db->value("SELECT COUNT(*) FROM chroma_projects p JOIN chroma_project_types t ON t.id = p.project_type_id WHERE t.has_stages = 1 AND p.status = 'open' $conf");
        $items = $this->db->one("SELECT COUNT(i.id) AS total, COALESCE(SUM(s.is_done = 1), 0) AS done FROM chroma_project_items i JOIN chroma_projects p ON p.id = i.project_id LEFT JOIN chroma_item_statuses s ON s.id = i.status_id WHERE p.status = 'open' $conf");
        return [
            'turnaround' => ['value' => $turn && $turn['avg_days'] !== null ? number_format((float) $turn['avg_days'], 1) : '—', 'label' => $turn && (int) $turn['n'] > 0 ? 'average days, last 90 days (' . (int) $turn['n'] . ' completed)' : 'no projects completed in the last 90 days'],
            'aging' => ['value' => (string) $open, 'label' => $open === 0 ? 'open projects' : 'open projects · oldest ' . (int) $oldest . ' days'],
            'codes' => ['value' => (string) $codesMonth, 'label' => 'sell numbers this month · ' . $eMonth . ' E numbers'],
            'proofs' => ['value' => (string) $proofs, 'label' => 'proofs this month'],
            'rnd' => ['value' => (string) $rnd, 'label' => 'open R&D projects'],
            'items' => ['value' => (int) ($items['done'] ?? 0) . ' / ' . (int) ($items['total'] ?? 0), 'label' => 'items done on open projects'],
        ];
    }

    // ------------------------------------------------------------------
    // 1. Turnaround time (submitted -> completed)
    // ------------------------------------------------------------------

    /** @param array<string,mixed> $f @return array<string,mixed> */
    public function turnaround(array $f): array
    {
        $by = $f['by'] ?? 'customer';
        [$where, $params] = $this->projectWhere($f, 'p', 'p.date_completed');
        $rows = $this->db->all(
            "SELECT p.id, p.project_number, p.title, p.date_submitted, p.date_completed,
                    COALESCE(c.name, '(no customer)') AS customer, COALESCE(u.display_name, u.username, '(unassigned)') AS tech, t.label AS type,
                    DATEDIFF(p.date_completed, p.date_submitted) AS days
             FROM chroma_projects p
             LEFT JOIN chroma_customers c ON c.id = p.customer_id
             LEFT JOIN chroma_users u ON u.id = p.assigned_user_id
             JOIN chroma_project_types t ON t.id = p.project_type_id
             WHERE p.status = 'completed' AND p.date_submitted IS NOT NULL AND p.date_completed IS NOT NULL $where
             ORDER BY p.date_completed DESC, p.project_number DESC",
            $params
        );
        $groups = [];
        $all = [];
        foreach ($rows as $r) {
            $key = (string) $r[$by === 'tech' ? 'tech' : ($by === 'type' ? 'type' : 'customer')];
            $groups[$key][] = max(0, (int) $r['days']);
            $all[] = max(0, (int) $r['days']);
        }
        $table = [];
        foreach ($groups as $key => $days) {
            $table[] = $this->stats($key, $days);
        }
        usort($table, fn($a, $b) => [$b['count'], $a['group']] <=> [$a['count'], $b['group']]);
        $overall = $all === [] ? null : $this->stats('All projects', $all);
        $maxAvg = 0.0;
        foreach ($table as $t) {
            $maxAvg = max($maxAvg, (float) $t['average']);
        }
        $groupLabel = ['customer' => 'Customer', 'tech' => 'Tech', 'type' => 'Project type'][$by] ?? 'Customer';
        return [
            'by' => $by,
            'overall' => $overall,
            'max_average' => $maxAvg,
            'projects' => $rows,
            'tables' => [
                'summary' => [
                    'title' => 'Turnaround by ' . strtolower($groupLabel),
                    'columns' => ['group' => $groupLabel, 'count' => 'Projects completed', 'average' => 'Average days', 'median' => 'Median days', 'fastest' => 'Fastest (days)', 'slowest' => 'Slowest (days)'],
                    'rows' => $table,
                ],
                'projects' => [
                    'title' => 'Completed projects in this range',
                    'columns' => ['project_number' => 'Project #', 'title' => 'Title', 'type' => 'Type', 'customer' => 'Customer', 'tech' => 'Tech', 'date_submitted' => 'Submitted', 'date_completed' => 'Completed', 'days' => 'Days'],
                    'rows' => $rows,
                ],
            ],
        ];
    }

    /** @param list<int> $days @return array<string,mixed> */
    private function stats(string $group, array $days): array
    {
        sort($days);
        $n = count($days);
        $mid = intdiv($n, 2);
        $median = $n === 0 ? 0 : ($n % 2 === 1 ? $days[$mid] : ($days[$mid - 1] + $days[$mid]) / 2);
        return [
            'group' => $group,
            'count' => $n,
            'average' => $n === 0 ? 0 : round(array_sum($days) / $n, 1),
            'median' => round($median, 1),
            'fastest' => $n === 0 ? 0 : $days[0],
            'slowest' => $n === 0 ? 0 : $days[$n - 1],
        ];
    }

    // ------------------------------------------------------------------
    // 2. Open projects aging
    // ------------------------------------------------------------------

    /** @param array<string,mixed> $f @return array<string,mixed> */
    public function aging(array $f, int $page = 1, int $perPage = 50): array
    {
        [$where, $params] = $this->projectWhere($f, 'p', null);
        $base = "FROM chroma_projects p
                 LEFT JOIN chroma_customers c ON c.id = p.customer_id
                 LEFT JOIN chroma_users u ON u.id = p.assigned_user_id
                 JOIN chroma_project_types t ON t.id = p.project_type_id
                 WHERE p.status = 'open' $where";
        $ageExpr = 'DATEDIFF(' . $this->todayLiteral() . ', COALESCE(p.date_submitted, DATE(p.created_at)))';
        $agg = $this->db->one(
            "SELECT COUNT(*) AS total,
                    SUM($ageExpr BETWEEN 0 AND 7) AS b1, SUM($ageExpr BETWEEN 8 AND 14) AS b2, SUM($ageExpr BETWEEN 15 AND 30) AS b3,
                    SUM($ageExpr BETWEEN 31 AND 60) AS b4, SUM($ageExpr > 60) AS b5, SUM($ageExpr < 0) AS future,
                    AVG(GREATEST($ageExpr, 0)) AS avg_age, MAX($ageExpr) AS max_age
             $base",
            $params
        ) ?? [];
        $counts = ['0-7' => (int) ($agg['b1'] ?? 0), '8-14' => (int) ($agg['b2'] ?? 0), '15-30' => (int) ($agg['b3'] ?? 0), '31-60' => (int) ($agg['b4'] ?? 0), '60+' => (int) ($agg['b5'] ?? 0)];
        $counts['0-7'] += (int) ($agg['future'] ?? 0); // a submitted date in the future counts as brand new
        $total = (int) ($agg['total'] ?? 0);
        $buckets = [];
        foreach (self::AGE_BUCKETS as $label => [$min, $max]) {
            $buckets[] = ['bucket' => $label . ' days', 'key' => $label, 'count' => $counts[$label], 'share' => $total ? round($counts[$label] / $total * 100, 1) : 0.0];
        }

        $listWhere = '';
        $listParams = $params;
        if (!empty($f['bucket']) && isset(self::AGE_BUCKETS[$f['bucket']])) {
            [$min, $max] = self::AGE_BUCKETS[$f['bucket']];
            $listWhere .= $max === null ? " AND $ageExpr >= :bmin" : " AND $ageExpr BETWEEN :bmin AND :bmax";
            $listParams['bmin'] = $min === 0 ? -100000 : $min;
            if ($max !== null) {
                $listParams['bmax'] = $max;
            }
        }
        $listTotal = (int) $this->db->value("SELECT COUNT(*) $base $listWhere", $listParams);
        $offset = max(0, ($page - 1) * $perPage);
        $list = $this->db->all(
            "SELECT p.id, p.project_number, p.title, p.date_submitted, p.target_date, p.confidential,
                    COALESCE(c.name, '') AS customer, COALESCE(u.display_name, u.username, '') AS tech, t.label AS type, t.color AS type_color,
                    GREATEST($ageExpr, 0) AS age,
                    (SELECT COUNT(*) FROM chroma_project_items i WHERE i.project_id = p.id) AS item_count,
                    (SELECT COUNT(*) FROM chroma_project_items i JOIN chroma_item_statuses s ON s.id = i.status_id WHERE i.project_id = p.id AND s.is_done = 1) AS items_done
             $base $listWhere
             ORDER BY age DESC, p.project_number ASC
             LIMIT $perPage OFFSET $offset",
            $listParams
        );
        foreach ($list as &$row) {
            $row['bucket'] = self::bucketFor((int) $row['age']);
            $row['progress'] = (int) $row['items_done'] . ' of ' . (int) $row['item_count'];
        }
        unset($row);
        return [
            'total' => $total,
            'average_age' => $agg['avg_age'] !== null ? round((float) $agg['avg_age'], 1) : null,
            'max_age' => $agg['max_age'] !== null ? max(0, (int) $agg['max_age']) : null,
            'buckets' => $buckets,
            'list' => $list,
            'list_total' => $listTotal,
            'page' => $page,
            'per_page' => $perPage,
            'tables' => [
                'buckets' => ['title' => 'Open projects by age', 'columns' => ['bucket' => 'Age', 'count' => 'Open projects', 'share' => '% of open'], 'rows' => $buckets],
                'list' => ['title' => 'Open projects', 'columns' => ['project_number' => 'Project #', 'title' => 'Title', 'type' => 'Type', 'customer' => 'Customer', 'tech' => 'Tech', 'date_submitted' => 'Submitted', 'age' => 'Days open', 'bucket' => 'Bucket', 'progress' => 'Items done'], 'rows' => $list],
            ],
        ];
    }

    /** Full (unpaginated) aging list for export. @param array<string,mixed> $f @return list<array<string,mixed>> */
    public function agingAll(array $f): array
    {
        $out = [];
        $page = 1;
        do {
            $r = $this->aging($f, $page, 500);
            foreach ($r['list'] as $row) {
                $out[] = $row;
            }
            $page++;
        } while (count($out) < $r['list_total'] && $r['list'] !== []);
        return $out;
    }

    public static function bucketFor(int $age): string
    {
        foreach (self::AGE_BUCKETS as $label => [$min, $max]) {
            if ($age >= $min && ($max === null || $age <= $max)) {
                return $label . ' days';
            }
        }
        return '0-7 days';
    }

    // ------------------------------------------------------------------
    // 3. Codes assigned per month per prefix (last 12 months pivot + E numbers)
    // ------------------------------------------------------------------

    /** @param array<string,mixed> $f @return array<string,mixed> */
    public function codesPerMonth(array $f): array
    {
        $end = $f['to'] ?? $this->today;
        $months = $this->monthKeys($end, 12);
        $start = $months[0] . '-01';
        $endOfMonth = (new \DateTimeImmutable(substr($end, 0, 7) . '-01'))->modify('last day of this month')->format('Y-m-d');
        $prefixWhere = '';
        $params = ['start' => $start, 'end' => $endOfMonth];
        if (!empty($f['prefix_id'])) {
            $prefixWhere = ' AND s.prefix_id = :prefix_id';
            $params['prefix_id'] = (int) $f['prefix_id'];
        }
        $sell = $this->db->all(
            "SELECT s.prefix_id, DATE_FORMAT(s.date_assigned, '%Y-%m') AS ym, COUNT(*) AS n, SUM(s.voided = 1) AS voided
             FROM chroma_sell_numbers s
             WHERE s.date_assigned BETWEEN :start AND :end $prefixWhere
             GROUP BY s.prefix_id, ym",
            $params
        );
        $prefixes = $this->db->all('SELECT id, code, label, retired, tag FROM chroma_code_prefixes ORDER BY sort_order, code');
        $byPrefix = [];
        foreach ($sell as $r) {
            $byPrefix[(int) $r['prefix_id']][$r['ym']] = ['n' => (int) $r['n'], 'voided' => (int) $r['voided']];
        }
        $rows = [];
        $monthTotals = array_fill_keys($months, 0);
        $max = 0;
        foreach ($prefixes as $p) {
            if (!empty($f['prefix_id']) && (int) $p['id'] !== (int) $f['prefix_id']) {
                continue;
            }
            $cells = $byPrefix[(int) $p['id']] ?? [];
            if ($cells === [] && (int) $p['retired'] === 1) {
                continue; // retired prefixes with nothing in the window just add noise
            }
            $row = ['prefix' => $p['code'], 'label' => $p['label'], 'total' => 0, 'voided' => 0];
            foreach ($months as $m) {
                $n = $cells[$m]['n'] ?? 0;
                $row[$m] = $n;
                $row['total'] += $n;
                $row['voided'] += $cells[$m]['voided'] ?? 0;
                $monthTotals[$m] += $n;
            }
            $max = max($max, $row['total']);
            $rows[] = $row;
        }
        usort($rows, fn($a, $b) => $b['total'] <=> $a['total'] ?: strcmp($a['prefix'], $b['prefix']));
        $internal = $this->db->all(
            "SELECT DATE_FORMAT(date_assigned, '%Y-%m') AS ym, COUNT(*) AS n, SUM(voided = 1) AS voided FROM chroma_internal_codes WHERE date_assigned BETWEEN :start AND :end GROUP BY ym",
            ['start' => $start, 'end' => $endOfMonth]
        );
        $eRow = ['prefix' => 'E', 'label' => 'E numbers (internal codes)', 'total' => 0, 'voided' => 0];
        foreach ($months as $m) {
            $eRow[$m] = 0;
        }
        foreach ($internal as $r) {
            $eRow[$r['ym']] = (int) $r['n'];
            $eRow['total'] += (int) $r['n'];
            $eRow['voided'] += (int) $r['voided'];
        }
        $totalsRow = ['prefix' => 'All sell numbers', 'label' => '', 'total' => array_sum($monthTotals), 'voided' => array_sum(array_column($rows, 'voided'))] + $monthTotals;
        $columns = ['prefix' => 'Prefix', 'label' => 'What it is for'];
        foreach ($months as $m) {
            $columns[$m] = self::monthLabel($m);
        }
        $columns['total'] = 'Total';
        $columns['voided'] = 'Of which voided';
        $export = $rows;
        $export[] = $totalsRow;
        $export[] = $eRow;
        return [
            'months' => $months,
            'month_labels' => array_map([self::class, 'monthLabel'], $months),
            'month_totals' => $monthTotals,
            'rows' => $rows,
            'e_row' => $eRow,
            'totals_row' => $totalsRow,
            'max_total' => max($max, $eRow['total']),
            'max_month' => max(1, max($monthTotals ?: [0]), max(array_slice($eRow, 4) ?: [0])),
            'range' => ['from' => $start, 'to' => $endOfMonth],
            'tables' => [
                'pivot' => ['title' => 'Codes assigned per month', 'columns' => $columns, 'rows' => $export],
            ],
        ];
    }

    // ------------------------------------------------------------------
    // 4. Proofs generated (per month and per tech)
    // ------------------------------------------------------------------

    /** @param array<string,mixed> $f @return array<string,mixed> */
    public function proofs(array $f): array
    {
        $dateExpr = 'COALESCE(i.date_completed, i.date_started, p.date_submitted, DATE(i.created_at))';
        [$where, $params] = $this->projectWhere($f, 'p', $dateExpr, 'COALESCE(i.assigned_user_id, p.assigned_user_id)');
        $base = "FROM chroma_project_items i JOIN chroma_projects p ON p.id = i.project_id WHERE 1 = 1 $where";
        $byMonth = $this->db->all(
            "SELECT DATE_FORMAT($dateExpr, '%Y-%m') AS ym, COALESCE(SUM(i.proof_count), 0) AS proofs, SUM(i.proofs_generated = 1) AS items_with_proofs, COUNT(*) AS items
             $base GROUP BY ym ORDER BY ym",
            $params
        );
        $byTech = $this->db->all(
            "SELECT COALESCE(u.display_name, u.username, '(unassigned)') AS tech, COALESCE(SUM(i.proof_count), 0) AS proofs, SUM(i.proofs_generated = 1) AS items_with_proofs, COUNT(*) AS items
             FROM chroma_project_items i JOIN chroma_projects p ON p.id = i.project_id
             LEFT JOIN chroma_users u ON u.id = COALESCE(i.assigned_user_id, p.assigned_user_id)
             WHERE 1 = 1 $where
             GROUP BY COALESCE(i.assigned_user_id, p.assigned_user_id), tech ORDER BY proofs DESC, tech",
            $params
        );
        $months = [];
        $maxMonth = 0;
        foreach ($byMonth as $r) {
            $months[] = ['month' => self::monthLabel((string) $r['ym']), 'ym' => $r['ym'], 'proofs' => (int) $r['proofs'], 'items_with_proofs' => (int) $r['items_with_proofs'], 'items' => (int) $r['items']];
            $maxMonth = max($maxMonth, (int) $r['proofs']);
        }
        $techs = [];
        $maxTech = 0;
        foreach ($byTech as $r) {
            $techs[] = ['tech' => $r['tech'], 'proofs' => (int) $r['proofs'], 'items_with_proofs' => (int) $r['items_with_proofs'], 'items' => (int) $r['items']];
            $maxTech = max($maxTech, (int) $r['proofs']);
        }
        return [
            'total_proofs' => array_sum(array_column($months, 'proofs')),
            'total_items_with_proofs' => array_sum(array_column($months, 'items_with_proofs')),
            'max_month' => $maxMonth,
            'max_tech' => $maxTech,
            'tables' => [
                'month' => ['title' => 'Proofs per month', 'columns' => ['month' => 'Month', 'proofs' => 'Proofs made', 'items_with_proofs' => 'Items with proofs', 'items' => 'Items in total'], 'rows' => $months],
                'tech' => ['title' => 'Proofs per tech', 'columns' => ['tech' => 'Tech', 'proofs' => 'Proofs made', 'items_with_proofs' => 'Items with proofs', 'items' => 'Items in total'], 'rows' => $techs],
            ],
        ];
    }

    // ------------------------------------------------------------------
    // 5. R&D projects by stage
    // ------------------------------------------------------------------

    /** @param array<string,mixed> $f @return array<string,mixed> */
    public function rndByStage(array $f): array
    {
        $status = $f['status'] ?? 'open';
        $f = ['status' => $status === 'all' ? null : $status] + $f;
        [$where, $params] = $this->projectWhere($f, 'p', 'p.date_submitted');
        $statusSql = $f['status'] ? ' AND p.status = :status' : '';
        if ($f['status']) {
            $params['status'] = $f['status'];
        }
        $projects = $this->db->all(
            "SELECT p.id, p.project_number, p.title, p.status, p.stage_id, p.date_submitted, p.target_date, p.confidential,
                    COALESCE(u.display_name, u.username, '') AS lead, t.label AS type, t.id AS type_id,
                    COALESCE(st.label, '(no stage yet)') AS stage, COALESCE(st.sort_order, 0) AS stage_sort, COALESCE(st.is_terminal, 0) AS is_terminal,
                    GREATEST(DATEDIFF(" . $this->todayLiteral() . ", COALESCE(p.date_submitted, DATE(p.created_at))), 0) AS age,
                    (SELECT COUNT(*) FROM chroma_project_items i WHERE i.project_id = p.id) AS item_count
             FROM chroma_projects p
             JOIN chroma_project_types t ON t.id = p.project_type_id
             LEFT JOIN chroma_project_stages st ON st.id = p.stage_id
             LEFT JOIN chroma_users u ON u.id = p.assigned_user_id
             WHERE t.has_stages = 1 $statusSql $where
             ORDER BY stage_sort, p.project_number",
            $params
        );
        // Every stage of every staged type appears, even with zero projects, so an empty stage is visible.
        $stages = $this->db->all('SELECT st.id, st.label, st.sort_order, st.is_terminal, t.label AS type FROM chroma_project_stages st JOIN chroma_project_types t ON t.id = st.project_type_id WHERE t.has_stages = 1 AND st.active = 1 ORDER BY t.sort_order, st.sort_order');
        $counts = [];
        foreach ($stages as $s) {
            $counts[(int) $s['id']] = ['stage' => $s['label'], 'stage_id' => (int) $s['id'], 'is_terminal' => (int) $s['is_terminal'], 'count' => 0, 'avg_age' => 0, 'ages' => []];
        }
        $counts[0] = ['stage' => '(no stage yet)', 'stage_id' => 0, 'is_terminal' => 0, 'count' => 0, 'avg_age' => 0, 'ages' => []];
        foreach ($projects as $p) {
            $sid = (int) ($p['stage_id'] ?? 0);
            if (!isset($counts[$sid])) {
                $sid = 0;
            }
            $counts[$sid]['count']++;
            $counts[$sid]['ages'][] = (int) $p['age'];
        }
        $rows = [];
        $max = 0;
        foreach ($counts as $c) {
            if ($c['stage_id'] === 0 && $c['count'] === 0) {
                continue;
            }
            $c['avg_age'] = $c['ages'] ? round(array_sum($c['ages']) / count($c['ages']), 1) : 0;
            unset($c['ages']);
            $rows[] = $c;
            $max = max($max, $c['count']);
        }
        return [
            'status' => $status,
            'total' => count($projects),
            'max' => $max,
            'projects' => $projects,
            'tables' => [
                'stages' => ['title' => 'R&D projects by stage', 'columns' => ['stage' => 'Stage', 'count' => 'Projects', 'avg_age' => 'Average days since submitted'], 'rows' => $rows],
                'projects' => ['title' => 'R&D projects', 'columns' => ['project_number' => 'Project #', 'title' => 'Title', 'stage' => 'Stage', 'status' => 'Status', 'lead' => 'Lead', 'date_submitted' => 'Submitted', 'target_date' => 'Target date', 'age' => 'Days since submitted', 'item_count' => 'Trials'], 'rows' => $projects],
            ],
        ];
    }

    // ------------------------------------------------------------------
    // 6. Items done vs total
    // ------------------------------------------------------------------

    /** @param array<string,mixed> $f @return array<string,mixed> */
    public function itemsDone(array $f): array
    {
        $by = $f['by'] ?? 'type';
        $status = $f['status'] ?? 'open';
        $f = ['status' => $status === 'all' ? null : $status] + $f;
        [$where, $params] = $this->projectWhere($f, 'p', 'p.date_submitted');
        $statusSql = $f['status'] ? ' AND p.status = :status' : '';
        if ($f['status']) {
            $params['status'] = $f['status'];
        }
        $groupExpr = match ($by) {
            'tech' => "COALESCE(u.display_name, u.username, '(unassigned)')",
            'customer' => "COALESCE(c.name, '(no customer)')",
            default => 't.label',
        };
        $rows = $this->db->all(
            "SELECT $groupExpr AS `group`, COUNT(i.id) AS total, COALESCE(SUM(s.is_done = 1), 0) AS done, COALESCE(SUM(s.is_done = 1 AND s.is_success = 1), 0) AS succeeded,
                    COUNT(DISTINCT p.id) AS projects
             FROM chroma_project_items i
             JOIN chroma_projects p ON p.id = i.project_id
             JOIN chroma_project_types t ON t.id = p.project_type_id
             LEFT JOIN chroma_customers c ON c.id = p.customer_id
             LEFT JOIN chroma_users u ON u.id = COALESCE(i.assigned_user_id, p.assigned_user_id)
             LEFT JOIN chroma_item_statuses s ON s.id = i.status_id
             WHERE 1 = 1 $statusSql $where
             GROUP BY `group` ORDER BY total DESC, `group`",
            $params
        );
        $total = 0;
        $done = 0;
        foreach ($rows as &$r) {
            $r['total'] = (int) $r['total'];
            $r['done'] = (int) $r['done'];
            $r['succeeded'] = (int) $r['succeeded'];
            $r['projects'] = (int) $r['projects'];
            $r['remaining'] = $r['total'] - $r['done'];
            $r['percent'] = $r['total'] ? round($r['done'] / $r['total'] * 100) : 0;
            $total += $r['total'];
            $done += $r['done'];
        }
        unset($r);
        $groupLabel = ['customer' => 'Customer', 'tech' => 'Tech', 'type' => 'Project type'][$by] ?? 'Project type';
        return [
            'by' => $by,
            'status' => $status,
            'total' => $total,
            'done' => $done,
            'percent' => $total ? (int) round($done / $total * 100) : 0,
            'tables' => [
                'summary' => ['title' => 'Items done vs total by ' . strtolower($groupLabel), 'columns' => ['group' => $groupLabel, 'projects' => 'Projects', 'done' => 'Done', 'remaining' => 'Still to do', 'total' => 'Total items', 'percent' => '% done', 'succeeded' => 'Of which matched / approved'], 'rows' => $rows],
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * WHERE fragments shared by the project-based reports.
     * @param array<string,mixed> $f
     * @return array{0:string,1:array<string,mixed>}
     */
    private function projectWhere(array $f, string $alias, ?string $dateExpr, ?string $techExpr = null): array
    {
        $where = $this->confidentialClause($alias);
        $params = [];
        if ($dateExpr !== null) {
            if (!empty($f['from'])) {
                $where .= " AND $dateExpr >= :from";
                $params['from'] = $f['from'];
            }
            if (!empty($f['to'])) {
                $where .= " AND $dateExpr <= :to";
                $params['to'] = $f['to'];
            }
        }
        if (!empty($f['customer_id'])) {
            $where .= " AND $alias.customer_id = :customer_id";
            $params['customer_id'] = (int) $f['customer_id'];
        }
        if (!empty($f['user_id'])) {
            $where .= ' AND ' . ($techExpr ?? "$alias.assigned_user_id") . ' = :user_id';
            $params['user_id'] = (int) $f['user_id'];
        }
        if (!empty($f['type_id'])) {
            $where .= " AND $alias.project_type_id = :type_id";
            $params['type_id'] = (int) $f['type_id'];
        }
        return [$where, $params];
    }

    /**
     * Who may count a confidential project: anyone with
     * projects.view_confidential, otherwise its lead (assigned_user_id),
     * its creator and its team, the same rule as the project pages
     * (Domain\Projects\Visibility). $viewerId is an int, so it is inlined
     * rather than bound: the callers mix named and positional parameters.
     */
    private function confidentialClause(string $alias): string
    {
        if ($this->includeConfidential) {
            return '';
        }
        if ($this->viewerId !== null && $this->viewerId > 0) {
            $u = $this->viewerId;
            return " AND ($alias.confidential = 0 OR $alias.assigned_user_id = $u OR $alias.created_by = $u"
                . " OR $alias.id IN (SELECT t.project_id FROM chroma_project_team t WHERE t.user_id = $u))";
        }
        return " AND $alias.confidential = 0";
    }

    /** The last $n month keys (YYYY-MM) ending with the month of $end, oldest first. @return list<string> */
    public function monthKeys(string $end, int $n = 12): array
    {
        $d = new \DateTimeImmutable(substr($end, 0, 7) . '-01');
        $out = [];
        for ($i = $n - 1; $i >= 0; $i--) {
            $out[] = $d->modify("-$i months")->format('Y-m');
        }
        return $out;
    }

    public static function monthLabel(string $ym): string
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $ym . '-01');
        return $d ? $d->format('M Y') : $ym;
    }

    /**
     * "Today" as a quoted SQL literal. The same expression is used several
     * times in one statement, which a named placeholder cannot do with
     * native prepares; the value is validated to YYYY-MM-DD first.
     */
    private function todayLiteral(): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->today)) {
            throw new \InvalidArgumentException('today must be YYYY-MM-DD');
        }
        return "'" . $this->today . "'";
    }

    private function shiftDate(string $date, string $modifier): string
    {
        return (new \DateTimeImmutable($date))->modify($modifier)->format('Y-m-d');
    }
}
