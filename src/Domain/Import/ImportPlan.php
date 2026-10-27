<?php
declare(strict_types=1);

namespace Chroma\Domain\Import;

/**
 * What a legacy import would do (dry run) or did (commit). Built by
 * LegacyImporter::plan() without touching the database; apply() then walks
 * the actions. The same object renders the on-screen report, the CLI text
 * and the JSON summary stored in chroma_imports.
 */
final class ImportPlan
{
    /** How many entries of each list are kept in the stored summary. */
    public const LIST_LIMIT = 300;

    /** @var array<string,mixed> */
    public array $options = ['map_uvv' => true];

    /** @var list<array<string,mixed>> name, kind, label, sheet, header_row, row_count, counts */
    public array $files = [];

    /** @var list<array<string,mixed>> ordered actions: create_internal | update_internal | create_sell | update_sell */
    public array $actions = [];

    /** @var list<array<string,mixed>> type, file, file_label, row, message, raw, code */
    public array $issues = [];

    /** @var array<string,array<int|string,mixed>> duplicates, unparseable, unknown_prefixes, uvv_rows, legacy_internal, unknown_suffixes, bad_dates, new_customers, prefix_suffixes */
    public array $lists = [
        'duplicates' => [], 'unparseable' => [], 'unknown_prefixes' => [], 'uvv_rows' => [], 'legacy_internal' => [],
        'unknown_suffixes' => [], 'bad_dates' => [], 'new_customers' => [], 'prefix_suffixes' => [],
    ];

    /** @var array<string,int> */
    public array $totals = [
        'rows' => 0, 'create_internal' => 0, 'update_internal' => 0, 'create_sell' => 0, 'update_sell' => 0,
        'skip' => 0, 'issues' => 0, 'issues_known' => 0, 'new_customers' => 0, 'new_prefixes' => 0,
    ];

    /** Filled in by apply(): what really happened. @var array<string,mixed>|null */
    public ?array $applied = null;

    public ?int $importId = null;
    public bool $committed = false;

    /** @var list<string> */
    public array $log = [];

    /** @param array<string,mixed> $file */
    public function addFile(array $file): int
    {
        $file['counts'] = ['create_internal' => 0, 'update_internal' => 0, 'create_sell' => 0, 'update_sell' => 0, 'skip' => 0, 'issues' => 0, 'issues_known' => 0, 'repeat' => 0];
        $this->files[] = $file;
        return count($this->files) - 1;
    }

    public function count(int $fileIndex, string $key, int $by = 1): void
    {
        $this->files[$fileIndex]['counts'][$key] = ($this->files[$fileIndex]['counts'][$key] ?? 0) + $by;
        if (isset($this->totals[$key])) {
            $this->totals[$key] += $by;
        }
    }

    /** @param array<string,mixed> $raw */
    public function addIssue(string $type, int $fileIndex, int $row, string $message, array $raw = [], ?string $code = null): void
    {
        $this->issues[] = [
            'type' => $type,
            'file' => $fileIndex,
            'file_label' => $this->files[$fileIndex]['label'] ?? '',
            'file_name' => $this->files[$fileIndex]['name'] ?? '',
            'row' => $row,
            'message' => $message,
            'raw' => $raw,
            'code' => $code,
        ];
        $this->count($fileIndex, 'issues');
    }

    public function fileLabel(int $i): string
    {
        return (string) ($this->files[$i]['label'] ?? 'file ' . ($i + 1));
    }

    /** @return list<array<string,mixed>> */
    public function issuesOfType(string $type): array
    {
        return array_values(array_filter($this->issues, fn($i) => $i['type'] === $type));
    }

    /** Issues that are not already on the list from an earlier run. @return list<array<string,mixed>> */
    public function newIssues(): array
    {
        return array_values(array_filter($this->issues, fn($i) => empty($i['known'])));
    }

    public function knownIssueCount(): int
    {
        return count($this->issues) - count($this->newIssues());
    }

    public function hasUvvRows(): bool
    {
        return $this->lists['uvv_rows'] !== [];
    }

    // ------------------------------------------------------------------
    // Output
    // ------------------------------------------------------------------

