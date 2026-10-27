<?php
declare(strict_types=1);

namespace Chroma\Domain\Codes;

use Chroma\Core\Db;

/**
 * Code lookup and code logs: the replacement for the cross-reference
 * spreadsheet and the per-prefix log sheets.
 *
 * Search accepts any spelling (y-1000, Y 1000, y1000, E6374-10, 6374, free
 * text) and only ever touches indexed columns: sell_number_normalized with a
 * prefix LIKE, the E number range index, the customer name index and the
 * FULLTEXT indexes on descriptions. Results are grouped by product: an E
 * number with all of its sell numbers, or a sell number that has no E number.
 */
final class CodeSearch
{
    public const MAX_EXPORT = 20000;

    public function __construct(private readonly Db $db)
    {
    }

    // ------------------------------------------------------------------
    // Parsing
    // ------------------------------------------------------------------

    /**
     * What a search string looks like. Several readings are tried at once
     * (a code, a number, a customer, words in a description).
     *
     * @return array{raw:string,sells:list<string>,internal:?array{number:int,version:string},digits:?int,prefix:?string,words:string,legacy:?string}
     */
    public static function parse(string $q): array
    {
        $raw = trim(preg_replace('/\s+/', ' ', $q) ?? $q);
        $out = ['raw' => $raw, 'sells' => [], 'internal' => null, 'digits' => null, 'prefix' => null, 'words' => '', 'legacy' => null];
        if ($raw === '') {
            return $out;
        }
        $candidates = [$raw];
        $tokens = explode(' ', $raw);
        if (count($tokens) > 1) {
            $candidates[] = $tokens[0];
        }
        if (!CodeFormat::looksInternal($raw)) {
            foreach ($candidates as $c) {
                $p = CodeFormat::parseSell($c);
                if ($p !== null && !in_array($p['normalized'], $out['sells'], true)) {
                    $out['sells'][] = $p['normalized'];
                }
            }
        }
        foreach ($candidates as $c) {
            $i = CodeFormat::parseInternal($c);
            if ($i !== null) {
                $out['internal'] = $i;
                break;
            }
        }
        if (ctype_digit($raw)) {
            $out['digits'] = (int) $raw;
        }
        if (preg_match('/^[A-Za-z]{1,12}$/', $raw)) {
            $out['prefix'] = strtoupper($raw);
        }
        $out['words'] = self::booleanQuery($raw);
        $out['legacy'] = mb_strlen($raw) <= 100 ? strtoupper($raw) : null;
        return $out;
    }

