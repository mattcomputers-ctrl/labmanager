<?php
declare(strict_types=1);

namespace Chroma\Tests;

use Chroma\Core\ValidationException;
use Chroma\Domain\Codes\CodeAssigner;
use Chroma\Domain\Import\ImportIssues;
use Chroma\Domain\Import\LegacyImporter;
use Chroma\Domain\Import\SpreadsheetReader;

require_once __DIR__ . '/fixtures/make-legacy-fixtures.php';

final class LegacyImporterTest extends TestCase
{
    /** @var array{crossref:string,xlog:string,uvlog:string,catchup:string} */
    private static array $fx;
    private LegacyImporter $importer;

    public static function setUpBeforeClass(): void
    {
        $dir = __DIR__ . '/fixtures/legacy';
        $need = ['crossref' => 'code-cross-reference.xls', 'xlog' => 'security-product-code-log.xlsx', 'uvlog' => 'uv-product-code-log.xlsx', 'catchup' => 'code-cross-reference-catchup.xlsx'];
        $missing = array_filter($need, fn($f) => !is_file("$dir/$f"));
        if ($missing) {
            make_legacy_fixtures($dir);
        }
        self::$fx = array_map(fn($f) => "$dir/$f", $need);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->importer = new LegacyImporter($this->db, $this->app->audit);
    }

    /** @return list<array{path:string,name:string}> */
    private function files(string ...$keys): array
    {
        return array_map(fn($k) => ['path' => self::$fx[$k], 'name' => basename(self::$fx[$k])], $keys);
    }

    private function sell(string $normalized): ?array
    {
        return $this->db->one('SELECT s.*, p.code AS prefix_code, x.code AS suffix_code FROM chroma_sell_numbers s JOIN chroma_code_prefixes p ON p.id = s.prefix_id LEFT JOIN chroma_code_suffixes x ON x.id = s.suffix_id WHERE s.sell_number_normalized = ?', [$normalized]);
    }

    private function internal(int $number, string $version = ''): ?array
    {
        return $this->db->one('SELECT * FROM chroma_internal_codes WHERE number = ? AND version = ?', [$number, $version]);
    }

    private function counts(): array
    {
        return [
            'internal' => (int) $this->db->value('SELECT COUNT(*) FROM chroma_internal_codes'),
            'sell' => (int) $this->db->value('SELECT COUNT(*) FROM chroma_sell_numbers'),
            'issues' => (int) $this->db->value('SELECT COUNT(*) FROM chroma_import_issues'),
            'customers' => (int) $this->db->value('SELECT COUNT(*) FROM chroma_customers'),
            'prefixes' => (int) $this->db->value('SELECT COUNT(*) FROM chroma_code_prefixes'),
            'audit' => (int) $this->db->value('SELECT COUNT(*) FROM chroma_audit_log'),
            'imports' => (int) $this->db->value('SELECT COUNT(*) FROM chroma_imports'),
        ];
    }

    // ------------------------------------------------------------------

    public function testReaderIdentifiesEachFileByItsHeaderRowNotItsName(): void
    {
        $r = new SpreadsheetReader();
        $x = $r->read(self::$fx['crossref'], 'whatever.xls');
        $this->assertSame('crossref', $x['kind']);
        $this->assertSame(3, $x['header_row'], 'header is on row 3, under two title rows');
        $this->assertSame(['internal_code', 'sell_number', 'date', 'customer', 'description', 'resale', 'vendor_name', 'vendor_code', 'handschy'], array_keys($x['columns']));

        $s = $r->read(self::$fx['xlog'], 'renamed.xlsx');
        $this->assertSame('xlog', $s['kind']);
        $this->assertSame(1, $s['header_row']);

        $u = $r->read(self::$fx['uvlog']);
        $this->assertSame('uvlog', $u['kind']);
        $this->assertSame(2, $u['header_row']);

        // Dates: Excel serial, US text and ISO text all come out as YYYY-MM-DD; junk stays null with the raw text kept.
        $byRow = [];
        foreach ($x['rows'] as $row) {
            $byRow[$row['row']] = $row;
        }
        $this->assertSame('1998-05-04', $byRow[4]['date']);
        $this->assertSame('2001-03-14', $byRow[5]['date']);
        $this->assertSame('2019-03-14', $byRow[8]['date']);
        $this->assertNull($byRow[30]['date']);
        $this->assertSame('not a date', $byRow[30]['date_raw']);
        // Trailing spaces are trimmed; blank rows are dropped (row 7 is missing).
        $this->assertSame('E8400', $byRow[25]['internal_code']);
        $this->assertSame('Noble Foods', $byRow[25]['customer']);
        $this->assertArrayNotHasKey(7, $byRow);
    }

    public function testUnrecognisedSheetIsRefusedInPlainLanguage(): void
    {
        $path = (getenv('CHROMA_TEST_TMP') ?: sys_get_temp_dir()) . '/chroma-not-legacy.xlsx';
        write_sheet($path, 'Other', [['Name', 'Qty', 'Price']], [['Widget', 2, 3.5]], 'xlsx');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/does not look like one of the three legacy sheets/');
        (new SpreadsheetReader())->read($path);
    }