    /**
     * JSON-able summary for chroma_imports.summary_json and the review
     * screen. Lists are truncated with a "more" count; actions are not
     * included (they are recomputed from the files when committing).
     * @return array<string,mixed>
     */
    public function summary(): array
    {
        $lists = [];
        foreach ($this->lists as $k => $list) {
            $items = is_array($list) ? $list : [];
            // Keyed lists (prefix code => info, "prefix|suffix" => count) keep their keys; the rest are plain lists.
            $keyed = in_array($k, ['unknown_prefixes', 'prefix_suffixes'], true);
            $lists[$k] = ['total' => count($items), 'items' => $keyed ? array_slice($items, 0, self::LIST_LIMIT, true) : array_slice(array_values($items), 0, self::LIST_LIMIT)];
        }
        $issuesByType = [];
        foreach ($this->newIssues() as $i) {
            $issuesByType[$i['type']] = ($issuesByType[$i['type']] ?? 0) + 1;
        }
        return [
            'options' => $this->options,
            'committed' => $this->committed,
            'files' => $this->files,
            'totals' => $this->totals,
            'issues_by_type' => $issuesByType,
            'issues' => array_slice(array_map(fn($i) => ['type' => $i['type'], 'file_label' => $i['file_label'], 'row' => $i['row'], 'message' => $i['message'], 'code' => $i['code'], 'known' => !empty($i['known'])], $this->issues), 0, self::LIST_LIMIT),
            'issues_total' => count($this->issues),
            'issues_known' => $this->knownIssueCount(),
            'lists' => $lists,
            'applied' => $this->applied,
        ];
    }