    /** Turn free text into a safe boolean-mode fulltext query: every word required, prefix-matched. */
    public static function booleanQuery(string $q): string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $parts = [];
        foreach ($words as $w) {
            if (mb_strlen($w) < 2) {
                continue;
            }
            $parts[] = '+' . $w . '*';
        }
        return implode(' ', $parts);
    }

    /**
     * "E63" should match E63, E630-E639, E6300-E6399 … up to 6 digits, and
     * nothing in between (E6374 must not pull in E9102).
     * @return list<array{0:int,1:int}> inclusive ranges, the exact number first
     */
    public static function numberRanges(int $n): array
    {
        $ranges = [[$n, $n]];
        $len = strlen((string) $n);
        for ($k = 1; $len + $k <= 6; $k++) {
            $base = $n * (10 ** $k);
            $ranges[] = [$base, $base + (10 ** $k) - 1];
        }
        return $ranges;
    }

    /** SQL for the "starts with these digits" ranges (without the exact number). @return array{0:string,1:list<int>} */
    private static function rangeCond(string $column, int $n, bool $includeExact): array
    {
        $parts = [];
        $params = [];
        foreach (self::numberRanges($n) as $i => [$lo, $hi]) {
            if ($i === 0 && !$includeExact) {
                continue;
            }
            $parts[] = "($column BETWEEN ? AND ?)";
            $params[] = $lo;
            $params[] = $hi;
        }
        return ['(' . implode(' OR ', $parts) . ')', $params];
    }

    // ------------------------------------------------------------------
    // Lookup
    // ------------------------------------------------------------------

    /**
     * Search, grouped by product, with server-side pagination.
     *
     * @param array{show?:string,prefix?:string} $filters show = all|active|voided
     * @return array{total:int,page:int,per_page:int,products:list<array<string,mixed>>,parsed:array<string,mixed>}
     */
    public function search(string $q, int $page = 1, int $perPage = 25, array $filters = []): array
    {
        $parsed = self::parse($q);
        $page = max(1, $page);
        $perPage = max(1, min(500, $perPage));
        [$sql, $params] = $this->candidateSql($parsed, $filters);
        if ($sql === '') {
            return ['total' => 0, 'page' => $page, 'per_page' => $perPage, 'products' => [], 'parsed' => $parsed];
        }
        $total = (int) $this->db->value("SELECT COUNT(*) FROM (SELECT pk, pid FROM ($sql) t GROUP BY pk, pid) c", $params);
        $keys = $this->db->all(
            "SELECT pk, pid, MIN(r) AS r, MIN(sortn) AS sortn FROM ($sql) t GROUP BY pk, pid ORDER BY r, sortn, pk, pid LIMIT " . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
            $params
        );
        return ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'products' => $this->products($keys), 'parsed' => $parsed];
    }

    /**
     * Every matching row flattened for CSV/XLSX (one row per sell number;
     * an E number without sell numbers gets a row of its own).
     * @param array{show?:string,prefix?:string} $filters
     * @return list<array<string,mixed>>
     */
    public function exportRows(string $q, array $filters = []): array
    {
        $rows = [];
        $page = 1;
        do {
            $res = $this->search($q, $page, 500, $filters);
            foreach ($res['products'] as $p) {
                foreach (self::flatten($p) as $r) {
                    $rows[] = $r;
                }
            }
            $page++;
        } while (count($res['products']) === 500 && count($rows) < self::MAX_EXPORT);
        return $rows;
    }

    /** @param array<string,mixed> $product @return list<array<string,mixed>> */
    public static function flatten(array $product): array
    {
        $i = $product['internal'];
        $row = function (?array $s) use ($i, $product): array {
            return [
                'internal_code' => $i['display'] ?? '',
                'sell_number' => $s['display'] ?? '',
                'description' => $product['description'],
                'sell_description' => $s['description'] ?? '',
                'customer' => $product['customer'] ?? '',
                'sell_customer' => $s['customer'] ?? '',
                'date_assigned' => $s['date_assigned'] ?? ($i['date_assigned'] ?? ''),
                'reason' => !empty($s['reason']) ? (CodeAssigner::REASONS[$s['reason']] ?? $s['reason']) : '',
                'resale' => $i ? ((int) $i['resale'] === 1 ? 'Yes' : 'No') : '',
                'vendor' => $i ? trim((string) ($i['vendor_name'] ?? '') . ' ' . (string) ($i['vendor_code'] ?? '')) : '',
                'project' => implode('; ', array_map(fn($o) => 'Project ' . $o['project_number'], $product['origins'])),
                'voided' => $s !== null && (int) $s['voided'] === 1 ? 'Voided' : ($i && (int) $i['voided'] === 1 ? ($s ? 'E number voided' : 'Voided') : ''),
            ];
        };
        $out = [];
        foreach ($product['sells'] as $s) {
            $out[] = $row($s);
        }
        if ($out === [] && $i) {
            $out[] = $row(null);
        }
        return $out;
    }

    /** @return array<string,string> */
    public static function exportColumns(): array
    {
        return [
            'internal_code' => 'E number', 'sell_number' => 'Sell number', 'description' => 'Description', 'sell_description' => 'Sell number description',
            'customer' => 'Customer', 'sell_customer' => 'Sell number customer', 'date_assigned' => 'Date assigned', 'reason' => 'Reason',
            'resale' => 'Resale', 'vendor' => 'Vendor', 'project' => 'Project', 'voided' => 'Voided',
        ];
    }

    /**
     * The UNION of indexed probes, each yielding a product key:
     * pk = 'E' (an E number, pid = its id) or 'S' (a sell number with no E number, pid = its id).
     * @param array<string,mixed> $parsed
     * @param array{show?:string,prefix?:string} $filters
     * @return array{0:string,1:list<mixed>}
     */
    private function candidateSql(array $parsed, array $filters): array
    {
        $show = $filters['show'] ?? 'all';
        $sellExtra = '';
        $intExtra = '';
        $extraParams = [];
        if ($show === 'active') {
            $sellExtra .= ' AND s.voided = 0';
            $intExtra .= ' AND i.voided = 0';
        } elseif ($show === 'voided') {
            $sellExtra .= ' AND s.voided = 1';
            $intExtra .= ' AND i.voided = 1';
        }
        if (!empty($filters['prefix'])) {
            $sellExtra .= ' AND s.prefix_id = (SELECT id FROM chroma_code_prefixes WHERE code = ?)';
            $extraParams[] = strtoupper((string) $filters['prefix']);
        }
        $sellSelect = "SELECT IF(s.internal_code_id IS NULL, 'S', 'E') AS pk, COALESCE(s.internal_code_id, s.id) AS pid, %d AS r, s.number AS sortn FROM chroma_sell_numbers s WHERE %s" . $sellExtra;
        $intSelect = "SELECT 'E' AS pk, i.id AS pid, %d AS r, i.number AS sortn FROM chroma_internal_codes i WHERE %s" . $intExtra;
        $parts = [];
        $params = [];
        $add = function (string $select, int $rank, string $cond, array $p) use (&$parts, &$params, $extraParams, $sellSelect) {
            $parts[] = sprintf($select, $rank, $cond);
            foreach ($p as $v) {
                $params[] = $v;
            }
            if ($select === $sellSelect) {
                foreach ($extraParams as $v) {
                    $params[] = $v;
                }
            }
        };
        if (!empty($filters['prefix']) && $parsed['raw'] === '') {
            // Browsing one prefix without a search term.
            $add($sellSelect, 1, '1 = 1', []);
        }
        foreach ($parsed['sells'] as $norm) {
            $add($sellSelect, 0, 's.sell_number_normalized = ?', [$norm]);
            $add($sellSelect, 1, 's.sell_number_normalized LIKE ?', [$norm . '%']);
        }
        if ($parsed['internal'] !== null) {
            $n = $parsed['internal']['number'];
            $v = $parsed['internal']['version'];
            if ($v !== '') {
                $add($intSelect, 0, 'i.number = ? AND i.version = ?', [$n, $v]);
            } else {
                $add($intSelect, 0, 'i.number = ?', [$n]);
                [$cond, $p] = self::rangeCond('i.number', $n, false);
                $add($intSelect, 1, $cond, $p);
            }
        }
        if ($parsed['digits'] !== null) {
            // "1000" also finds Y1000, BK1000 … under any prefix, via ix_sell_number (db/migrations/0040).
            $add($sellSelect, 1, 's.number = ?', [$parsed['digits']]);
        }
        if ($parsed['prefix'] !== null) {
            $add($sellSelect, 2, 's.prefix_id = (SELECT id FROM chroma_code_prefixes WHERE code = ?)', [$parsed['prefix']]);
        }
        if ($parsed['legacy'] !== null && $parsed['sells'] === [] && $parsed['internal'] === null) {
            $add($intSelect, 1, 'i.legacy_internal_code = ?', [$parsed['legacy']]);
        }
        if ($parsed['words'] !== '') {
            $add($sellSelect, 3, 'MATCH(s.description) AGAINST (? IN BOOLEAN MODE)', [$parsed['words']]);
            $add($intSelect, 3, 'MATCH(i.description) AGAINST (? IN BOOLEAN MODE)', [$parsed['words']]);
        }
        $customerIds = $this->customerIds($parsed['raw']);
        if ($customerIds !== []) {
            $in = implode(',', array_fill(0, count($customerIds), '?'));
            $add($sellSelect, 4, "s.original_customer_id IN ($in)", $customerIds);
            $add($intSelect, 4, "i.original_customer_id IN ($in)", $customerIds);
        }
        if ($parts === []) {
            return ['', []];
        }
        return [implode(' UNION ALL ', $parts), $params];
    }

    /** Plain-language label for a code's source column (used by lists, pages and exports). */
    public static function sourceLabel(?string $source): string
    {
        return match ((string) $source) {
            'manual' => 'Entered by hand',
            'import' => 'Imported from the spreadsheets',
            '' => '',
            default => 'Assigned in Chroma',
        };
    }

    /** @return list<int> */
    private function customerIds(string $raw): array
    {
        if (mb_strlen($raw) < 2) {
            return [];
        }
        $norm = mb_strtolower(trim(preg_replace('/[^a-z0-9]+/iu', ' ', $raw) ?? $raw));
        $norm = trim(preg_replace('/\s+/', ' ', $norm) ?? $norm);
        if ($norm === '' || ctype_digit($norm)) {
            return [];
        }
        return array_map('intval', $this->db->column(
            'SELECT id FROM chroma_customers WHERE name_normalized LIKE ? OR name_normalized LIKE ? LIMIT 50',
            [$norm . '%', '% ' . $norm . '%']
        ));
    }

    // ------------------------------------------------------------------
    // Product cards
    // ------------------------------------------------------------------

    /**
     * Load full product groups for a list of keys.
     * @param list<array<string,mixed>> $keys rows with pk and pid
     * @return list<array<string,mixed>>
     */
    public function products(array $keys): array
    {
        $eIds = [];
        $sIds = [];
        foreach ($keys as $k) {
            if ($k['pk'] === 'E') {
                $eIds[] = (int) $k['pid'];
            } else {
                $sIds[] = (int) $k['pid'];
            }
        }
        $internals = $eIds ? $this->internalRows($eIds) : [];
        $aliases = $eIds ? $this->sellRows('s.internal_code_id IN (' . implode(',', $eIds) . ')') : [];
        $standalone = $sIds ? $this->sellRows('s.id IN (' . implode(',', $sIds) . ')') : [];
        $origins = $this->origins($eIds, array_merge($sIds, array_map(fn($s) => (int) $s['id'], $aliases)));

        $byE = [];
        foreach ($aliases as $s) {
            $byE[(int) $s['internal_code_id']][] = $s;
        }
        $out = [];
        foreach ($keys as $k) {
            $pid = (int) $k['pid'];
            if ($k['pk'] === 'E') {
                $i = $internals[$pid] ?? null;
                if ($i === null) {
                    continue;
                }
                $sells = $byE[$pid] ?? [];
                $out[] = self::productFromInternal($i, $sells, $origins);
            } else {
                $s = null;
                foreach ($standalone as $row) {
                    if ((int) $row['id'] === $pid) {
                        $s = $row;
                        break;
                    }
                }
                if ($s === null) {
                    continue;
                }
                $out[] = [
                    'key' => 'S' . $pid, 'type' => 'sell', 'internal' => null, 'sells' => [$s],
                    'description' => $s['description'], 'customer' => $s['customer'], 'voided' => (int) $s['voided'] === 1,
                    'date_assigned' => $s['date_assigned'],
                    'origins' => array_values(array_filter($origins, fn($o) => (int) $o['sell_number_id'] === $pid)),
                ];
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $i @param list<array<string,mixed>> $sells @param list<array<string,mixed>> $origins @return array<string,mixed> */
    public static function productFromInternal(array $i, array $sells, array $origins): array
    {
        $sellIds = array_map(fn($s) => (int) $s['id'], $sells);
        $allVoid = $sells !== [] && count(array_filter($sells, fn($s) => (int) $s['voided'] === 1)) === count($sells);
        return [
            'key' => 'E' . (int) $i['id'], 'type' => 'internal', 'internal' => $i, 'sells' => $sells,
            'description' => $i['description'] !== '' ? $i['description'] : ($sells[0]['description'] ?? ''),
            'customer' => $i['customer'] ?? ($sells[0]['customer'] ?? null),
            'voided' => (int) $i['voided'] === 1 || $allVoid,
            'date_assigned' => $i['date_assigned'],
            'origins' => array_values(array_filter($origins, fn($o) => (int) $o['internal_code_id'] === (int) $i['id'] || in_array((int) $o['sell_number_id'], $sellIds, true))),
        ];
    }

    /** @param list<int> $ids @return array<int,array<string,mixed>> keyed by id */
    public function internalRows(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->db->all(
            'SELECT i.*, c.name AS customer, u.display_name AS assigned_by_name, u.username AS assigned_by_username, vu.display_name AS voided_by_name
             FROM chroma_internal_codes i LEFT JOIN chroma_customers c ON c.id = i.original_customer_id
             LEFT JOIN chroma_users u ON u.id = i.assigned_by LEFT JOIN chroma_users vu ON vu.id = i.voided_by
             WHERE i.id IN (' . implode(',', array_map('intval', $ids)) . ')'
        );
        $out = [];
        foreach ($rows as $r) {
            $r['display'] = CodeFormat::internal((int) $r['number'], $r['version']);
            $r['assigned_by_name'] = $r['assigned_by_name'] ?: $r['assigned_by_username'];
            $out[(int) $r['id']] = $r;
        }
        return $out;
    }

    /** @param list<mixed> $params @return list<array<string,mixed>> */
    public function sellRows(string $where, array $params = [], string $order = 'p.sort_order, p.code, s.number, s.suffix_id', string $limit = ''): array
    {
        $rows = $this->db->all(
            "SELECT s.*, p.code AS prefix_code, p.label AS prefix_label, p.min_digits, p.retired AS prefix_retired, x.code AS suffix_code,
                    c.name AS customer, u.display_name AS assigned_by_name, u.username AS assigned_by_username, vu.display_name AS voided_by_name,
                    i.number AS internal_number, i.version AS internal_version, i.description AS internal_description, i.voided AS internal_voided
             FROM chroma_sell_numbers s JOIN chroma_code_prefixes p ON p.id = s.prefix_id LEFT JOIN chroma_code_suffixes x ON x.id = s.suffix_id
             LEFT JOIN chroma_customers c ON c.id = s.original_customer_id LEFT JOIN chroma_users u ON u.id = s.assigned_by LEFT JOIN chroma_users vu ON vu.id = s.voided_by
             LEFT JOIN chroma_internal_codes i ON i.id = s.internal_code_id
             WHERE $where ORDER BY $order $limit",
            $params
        );
        foreach ($rows as &$r) {
            $r['display'] = CodeFormat::sell($r['prefix_code'], (int) $r['number'], (int) $r['min_digits'], $r['suffix_code']);
            $r['internal_display'] = $r['internal_number'] ? CodeFormat::internal((int) $r['internal_number'], $r['internal_version']) : null;
            $r['assigned_by_name'] = $r['assigned_by_name'] ?: $r['assigned_by_username'];
        }
        return $rows;
    }

    /**
     * Which project/item each code came from.
     * @param list<int> $internalIds @param list<int> $sellIds
     * @return list<array<string,mixed>>
     */
    public function origins(array $internalIds, array $sellIds): array
    {
        $select = 'SELECT pi.id AS item_id, pi.sequence, pi.description AS item_description, pi.internal_code_id, pi.sell_number_id,
                          p.id AS project_id, p.project_number, p.title AS project_title, p.confidential, p.status AS project_status, c.name AS project_customer
                   FROM chroma_project_items pi JOIN chroma_projects p ON p.id = pi.project_id LEFT JOIN chroma_customers c ON c.id = p.customer_id WHERE ';
        $rows = [];
        if ($internalIds !== []) {
            $rows = $this->db->all($select . 'pi.internal_code_id IN (' . implode(',', array_map('intval', $internalIds)) . ')');
        }
        if ($sellIds !== []) {
            foreach ($this->db->all($select . 'pi.sell_number_id IN (' . implode(',', array_map('intval', $sellIds)) . ')') as $r) {
                $rows[] = $r;
            }
        }
        $seen = [];
        $out = [];
        foreach ($rows as $r) {
            if (isset($seen[(int) $r['item_id']])) {
                continue;
            }
            $seen[(int) $r['item_id']] = true;
            $out[] = $r;
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Code logs
    // ------------------------------------------------------------------

    /**
     * Prefixes with how many sell numbers each holds, plus the E number log.
     * @return list<array<string,mixed>>
     */
    public function logPrefixes(): array
    {
        $counts = [];
        foreach ($this->db->all('SELECT prefix_id, COUNT(*) AS n, SUM(voided) AS v, MAX(date_assigned) AS last FROM chroma_sell_numbers GROUP BY prefix_id') as $r) {
            $counts[(int) $r['prefix_id']] = $r;
        }
        $out = [];
        $e = $this->db->one('SELECT COUNT(*) AS n, SUM(voided) AS v, MAX(date_assigned) AS last FROM chroma_internal_codes');
        $out[] = ['code' => 'E', 'label' => 'E numbers', 'description' => 'Internal formula codes, one global sequence.', 'retired' => 0, 'tag' => '', 'count' => (int) ($e['n'] ?? 0), 'voided' => (int) ($e['v'] ?? 0), 'last' => $e['last'] ?? null];
        foreach ($this->db->all('SELECT id, code, label, description, retired, tag, next_number, min_digits FROM chroma_code_prefixes ORDER BY retired, sort_order, code') as $p) {
            $c = $counts[(int) $p['id']] ?? null;
            $out[] = $p + ['count' => (int) ($c['n'] ?? 0), 'voided' => (int) ($c['v'] ?? 0), 'last' => $c['last'] ?? null];
        }
        return $out;
    }

    /**
     * One log: a prefix's sell numbers newest first, or 'E' for E numbers.
     * @param array{q?:string,show?:string,from?:string,to?:string} $filters
     * @return array{prefix:array<string,mixed>|null,rows:list<array<string,mixed>>,total:int,page:int,per_page:int}
     */
    public function log(string $prefixCode, array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(500, $perPage));
        $offset = ($page - 1) * $perPage;
        $q = trim((string) ($filters['q'] ?? ''));
        $show = $filters['show'] ?? 'all';
        $conds = [];
        $params = [];
        if ($prefixCode === 'E') {
            $alias = 'i';
            if ($q !== '') {
                $p = CodeFormat::parseInternal($q);
                if ($p !== null) {
                    [$cond, $cp] = self::rangeCond('i.number', $p['number'], true);
                    $conds[] = $cond;
                    array_push($params, ...$cp);
                } else {
                    $words = self::booleanQuery($q);
                    if ($words === '') {
                        return ['prefix' => null, 'rows' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage];
                    }
                    $conds[] = 'MATCH(i.description) AGAINST (? IN BOOLEAN MODE)';
                    $params[] = $words;
                }
            }
            $prefix = ['code' => 'E', 'label' => 'E numbers', 'retired' => 0];
        } else {
            $alias = 's';
            $prefix = $this->db->one('SELECT * FROM chroma_code_prefixes WHERE code = ?', [strtoupper($prefixCode)]);
            if ($prefix === null) {
                return ['prefix' => null, 'rows' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage];
            }
            $conds[] = 's.prefix_id = ?';
            $params[] = (int) $prefix['id'];
            if ($q !== '') {
                $sell = CodeFormat::parseSell($q);
                if ($sell !== null && $sell['prefix'] === $prefix['code']) {
                    $conds[] = 's.sell_number_normalized LIKE ?';
                    $params[] = $sell['normalized'] . '%';
                } elseif (ctype_digit($q)) {
                    [$cond, $cp] = self::rangeCond('s.number', (int) $q, true);
                    $conds[] = $cond;
                    array_push($params, ...$cp);
                } else {
                    $words = self::booleanQuery($q);
                    if ($words === '') {
                        return ['prefix' => $prefix, 'rows' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage];
                    }
                    $conds[] = 'MATCH(s.description) AGAINST (? IN BOOLEAN MODE)';
                    $params[] = $words;
                }
            }
        }
        if ($show === 'active') {
            $conds[] = "$alias.voided = 0";
        } elseif ($show === 'voided') {
            $conds[] = "$alias.voided = 1";
        }
        foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
            $d = trim((string) ($filters[$k] ?? ''));
            if ($d !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                $conds[] = "$alias.date_assigned $op ?";
                $params[] = $d;
            }
        }
        $where = implode(' AND ', $conds) ?: '1 = 1';
        if ($prefixCode === 'E') {
            $total = (int) $this->db->value("SELECT COUNT(*) FROM chroma_internal_codes i WHERE $where", $params);
            $ids = array_map('intval', $this->db->column("SELECT i.id FROM chroma_internal_codes i WHERE $where ORDER BY i.number DESC, i.version DESC LIMIT $perPage OFFSET $offset", $params));
            $internals = $this->internalRows($ids);
            $aliases = $ids ? $this->sellRows('s.internal_code_id IN (' . implode(',', $ids) . ')') : [];
            $byE = [];
            foreach ($aliases as $s) {
                $byE[(int) $s['internal_code_id']][] = $s;
            }
            $rows = [];
            foreach ($ids as $id) {
                if (isset($internals[$id])) {
                    $r = $internals[$id];
                    $r['sells'] = $byE[$id] ?? [];
                    $rows[] = $r;
                }
            }
        } else {
            $total = (int) $this->db->value("SELECT COUNT(*) FROM chroma_sell_numbers s WHERE $where", $params);
            $rows = $this->sellRows($where, $params, 's.number DESC, s.suffix_id', "LIMIT $perPage OFFSET $offset");
        }
        return ['prefix' => $prefix, 'rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * Rows of one log for export (capped).
     * @param array{q?:string,show?:string,from?:string,to?:string} $filters
     * @return list<array<string,mixed>>
     */
    public function logExportRows(string $prefixCode, array $filters = []): array
    {
        $out = [];
        $page = 1;
        do {
            $res = $this->log($prefixCode, $filters, $page, 500);
            foreach ($res['rows'] as $r) {
                if ($prefixCode === 'E') {
                    $out[] = [
                        'code' => $r['display'], 'description' => $r['description'], 'sell_numbers' => implode(', ', array_map(fn($s) => $s['display'], $r['sells'])),
                        'customer' => $r['customer'] ?? '', 'date_assigned' => $r['date_assigned'] ?? '', 'assigned_by' => $r['assigned_by_name'] ?? '',
                        'resale' => (int) $r['resale'] === 1 ? 'Yes' : 'No', 'vendor' => trim((string) ($r['vendor_name'] ?? '') . ' ' . (string) ($r['vendor_code'] ?? '')),
                        'source' => self::sourceLabel($r['source']), 'voided' => (int) $r['voided'] === 1 ? 'Voided' : '', 'void_reason' => $r['void_reason'] ?? '',
                    ];
                } else {
                    $out[] = [
                        'code' => $r['display'], 'description' => $r['description'], 'internal_code' => $r['internal_display'] ?? '',
                        'customer' => $r['customer'] ?? '', 'date_assigned' => $r['date_assigned'] ?? '', 'assigned_by' => $r['assigned_by_name'] ?? '',
                        'reason' => $r['reason'] ? (CodeAssigner::REASONS[$r['reason']] ?? $r['reason']) : '', 'legacy_text' => $r['legacy_text'] ?? '',
                        'source' => self::sourceLabel($r['source']), 'voided' => (int) $r['voided'] === 1 ? 'Voided' : '', 'void_reason' => $r['void_reason'] ?? '',
                    ];
                }
            }
            $page++;
        } while (count($res['rows']) === 500 && count($out) < self::MAX_EXPORT);
        return $out;
    }

    /** @return array<string,string> */
    public static function logExportColumns(string $prefixCode): array
    {
        if ($prefixCode === 'E') {
            return ['code' => 'E number', 'description' => 'Description', 'sell_numbers' => 'Sell numbers', 'customer' => 'Customer', 'date_assigned' => 'Date assigned', 'assigned_by' => 'Assigned by', 'resale' => 'Resale', 'vendor' => 'Vendor', 'source' => 'Source', 'voided' => 'Voided', 'void_reason' => 'Void reason'];
        }
        return ['code' => 'Sell number', 'description' => 'Description', 'internal_code' => 'E number', 'customer' => 'Customer', 'date_assigned' => 'Date assigned', 'assigned_by' => 'Assigned by', 'reason' => 'Reason', 'legacy_text' => 'Original spelling', 'source' => 'Source', 'voided' => 'Voided', 'void_reason' => 'Void reason'];
    }
}