    public function testDryRunWritesNothingAndReportsWhatWouldHappen(): void
    {
        $before = $this->counts();
        $plan = $this->importer->dryRun($this->files('crossref', 'xlog', 'uvlog'), [], $this->adminId, false);
        $this->assertSame($before, $this->counts(), 'a dry run must not write anything');

        $this->assertCount(3, $plan->files);
        $this->assertSame(['crossref', 'xlog', 'uvlog'], array_column($plan->files, 'kind'), 'the cross-reference is processed first whatever the upload order');
        $c = $plan->files[0]['counts'];
        $this->assertSame(31, $c['create_internal']);
        $this->assertSame(29, $c['create_sell']);
        $this->assertSame(299, $c['skip'], 'pre-filled empty E# rows are skipped');
        $this->assertSame(5, $c['issues']);

        $x = $plan->files[1]['counts'];
        $this->assertSame(6, $x['create_sell'], 'X104043 is already in the cross-reference, so 6 of 7 used X codes are new');
        $this->assertSame(4, $x['create_internal'], 'E numbers that only appear in the X log are created from it');
        $this->assertSame(1, $x['repeat']);
        $this->assertSame(457, $x['skip']);

        $u = $plan->files[2]['counts'];
        $this->assertSame(3, $u['create_sell']);
        $this->assertSame(1, $u['create_internal']);
        $this->assertSame(2, $u['repeat'], 'UV25510 and UV25672 are already in the cross-reference');

        $this->assertSame(['ZQ'], array_keys($plan->lists['unknown_prefixes']));
        $this->assertSame(2, $plan->lists['unknown_prefixes']['ZQ']['count']);
        $this->assertSame(['UVV185', 'UVV-184'], array_column($plan->lists['uvv_rows'], 'original'));
        $this->assertSame(['UVVL185', 'UVVL184'], array_column($plan->lists['uvv_rows'], 'mapped'));
        $this->assertSame(['Y1500'], array_column($plan->lists['duplicates'], 'code'));
        $this->assertSame(['38-5000', 'PURCHASED ONLY'], array_column($plan->lists['unparseable'], 'text'));
        $this->assertSame(['Y1600XY'], array_column($plan->lists['unknown_suffixes'], 'text'));
        $this->assertSame(['3UC4779', '2UY0480', '3UC4779', '3UC4779'], array_column($plan->lists['legacy_internal'], 'legacy'));
        $this->assertSame(['not a date'], array_column($plan->lists['bad_dates'], 'text'));
        $this->assertContains('Acme Labels', $plan->lists['new_customers']);
        $this->assertSame(['Y|UV' => 1, 'B|UV' => 1], $plan->lists['prefix_suffixes']);

        $types = array_count_values(array_column($plan->issues, 'type'));
        $this->assertSame(['duplicate' => 1, 'unparseable' => 2, 'unknown_suffix' => 1, 'no_code' => 1], $types);

        $text = $plan->toText();
        $this->assertStringContainsString('DRY RUN (nothing was written)', $text);
        $this->assertStringContainsString('UVV185 → UVVL185', $text);
        $this->assertStringContainsString('ZQ — 2 rows', $text);
    }

    public function testRecordedDryRunOnlyTouchesTheImportsTable(): void
    {
        $before = $this->counts();
        $plan = $this->importer->dryRun($this->files('crossref'), [], $this->adminId);
        $after = $this->counts();
        $this->assertSame($before['imports'] + 1, $after['imports']);
        unset($before['imports'], $after['imports']);
        $this->assertSame($before, $after);
        $run = $this->db->one('SELECT * FROM chroma_imports WHERE id = ?', [$plan->importId]);
        $this->assertSame('dry_run', $run['status']);
        $this->assertSame(1, (int) $run['dry_run']);
        $summary = json_decode($run['summary_json'], true);
        $this->assertSame(31, $summary['totals']['create_internal']);
    }

