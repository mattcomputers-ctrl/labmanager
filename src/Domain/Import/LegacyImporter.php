<?php
declare(strict_types=1);

namespace Chroma\Domain\Import;

use Chroma\Core\Db;
use Chroma\Core\ValidationException;
use Chroma\Db\Seeder;
use Chroma\Domain\Audit\AuditLog;
use Chroma\Domain\Codes\CodeAssigner;
use Chroma\Domain\Codes\CodeFormat;
use Chroma\Domain\Customers\Customers;

/**
 * Imports the three legacy spreadsheets (SPEC §2.3).
 *
 *   plan()    reads the files and works out what would happen. Pure: no writes.
 *   dryRun()  plan() + a chroma_imports record of the dry run.
 *   commit()  plan() + apply(): creates and fills rows, then records the run.
 *
 * Rules that hold:
 *   - Matching is on the normalized code (E number + version, or normalized
 *     sell number). Existing rows only get empty fields filled; nothing is
 *     overwritten and nothing is deleted. Running the same files twice does
 *     nothing the second time.
 *   - Codes are created only through CodeAssigner::manualInternal /
 *     manualSell (source 'import'), which handle uniqueness, counters and
 *     the audit log. Fill-in updates of existing rows are audited here.
 *   - Duplicate sell numbers: the first row wins (or Chroma's existing row),
 *     and any later row that names a different E number — in the same sheet,
 *     in another sheet, or against what Chroma already holds — becomes a
 *     chroma_import_issues row. Unparseable codes, unknown suffixes and
 *     descriptions with no code also become issues. Issues already on the
 *     list from an earlier run are reported as such and not written twice.
 *   - Only one commit runs at a time (advisory lock), and a recorded dry run
 *     can be committed once.
 *   - Unknown prefixes that parse are created as RETIRED prefixes.
 *   - UVV rows are imported under UVVL (original text kept in legacy_text)
 *     unless the option map_uvv is false.
 *   - Legacy non-E internal codes (2UY0480) are stored as text on the sell
 *     number, never as E numbers.
 *   - After a commit every prefix counter and the E sequence move to
 *     max-in-use + 1 unless an admin already set something higher.
 */
final class LegacyImporter
{
    public const SOURCE = 'import';
    public const NO_DESCRIPTION = 'No description in legacy sheet';
    private const KIND_ORDER = [SpreadsheetReader::KIND_CROSSREF => 0, SpreadsheetReader::KIND_XLOG => 1, SpreadsheetReader::KIND_UVLOG => 2, SpreadsheetReader::KIND_CODELOG => 3];

    private CodeAssigner $codes;
    private Customers $customers;
    private SpreadsheetReader $reader;

    /** @var array<string,array<string,mixed>> prefix code => row */
    private array $prefixes = [];
    /** @var array<string,array<string,mixed>> suffix code => row */
    private array $suffixes = [];
    /** @var array<string,bool> "prefixCode|suffixCode" => allowed */
    private array $prefixSuffix = [];
    /** @var array<string,array<string,mixed>> "number|version" => row */
    private array $dbInternal = [];
    /** @var array<string,array<string,mixed>> normalized => row */
    private array $dbSell = [];
    /** @var array<string,int> normalized customer name => id */
    private array $customerIds = [];
    /** @var array<int,string> internal code id => "number|version" */
    private array $internalKeyById = [];
    /** @var array<string,bool> "type|fingerprint" of every issue already recorded (open or resolved) */
    private array $knownIssues = [];

    /** MariaDB advisory lock name: one legacy import at a time, whichever door it comes in by. */
    private const LOCK_NAME = 'chroma_legacy_import';

    public function __construct(
        private readonly Db $db,
        private readonly AuditLog $audit,
        ?CodeAssigner $codes = null,
        ?Customers $customers = null,
        ?SpreadsheetReader $reader = null,
    ) {
        $this->codes = $codes ?? new CodeAssigner($db, $audit);
        $this->customers = $customers ?? new Customers($db);
        $this->reader = $reader ?? new SpreadsheetReader();
    }

    // ------------------------------------------------------------------
    // Entry points
    // ------------------------------------------------------------------

