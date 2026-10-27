<?php
declare(strict_types=1);

namespace Chroma\Http\Controllers\Api;

use Chroma\Core\Request;
use Chroma\Core\Response;
use Chroma\Domain\Codes\CodeFormat;
use Chroma\Http\Controller;

/** Typeahead sources: {results:[{id,label,sub,...}]}. Limited to 15 rows each. */
final class LookupApiController extends Controller
{
    public function customers(Request $request): Response
    {
        $q = $request->str('q');
        $norm = \Chroma\Db\Seeder::normalizeName($q);
        $rows = $q === ''
            ? $this->db->all('SELECT id, name FROM chroma_customers WHERE active = 1 ORDER BY name LIMIT 15')
            : $this->db->all('SELECT id, name FROM chroma_customers WHERE active = 1 AND (name_normalized LIKE ? OR name_normalized LIKE ?) ORDER BY (name_normalized LIKE ?) DESC, name LIMIT 15',
                [$norm . '%', '% ' . $norm . '%', $norm . '%']);
        return $this->json(['results' => array_map(fn($r) => ['id' => (int) $r['id'], 'label' => $r['name']], $rows)]);
    }

    public function users(Request $request): Response
    {
        $q = $request->str('q');
        $rows = $this->db->all('SELECT id, username, display_name FROM chroma_users WHERE active = 1 AND (display_name LIKE ? OR username LIKE ?) ORDER BY display_name LIMIT 15', [$q . '%', $q . '%']);
        return $this->json(['results' => array_map(fn($r) => ['id' => (int) $r['id'], 'label' => $r['display_name'] ?: $r['username'], 'sub' => $r['username']], $rows)]);
    }

    /** Search E numbers and sell numbers together (for "pick an existing product"). */
    public function codes(Request $request): Response
    {
        $q = $request->str('q');
        $results = [];
        if ($q === '') {
            return $this->json(['results' => []]);
        }
        $limit = 10;
        $i = CodeFormat::parseInternal($q);
        if ($i !== null) {
            [$cond, $params] = self::numberPrefixCondition('number', $i['number']);
            if ($i['version'] !== '') {
                $cond .= ' AND version LIKE ?';
                $params[] = $i['version'] . '%';
            }
            $rows = $this->db->all(
                'SELECT id, number, version, description, voided FROM chroma_internal_codes WHERE ' . $cond . ' ORDER BY number, version LIMIT ' . $limit,
                $params
            );
            foreach ($rows as $r) {
                $results[] = ['id' => 'E' . $r['id'], 'type' => 'internal', 'internal_code_id' => (int) $r['id'], 'label' => CodeFormat::internal((int) $r['number'], $r['version']) . ($r['voided'] ? ' (voided)' : ''), 'sub' => $r['description']];
            }
        }
        $s = CodeFormat::parseSell($q);
        if ($s !== null) {
            $rows = $this->db->all(
                'SELECT s.id, s.number, s.description, s.voided, s.internal_code_id, p.code AS prefix_code, p.min_digits, x.code AS suffix_code, i.number AS inum, i.version AS iver
                 FROM chroma_sell_numbers s JOIN chroma_code_prefixes p ON p.id = s.prefix_id LEFT JOIN chroma_code_suffixes x ON x.id = s.suffix_id LEFT JOIN chroma_internal_codes i ON i.id = s.internal_code_id
                 WHERE s.sell_number_normalized LIKE ? OR s.sell_number_normalized LIKE ? OR s.legacy_text = ? ORDER BY s.number LIMIT ' . $limit,
                [$s['prefix'] . $s['number'] . '%', CodeFormat::canonicalPrefix($s['prefix']) . $s['number'] . '%', trim($q)]
            );
            foreach ($rows as $r) {
                $disp = CodeFormat::sell($r['prefix_code'], (int) $r['number'], (int) $r['min_digits'], $r['suffix_code']);
                $results[] = ['id' => 'S' . $r['id'], 'type' => 'sell', 'sell_number_id' => (int) $r['id'], 'internal_code_id' => $r['internal_code_id'] ? (int) $r['internal_code_id'] : null,
                    'label' => $disp . ($r['voided'] ? ' (voided)' : '') . ($r['inum'] ? ' · ' . CodeFormat::internal((int) $r['inum'], $r['iver']) : ''), 'sub' => $r['description']];
            }
        }
        if (count($results) < 5 && mb_strlen($q) >= 3 && self::booleanQuery($q) !== '') {
            $like = $q . '%';
            $rows = $this->db->all(
                'SELECT s.id, s.number, s.description, s.voided, s.internal_code_id, p.code AS prefix_code, p.min_digits, x.code AS suffix_code
                 FROM chroma_sell_numbers s JOIN chroma_code_prefixes p ON p.id = s.prefix_id LEFT JOIN chroma_code_suffixes x ON x.id = s.suffix_id
                 WHERE MATCH(s.description) AGAINST (? IN BOOLEAN MODE) ORDER BY s.id DESC LIMIT ' . $limit,
                [self::booleanQuery($q)]
            );
            foreach ($rows as $r) {
                $disp = CodeFormat::sell($r['prefix_code'], (int) $r['number'], (int) $r['min_digits'], $r['suffix_code']);
                $results[] = ['id' => 'S' . $r['id'], 'type' => 'sell', 'sell_number_id' => (int) $r['id'], 'internal_code_id' => $r['internal_code_id'] ? (int) $r['internal_code_id'] : null, 'label' => $disp, 'sub' => $r['description']];
            }
            $rows = $this->db->all(
                'SELECT id, number, version, description FROM chroma_internal_codes WHERE MATCH(description) AGAINST (? IN BOOLEAN MODE) ORDER BY id DESC LIMIT ' . $limit,
                [self::booleanQuery($q)]
            );
            foreach ($rows as $r) {
                $results[] = ['id' => 'E' . $r['id'], 'type' => 'internal', 'internal_code_id' => (int) $r['id'], 'label' => CodeFormat::internal((int) $r['number'], $r['version']), 'sub' => $r['description']];
            }
            unset($like);
        }
        return $this->json(['results' => array_slice($results, 0, 15)]);
    }