    public function testCommitCreatesTheExpectedRows(): void
    {
        $plan = $this->importer->commit($this->files('uvlog', 'xlog', 'crossref'), [], $this->adminId);
        $a = $plan->applied;
        $this->assertSame(36, $a['create_internal']);
        $this->assertSame(38, $a['create_sell']);
        $this->assertSame(0, $a['refused']);
        $this->assertSame(5, $a['issues']);
        $this->assertSame(1, $a['prefixes']);
        $this->assertSame(36, (int) $this->db->value('SELECT COUNT(*) FROM chroma_internal_codes'));
        $this->assertSame(38, (int) $this->db->value('SELECT COUNT(*) FROM chroma_sell_numbers'));
        $this->assertSame(38, (int) $this->db->value("SELECT COUNT(*) FROM chroma_sell_numbers WHERE source = 'import'"));

        // Hyphenated / lowercase sell numbers are normalized, original text kept.
        $y = $this->sell('Y1000');
        $this->assertSame('Y-1000', $y['legacy_text']);
        $this->assertSame('1998-05-04', $y['date_assigned']);
        $this->assertSame((int) $this->internal(1010)['id'], (int) $y['internal_code_id']);
        $this->assertSame('y-1001uv', $this->sell('Y1001UV')['legacy_text']);
        $this->assertSame('UV', $this->sell('Y1001UV')['suffix_code']);
        $this->assertSame('or-1453', $this->sell('OR1453')['legacy_text']);
        $this->assertSame((int) $this->internal(8700)['id'], (int) $this->sell('OR1453')['internal_code_id']);
        $this->assertNull($this->sell('T2449')['legacy_text'], 'already-canonical text is not duplicated into legacy_text');

        // E numbers with versions are separate rows sharing the base number.
        $this->assertNotNull($this->internal(6374, '10'));
        $this->assertNotNull($this->internal(6374, '12'));
        $this->assertSame((int) $this->internal(6374, '10')['id'], (int) $this->sell('B2970UV')['internal_code_id']);
        $this->assertSame((int) $this->internal(6374, '12')['id'], (int) $this->sell('B2970')['internal_code_id']);

        // Resale row with vendor fields and a Handschy number.
        $r = $this->internal(7001);
        $this->assertSame(1, (int) $r['resale']);
        $this->assertSame('Flint Group', $r['vendor_name']);
        $this->assertSame('FG-OPV-12', $r['vendor_code']);
        $this->assertSame('H-4421', $r['legacy_handschy_number']);
        $this->assertSame('Dunn Flexo', $this->db->value('SELECT name FROM chroma_customers WHERE id = ?', [$r['original_customer_id']]));

        // One E# with two sell numbers (genuine relabel) is not a duplicate.
        $e7200 = (int) $this->internal(7200)['id'];
        $this->assertSame($e7200, (int) $this->sell('R2100')['internal_code_id']);
        $this->assertSame($e7200, (int) $this->sell('R2156')['internal_code_id']);
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM chroma_internal_codes WHERE number = 7200'));

        // E# with no sell number; sell number with no E#.
        $this->assertSame('Formula pending', $this->internal(7300)['description']);
        $this->assertNull($this->sell('T2449')['internal_code_id']);

        // UVV rows live under UVVL with the original spelling kept.
        $this->assertSame('UVVL', $this->sell('UVVL185')['prefix_code']);
        $this->assertSame('UVV185', $this->sell('UVVL185')['legacy_text']);
        $this->assertSame('UVV-184', $this->sell('UVVL184')['legacy_text']);
        $this->assertNull($this->db->value("SELECT id FROM chroma_code_prefixes WHERE code = 'UVV'"));

        // Unknown prefix created as retired; retired seeded prefixes accepted.
        $zq = $this->db->one("SELECT * FROM chroma_code_prefixes WHERE code = 'ZQ'");
        $this->assertSame(1, (int) $zq['retired']);
        $this->assertSame('retired', $zq['tag']);
        $this->assertSame(2, (int) $this->db->value('SELECT COUNT(*) FROM chroma_sell_numbers WHERE prefix_id = ?', [$zq['id']]));
        $this->assertSame('A', $this->sell('A12')['prefix_code']);
        $this->assertSame('HBK', $this->sell('HBK100')['prefix_code']);
        $this->assertSame('HBK0100', $this->sell('HBK100')['legacy_text']);

        // Junk rows still get their E number; only the sell number goes to issues.
        $this->assertNotNull($this->internal(8100));
        $this->assertNotNull($this->internal(8101));
        $this->assertNotNull($this->internal(8300));
        $this->assertNull($this->db->value("SELECT id FROM chroma_sell_numbers WHERE sell_number_normalized LIKE 'Y1600%'"));

        // Trailing spaces, missing description, missing date.
        $this->assertSame('Trailing spaces green', $this->sell('G2101')['description']);
        $this->assertSame('P1555 — ' . LegacyImporter::NO_DESCRIPTION, $this->internal(8600)['description']);
        $this->assertStringContainsString(LegacyImporter::NO_DESCRIPTION, $this->sell('P1555')['description']);
        $this->assertNull($this->sell('BN1439')['date_assigned']);
        $this->assertNull($this->internal(8800)['date_assigned']);
        $this->assertNull($this->sell('W1098')['date_assigned'], 'an unreadable date is left blank, not guessed');
        // The audit snapshot taken at creation says the same thing as the row: no date, not the import day.
        foreach ([['chroma_sell_numbers', (int) $this->sell('BN1439')['id'], 'code.import_sell'], ['chroma_internal_codes', (int) $this->internal(8800)['id'], 'code.import_internal']] as [$table, $id, $action]) {
            $after = $this->db->value('SELECT after_json FROM chroma_audit_log WHERE table_name = ? AND row_id = ? AND action = ?', [$table, $id, $action]);
            $this->assertNotNull($after, "$action was logged");
            $snapshot = json_decode((string) $after, true);
            $this->assertArrayHasKey('date_assigned', $snapshot);
            $this->assertNull($snapshot['date_assigned'], "the audit snapshot of an undated $table row has no date either");
        }

        // X log: codes without E#, comments as notes, E numbers created from the log, cross-file fill-in.
        $this->assertNull($this->sell('X104041')['internal_code_id']);
        $this->assertSame('Same E#, new formula version', $this->sell('X102002')['notes']);
        $this->assertSame('Also coded as R2156', $this->sell('X103500')['notes']);
        $this->assertSame((int) $this->internal(5003, '2')['id'], (int) $this->sell('X103500')['internal_code_id']);
        $this->assertSame('Early security black', $this->internal(5001)['description']);
        $x43 = $this->sell('X104043');
        $this->assertSame('Security ink block 104', $x43['description']);
        $this->assertSame('Same as cross-reference row', $x43['notes'], 'the X log comment fills the empty notes of the cross-reference row');
        $this->assertSame((int) $this->internal(9000)['id'], (int) $x43['internal_code_id']);
        $this->assertSame('2026-10-20', $x43['date_assigned']);

        // UV log: legacy non-E internal codes are text, never E rows.
        $this->assertSame('2UY0480', $this->sell('UV25500')['legacy_internal_code']);
        $this->assertNull($this->sell('UV25500')['internal_code_id']);
        $this->assertSame('3UC4779', $this->sell('UV25510')['legacy_internal_code']);
        $this->assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM chroma_internal_codes WHERE legacy_internal_code IS NOT NULL OR number IN (2, 3)"));
        $this->assertSame((int) $this->internal(8960)['id'], (int) $this->sell('UV25502')['internal_code_id']);

        // Suffix UV is now allowed for Y and B (found in the sheets); X still has none.
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM chroma_code_prefix_suffixes WHERE prefix_id = ? AND suffix_id = ?', [$this->prefixId('B'), $this->suffixId('UV')]));
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM chroma_code_prefix_suffixes WHERE prefix_id = ?', [$this->prefixId('X')]));

        // Everything is audited and the run is recorded.
        // Imported rows are logged as imports, never as "entered by hand", so the history of a code says where it really came from.
        $this->assertSame(36, (int) $this->db->value("SELECT COUNT(*) FROM chroma_audit_log WHERE action = 'code.import_internal'"));
        $this->assertSame(38, (int) $this->db->value("SELECT COUNT(*) FROM chroma_audit_log WHERE action = 'code.import_sell'"));
        $this->assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM chroma_audit_log WHERE action IN ('code.manual_internal', 'code.manual_sell')"));
        $this->assertSame(38, (int) $this->db->value("SELECT COUNT(*) FROM chroma_audit_log WHERE action = 'code.import_sell' AND note LIKE 'Imported from the spreadsheets: %'"));
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM chroma_audit_log WHERE action = 'import.commit'"));
        $run = $this->db->one('SELECT * FROM chroma_imports WHERE id = ?', [$plan->importId]);
        $this->assertSame('done', $run['status']);
        $this->assertSame(0, (int) $run['dry_run']);
        $this->assertStringContainsString('COMMITTED', $run['log_text']);
        $this->assertCount(3, json_decode($run['files_json'], true));
    }