    /**
     * @param list<array{path:string,name?:string}> $files
     * @param array{map_uvv?:bool} $options
     */
    public function dryRun(array $files, array $options = [], ?int $actorId = null, bool $record = true): ImportPlan
    {
        $plan = $this->plan($files, $options);
        if ($record) {
            $now = $this->db->now();
            $plan->importId = $this->db->insert('chroma_imports', [
                'started_by' => $actorId,
                'started_at' => $now,
                'finished_at' => $now,
                'dry_run' => 1,
                'status' => 'dry_run',
                'files_json' => json_encode($this->filesMeta($files, $plan), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'summary_json' => json_encode($plan->summary(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'log_text' => $plan->toText(),
            ]);
        }
        return $plan;
    }

    /**
     * @param list<array{path:string,name?:string}> $files
     * @param array{map_uvv?:bool} $options
     */
    public function commit(array $files, array $options = [], ?int $actorId = null, ?int $dryRunId = null): ImportPlan
    {
        // Only one legacy import at a time, whether it comes from the admin
        // screen or the CLI: a second one would block on CodeAssigner's locks
        // and then fail on a duplicate code.
        if ((int) $this->db->value('SELECT GET_LOCK(?, 0)', [self::LOCK_NAME]) !== 1) {
            throw ValidationException::general('Another legacy import is running right now. Wait for it to finish (it usually takes under a minute), then check Past imports before running it again.');
        }
        try {
            if ($dryRunId !== null) {
                $this->claimDryRun($dryRunId);
            }
            try {
                $plan = $this->plan($files, $options);
                $now = $this->db->now();
                $importId = $this->db->insert('chroma_imports', [
                    'started_by' => $actorId,
                    'started_at' => $now,
                    'dry_run' => 0,
                    'status' => 'running',
                    'files_json' => json_encode($this->filesMeta($files, $plan), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
                $plan->importId = $importId;
                try {
                    $this->apply($plan, $actorId, $importId);
                } catch (\Throwable $e) {
                    $e = $this->plainFailure($e);
                    $plan->log[] = 'FAILED: ' . $e->getMessage();
                    $this->db->update('chroma_imports', ['finished_at' => $this->db->now(), 'status' => 'failed', 'log_text' => $plan->toText()], ['id' => $importId]);
                    throw $e;
                }
            } catch (\Throwable $e) {
                if ($dryRunId !== null) {
                    // The dry run is still good: let the admin try the commit again.
                    $this->db->update('chroma_imports', ['status' => 'dry_run'], ['id' => $dryRunId]);
                }
                throw $e;
            }
            $plan->committed = true;
            $this->db->update('chroma_imports', [
                'finished_at' => $this->db->now(),
                'status' => 'done',
                'summary_json' => json_encode($plan->summary(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'log_text' => $plan->toText(),
            ], ['id' => $importId]);
            if ($dryRunId !== null) {
                $this->db->update('chroma_imports', ['status' => 'committed'], ['id' => $dryRunId]);
            }
            $this->audit->record('import.commit', 'chroma_imports', $importId, null, $plan->applied, sprintf(
                'Legacy import: %d E numbers and %d sell numbers created, %d and %d filled in, %d issues',
                $plan->applied['create_internal'], $plan->applied['create_sell'], $plan->applied['update_internal'], $plan->applied['update_sell'], $plan->applied['issues']
            ), $actorId);
            return $plan;
        } finally {
            $this->db->value('SELECT RELEASE_LOCK(?)', [self::LOCK_NAME]);
        }
    }

    /**
     * Mark a recorded dry run as "committing" so a second tab, a double
     * submit or an F5 cannot commit it a second time. Row-locked, so two
     * requests arriving together cannot both get through.
     */
    private function claimDryRun(int $dryRunId): void
    {
        $this->db->transaction(function (Db $db) use ($dryRunId) {
            $run = $db->one('SELECT id, status, dry_run FROM chroma_imports WHERE id = ? FOR UPDATE', [$dryRunId]);
            if ($run === null || (int) $run['dry_run'] !== 1) {
                throw ValidationException::general('That dry run does not exist any more. Upload the files again to get a fresh one.');
            }
            if ($run['status'] === 'committing') {
                throw ValidationException::general('This dry run is being committed right now, probably from another tab or a second click. Wait a minute, then check Past imports; do not commit it again.');
            }
            if ($run['status'] !== 'dry_run') {
                throw ValidationException::general('That dry run was already committed or replaced. Start again from the upload step if you need to import more.');
            }
            $db->update('chroma_imports', ['status' => 'committing'], ['id' => $dryRunId]);
        });
    }

    /** Turn the one database error a double import can produce into plain language; leave everything else as it is. */
    private function plainFailure(\Throwable $e): \Throwable
    {
        if ($e instanceof \PDOException && (str_contains($e->getMessage(), '1062') || str_contains($e->getMessage(), 'Duplicate entry'))) {
            return ValidationException::general('A code this run was about to create appeared in Chroma while it was running, most likely because a second import of the same files finished at the same time. Nothing from this run was kept. Check Past imports, then upload the files again for a fresh dry run.');
        }
        return $e;
    }

    // ------------------------------------------------------------------
    // Planning (no writes)
    // ------------------------------------------------------------------

    /**
     * @param list<array{path:string,name?:string}> $files
     * @param array{map_uvv?:bool} $options
     */
    public function plan(array $files, array $options = []): ImportPlan
    {
        if ($files === []) {
            throw ValidationException::single('files', 'Choose at least one spreadsheet to import.');
        }
        if (count($files) > 3) {
            throw ValidationException::single('files', 'Upload at most three files at a time: the cross-reference, the security log and the UV log.');
        }
        $plan = new ImportPlan();
        $plan->options = ['map_uvv' => (bool) ($options['map_uvv'] ?? true)];

        $read = [];
        foreach ($files as $i => $f) {
            $name = (string) ($f['name'] ?? basename((string) $f['path']));
            $read[] = ['order' => $i, 'name' => $name, 'path' => (string) $f['path'], 'data' => $this->reader->read((string) $f['path'], $name)];
        }
        // The cross-reference is the primary source: process it first so the
        // code logs fill in rather than define.
        usort($read, fn($a, $b) => [self::KIND_ORDER[$a['data']['kind']] ?? 9, $a['order']] <=> [self::KIND_ORDER[$b['data']['kind']] ?? 9, $b['order']]);
        $kinds = [];
        foreach ($read as $r) {
            $k = $r['data']['kind'];
            if (isset($kinds[$k]) && $k !== SpreadsheetReader::KIND_CODELOG) {
                throw ValidationException::single('files', sprintf('"%s" and "%s" are both the %s. Upload each sheet once.', $kinds[$k], $r['name'], SpreadsheetReader::labelFor($k)));
            }
            $kinds[$k] = $r['name'];
        }

        $this->preload();
        $internalIdx = [];
        $sellIdx = [];
        $seenCustomers = [];
        foreach ($read as $r) {
            $d = $r['data'];
            $fi = $plan->addFile([
                'name' => $r['name'], 'path' => $r['path'], 'kind' => $d['kind'], 'label' => $d['label'], 'sheet' => $d['sheet'],
                'header_row' => $d['header_row'], 'row_count' => $d['row_count'],
                'columns' => array_keys($d['columns']),
            ]);
            foreach ($d['rows'] as $row) {
                $this->planRow($plan, $fi, $row, $internalIdx, $sellIdx, $seenCustomers);
            }
        }
        $plan->totals['new_customers'] = count($plan->lists['new_customers']);
        $plan->totals['new_prefixes'] = count($plan->lists['unknown_prefixes']);
        // Issues already on the list from an earlier run (open or resolved) are
        // not written again, so they are reported separately and not counted
        // as "to expect".
        foreach ($plan->issues as $i => $issue) {
            if (isset($this->knownIssues[$issue['type'] . '|' . self::fingerprint($issue)])) {
                $plan->issues[$i]['known'] = true;
                $plan->count((int) $issue['file'], 'issues', -1);
                $plan->count((int) $issue['file'], 'issues_known', 1);
            }
        }
        return $plan;
    }

    private function preload(): void
    {
        $this->prefixes = [];
        foreach ($this->db->all('SELECT * FROM chroma_code_prefixes') as $p) {
            $this->prefixes[strtoupper((string) $p['code'])] = $p;
        }
        $this->suffixes = [];
        foreach ($this->db->all('SELECT * FROM chroma_code_suffixes') as $s) {
            $this->suffixes[strtoupper((string) $s['code'])] = $s;
        }
        $this->prefixSuffix = [];
        foreach ($this->db->all('SELECT p.code AS p, s.code AS s FROM chroma_code_prefix_suffixes ps JOIN chroma_code_prefixes p ON p.id = ps.prefix_id JOIN chroma_code_suffixes s ON s.id = ps.suffix_id') as $r) {
            $this->prefixSuffix[$r['p'] . '|' . $r['s']] = true;
        }
        $this->dbInternal = [];
        $this->internalKeyById = [];
        foreach ($this->db->all('SELECT id, number, version, description, resale, vendor_name, vendor_code, legacy_handschy_number, legacy_internal_code, date_assigned, original_customer_id, notes FROM chroma_internal_codes') as $r) {
            $this->dbInternal[$r['number'] . '|' . $r['version']] = $r;
            $this->internalKeyById[(int) $r['id']] = $r['number'] . '|' . $r['version'];
        }
        $this->knownIssues = [];
        foreach ($this->db->all("SELECT issue_type, JSON_VALUE(raw_json, '$.fingerprint') AS fp FROM chroma_import_issues") as $r) {
            if ($r['fp'] !== null && $r['fp'] !== '') {
                $this->knownIssues[$r['issue_type'] . '|' . $r['fp']] = true;
            }
        }
        $this->dbSell = [];
        foreach ($this->db->all('SELECT id, sell_number_normalized, description, legacy_text, legacy_internal_code, original_customer_id, date_assigned, notes, internal_code_id FROM chroma_sell_numbers') as $r) {
            $this->dbSell[$r['sell_number_normalized']] = $r;
        }
        $this->customerIds = [];
        foreach ($this->db->all('SELECT id, name_normalized FROM chroma_customers ORDER BY active DESC, id') as $c) {
            $this->customerIds[$c['name_normalized']] ??= (int) $c['id'];
        }
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,int> $internalIdx
     * @param array<string,int> $sellIdx
     * @param array<string,bool> $seenCustomers
     */
    private function planRow(ImportPlan $plan, int $fi, array $row, array &$internalIdx, array &$sellIdx, array &$seenCustomers): void
    {
        $plan->totals['rows']++;
        $rowNo = (int) $row['row'];
        $label = $plan->fileLabel($fi);
        $internalText = (string) $row['internal_code'];
        $sellText = (string) $row['sell_number'];
        $desc = (string) $row['description'];
        $comments = (string) $row['comments'];
        $customer = (string) $row['customer'];

        // --- Internal code column: an E number, or a legacy non-E code kept as text.
        $internalKey = null;
        $internalDisplay = '';
        $legacyInternal = null;
        if ($internalText !== '') {
            $parsed = null;
            if (CodeFormat::looksInternal($internalText) || preg_match('/^\d+(\s*-\s*[A-Za-z0-9]+)?$/', $internalText)) {
                $parsed = CodeFormat::parseInternal($internalText);
            }
            if ($parsed !== null) {
                $internalKey = $parsed['number'] . '|' . $parsed['version'];
                $internalDisplay = CodeFormat::internal($parsed['number'], $parsed['version']);
            } else {
                $legacyInternal = mb_substr($internalText, 0, 100);
            }
        }

        // --- Sell number column.
        $sell = null;
        $sellOriginal = $sellText;
        $sellUnreadable = false;
        if ($sellText !== '') {
            $p = CodeFormat::parseSell($sellText);
            if ($p === null) {
                $sellUnreadable = true;
            } else {
                $fromUvv = false;
                if ($p['prefix'] === 'UVV' && $plan->options['map_uvv']) {
                    $p['prefix'] = 'UVVL';
                    $p['normalized'] = 'UVVL' . $p['number'] . $p['suffix'];
                    $fromUvv = true;
                }
                if ($p['suffix'] !== '' && !isset($this->suffixes[$p['suffix']])) {
                    $plan->lists['unknown_suffixes'][] = ['text' => $sellText, 'suffix' => $p['suffix'], 'file_label' => $label, 'row' => $rowNo];
                    $plan->addIssue('unknown_suffix', $fi, $rowNo, sprintf(
                        '%s ends in "%s", which is not a known suffix (known suffixes: %s). Add it under Admin → Suffixes and run the import again, enter a corrected code, or discard this row.',
                        $sellText, $p['suffix'], implode(', ', array_keys($this->suffixes)) ?: 'none'
                    ), $this->rawFor($row, $internalDisplay), $sellText);
                    $p = null;
                } else {
                    if (!isset($this->prefixes[$p['prefix']])) {
                        $u = $plan->lists['unknown_prefixes'][$p['prefix']] ?? ['count' => 0, 'sample' => $sellText, 'digits' => 0, 'file_label' => $label, 'row' => $rowNo];
                        $u['count']++;
                        $u['digits'] = max($u['digits'], strlen((string) $p['number']));
                        $plan->lists['unknown_prefixes'][$p['prefix']] = $u;
                    }
                    if ($fromUvv) {
                        $plan->lists['uvv_rows'][] = ['original' => $sellText, 'mapped' => $p['normalized'], 'file_label' => $label, 'row' => $rowNo, 'internal' => $internalDisplay];
                    }
                    $sell = $p;
                }
            }
        }

        if ($row['date_raw'] !== '' && $row['date'] === null) {
            $plan->lists['bad_dates'][] = ['text' => $row['date_raw'], 'file_label' => $label, 'row' => $rowNo];
        }

        $hasContent = $desc !== '' || $comments !== '' || $customer !== '' || (string) $row['vendor_name'] !== '' || (string) $row['handschy'] !== '';

        // Pre-filled rows (an E# with nothing else, or a blank X/UV code) and genuinely empty lines: skip quietly.
        if (!$hasContent && ($sellText === '' || $internalText === '')) {
            $plan->count($fi, 'skip');
            return;
        }
        if ($sellUnreadable) {
            $plan->lists['unparseable'][] = ['text' => $sellText, 'file_label' => $label, 'row' => $rowNo, 'internal' => $internalDisplay];
            $plan->addIssue('unparseable', $fi, $rowNo, sprintf(
                '"%s" is not a code Chroma can read (a code is a prefix, a number and an optional suffix, like Y1714UV or X104043)%s. Enter the corrected code, or discard this row.',
                $sellText, $desc !== '' ? ' — the row says "' . mb_substr($desc, 0, 80) . '"' : ''
            ), $this->rawFor($row, $internalDisplay), $sellText);
        }
        if ($sell === null && $internalKey === null) {
            if (!$sellUnreadable && $sellText === '') {
                $what = $legacyInternal !== null
                    ? sprintf('This row has the legacy internal code %s but no sell number, so there is nothing to attach it to', $legacyInternal)
                    : sprintf('This row has a description ("%s") but no E number and no sell number, so there is nothing to attach it to', mb_substr($desc, 0, 80));
                $plan->addIssue('no_code', $fi, $rowNo, $what . '. Enter the right code, or discard this row.', $this->rawFor($row, $internalDisplay), null);
            }
            return;
        }

        if ($customer !== '') {
            $norm = Seeder::normalizeName($customer);
            if ($norm !== '' && !isset($this->customerIds[$norm]) && !isset($seenCustomers[$norm])) {
                $seenCustomers[$norm] = true;
                $plan->lists['new_customers'][] = $customer;
            }
        }
        if ($legacyInternal !== null) {
            $plan->lists['legacy_internal'][] = ['legacy' => $legacyInternal, 'code' => $sell['normalized'] ?? '', 'file_label' => $label, 'row' => $rowNo];
        }

        // --- E number: create, or fill empty fields of an existing one.
        if ($internalKey !== null) {
            [$number, $version] = explode('|', $internalKey, 2);
            $data = [
                'description' => $desc !== '' ? $desc : ($sell ? $sell['normalized'] . ' — ' . self::NO_DESCRIPTION : self::NO_DESCRIPTION),
                'resale' => self::yes($row['resale']),
                'vendor_name' => self::nz($row['vendor_name']),
                'vendor_code' => self::nz($row['vendor_code']),
                'legacy_handschy_number' => self::nz($row['handschy']),
                'date_assigned' => $row['date'],
                'customer' => self::nz($customer),
                'notes' => $sell === null ? self::nz($comments) : null,
            ];
            if (isset($this->dbInternal[$internalKey])) {
                $fill = $this->fillFor($this->dbInternal[$internalKey], $data, self::INTERNAL_FIELDS);
                if ($fill !== []) {
                    $k = 'ui:' . $internalKey;
                    if (isset($internalIdx[$k])) {
                        $plan->actions[$internalIdx[$k]]['fill'] += $fill; // first value wins
                    } else {
                        $internalIdx[$k] = count($plan->actions);
                        $plan->actions[] = ['type' => 'update_internal', 'key' => $internalKey, 'code' => $internalDisplay, 'id' => (int) $this->dbInternal[$internalKey]['id'], 'fill' => $fill, 'file' => $fi, 'row' => $rowNo];
                        $plan->count($fi, 'update_internal');
                    }
                }
            } elseif (isset($internalIdx[$internalKey])) {
                $prev = &$plan->actions[$internalIdx[$internalKey]];
                foreach ($data as $f => $v) {
                    if (self::empty($prev['data'][$f] ?? null) && !self::empty($v) && !($f === 'description' && $v === self::NO_DESCRIPTION)) {
                        $prev['data'][$f] = $v;
                    }
                }
                if (str_ends_with((string) $prev['data']['description'], self::NO_DESCRIPTION) && $desc !== '') {
                    $prev['data']['description'] = $desc;
                }
                unset($prev);
            } else {
                $internalIdx[$internalKey] = count($plan->actions);
                $plan->actions[] = ['type' => 'create_internal', 'key' => $internalKey, 'code' => $internalDisplay, 'number' => (int) $number, 'version' => $version, 'data' => $data, 'file' => $fi, 'row' => $rowNo];
                $plan->count($fi, 'create_internal');
            }
        }

        // --- Sell number: create, fill, merge across files, or flag a duplicate.
        if ($sell !== null) {
            $norm = $sell['normalized'];
            $data = [
                'description' => $desc !== '' ? $desc : ($internalDisplay !== '' ? $internalDisplay . ' — ' . self::NO_DESCRIPTION : self::NO_DESCRIPTION),
                'legacy_text' => strtoupper(trim($sellOriginal)) !== $norm ? mb_substr($sellOriginal, 0, 60) : null,
                'internal_key' => $internalKey,
                'legacy_internal_code' => $legacyInternal,
                'customer' => self::nz($customer),
                'date_assigned' => $row['date'],
                'notes' => self::nz($comments),
            ];
            $dbRow = $this->dbSell[$norm] ?? null;
            $dbKey = $dbRow && !empty($dbRow['internal_code_id']) ? ($this->internalKeyById[(int) $dbRow['internal_code_id']] ?? null) : null;
            if ($dbRow !== null && $internalKey !== null && $dbKey !== null && $dbKey !== $internalKey) {
                // The sheet says this sell number belongs to a different E number than Chroma does.
                // Chroma's row is kept untouched; the disagreement goes to issues so nobody loses the link silently.
                $this->duplicate($plan, $fi, $rowNo, $label, $row, $norm, $internalDisplay, $desc, [
                    'where' => 'Chroma', 'row' => null, 'internal' => $this->displayKey($dbKey), 'description' => (string) $dbRow['description'],
                ]);
            } elseif ($dbRow !== null) {
                $fill = $this->fillFor($dbRow, $data, self::SELL_FIELDS);
                if ($fill !== []) {
                    $k = 'us:' . $norm;
                    if (isset($sellIdx[$k])) {
                        $plan->actions[$sellIdx[$k]]['fill'] += $fill;
                    } else {
                        $sellIdx[$k] = count($plan->actions);
                        $plan->actions[] = ['type' => 'update_sell', 'code' => $norm, 'id' => (int) $this->dbSell[$norm]['id'], 'fill' => $fill, 'file' => $fi, 'row' => $rowNo];
                        $plan->count($fi, 'update_sell');
                    }
                } else {
                    $plan->count($fi, 'repeat');
                }
            } elseif (isset($sellIdx[$norm])) {
                $prev = &$plan->actions[$sellIdx[$norm]];
                $prevKey = $prev['data']['internal_key'] ?? null;
                $conflict = $internalKey !== null && $prevKey !== null && $prevKey !== $internalKey;
                $prevDesc = (string) $prev['data']['description'];
                $sameDesc = $desc === '' || str_ends_with($prevDesc, self::NO_DESCRIPTION) || mb_strtolower($prevDesc) === mb_strtolower($desc);
                if (!$conflict && ($prev['file'] !== $fi || $sameDesc)) {
                    // Same code in another sheet (X104043 in the cross-reference and the X log), or a harmless repeat: fill in the blanks.
                    foreach ($data as $f => $v) {
                        if (self::empty($prev['data'][$f] ?? null) && !self::empty($v)) {
                            $prev['data'][$f] = $v;
                        }
                    }
                    if (str_ends_with($prevDesc, self::NO_DESCRIPTION) && $desc !== '') {
                        $prev['data']['description'] = $desc;
                    }
                    if ($comments !== '' && !self::empty($prev['data']['notes']) && !str_contains((string) $prev['data']['notes'], $comments)) {
                        $prev['data']['notes'] .= "\n" . $comments;
                    }
                    $plan->count($fi, 'repeat');
                } else {
                    // Same sell number again with a different E number (or, in the same sheet, a different description): a typo or a relabel.
                    $this->duplicate($plan, $fi, $rowNo, $label, $row, $norm, $internalDisplay, $desc, [
                        'where' => $plan->fileLabel((int) $prev['file']), 'row' => (int) $prev['row'], 'internal' => $prevKey ? $this->displayKey($prevKey) : '', 'description' => $prevDesc,
                    ]);
                }
                unset($prev);
            } else {
                $sellIdx[$norm] = count($plan->actions);
                $plan->actions[] = ['type' => 'create_sell', 'code' => $norm, 'prefix' => $sell['prefix'], 'number' => $sell['number'], 'suffix' => $sell['suffix'], 'data' => $data, 'file' => $fi, 'row' => $rowNo];
                $plan->count($fi, 'create_sell');
                if ($sell['suffix'] !== '') {
                    $pair = $sell['prefix'] . '|' . $sell['suffix'];
                    $plan->lists['prefix_suffixes'][$pair] = ($plan->lists['prefix_suffixes'][$pair] ?? 0) + 1;
                }
            }
        }
    }

    /**
     * A sell number that is already taken, by an earlier row (same sheet or
     * another sheet) or by Chroma itself, where this row disagrees about the
     * E number or the description. The kept side stays as it is; this row is
     * listed in the report and becomes an issue to correct, merge or discard.
     * @param array<string,mixed> $row the sheet row
     * @param array{where:string,row:int|null,internal:string,description:string} $kept
     */
    private function duplicate(ImportPlan $plan, int $fi, int $rowNo, string $label, array $row, string $norm, string $internalDisplay, string $desc, array $kept): void
    {
        $inChroma = $kept['row'] === null;
        $plan->lists['duplicates'][] = [
            'code' => $norm, 'kept_where' => $kept['where'], 'kept_row' => $kept['row'], 'kept_internal' => $kept['internal'],
            'row' => $rowNo, 'internal' => $internalDisplay, 'description' => $desc, 'file_label' => $label,
        ];
        $thisSide = sprintf('this row (%s, "%s")', $internalDisplay ?: 'no E#', mb_substr($desc, 0, 60));
        $advice = 'If this one is a typo, enter the corrected code; if it is a genuine relabel or a second product, merge it into the kept code or enter a new code for it.';
        if ($inChroma) {
            $message = sprintf('%s is already in Chroma, linked to %s ("%s"), but %s names a different E number. Chroma\'s row was kept as it is and this row was not merged into it. %s',
                $norm, $kept['internal'], mb_substr($kept['description'], 0, 60), $thisSide, $advice);
        } elseif ($kept['where'] !== $label) {
            $message = sprintf('%s is in both the %s (row %d, %s, "%s") and the %s with a different E number. The %s row was kept; %s was not linked to it. %s',
                $norm, $kept['where'], $kept['row'], $kept['internal'] ?: 'no E#', mb_substr($kept['description'], 0, 60), $label, $kept['where'], $thisSide, $advice);
        } else {
            $message = sprintf('%s is listed more than once in the %s. Row %d (%s, "%s") was kept; %s was not linked to it. %s',
                $norm, $label, $kept['row'], $kept['internal'] ?: 'no E#', mb_substr($kept['description'], 0, 60), $thisSide, $advice);
        }
        $plan->addIssue('duplicate', $fi, $rowNo, $message, $this->rawFor($row, $internalDisplay) + [
            'kept_row' => $kept['row'], 'kept_where' => $kept['where'], 'kept_code' => $norm, 'kept_internal' => $kept['internal'],
        ], $norm);
    }

    /** import field => column on chroma_internal_codes */
    private const INTERNAL_FIELDS = [
        'description' => 'description', 'resale' => 'resale', 'vendor_name' => 'vendor_name', 'vendor_code' => 'vendor_code',
        'legacy_handschy_number' => 'legacy_handschy_number', 'date_assigned' => 'date_assigned', 'customer' => 'original_customer_id', 'notes' => 'notes',
    ];
    /** import field => column on chroma_sell_numbers */
    private const SELL_FIELDS = [
        'description' => 'description', 'legacy_text' => 'legacy_text', 'internal_key' => 'internal_code_id', 'legacy_internal_code' => 'legacy_internal_code',
        'customer' => 'original_customer_id', 'date_assigned' => 'date_assigned', 'notes' => 'notes',
    ];

    /**
     * Which import values would fill an empty field of an existing row.
     * @param array<string,mixed> $dbRow @param array<string,mixed> $data @param array<string,string> $map
     * @return array<string,mixed> import field => value
     */
    private function fillFor(array $dbRow, array $data, array $map): array
    {
        $fill = [];
        foreach ($map as $field => $column) {
            $v = $data[$field] ?? null;
            if (self::empty($v) || ($field === 'description' && str_ends_with((string) $v, self::NO_DESCRIPTION))) {
                continue;
            }
            $current = $dbRow[$column] ?? null;
            $isEmpty = self::empty($current)
                || ($column === 'description' && str_ends_with((string) $current, self::NO_DESCRIPTION))
                || ($column === 'date_assigned' && str_starts_with((string) $current, '0000'));
            if ($isEmpty) {
                $fill[$field] = $v;
            }
        }
        return $fill;
    }

    // ------------------------------------------------------------------
    // Applying (commit)
    // ------------------------------------------------------------------

    private function apply(ImportPlan $plan, ?int $actorId, int $importId): void
    {
        $t0 = microtime(true);
        $applied = ['create_internal' => 0, 'update_internal' => 0, 'create_sell' => 0, 'update_sell' => 0, 'links' => 0, 'issues' => 0, 'customers' => 0, 'prefixes' => 0, 'refused' => 0, 'counters' => []];
        $this->db->transaction(function (Db $db) use ($plan, $actorId, $importId, &$applied) {
            $now = $db->now();
            // Unknown prefixes become retired prefixes (visible in lookup, not assignable).
            foreach ($plan->lists['unknown_prefixes'] as $code => $info) {
                $code = (string) $code;
                if (isset($this->prefixes[$code])) {
                    continue;
                }
                $id = $db->insert('chroma_code_prefixes', [
                    'code' => $code, 'label' => $code . ' (retired)', 'description' => 'Historical prefix found by the legacy import. Not assignable.',
                    'next_number' => 1, 'min_digits' => max(1, min(6, (int) $info['digits'])), 'retired' => 1, 'retired_at' => $now, 'retired_by' => $actorId,
                    'sort_order' => 950, 'tag' => 'retired', 'created_at' => $now, 'updated_at' => $now,
                ]);
                $this->prefixes[$code] = $db->one('SELECT * FROM chroma_code_prefixes WHERE id = ?', [$id]) ?? [];
                $this->audit->record('prefix.create', 'chroma_code_prefixes', $id, null, $this->prefixes[$code], "Retired prefix $code created by the legacy import", $actorId);
                $applied['prefixes']++;
            }
            // Suffixes actually found per prefix become allowed for that prefix.
            foreach (array_keys($plan->lists['prefix_suffixes']) as $pair) {
                [$pc, $sc] = explode('|', (string) $pair, 2);
                if (isset($this->prefixSuffix[$pair]) || !isset($this->prefixes[$pc], $this->suffixes[$sc])) {
                    continue;
                }
                $db->run('INSERT IGNORE INTO chroma_code_prefix_suffixes (prefix_id, suffix_id) VALUES (?, ?)', [(int) $this->prefixes[$pc]['id'], (int) $this->suffixes[$sc]['id']]);
                $this->prefixSuffix[$pair] = true;
                $this->audit->record('prefix.allow_suffix', 'chroma_code_prefixes', (int) $this->prefixes[$pc]['id'], null, ['suffix' => $sc], "Suffix $sc allowed for $pc (found in the legacy import)", $actorId);
            }

            $createdInternal = [];
            $customerCache = [];
            $customerId = function (?string $name) use (&$customerCache, &$applied): ?int {
                if ($name === null || $name === '') {
                    return null;
                }
                $norm = Seeder::normalizeName($name);
                if (isset($customerCache[$norm])) {
                    return $customerCache[$norm];
                }
                if (isset($this->customerIds[$norm])) {
                    return $customerCache[$norm] = $this->customerIds[$norm];
                }
                $id = $this->customers->findOrCreate($name, self::SOURCE);
                if ($id !== null) {
                    $applied['customers']++;
                    $customerCache[$norm] = $id;
                }
                return $id;
            };
            $internalId = function (?string $key) use (&$createdInternal): ?int {
                if ($key === null) {
                    return null;
                }
                return $createdInternal[$key] ?? (isset($this->dbInternal[$key]) ? (int) $this->dbInternal[$key]['id'] : null);
            };

            foreach ($plan->actions as $a) {
                switch ($a['type']) {
                    case 'create_internal':
                        $d = $a['data'];
                        $payload = [
                            'description' => $d['description'], 'resale' => $d['resale'], 'vendor_name' => $d['vendor_name'], 'vendor_code' => $d['vendor_code'],
                            'legacy_handschy_number' => $d['legacy_handschy_number'], 'original_customer_id' => $customerId($d['customer']), 'notes' => $d['notes'],
                            // Passed even when null: a row with no date in the sheet keeps no date (CodeAssigner only defaults to today when the key is absent), and the audit snapshot then matches the row.
                            'date_assigned' => $d['date_assigned'],
                        ];
                        try {
                            $row = $this->codes->manualInternal($a['code'], $payload, $actorId, self::SOURCE);
                        } catch (ValidationException $e) {
                            $this->refused($plan, $a, $e->getMessage());
                            $applied['refused']++;
                            break;
                        }
                        $createdInternal[$a['key']] = (int) $row['id'];
                        $applied['create_internal']++;
                        break;

                    case 'update_internal':
                        $set = [];
                        foreach ($a['fill'] as $f => $v) {
                            $set[self::INTERNAL_FIELDS[$f]] = $f === 'customer' ? $customerId($v) : ($f === 'resale' ? 1 : $v);
                        }
                        $set = array_filter($set, fn($v) => $v !== null);
                        if ($set !== []) {
                            $before = $db->one('SELECT * FROM chroma_internal_codes WHERE id = ?', [$a['id']]);
                            $db->update('chroma_internal_codes', $set + ['updated_at' => $now], ['id' => $a['id']]);
                            $this->audit->record('import.fill_internal', 'chroma_internal_codes', $a['id'], $before, $set, 'Empty fields filled in by the legacy import for ' . $a['code'], $actorId);
                            $applied['update_internal']++;
                        }
                        break;

                    case 'create_sell':
                        $d = $a['data'];
                        $payload = [
                            'description' => $d['description'], 'legacy_text' => $d['legacy_text'], 'internal_code_id' => $internalId($d['internal_key']),
                            'legacy_internal_code' => $d['legacy_internal_code'], 'original_customer_id' => $customerId($d['customer']), 'notes' => $d['notes'],
                            'date_assigned' => $d['date_assigned'],
                        ];
                        try {
                            $row = $this->codes->manualSell($a['code'], $payload, $actorId, self::SOURCE, true);
                        } catch (ValidationException $e) {
                            $this->refused($plan, $a, $e->getMessage());
                            $applied['refused']++;
                            break;
                        }
                        $applied['create_sell']++;
                        break;

                    case 'update_sell':
                        $set = [];
                        foreach ($a['fill'] as $f => $v) {
                            if ($f === 'internal_key') {
                                $iid = $internalId($v);
                                if ($iid !== null) {
                                    $this->codes->linkSellToInternal($a['id'], $iid, $actorId);
                                    $applied['links']++;
                                }
                                continue;
                            }
                            $set[self::SELL_FIELDS[$f]] = $f === 'customer' ? $customerId($v) : $v;
                        }
                        $set = array_filter($set, fn($v) => $v !== null);
                        if ($set !== []) {
                            $before = $db->one('SELECT * FROM chroma_sell_numbers WHERE id = ?', [$a['id']]);
                            $db->update('chroma_sell_numbers', $set + ['updated_at' => $now], ['id' => $a['id']]);
                            $this->audit->record('import.fill_sell', 'chroma_sell_numbers', $a['id'], $before, $set, 'Empty fields filled in by the legacy import for ' . $a['code'], $actorId);
                        }
                        $applied['update_sell']++;
                        break;
                }
            }

            $applied['counters'] = $this->moveCounters($db, $actorId);

            foreach ($plan->issues as $issue) {
                if ($this->writeIssue($db, $importId, $issue)) {
                    $applied['issues']++;
                }
            }
        });
        $applied['seconds'] = round(microtime(true) - $t0, 1);
        $plan->applied = $applied;
    }

    /** @param array<string,mixed> $action */
    private function refused(ImportPlan $plan, array $action, string $why): void
    {
        $plan->addIssue('refused', (int) $action['file'], (int) $action['row'], sprintf('%s could not be created: %s', $action['code'], $why), [
            'code' => $action['code'], 'description' => $action['data']['description'] ?? '', 'internal' => isset($action['data']['internal_key']) ? $this->displayKey($action['data']['internal_key']) : $action['code'],
            'comments' => $action['data']['notes'] ?? '', 'customer' => $action['data']['customer'] ?? '', 'date' => $action['data']['date_assigned'] ?? null,
        ], (string) $action['code']);
    }

    /**
     * Every prefix counter and the E sequence move to max-in-use + 1 unless
     * the admin already set something higher. Returns plain-language lines.
     * @return list<string>
     */
    private function moveCounters(Db $db, ?int $actorId): array
    {
        $lines = [];
        $max = [];
        foreach ($db->all('SELECT prefix_id, MAX(number) AS m FROM chroma_sell_numbers GROUP BY prefix_id') as $r) {
            $max[(int) $r['prefix_id']] = (int) $r['m'];
        }
        foreach ($db->all('SELECT * FROM chroma_code_prefixes') as $p) {
            $want = ($max[(int) $p['id']] ?? 0) + 1;
            if ($want > (int) $p['next_number']) {
                $db->update('chroma_code_prefixes', ['next_number' => $want, 'updated_at' => $db->now()], ['id' => (int) $p['id']]);
                $this->audit->record('prefix.set_next', 'chroma_code_prefixes', (int) $p['id'], ['next_number' => (int) $p['next_number']], ['next_number' => $want],
                    sprintf('Next %s number set to %d by the legacy import (was %d)', $p['code'], $want, (int) $p['next_number']), $actorId);
                $lines[] = sprintf('%s: next number %d (was %d)', $p['code'], $want, (int) $p['next_number']);
            }
        }
        $maxE = (int) ($db->value('SELECT MAX(number) FROM chroma_internal_codes') ?? 0);
        $seq = $db->one("SELECT next_value FROM chroma_sequences WHERE name = 'internal_code'");
        $current = (int) ($seq['next_value'] ?? 1);
        if ($maxE + 1 > $current) {
            $db->run("INSERT INTO chroma_sequences (name, next_value, updated_at) VALUES ('internal_code', ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE next_value = VALUES(next_value), updated_at = UTC_TIMESTAMP()", [$maxE + 1]);
            $this->audit->record('sequence.set_internal', 'chroma_sequences', null, ['next_value' => $current], ['next_value' => $maxE + 1], sprintf('Next E number set to E%d by the legacy import (was E%d)', $maxE + 1, $current), $actorId);
            $lines[] = sprintf('E numbers: next E%d (was E%d)', $maxE + 1, $current);
        }
        return $lines;
    }

    /** Write one issue unless an identical one (open or resolved) already exists, so re-runs do not pile up. @param array<string,mixed> $issue */
    private function writeIssue(Db $db, int $importId, array $issue): bool
    {
        if (!empty($issue['known'])) {
            return false;
        }
        $source = mb_substr((string) $issue['file_label'], 0, 100);
        $message = mb_substr((string) $issue['message'], 0, 500);
        // The same row in a later copy of the sheet may sit on a different row number, so the
        // fingerprint is built from the content, not the position.
        $fingerprint = self::fingerprint($issue);
        $exists = $db->value("SELECT id FROM chroma_import_issues WHERE issue_type = ? AND JSON_VALUE(raw_json, '$.fingerprint') = ? LIMIT 1", [$issue['type'], $fingerprint]);
        if ($exists !== null) {
            return false;
        }
        $db->insert('chroma_import_issues', [
            'import_id' => $importId,
            'source_file' => $source,
            'source_row' => $issue['row'],
            'issue_type' => $issue['type'],
            'message' => $message,
            'raw_json' => json_encode(['file_name' => $issue['file_name'], 'code' => $issue['code'], 'fingerprint' => $fingerprint] + $issue['raw'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'open',
            'created_at' => $db->now(),
        ]);
        return true;
    }

    // ------------------------------------------------------------------
    // Small helpers
    // ------------------------------------------------------------------

    /** Content-based identity of an issue, independent of row numbers. @param array<string,mixed> $issue */
    public static function fingerprint(array $issue): string
    {
        $raw = $issue['raw'] ?? [];
        $parts = [$issue['type'], $issue['file_label'] ?? '', $issue['code'] ?? ''];
        foreach (['sell', 'internal', 'description', 'comments', 'customer', 'kept_code'] as $k) {
            $parts[] = mb_strtolower(trim((string) ($raw[$k] ?? '')));
        }
        return sha1(implode('|', $parts));
    }

    /** @param list<array{path:string,name?:string}> $files @return list<array<string,mixed>> */
    private function filesMeta(array $files, ImportPlan $plan): array
    {
        $out = [];
        foreach ($plan->files as $f) {
            $out[] = ['name' => $f['name'], 'path' => $f['path'], 'kind' => $f['kind'], 'label' => $f['label'], 'rows' => $f['row_count'], 'size' => is_file($f['path']) ? filesize($f['path']) : null];
        }
        return $out;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function rawFor(array $row, string $internalDisplay): array
    {
        return [
            'row' => $row['row'], 'internal' => $internalDisplay !== '' ? $internalDisplay : (string) $row['internal_code'], 'sell' => (string) $row['sell_number'],
            'description' => (string) $row['description'], 'customer' => (string) $row['customer'], 'date' => $row['date'], 'date_raw' => $row['date_raw'],
            'resale' => (string) $row['resale'], 'vendor_name' => (string) $row['vendor_name'], 'vendor_code' => (string) $row['vendor_code'],
            'handschy' => (string) $row['handschy'], 'comments' => (string) $row['comments'],
        ];
    }

    private function displayKey(string $key): string
    {
        [$n, $v] = explode('|', $key, 2);
        return CodeFormat::internal((int) $n, $v);
    }

    public static function yes(mixed $v): bool
    {
        $s = strtolower(trim((string) $v));
        return in_array($s, ['y', 'yes', 'x', 'true', '1', 'resale', 'r'], true);
    }

    private static function nz(mixed $v): ?string
    {
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    private static function empty(mixed $v): bool
    {
        return $v === null || $v === '' || $v === false || $v === 0 || $v === '0';
    }
}