    /** Plain-text report for the CLI and the stored log. */
    public function toText(): string
    {
        $o = [];
        $o[] = $this->committed ? 'Legacy import — COMMITTED' : 'Legacy import — DRY RUN (nothing was written)';
        $o[] = str_repeat('=', 60);
        $o[] = 'Files:';
        foreach ($this->files as $i => $f) {
            $c = $f['counts'];
            $o[] = sprintf('  %d. %s — %s (sheet "%s", header on row %d, %s data rows)', $i + 1, $f['name'], $f['label'], $f['sheet'], $f['header_row'], number_format($f['row_count']));
            $o[] = sprintf('     E numbers: %s new, %s filled in · Sell numbers: %s new, %s filled in · %s skipped (empty) · %s repeated rows · %s issues',
                number_format($c['create_internal']), number_format($c['update_internal']), number_format($c['create_sell']), number_format($c['update_sell']),
                number_format($c['skip']), number_format($c['repeat'] ?? 0), number_format($c['issues']));
        }
        $t = $this->totals;
        $o[] = '';
        $o[] = sprintf('Totals: %s E numbers to create, %s to fill in; %s sell numbers to create, %s to fill in; %s rows skipped; %s issues%s; %s new customers; %s new (retired) prefixes.',
            number_format($t['create_internal']), number_format($t['update_internal']), number_format($t['create_sell']), number_format($t['update_sell']),
            number_format($t['skip']), number_format($t['issues']), ($t['issues_known'] ?? 0) ? ' (plus ' . number_format($t['issues_known']) . ' already on the issues list from an earlier run)' : '',
            number_format($t['new_customers']), number_format($t['new_prefixes']));
        if ($this->applied) {
            $a = $this->applied;
            $o[] = sprintf('Applied: %s E numbers created, %s filled in; %s sell numbers created, %s filled in; %s links added; %s issues written; %s customers created; %s prefixes created; %s refused by the code rules.',
                number_format($a['create_internal'] ?? 0), number_format($a['update_internal'] ?? 0), number_format($a['create_sell'] ?? 0), number_format($a['update_sell'] ?? 0),
                number_format($a['links'] ?? 0), number_format($a['issues'] ?? 0), number_format($a['customers'] ?? 0), number_format($a['prefixes'] ?? 0), number_format($a['refused'] ?? 0));
            foreach ($a['counters'] ?? [] as $line) {
                $o[] = '  counter: ' . $line;
            }
            if (isset($a['seconds'])) {
                $o[] = sprintf('  took %.1f seconds', $a['seconds']);
            }
        }

        $section = function (string $title, array $items, callable $fmt) use (&$o) {
            $o[] = '';
            $o[] = $title . ' (' . number_format(count($items)) . ')';
            if (!$items) {
                $o[] = '  none';
                return;
            }
            $n = 0;
            foreach ($items as $k => $it) {
                $o[] = '  ' . $fmt($it, $k);
                if (++$n >= 60) {
                    $o[] = '  … and ' . number_format(count($items) - 60) . ' more';
                    break;
                }
            }
        };
        $section('Unknown prefixes (will be created as RETIRED prefixes, visible in lookup but not assignable)', $this->lists['unknown_prefixes'],
            fn($p, $code) => sprintf('%s — %s rows, e.g. %s (%s row %d)', $code, number_format($p['count']), $p['sample'], $p['file_label'], $p['row']));
        $section('UVV rows that will be imported under UVVL' . ($this->options['map_uvv'] ? '' : ' — MAPPING SWITCHED OFF: they will be imported under a new retired prefix UVV'), $this->lists['uvv_rows'],
            fn($r) => sprintf('%s → %s (%s row %d%s)', $r['original'], $r['mapped'], $r['file_label'], $r['row'], $r['internal'] ? ', ' . $r['internal'] : ''));
        $section('Duplicate sell numbers (the first row, or Chroma\'s own row, is kept; the others become issues)', $this->lists['duplicates'],
            fn($d) => sprintf('%s — %s; %s row %d (%s) not linked', $d['code'], self::keptText($d), $d['file_label'], $d['row'], $d['internal'] ?: 'no E#'));
        $section('Codes that could not be read (sent to issues)', $this->lists['unparseable'],
            fn($u) => sprintf('"%s" (%s row %d%s)', $u['text'], $u['file_label'], $u['row'], $u['internal'] ? ', ' . $u['internal'] : ''));
        $section('Unknown suffixes (sent to issues)', $this->lists['unknown_suffixes'],
            fn($u) => sprintf('%s — suffix %s (%s row %d)', $u['text'], $u['suffix'], $u['file_label'], $u['row']));
        $section('Legacy non-E internal codes (kept as text on the sell number)', $this->lists['legacy_internal'],
            fn($l) => sprintf('%s on %s (%s row %d)', $l['legacy'], $l['code'] ?: 'no sell number', $l['file_label'], $l['row']));
        $section('Dates that could not be read (left blank)', $this->lists['bad_dates'],
            fn($d) => sprintf('"%s" (%s row %d)', $d['text'], $d['file_label'], $d['row']));
        $section('Suffixes found per prefix (will be allowed for that prefix)', $this->lists['prefix_suffixes'],
            fn($n, $pair) => sprintf('%s — %s rows', str_replace('|', ' + ', (string) $pair), number_format($n)));
        $section('New customers', $this->lists['new_customers'], fn($c) => (string) $c);
        $section('Issues', $this->issues, fn($i) => sprintf('[%s]%s %s row %d: %s', $i['type'], empty($i['known']) ? '' : ' (already on the issues list)', $i['file_label'], $i['row'], $i['message']));
        if ($this->log) {
            $o[] = '';
            $o[] = 'Log:';
            foreach ($this->log as $line) {
                $o[] = '  ' . $line;
            }
        }
        return implode("\n", $o) . "\n";
    }

    /** "kept row 11 (E7100)" / "kept Code Cross Reference row 28 (E9000)" / "already in Chroma as E7200". @param array<string,mixed> $d a duplicates entry */
    public static function keptText(array $d): string
    {
        $internal = (string) ($d['kept_internal'] ?? '');
        if (($d['kept_row'] ?? null) === null) {
            return 'already in Chroma' . ($internal !== '' ? ' linked to ' . $internal : '');
        }
        $where = (string) ($d['kept_where'] ?? '');
        $sameSheet = $where === '' || $where === (string) ($d['file_label'] ?? '');
        return sprintf('kept %srow %d (%s)', $sameSheet ? '' : $where . ' ', (int) $d['kept_row'], $internal ?: 'no E#');
    }

    /** Plain-language names for issue types, shared by the report and the issues screen. @return array<string,string> */
    public static function issueTypes(): array
    {
        return [
            'duplicate' => 'Sell number already taken',
            'unparseable' => 'Code could not be read',
            'unknown_suffix' => 'Unknown suffix',
            'no_code' => 'Description with no code',
            'refused' => 'Refused by the code rules',
        ];
    }

    public static function issueTypeLabel(string $type): string
    {
        return self::issueTypes()[$type] ?? ucfirst(str_replace('_', ' ', $type));
    }
}