    public function testDuplicatesKeepTheFirstRowAndBecomeIssues(): void
    {
        $this->importer->commit($this->files('crossref'), [], $this->adminId);
        $y = $this->sell('Y1500');
        $this->assertSame((int) $this->internal(7100)['id'], (int) $y['internal_code_id'], 'the first row wins');
        $this->assertSame('Warm Yellow', $y['description']);
        $this->assertNotNull($this->internal(7150), 'the E number on the duplicate row is still created');
        $issue = $this->db->one("SELECT * FROM chroma_import_issues WHERE issue_type = 'duplicate'");
        $this->assertNotNull($issue);
        $this->assertSame('open', $issue['status']);
        $this->assertSame('Code Cross Reference', $issue['source_file']);
        $this->assertSame(12, (int) $issue['source_row']);
        $this->assertStringContainsString('Y1500 is listed more than once', $issue['message']);
        $raw = json_decode($issue['raw_json'], true);
        $this->assertSame('E7150', $raw['internal']);
        $this->assertSame('Lemon Yellow', $raw['description']);
        $this->assertSame(11, $raw['kept_row']);

        $types = [];
        foreach ($this->db->all('SELECT issue_type, COUNT(*) n FROM chroma_import_issues GROUP BY issue_type') as $r) {
            $types[$r['issue_type']] = (int) $r['n'];
        }
        $this->assertSame(['duplicate' => 1, 'no_code' => 1, 'unknown_suffix' => 1, 'unparseable' => 2], $types);
    }

    public function testRunningTheSameFilesTwiceCreatesNothingNew(): void
    {
        $this->importer->commit($this->files('crossref', 'xlog', 'uvlog'), [], $this->adminId);
        $before = $this->counts();
        $plan = $this->importer->dryRun($this->files('crossref', 'xlog', 'uvlog'), [], $this->adminId, false);
        $this->assertSame(0, $plan->totals['create_internal'] + $plan->totals['update_internal'] + $plan->totals['create_sell'] + $plan->totals['update_sell']);
        $this->assertSame(0, $plan->totals['new_customers']);
        $this->assertSame(0, $plan->totals['new_prefixes']);

        $second = $this->importer->commit($this->files('crossref', 'xlog', 'uvlog'), [], $this->adminId);
        $after = $this->counts();
        $this->assertSame($before['internal'], $after['internal']);
        $this->assertSame($before['sell'], $after['sell']);
        $this->assertSame($before['customers'], $after['customers']);
        $this->assertSame($before['prefixes'], $after['prefixes']);
        $this->assertSame($before['issues'], $after['issues'], 'the same issues are not written twice');
        $this->assertSame($before['imports'] + 1, $after['imports']);
        $this->assertSame(0, $second->applied['create_sell'] + $second->applied['create_internal'] + $second->applied['update_sell'] + $second->applied['update_internal'] + $second->applied['issues']);
    }

