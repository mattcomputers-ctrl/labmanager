<?php
declare(strict_types=1);

namespace Chroma\Domain\Api;

use Chroma\Core\Db;
use Chroma\Domain\Codes\CodeFormat;

/**
 * Read-only queries behind the public JSON API (/api/v1). No HTTP here:
 * every method returns plain arrays shaped exactly as the API documents
 * them (docs/API.md), so the controller only adds the envelope.
 *
 * Confidential projects never leave this class: project queries go through
 * the v_chroma_project_status view, which already excludes them.
 */
final class ApiQueries
{
    public const MAX_PER_PAGE = 200;

    public function __construct(private readonly Db $db)
    {
    }

    // ------------------------------------------------------------------
    // Codes
    // ------------------------------------------------------------------

    /**
     * Search sell numbers. Accepts any spelling the app accepts (y-1000,
     * Y 1000, b2978uv), an E number (E6374, 6374-10: returns the sell
     * numbers linked to it) or free text (matched against descriptions).
     *
     * @return array{data:list<array<string,mixed>>,total:int}
     */
    public function searchCodes(string $q, int $page, int $perPage, bool $includeVoided = true): array
    {
        $q = trim($q);
        $where = [];
        $params = [];
        if ($q !== '') {
            $sell = CodeFormat::parseSell($q);
            $internal = CodeFormat::looksInternal($q) || preg_match('/^\d+(-[A-Za-z0-9]+)?$/', $q) ? CodeFormat::parseInternal($q) : null;
            if ($internal !== null) {
                $where[] = 'ic.number = :inum' . ($internal['version'] !== '' ? ' AND ic.version = :iver' : '');
                $params['inum'] = $internal['number'];
                if ($internal['version'] !== '') {
                    $params['iver'] = $internal['version'];
                }
            } elseif ($sell !== null && ($this->prefixExists($sell['prefix']) || $this->prefixExists(CodeFormat::canonicalPrefix($sell['prefix'])))) {
                // Only a real prefix is read as a sell number: "pantone 300"
                // or "stress test 4" parse as PANTONE300 / STRESSTEST4 but
                // must search descriptions, exactly as the app's lookup does.
                // Historical spellings (UVV185, UVV-184) resolve through the
                // canonical prefix and the spelling kept in legacy_text.
                $where[] = '(s.sell_number_normalized LIKE :norm OR s.sell_number_normalized LIKE :canon OR s.legacy_text = :legacy)';
                $params['norm'] = $sell['normalized'] . '%';
                $params['canon'] = CodeFormat::canonicalPrefix($sell['prefix']) . $sell['number'] . $sell['suffix'] . '%';
                $params['legacy'] = strtoupper($q);
            } else {
                // Boolean-mode fulltext only: every word required, prefix
                // matched. No LIKE, so % and _ in q are plain text and the
                // search never scans the whole table.
                $where[] = 'MATCH(s.description) AGAINST (:ft IN BOOLEAN MODE)';
                // "Pantone300" typed as one word: split letters from digits so
                // it finds "Pantone 300" the way "pantone 300" does.
                $text = ($sell !== null && !preg_match('/\s/', $q)) ? (preg_replace('/(?<=\D)(?=\d)|(?<=\d)(?=\D)/', ' ', $q) ?? $q) : $q;
                $params['ft'] = self::booleanQuery($text);
            }
        }
        if (!$includeVoided) {
            $where[] = 's.voided = 0';
        }
        $sql = $this->sellSelect() . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
        $total = (int) $this->db->value('SELECT COUNT(*) FROM chroma_sell_numbers s LEFT JOIN chroma_internal_codes ic ON ic.id = s.internal_code_id' . ($where ? ' WHERE ' . implode(' AND ', $where) : ''), $params);
        $offset = ($page - 1) * $perPage;
        $rows = $this->db->all($sql . " ORDER BY p.sort_order, p.code, s.number, s.suffix_id LIMIT $perPage OFFSET $offset", $params);
        return ['data' => array_map([$this, 'formatSell'], $rows), 'total' => $total];
    }

