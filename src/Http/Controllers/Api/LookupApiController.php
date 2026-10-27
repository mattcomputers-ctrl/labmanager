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
            $rows = $this->db->all(
                'SELECT id, number, version, description, voided FROM chroma_internal_codes WHERE number >= ? AND number < ? ORDER BY number, version LIMIT ' . $limit,
                [$i['number'], $this->upperBound($i['number'])]
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
                 WHERE s.sell_number_normalized LIKE ? ORDER BY s.number LIMIT ' . $limit,
                [$s['prefix'] . $s['number'] . '%']
            );
            foreach ($rows as $r) {
                $disp = CodeFormat::sell($r['prefix_code'], (int) $r['number'], (int) $r['min_digits'], $r['suffix_code']);
                $results[] = ['id' => 'S' . $r['id'], 'type' => 'sell', 'sell_number_id' => (int) $r['id'], 'internal_code_id' => $r['internal_code_id'] ? (int) $r['internal_code_id'] : null,
                    'label' => $disp . ($r['voided'] ? ' (voided)' : '') . ($r['inum'] ? ' · ' . CodeFormat::internal((int) $r['inum'], $r['iver']) : ''), 'sub' => $r['description']];
            }
        }
        if (count($results) < 5 && mb_strlen($q) >= 3) {
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

    private function upperBound(int $n): int
    {
        // "E63" should match E63, E630-E639, E6300-E6399 … up to 6 digits.
        $len = strlen((string) $n);
        return $len >= 6 ? $n + 1 : (int) ($n . str_repeat('9', 6 - $len)) + 1;
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
}