    public function testCatchUpFileAddsOnlyTheNewRowsAndFillsOnlyEmptyFields(): void
    {
        $this->importer->commit($this->files('crossref', 'xlog', 'uvlog'), [], $this->adminId);
        $before = $this->counts();
        $plan = $this->importer->commit($this->files('catchup'), [], $this->adminId);
        $a = $plan->applied;
        $this->assertSame(4, $a['create_internal'], 'E9150, E9151, E9302 and E9303 are new');
        $this->assertSame(4, $a['create_sell'], 'Y1715, B2971UV, R2200 and Y1720 are new; R2100 already exists');
        $this->assertSame(1, $a['update_internal'], 'E8800 gets its date');
        $this->assertSame(1, $a['update_sell'], 'BN1439 gets its date');
        $this->assertSame(1, $a['issues'], 'only the new R2100 conflict is written; the issues already recorded are not repeated');
        $this->assertSame(5, $plan->totals['issues_known'], 'the five issues from the first run are reported as already listed');
        $this->assertSame(1, $plan->totals['issues'], 'and not counted as issues to expect');
        $this->assertSame(['not a date', '2019'], array_column($plan->lists['bad_dates'], 'text'), 'a bare year is not a date');
        $this->assertSame($before['internal'] + 4, $this->counts()['internal']);
        $this->assertSame($before['sell'] + 4, $this->counts()['sell']);
        $this->assertNull($this->internal(9303)['date_assigned'], 'a bare year is left blank, not turned into 1905');

        $this->assertSame('2024-02-02', $this->sell('BN1439')['date_assigned']);
        $this->assertSame('2024-02-02', $this->internal(8800)['date_assigned']);
        $this->assertSame('Brown no date', $this->sell('BN1439')['description']);
        $this->assertSame((int) $this->internal(7300)['id'], (int) $this->sell('R2200')['internal_code_id'], 'an existing E# gains its new sell number');
        $this->assertSame((int) $this->internal(9150)['id'], (int) $this->sell('Y1715')['internal_code_id']);
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM chroma_audit_log WHERE action = 'import.fill_sell'"));
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM chroma_audit_log WHERE action = 'import.fill_internal'"));

        // Counters follow the new highs.
        $this->assertSame(9304, (int) $this->db->value("SELECT next_value FROM chroma_sequences WHERE name = 'internal_code'"));
        $this->assertSame(1721, (int) $this->db->value('SELECT next_number FROM chroma_code_prefixes WHERE id = ?', [$this->prefixId('Y')]));
        $this->assertSame(2972, (int) $this->db->value('SELECT next_number FROM chroma_code_prefixes WHERE id = ?', [$this->prefixId('B')]));
    }

    public function testExistingRowsAreOnlyFilledNeverOverwritten(): void
    {
        $codes = new CodeAssigner($this->db, $this->app->audit);
        $codes->manualSell('Y1000', ['description' => 'Keep this description', 'notes' => 'Keep these notes'], $this->adminId);
        $this->db->run('UPDATE chroma_sell_numbers SET date_assigned = NULL WHERE sell_number_normalized = ?', ['Y1000']);
        $codes->manualInternal('E7001', ['description' => 'My own E7001', 'vendor_name' => 'My vendor'], $this->adminId);

        $plan = $this->importer->commit($this->files('crossref'), [], $this->adminId);
        $y = $this->sell('Y1000');
        $this->assertSame('Keep this description', $y['description']);
        $this->assertSame('Keep these notes', $y['notes']);
        $this->assertSame('1998-05-04', $y['date_assigned'], 'empty date was filled');
        $this->assertSame('Y-1000', $y['legacy_text'], 'empty legacy text was filled');
        $this->assertSame((int) $this->internal(1010)['id'], (int) $y['internal_code_id'], 'missing E# link was added');
        $this->assertSame('Acme Labels', $this->db->value('SELECT name FROM chroma_customers WHERE id = ?', [$y['original_customer_id']]));

        $e = $this->internal(7001);
        $this->assertSame('My own E7001', $e['description']);
        $this->assertSame('My vendor', $e['vendor_name']);
        $this->assertSame('FG-OPV-12', $e['vendor_code'], 'empty vendor code was filled');
        $this->assertSame(1, (int) $e['resale']);
        $this->assertSame(30, $plan->applied['create_internal']);
        $this->assertSame(28, $plan->applied['create_sell']);
        $this->assertSame(1, $plan->applied['update_internal']);
        $this->assertSame(1, $plan->applied['update_sell']);
        $this->assertSame(1, $plan->applied['links']);
    }

    public function testUvvMappingCanBeSwitchedOff(): void
    {
        $plan = $this->importer->dryRun($this->files('crossref'), ['map_uvv' => false], $this->adminId, false);
        $this->assertSame([], $plan->lists['uvv_rows']);
        $this->assertSame(['UVV', 'ZQ'], array_keys($plan->lists['unknown_prefixes']));
        $this->importer->commit($this->files('crossref'), ['map_uvv' => false], $this->adminId);
        $uvv = $this->db->one("SELECT * FROM chroma_code_prefixes WHERE code = 'UVV'");
        $this->assertSame(1, (int) $uvv['retired']);
        $this->assertSame('UVV', $this->sell('UVV185')['prefix_code']);
        $this->assertNull($this->sell('UVVL185'));
    }