    /** One sell number by any spelling, or null. @return array<string,mixed>|null */
    public function sellNumber(string $code): ?array
    {
        $sell = CodeFormat::parseSell($code);
        if ($sell === null) {
            return null;
        }
        // The literal spelling first, then the canonical prefix (UVV185 ->
        // UVVL185), then the spelling as it stood on the old sheets: the
        // same three readings the app's lookup page uses.
        $row = $this->db->one($this->sellSelect() . ' WHERE s.sell_number_normalized = ?', [$sell['normalized']]);
        $canon = CodeFormat::canonicalPrefix($sell['prefix']) . $sell['number'] . $sell['suffix'];
        if ($row === null && $canon !== $sell['normalized']) {
            $row = $this->db->one($this->sellSelect() . ' WHERE s.sell_number_normalized = ?', [$canon]);
        }
        if ($row === null) {
            $row = $this->db->one($this->sellSelect() . ' WHERE s.legacy_text = ?', [strtoupper(trim($code))]);
        }
        if ($row === null) {
            return null;
        }
        $out = $this->formatSell($row);
        $out['notes'] = $row['notes'];
        $out['legacy_text'] = $row['legacy_text'];
        $out['reason'] = $row['reason'];
        $out['void_reason'] = $row['void_reason'];
        if ($row['internal_code_id']) {
            $out['aliases'] = array_values(array_filter(
                array_map([$this, 'formatSellBrief'], $this->db->all($this->sellSelect() . ' WHERE s.internal_code_id = ? ORDER BY s.number', [(int) $row['internal_code_id']])),
                fn($a) => $a['sell_number'] !== $out['sell_number']
            ));
        } else {
            $out['aliases'] = [];
        }
        return $out;
    }