    /**
     * "E63" should match E63, E630–E639, E6300–E6399 … up to 7 digits, but
     * not E7000. Returns a SQL condition over $column and its parameters.
     * @return array{0:string,1:list<int>}
     */
    public static function numberPrefixCondition(string $column, int $n): array
    {
        $digits = (string) $n;
        $parts = ["$column = ?"];
        $params = [$n];
        for ($extra = 1; strlen($digits) + $extra <= 7; $extra++) {
            $parts[] = "($column BETWEEN ? AND ?)";
            $params[] = (int) ($digits . str_repeat('0', $extra));
            $params[] = (int) ($digits . str_repeat('9', $extra));
        }
        return ['(' . implode(' OR ', $parts) . ')', $params];
    }

    /** InnoDB's default stopword list; a required stopword can never match. */
    public const FT_STOPWORDS = ['a', 'about', 'an', 'are', 'as', 'at', 'be', 'by', 'com', 'de', 'en', 'for', 'from', 'how', 'i', 'in', 'is', 'it', 'la', 'of', 'on', 'or', 'that', 'the', 'this', 'to', 'was', 'what', 'when', 'where', 'who', 'will', 'with', 'und', 'www'];
    /** innodb_ft_min_token_size default: shorter words are not in the index at all. */
    public const FT_MIN_TOKEN = 3;

    /**
     * Turn free text into a safe boolean-mode fulltext query. Words that are
     * in the index are required (+word*); words InnoDB does not index (shorter
     * than 3 characters such as "UV", or stopwords such as "for") are left out,
     * because requiring them would return nothing.
     */
    public static function booleanQuery(string $q): string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $parts = [];
        foreach ($words as $w) {
            if (mb_strlen($w) < self::FT_MIN_TOKEN || in_array(mb_strtolower($w), self::FT_STOPWORDS, true)) {
                continue;
            }
            $parts[] = '+' . $w . '*';
        }
        return implode(' ', $parts);
    }
}