    public function testCountersMoveToMaxInUsePlusOneUnlessAdminSetHigher(): void
    {
        $this->db->run('UPDATE chroma_code_prefixes SET next_number = 1 WHERE code IN ("T", "AQ")');
        $this->db->run('UPDATE chroma_code_prefixes SET next_number = 5000 WHERE code = "Y"');
        $this->db->run("UPDATE chroma_sequences SET next_value = 100 WHERE name = 'internal_code'");
        $plan = $this->importer->commit($this->files('crossref'), [], $this->adminId);
        $this->assertSame(2450, (int) $this->db->value('SELECT next_number FROM chroma_code_prefixes WHERE code = "T"'));
        $this->assertSame(5255, (int) $this->db->value('SELECT next_number FROM chroma_code_prefixes WHERE code = "AQ"'));
        $this->assertSame(5000, (int) $this->db->value('SELECT next_number FROM chroma_code_prefixes WHERE code = "Y"'), 'an admin value that is already higher is kept');
        $this->assertSame(9102, (int) $this->db->value("SELECT next_value FROM chroma_sequences WHERE name = 'internal_code'"));
        $this->assertContains('E numbers: next E9102 (was E100)', $plan->applied['counters']);
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM chroma_audit_log WHERE action = 'sequence.set_internal'"));
        // The next normal assignment continues after the imported numbers.
        $codes = new CodeAssigner($this->db, $this->app->audit);
        $this->assertSame('T2450', $codes->assignSell(['prefix_id' => $this->prefixId('T'), 'description' => 'next tint'], $this->adminId)['display']);
        $this->assertSame('E9102', $codes->assignInternal(['description' => 'next formula'], $this->adminId)['display']);
    }