    /**
     * One E number ("E6374-10", "6374-10" or "6374") with every sell number
     * that points at it. Without a version the base formula (no version)
     * is returned when it exists, otherwise the first version on file; the
     * 'versions' list names every version of that number.
     * @return array<string,mixed>|null
     */
    public function internalCode(string $code): ?array
    {
        $parsed = CodeFormat::parseInternal($code);
        if ($parsed === null) {
            return null;
        }
        $versions = $this->db->all('SELECT * FROM chroma_internal_codes WHERE number = ? ORDER BY (version = "") DESC, version', [$parsed['number']]);
        if ($versions === []) {
            return null;
        }
        $row = null;
        foreach ($versions as $v) {
            if ($v['version'] === $parsed['version']) {
                $row = $v;
                break;
            }
        }
        if ($row === null) {
            if ($parsed['version'] !== '') {
                return null; // asked for a specific version that does not exist
            }
            $row = $versions[0];
        }
        $customer = $row['original_customer_id'] ? $this->db->value('SELECT name FROM chroma_customers WHERE id = ?', [(int) $row['original_customer_id']]) : null;
        $sells = $this->db->all($this->sellSelect() . ' WHERE s.internal_code_id = ? ORDER BY p.sort_order, p.code, s.number', [(int) $row['id']]);
        $project = $this->db->one(
            'SELECT pr.project_number, pr.title FROM chroma_project_items i JOIN chroma_projects pr ON pr.id = i.project_id WHERE i.internal_code_id = ? AND pr.confidential = 0 ORDER BY i.id LIMIT 1',
            [(int) $row['id']]
        );
        return [
            'internal_code' => CodeFormat::internal((int) $row['number'], $row['version']),
            'number' => (int) $row['number'],
            'version' => $row['version'] === '' ? null : $row['version'],
            'versions' => array_map(fn($v) => CodeFormat::internal((int) $v['number'], $v['version']), $versions),
            'description' => $row['description'],
            'customer' => $customer,
            'resale' => (int) $row['resale'] === 1,
            'vendor_name' => $row['vendor_name'],
            'vendor_code' => $row['vendor_code'],
            'legacy_handschy_number' => $row['legacy_handschy_number'],
            'date_assigned' => $row['date_assigned'],
            'voided' => (int) $row['voided'] === 1,
            'void_reason' => $row['void_reason'],
            'notes' => $row['notes'],
            'project_number' => $project ? (int) $project['project_number'] : null,
            'sell_numbers' => array_map([$this, 'formatSellBrief'], $sells),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function prefixes(): array
    {
        $rows = $this->db->all('SELECT p.*, (SELECT COUNT(*) FROM chroma_sell_numbers s WHERE s.prefix_id = p.id) AS used, (SELECT MAX(s.number) FROM chroma_sell_numbers s WHERE s.prefix_id = p.id) AS highest FROM chroma_code_prefixes p ORDER BY p.sort_order, p.code');
        $suffixes = $this->db->all('SELECT ps.prefix_id, x.code FROM chroma_code_prefix_suffixes ps JOIN chroma_code_suffixes x ON x.id = ps.suffix_id WHERE x.active = 1 ORDER BY x.sort_order, x.code');
        $byPrefix = [];
        foreach ($suffixes as $s) {
            $byPrefix[(int) $s['prefix_id']][] = $s['code'];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'prefix' => $r['code'],
                'label' => $r['label'],
                'description' => $r['description'],
                'min_digits' => (int) $r['min_digits'],
                'retired' => (int) $r['retired'] === 1,
                'tag' => $r['tag'] === '' ? null : $r['tag'],
                'allowed_suffixes' => $byPrefix[(int) $r['id']] ?? [],
                'codes_in_use' => (int) $r['used'],
                'highest_number' => $r['highest'] === null ? null : (int) $r['highest'],
                'next_number' => max((int) $r['next_number'], (int) ($r['highest'] ?? 0) + 1),
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Projects (non-confidential only, via v_chroma_project_status)
    // ------------------------------------------------------------------

    /**
     * @param array{status?:?string,customer?:?string,type?:?string,updated_since?:?string} $f
     * @return array{data:list<array<string,mixed>>,total:int}
     */
    public function projects(array $f, int $page, int $perPage): array
    {
        $where = ['1 = 1'];
        $params = [];
        if (!empty($f['status'])) {
            $where[] = 'v.status = :status';
            $params['status'] = $f['status'];
        }
        if (!empty($f['customer'])) {
            if (ctype_digit((string) $f['customer'])) {
                $where[] = 'pr.customer_id = :customer_id';
                $params['customer_id'] = (int) $f['customer'];
            } else {
                $where[] = 'v.customer LIKE :customer';
                $params['customer'] = $f['customer'] . '%';
            }
        }
        if (!empty($f['type'])) {
            $where[] = 'v.project_type = :type';
            $params['type'] = $f['type'];
        }
        if (!empty($f['updated_since'])) {
            $where[] = 'pr.updated_at >= :since';
            $params['since'] = $f['updated_since'];
        }
        $from = 'FROM v_chroma_project_status v JOIN chroma_projects pr ON pr.id = v.project_id WHERE ' . implode(' AND ', $where);
        $total = (int) $this->db->value("SELECT COUNT(*) $from", $params);
        $offset = ($page - 1) * $perPage;
        $rows = $this->db->all("SELECT v.*, pr.updated_at, pr.created_at, pr.customer_id $from ORDER BY pr.updated_at DESC, v.project_number DESC LIMIT $perPage OFFSET $offset", $params);
        return ['data' => array_map([$this, 'formatProject'], $rows), 'total' => $total];
    }

    /** A project by number with its items and their codes, or null (missing or confidential). @return array<string,mixed>|null */
    public function project(int $number): ?array
    {
        $row = $this->db->one('SELECT v.*, pr.updated_at, pr.created_at, pr.customer_id, pr.description, pr.notes, pr.confidential FROM v_chroma_project_status v JOIN chroma_projects pr ON pr.id = v.project_id WHERE v.project_number = ?', [$number]);
        if ($row === null) {
            return null;
        }
        $out = $this->formatProject($row);
        $out['description'] = $row['description'];
        $stage = $this->db->value('SELECT st.label FROM chroma_projects pr LEFT JOIN chroma_project_stages st ON st.id = pr.stage_id WHERE pr.id = ?', [(int) $row['project_id']]);
        $out['stage'] = $stage;
        $items = $this->db->all(
            'SELECT i.id, i.sequence, i.kind, i.description, i.pantone, i.hex_color, i.proofs_generated, i.proof_count, i.is_digital_match, i.date_started, i.date_completed, i.substrate_text,
                    st.code AS status_code, st.label AS status_label, st.is_done,
                    ser.name AS ink_series, sub.name AS substrate, co.name AS coating, u.display_name AS assigned_to,
                    ic.number AS inum, ic.version AS iver, ic.voided AS ivoided,
                    sn.id AS sell_id
             FROM chroma_project_items i
             LEFT JOIN chroma_item_statuses st ON st.id = i.status_id
             LEFT JOIN chroma_ink_series ser ON ser.id = i.ink_series_id
             LEFT JOIN chroma_substrates sub ON sub.id = i.substrate_id
             LEFT JOIN chroma_coatings co ON co.id = i.coating_id
             LEFT JOIN chroma_users u ON u.id = i.assigned_user_id
             LEFT JOIN chroma_internal_codes ic ON ic.id = i.internal_code_id
             LEFT JOIN chroma_sell_numbers sn ON sn.id = i.sell_number_id
             WHERE i.project_id = ? ORDER BY i.sequence, i.id',
            [(int) $row['project_id']]
        );
        $out['items'] = [];
        foreach ($items as $i) {
            $sell = $i['sell_id'] ? $this->db->one($this->sellSelect() . ' WHERE s.id = ?', [(int) $i['sell_id']]) : null;
            $out['items'][] = [
                'sequence' => (int) $i['sequence'],
                'kind' => $i['kind'],
                'description' => $i['description'],
                'pantone' => $i['pantone'],
                'hex_color' => $i['hex_color'],
                'ink_series' => $i['ink_series'],
                'substrate' => $i['substrate'] ?? $i['substrate_text'],
                'coating' => $i['coating'],
                'proofs_generated' => (int) $i['proofs_generated'] === 1,
                'proof_count' => (int) $i['proof_count'],
                'digital_match' => (int) $i['is_digital_match'] === 1,
                'status' => $i['status_code'],
                'status_label' => $i['status_label'],
                'done' => (int) ($i['is_done'] ?? 0) === 1,
                'assigned_to' => $i['assigned_to'],
                'date_started' => $i['date_started'],
                'date_completed' => $i['date_completed'],
                'internal_code' => $i['inum'] ? CodeFormat::internal((int) $i['inum'], $i['iver']) : null,
                'internal_code_voided' => $i['inum'] ? (int) $i['ivoided'] === 1 : null,
                'sell_number' => $sell ? $this->formatSellBrief($sell) : null,
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** True when the letters are a configured sell-number prefix (Y, BK, X, UV…), retired or not. */
    private function prefixExists(string $prefix): bool
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM chroma_code_prefixes WHERE code = ?', [strtoupper($prefix)]) > 0;
    }

    private function sellSelect(): string
    {
        return 'SELECT s.*, p.code AS prefix_code, p.min_digits, x.code AS suffix_code,
                       ic.number AS inum, ic.version AS iver, ic.voided AS ivoided, ic.description AS idesc, ic.resale, ic.vendor_name, ic.vendor_code,
                       c.name AS customer,
                       (SELECT pr.project_number FROM chroma_project_items i JOIN chroma_projects pr ON pr.id = i.project_id WHERE i.sell_number_id = s.id AND pr.confidential = 0 ORDER BY i.id LIMIT 1) AS project_number
                FROM chroma_sell_numbers s
                JOIN chroma_code_prefixes p ON p.id = s.prefix_id
                LEFT JOIN chroma_code_suffixes x ON x.id = s.suffix_id
                LEFT JOIN chroma_internal_codes ic ON ic.id = s.internal_code_id
                LEFT JOIN chroma_customers c ON c.id = COALESCE(s.original_customer_id, ic.original_customer_id)';
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private function formatSell(array $r): array
    {
        return [
            'sell_number' => CodeFormat::sell($r['prefix_code'], (int) $r['number'], (int) $r['min_digits'], $r['suffix_code']),
            'prefix' => $r['prefix_code'],
            'number' => (int) $r['number'],
            'suffix' => $r['suffix_code'],
            'internal_code' => $r['inum'] ? CodeFormat::internal((int) $r['inum'], $r['iver']) : null,
            'internal_code_voided' => $r['inum'] ? (int) $r['ivoided'] === 1 : null,
            'description' => $r['description'] !== '' ? $r['description'] : ($r['idesc'] ?? ''),
            'customer' => $r['customer'],
            'resale' => $r['inum'] ? (int) $r['resale'] === 1 : null,
            'vendor_name' => $r['vendor_name'],
            'vendor_code' => $r['vendor_code'],
            'date_assigned' => $r['date_assigned'],
            'created_at' => $r['created_at'],
            'updated_at' => $r['updated_at'],
            'voided' => (int) $r['voided'] === 1,
            'project_number' => $r['project_number'] === null ? null : (int) $r['project_number'],
        ];
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private function formatSellBrief(array $r): array
    {
        return [
            'sell_number' => CodeFormat::sell($r['prefix_code'], (int) $r['number'], (int) $r['min_digits'], $r['suffix_code']),
            'description' => $r['description'],
            'date_assigned' => $r['date_assigned'],
            'voided' => (int) $r['voided'] === 1,
            'reason' => $r['reason'],
        ];
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private function formatProject(array $r): array
    {
        return [
            'project_number' => (int) $r['project_number'],
            'type' => $r['project_type'],
            'type_label' => $r['project_type_label'],
            'customer' => $r['customer'],
            'customer_id' => isset($r['customer_id']) && $r['customer_id'] !== null ? (int) $r['customer_id'] : null,
            'title' => $r['title'],
            'status' => $r['status'],
            'date_submitted' => $r['date_submitted'],
            'date_completed' => $r['date_completed'],
            'target_date' => $r['target_date'],
            'assigned_to' => $r['assigned_to'],
            'item_count' => (int) $r['item_count'],
            'items_done' => (int) $r['items_done'],
            'created_at' => $r['created_at'] ?? null,
            'updated_at' => $r['updated_at'] ?? null,
        ];
    }

    /** Turn free text into a safe boolean-mode fulltext query (each word required, prefix match). */
    public static function booleanQuery(string $q): string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        foreach ($words as $w) {
            if (mb_strlen($w) >= 2) {
                $out[] = '+' . $w . '*';
            }
        }
        return $out === [] ? '""' : implode(' ', $out);
    }
}