    public function testIssuesCanBeCorrectedMergedOrDiscarded(): void
    {
        $this->importer->commit($this->files('crossref'), [], $this->adminId);
        $issues = new ImportIssues($this->db, $this->app->audit);
        $this->assertSame(5, $issues->openCount());

        // Correct the duplicate: the typo row really was Y1501.
        $dup = $this->db->one("SELECT id FROM chroma_import_issues WHERE issue_type = 'duplicate'");
        $row = $issues->correct((int) $dup['id'], 'y-1501', $this->adminId);
        $this->assertSame('Y1501', $row['display']);
        $this->assertSame((int) $this->internal(7150)['id'], (int) $row['internal_code_id']);
        $this->assertSame('Lemon Yellow', $row['description']);
        $this->assertSame('Evergreen Foods', $this->db->value('SELECT name FROM chroma_customers WHERE id = ?', [$row['original_customer_id']]));
        $this->assertSame('2016-04-19', $row['date_assigned']);
        $resolved = $issues->find((int) $dup['id']);
        $this->assertSame('resolved', $resolved['status']);
        $this->assertSame('corrected', $resolved['resolution']);
        $this->assertSame($this->adminId, (int) $resolved['resolved_by']);
        try {
            $issues->correct((int) $dup['id'], 'Y1502', $this->adminId);
            $this->fail('resolving twice must be refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('already resolved', $e->getMessage());
        }

        $this->assertSame('y-1501', $row['legacy_text'], 'the spelling typed by the admin is kept as legacy text');
        $this->assertStringContainsString('sheet said "Y1500"', $row['notes'], 'the sheet\'s wrong text is quoted in the notes only');
        $this->assertNull($this->db->value("SELECT id FROM chroma_sell_numbers WHERE legacy_text = 'Y1500'"), 'the typo never becomes an alias that lookups could match');

        // Entering a code that is taken is refused by the code rules, and leaves nothing behind.
        $junk = $this->db->one("SELECT id FROM chroma_import_issues WHERE issue_type = 'unparseable' AND source_row = 19");
        $this->assertNull($issues->mergeTarget($issues->find((int) $junk['id'])), 'a junk sell number has nothing to merge into (its own E number is not a duplicate of anything)');
        $this->db->run("UPDATE chroma_import_issues SET raw_json = JSON_SET(raw_json, '$.customer', 'Brand New Customer') WHERE id = ?", [$junk['id']]);
        $customersBefore = (int) $this->db->value('SELECT COUNT(*) FROM chroma_customers');
        try {
            $issues->correct((int) $junk['id'], 'Y1000', $this->adminId);
            $this->fail('expected refusal');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('already used', $e->getMessage());
        }
        $this->assertSame('open', $issues->find((int) $junk['id'])['status']);
        $this->assertSame($customersBefore, (int) $this->db->value('SELECT COUNT(*) FROM chroma_customers'), 'a refused correction does not create the customer');
        // An E number can be entered too.
        $row = $issues->correct((int) $junk['id'], 'E8102', $this->adminId);
        $this->assertSame('E8102', $row['display']);
        $this->assertSame($customersBefore + 1, (int) $this->db->value('SELECT COUNT(*) FROM chroma_customers'));

        // Merge: reopen the duplicate, then fold it into the kept Y1500.
        $issues->reopen((int) $dup['id'], $this->adminId);
        $target = $issues->mergeTarget($issues->find((int) $dup['id']));
        $this->assertSame('Y1500', $target['display']);
        $issues->merge((int) $dup['id'], $this->adminId, 'It was a relabel after all');
        $kept = $this->sell('Y1500');
        $this->assertStringContainsString('Lemon Yellow', $kept['notes']);
        $this->assertSame('Warm Yellow', $kept['description']);
        $this->assertSame('merged', $issues->find((int) $dup['id'])['resolution']);

        // Discard needs a reason.
        $orphan = $this->db->one("SELECT id FROM chroma_import_issues WHERE issue_type = 'no_code'");
        $this->assertNull($issues->mergeTarget($issues->find((int) $orphan['id'])));
        try {
            $issues->discard((int) $orphan['id'], '  ', $this->adminId);
            $this->fail('a reason is required');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('why', $e->getMessage());
        }
        $issues->discard((int) $orphan['id'], 'Nothing to attach it to', $this->adminId);
        $this->assertSame('discarded', $issues->find((int) $orphan['id'])['resolution']);
        $this->assertSame(2, $issues->openCount());
        $this->assertSame(4, (int) $this->db->value("SELECT COUNT(*) FROM chroma_audit_log WHERE action = 'import.issue_resolve'"));

        // Listing and filters.
        $open = $issues->search(['status' => 'open']);
        $this->assertSame(2, $open['total']);
        $this->assertSame(1, $issues->search(['status' => 'all', 'q' => 'PURCHASED'])['total']);
        $this->assertSame(1, $issues->search(['status' => 'all', 'type' => 'unknown_suffix'])['total']);
        $this->assertNull($issues->mergeTarget($issues->find((int) $this->db->value("SELECT id FROM chroma_import_issues WHERE issue_type = 'unknown_suffix'"))), 'an unknown-suffix row has nothing to merge into either');
    }

    public function testASellNumberLinkedToAnotherENumberIsReportedNotSilentlyIgnored(): void
    {
        // Between two sheets in one run (nothing in Chroma yet): the X log gives X104043 a different E number than the cross-reference.
        $path = (getenv('CHROMA_TEST_TMP') ?: sys_get_temp_dir()) . '/chroma-xlog-conflict.xlsx';
        write_sheet($path, 'X Codes', [['Code', 'Description', 'Internal Code', 'Comments']], [
            ['X104043', 'Security ink block 104', 'E9001', 'different E# than the cross-reference'],
            ['X104041', 'Security green', 'E9002', ''],
        ], 'xlsx');
        $plan = $this->importer->dryRun([['path' => self::$fx['crossref'], 'name' => 'xref.xls'], ['path' => $path, 'name' => 'xlog.xlsx']], [], $this->adminId, false);
        $dups = array_values(array_filter($plan->lists['duplicates'], fn($d) => $d['code'] === 'X104043'));
        $this->assertCount(1, $dups);
        $this->assertSame('Code Cross Reference', $dups[0]['kept_where']);
        $this->assertSame('E9000', $dups[0]['kept_internal']);
        $this->assertSame('Security Product Code Log', $dups[0]['file_label']);
        $this->assertStringContainsString('kept Code Cross Reference row', $plan->toText());
        $msg = array_values(array_filter($plan->issues, fn($i) => $i['code'] === 'X104043'))[0]['message'];
        $this->assertStringContainsString('X104043 is in both the Code Cross Reference', $msg);
        $this->assertStringContainsString('with a different E number', $msg);
        $this->assertSame(1, $plan->files[1]['counts']['create_sell'], 'X104041 is still new from the log');
        $this->assertSame(0, $plan->files[1]['counts']['repeat'], 'a conflicting row is not a harmless repeat');

        // Against Chroma itself: the cross-reference is imported, then the catch-up sheet says R2100 belongs to E9302 while Chroma says E7200.
        $this->importer->commit($this->files('crossref'), [], $this->adminId);
        $e7200 = (int) $this->internal(7200)['id'];

        $plan = $this->importer->dryRun($this->files('catchup'), [], $this->adminId, false);
        $dups = array_values(array_filter($plan->lists['duplicates'], fn($d) => $d['code'] === 'R2100'));
        $this->assertCount(1, $dups, 'the disagreement is in the report');
        $this->assertNull($dups[0]['kept_row']);
        $this->assertSame('Chroma', $dups[0]['kept_where']);
        $this->assertSame('E7200', $dups[0]['kept_internal']);
        $this->assertSame('E9302', $dups[0]['internal']);
        $this->assertStringContainsString('R2100 — already in Chroma linked to E7200', $plan->toText());
        $issue = array_values(array_filter($plan->newIssues(), fn($i) => $i['code'] === 'R2100'))[0] ?? null;
        $this->assertNotNull($issue, 'and it is an issue');
        $this->assertSame('duplicate', $issue['type']);
        $this->assertStringContainsString('R2100 is already in Chroma, linked to E7200', $issue['message']);
        $this->assertStringContainsString('this row (E9302', $issue['message']);

        $this->importer->commit($this->files('catchup'), [], $this->adminId);
        $this->assertSame($e7200, (int) $this->sell('R2100')['internal_code_id'], 'Chroma\'s link is kept');
        $this->assertSame('Rhodamine Red', $this->sell('R2100')['description'], 'and nothing on the kept row is touched');
        $this->assertNotNull($this->internal(9302), 'the E number on the conflicting row is still created');
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM chroma_sell_numbers WHERE internal_code_id = ?', [$this->internal(9302)['id']]));
        $written = $this->db->one("SELECT * FROM chroma_import_issues WHERE issue_type = 'duplicate' AND raw_json LIKE '%R2100%'");
        $this->assertNotNull($written);
        $this->assertSame('open', $written['status']);
        $raw = json_decode($written['raw_json'], true);
        $this->assertSame('R2100', $raw['kept_code']);
        $this->assertSame('E7200', $raw['kept_internal']);
        $this->assertSame('E9302', $raw['internal']);
        $target = (new ImportIssues($this->db, $this->app->audit))->mergeTarget(ImportIssues::decorate($written));
        $this->assertSame('R2100', $target['display'], 'merge offers the kept sell number');

    }

    public function testADryRunCanOnlyBeCommittedOnceAndNotWhileAnotherImportRuns(): void
    {
        $dry = $this->importer->dryRun($this->files('crossref'), [], $this->adminId);
        $this->assertSame('dry_run', $this->db->value('SELECT status FROM chroma_imports WHERE id = ?', [$dry->importId]));

        // Someone else holds the import lock (another request or the CLI): refused in plain language, dry run untouched.
        $other = $this->db->fresh();
        $this->assertSame(1, (int) $other->value("SELECT GET_LOCK('chroma_legacy_import', 0)"));
        try {
            $this->importer->commit($this->files('crossref'), [], $this->adminId, $dry->importId);
            $this->fail('a second import must not run alongside the first');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Another legacy import is running right now', $e->getMessage());
        }
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM chroma_sell_numbers'));
        $this->assertSame('dry_run', $this->db->value('SELECT status FROM chroma_imports WHERE id = ?', [$dry->importId]));
        $other->value("SELECT RELEASE_LOCK('chroma_legacy_import')");

        // A dry run that is being committed (second tab, double click) is refused and not committed twice.
        $this->db->update('chroma_imports', ['status' => 'committing'], ['id' => $dry->importId]);
        try {
            $this->importer->commit($this->files('crossref'), [], $this->adminId, $dry->importId);
            $this->fail('a dry run mid-commit must not be committed again');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('being committed right now', $e->getMessage());
        }
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM chroma_sell_numbers'));
        $this->db->update('chroma_imports', ['status' => 'dry_run'], ['id' => $dry->importId]);

        // The real commit marks the dry run committed, and a second commit of it is refused.
        $plan = $this->importer->commit($this->files('crossref'), [], $this->adminId, $dry->importId);
        $this->assertSame('committed', $this->db->value('SELECT status FROM chroma_imports WHERE id = ?', [$dry->importId]));
        $this->assertSame('done', $this->db->value('SELECT status FROM chroma_imports WHERE id = ?', [$plan->importId]));
        $this->assertNull($this->db->value("SELECT IS_USED_LOCK('chroma_legacy_import')"), 'the lock is released afterwards');
        $sells = (int) $this->db->value('SELECT COUNT(*) FROM chroma_sell_numbers');
        try {
            $this->importer->commit($this->files('crossref'), [], $this->adminId, $dry->importId);
            $this->fail('a committed dry run must not be committed again');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('already committed or replaced', $e->getMessage());
        }
        $this->assertSame($sells, (int) $this->db->value('SELECT COUNT(*) FROM chroma_sell_numbers'));
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM chroma_imports WHERE dry_run = 0"));

        // A commit that fails part-way hands the dry run back so it can be retried, and the lock is released.
        $dry2 = $this->importer->dryRun($this->files('catchup'), [], $this->adminId);
        try {
            $this->importer->commit([['path' => sys_get_temp_dir() . '/chroma-gone.xlsx', 'name' => 'gone.xlsx']], [], $this->adminId, $dry2->importId);
            $this->fail('expected the failure to surface');
        } catch (\Throwable $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $this->assertSame('dry_run', $this->db->value('SELECT status FROM chroma_imports WHERE id = ?', [$dry2->importId]));
        $this->assertNull($this->db->value("SELECT IS_USED_LOCK('chroma_legacy_import')"));
    }

    public function testBareYearsAndOutOfRangeSerialsAreUnreadableDates(): void
    {
        $this->assertNull(SpreadsheetReader::parseDate('2019'), 'a year typed as text is not an Excel serial');
        $this->assertNull(SpreadsheetReader::parseDate(2019), 'nor is the number 2019');
        $this->assertNull(SpreadsheetReader::parseDate('1998'));
        $this->assertSame('2019-03-14', SpreadsheetReader::parseDate('2019-03-14'));
        $this->assertSame('2019-03-14', SpreadsheetReader::parseDate(43538));
        $this->assertSame('2019-03-14', SpreadsheetReader::parseDate('43538'));
        $this->assertSame('1950-01-01', SpreadsheetReader::parseDate(18264));
        $this->assertNull(SpreadsheetReader::parseDate(18263), 'before 1950 is not a lab date');
        $this->assertNull(SpreadsheetReader::parseDate(99999));
    }

    public function testUploadingTheSameSheetTwiceIsRefused(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/both the Code Cross Reference/');
        $this->importer->dryRun($this->files('crossref', 'catchup'), [], $this->adminId, false);
    }

    /**
     * The real cross-reference has ~8,100 rows and must import well under a
     * minute. The full-size run takes ~40 s over TCP (~22 s over the MariaDB
     * socket), almost all of it inside CodeAssigner; to keep the suite quick
     * this runs 3,000 rows against a proportional budget (22 s ≙ 60 s for 8,100).
     */
    public function testThousandsOfRowsImportWellUnderAMinute(): void
    {
        $dir = (getenv('CHROMA_TEST_TMP') ?: sys_get_temp_dir()) . '/chroma-legacy-large';
        $fx = make_legacy_fixtures($dir, 3000);
        $t = microtime(true);
        $plan = $this->importer->commit([['path' => $fx['large'], 'name' => 'large.xls'], ['path' => $fx['xlog'], 'name' => 'x.xlsx'], ['path' => $fx['uvlog'], 'name' => 'uv.xlsx']], [], $this->adminId);
        $seconds = microtime(true) - $t;
        $this->assertSame(3000 + 7, $plan->applied['create_internal'], 'the seven E numbers named only in the two logs are created from them');
        $this->assertSame(3000 + 7 + 5, $plan->applied['create_sell']);
        $this->assertSame(1901, $plan->files[0]['counts']['skip'], 'pre-filled E# rows after the data');
        $this->assertLessThan(22, $seconds, sprintf('import took %.1fs', $seconds));
        fwrite(STDERR, sprintf("\n[import of %d rows took %.1fs]\n", $plan->totals['rows'], $seconds));
    }
}
