<?php
declare(strict_types=1);

namespace Chroma\Tests;

use Chroma\Domain\Codes\CodeAssigner;
use Chroma\Domain\Codes\CodeDetails;
use Chroma\Domain\Codes\CodeSearch;

final class CodeSearchTest extends TestCase
{
    private CodeAssigner $codes;
    private CodeSearch $search;

    protected function setUp(): void
    {
        parent::setUp();
        $this->codes = new CodeAssigner($this->db, $this->app->audit);
        $this->search = new CodeSearch($this->db);
    }

    /** @return array<string,int> ids by name */
    private function seedProducts(): array
    {
        $cust = $this->db->insert('chroma_customers', ['name' => 'Acme Labels', 'name_normalized' => 'acme labels', 'active' => 1, 'source' => 'manual', 'created_at' => $this->db->now(), 'updated_at' => $this->db->now()]);
        $e1 = $this->codes->manualInternal('E6374', ['description' => 'Blister purple base', 'original_customer_id' => $cust], $this->adminId);
        $e1v = $this->codes->manualInternal('E6374-10', ['description' => 'Blister purple base v10'], $this->adminId);
        $y1000 = $this->codes->manualSell('y-1000', ['description' => 'Process yellow', 'internal_code_id' => $e1['id']], $this->adminId);
        $y1000uv = $this->codes->manualSell('Y1000UV', ['description' => 'Process yellow UV', 'internal_code_id' => $e1['id']], $this->adminId);
        $r2156 = $this->codes->manualSell('R2156', ['description' => 'Also coded as yellow (relabel)', 'internal_code_id' => $e1['id'], 'reason' => 'relabel'], $this->adminId);
        $lonely = $this->codes->manualSell('T2001', ['description' => 'Tint with no E number yet'], $this->adminId);
        $e2 = $this->codes->assignInternal(['description' => 'Gloss varnish'], $this->adminId); // E9102
        return ['cust' => $cust, 'e1' => (int) $e1['id'], 'e1v' => (int) $e1v['id'], 'y1000' => (int) $y1000['id'], 'y1000uv' => (int) $y1000uv['id'], 'r2156' => (int) $r2156['id'], 'lonely' => (int) $lonely['id'], 'e2' => (int) $e2['id']];
    }

    public function testParseAcceptsEverySpelling(): void
    {
        foreach (['y-1000', 'Y 1000', 'y1000', 'Y-1000 ', 'y 1000 uv'] as $q) {
            $p = CodeSearch::parse($q);
            $this->assertNotEmpty($p['sells'], $q);
            $this->assertStringStartsWith('Y1000', $p['sells'][0], $q);
            $this->assertNull($p['internal'], $q);
        }
        $p = CodeSearch::parse('E6374-10');
        $this->assertSame(['number' => 6374, 'version' => '10'], $p['internal']);
        $this->assertSame([], $p['sells']);
        $p = CodeSearch::parse('6374');
        $this->assertSame(6374, $p['internal']['number']);
        $this->assertSame(6374, $p['digits']);
        $p = CodeSearch::parse('Blister purple');
        $this->assertSame([], $p['sells']);
        $this->assertNull($p['internal']);
        $this->assertSame('+Blister* +purple*', $p['words']);
        $p = CodeSearch::parse('T2449 Blister');
        $this->assertSame(['T2449BLISTER', 'T2449'], $p['sells']);
        $this->assertSame('Y', CodeSearch::parse('y')['prefix']);
        $this->assertSame('', CodeSearch::parse('   ')['raw']);
    }

    public function testSellNumberSpellingsFindTheSameProductWithAliasesGrouped(): void
    {
        $ids = $this->seedProducts();
        foreach (['y-1000', 'Y 1000', 'y1000', 'Y1000UV'] as $q) {
            $res = $this->search->search($q);
            $this->assertSame(1, $res['total'], "query $q");
            $product = $res['products'][0];
            $this->assertSame('E' . $ids['e1'], $product['key'], "query $q");
            $this->assertSame('E6374', $product['internal']['display']);
            $this->assertSame(['Y1000', 'Y1000UV', 'R2156'], array_map(fn($s) => $s['display'], $product['sells']), "aliases grouped for $q");
            $this->assertSame('Acme Labels', $product['customer']);
        }
        // Searching the relabel alias lands on the same product.
        $res = $this->search->search('R2156');
        $this->assertSame('E' . $ids['e1'], $res['products'][0]['key']);
    }

    public function testInternalNumberSpellingsAndVersions(): void
    {
        $ids = $this->seedProducts();
        $res = $this->search->search('E6374-10');
        $this->assertSame(1, $res['total']);
        $this->assertSame('E6374-10', $res['products'][0]['internal']['display']);

        $res = $this->search->search('6374');
        $keys = array_map(fn($p) => $p['key'], $res['products']);
        $this->assertSame(['E' . $ids['e1'], 'E' . $ids['e1v']], $keys, 'exact base number first, then its versions');

        $res = $this->search->search('e 6374');
        $this->assertSame(2, $res['total']);

        $res = $this->search->search('E63');
        $this->assertSame(2, $res['total'], 'prefix of a number matches E63xx');
        $this->assertSame(0, $this->search->search('E95')['total'], 'but never the numbers in between');
        $this->assertSame(1, $this->search->search('E91')['total'], 'E91 matches E9102');
        $this->assertSame([[63, 63], [630, 639], [6300, 6399], [63000, 63999], [630000, 639999]], CodeSearch::numberRanges(63));
    }

    public function testFreeTextMatchesDescriptionsAndCustomers(): void
    {
        $ids = $this->seedProducts();
        $res = $this->search->search('blister purple');
        $this->assertSame(2, $res['total']);
        $this->assertContains('E' . $ids['e1'], array_map(fn($p) => $p['key'], $res['products']));

        $res = $this->search->search('tint');
        $this->assertSame(1, $res['total']);
        $this->assertSame('S' . $ids['lonely'], $res['products'][0]['key']);
        $this->assertSame('sell', $res['products'][0]['type']);
        $this->assertNull($res['products'][0]['internal']);

        $res = $this->search->search('Acme');
        $this->assertSame(1, $res['total']);
        $this->assertSame('E' . $ids['e1'], $res['products'][0]['key']);

        $res = $this->search->search('nothing like this anywhere');
        $this->assertSame(0, $res['total']);
        $this->assertSame([], $res['products']);
        $this->assertSame(0, $this->search->search('')['total']);
    }

    public function testVoidedCodesStayVisibleButFlagged(): void
    {
        $ids = $this->seedProducts();
        $this->codes->voidSell($ids['y1000uv'], 'Typo', $this->adminId);
        $res = $this->search->search('Y1000');
        $sells = $res['products'][0]['sells'];
        $uv = array_values(array_filter($sells, fn($s) => $s['display'] === 'Y1000UV'))[0];
        $this->assertSame(1, (int) $uv['voided']);
        $this->assertFalse($res['products'][0]['voided'], 'product is not voided while a live alias remains');
        $this->assertSame(0, $this->search->search('Y1000UV', 1, 25, ['show' => 'active'])['total']);
        $this->assertSame(1, $this->search->search('Y1000UV', 1, 25, ['show' => 'voided'])['total']);

        $this->codes->voidInternal($ids['e2'], 'Mistake', $this->adminId);
        $res = $this->search->search('E9102');
        $this->assertTrue($res['products'][0]['voided']);
    }

    public function testProjectOriginIsShown(): void
    {
        $ids = $this->seedProducts();
        $typeId = (int) $this->db->value('SELECT id FROM chroma_project_types ORDER BY id LIMIT 1');
        $now = $this->db->now();
        $pid = $this->db->insert('chroma_projects', ['project_number' => 101, 'project_type_id' => $typeId, 'customer_id' => $ids['cust'], 'title' => 'Acme spring labels', 'status' => 'open', 'date_submitted' => '2026-10-01', 'created_by' => $this->adminId, 'created_at' => $now, 'updated_at' => $now]);
        $this->db->insert('chroma_project_items', ['project_id' => $pid, 'sequence' => 1, 'kind' => 'color', 'description' => 'Pantone 663', 'internal_code_id' => $ids['e1'], 'sell_number_id' => $ids['y1000'], 'created_at' => $now, 'updated_at' => $now]);
        $this->db->insert('chroma_project_items', ['project_id' => $pid, 'sequence' => 2, 'kind' => 'color', 'description' => 'Tint only', 'sell_number_id' => $ids['lonely'], 'created_at' => $now, 'updated_at' => $now]);

        $res = $this->search->search('y1000');
        $origins = $res['products'][0]['origins'];
        $this->assertCount(1, $origins, 'item linked by both E# and sell number is listed once');
        $this->assertSame(101, (int) $origins[0]['project_number']);
        $this->assertSame('Acme spring labels', $origins[0]['project_title']);
        $this->assertSame($pid, (int) $origins[0]['project_id']);

        $res = $this->search->search('T2001');
        $this->assertSame(101, (int) $res['products'][0]['origins'][0]['project_number']);

        $details = new CodeDetails($this->db, $this->app->audit);
        $this->assertSame(101, (int) $details->sell($ids['lonely'])['origins'][0]['project_number']);
        $this->assertSame(101, (int) $details->internal($ids['e1'])['origins'][0]['project_number']);
    }

    public function testPaginationAndExportFlattening(): void
    {
        $y = $this->prefixId('Y');
        for ($i = 0; $i < 12; $i++) {
            $this->codes->assignSell(['prefix_id' => $y, 'description' => "Yellow $i"], $this->adminId);
        }
        $p1 = $this->search->search('Y', 1, 5);
        $p3 = $this->search->search('Y', 3, 5);
        $this->assertSame(12, $p1['total']);
        $this->assertCount(5, $p1['products']);
        $this->assertCount(2, $p3['products']);
        $this->assertSame('Y1715', $p1['products'][0]['sells'][0]['display'], 'browsing a prefix lists lowest number first');

        $rows = $this->search->exportRows('Y');
        $this->assertCount(12, $rows);
        $this->assertArrayHasKey('sell_number', $rows[0]);
        $this->assertSame(array_keys(CodeSearch::exportColumns()), array_keys($rows[0]));

        // An E number without sell numbers still exports as one row.
        $this->codes->assignInternal(['description' => 'Orphan formula'], $this->adminId);
        $rows = $this->search->exportRows('Orphan');
        $this->assertCount(1, $rows);
        $this->assertSame('E9102', $rows[0]['internal_code']);
        $this->assertSame('', $rows[0]['sell_number']);
    }

    public function testLogsAreNewestFirstWithCounts(): void
    {
        $ids = $this->seedProducts();
        $t = $this->prefixId('T');
        $a = $this->codes->assignSell(['prefix_id' => $t, 'description' => 'Tint A'], $this->adminId);
        $b = $this->codes->assignSell(['prefix_id' => $t, 'suffix_id' => $this->suffixId('UV'), 'description' => 'Tint Bravo'], $this->adminId);
        $this->codes->voidSell((int) $a['id'], 'oops', $this->adminId);

        $log = $this->search->log('T');
        $this->assertSame(3, $log['total']);
        $this->assertSame([$b['display'], $a['display'], 'T2001'], array_map(fn($r) => $r['display'], $log['rows']));
        $this->assertSame(1, (int) $log['rows'][1]['voided']);
        $this->assertSame('Admin', $log['rows'][0]['assigned_by_name']);

        $this->assertSame(2, $this->search->log('T', ['show' => 'active'])['total']);
        $this->assertSame(1, $this->search->log('t', ['q' => 'T2001'])['total']);
        $this->assertSame(1, $this->search->log('T', ['q' => 'Bravo'])['total']);
        $this->assertSame(1, $this->search->log('T', ['q' => '2001'])['total']);
        $this->assertSame(0, $this->search->log('ZZZ')['total']);
        $this->assertNull($this->search->log('ZZZ')['prefix']);

        $e = $this->search->log('E');
        $this->assertSame(3, $e['total']);
        $this->assertSame(['E9102', 'E6374-10', 'E6374'], array_map(fn($r) => $r['display'], $e['rows']));
        $this->assertSame(['Y1000', 'Y1000UV', 'R2156'], array_map(fn($s) => $s['display'], $e['rows'][2]['sells']));

        $prefixes = $this->search->logPrefixes();
        $this->assertSame('E', $prefixes[0]['code']);
        $this->assertSame(3, $prefixes[0]['count']);
        $byCode = [];
        foreach ($prefixes as $p) {
            $byCode[$p['code']] = $p;
        }
        $this->assertSame(3, $byCode['T']['count']);
        $this->assertSame(1, $byCode['T']['voided']);
        $this->assertSame(2, $byCode['Y']['count']);
        $this->assertSame(0, $byCode['X']['count']);

        $rows = $this->search->logExportRows('T');
        $this->assertCount(3, $rows);
        $this->assertSame('Voided', $rows[1]['voided']);
        $this->assertSame(array_keys(CodeSearch::logExportColumns('T')), array_keys($rows[0]));
        $this->assertSame('Y1000, Y1000UV, R2156', $this->search->logExportRows('E')[2]['sell_numbers']);
    }

    public function testDetailsEditsAreAuditedAndSdsLinkFollowsSetting(): void
    {
        $ids = $this->seedProducts();
        $details = new CodeDetails($this->db, $this->app->audit);
        $details->updateNotes('internal', $ids['e1'], 'Keep away from heat', $this->adminId);
        $details->updateDescription('sell', $ids['y1000'], 'Process yellow (relabelled)', $this->adminId);
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM chroma_audit_log WHERE action = "code.notes" AND row_id = ?', [$ids['e1']]));
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM chroma_audit_log WHERE action = "code.description" AND row_id = ?', [$ids['y1000']]));
        $details->updateNotes('internal', $ids['e1'], 'Keep away from heat', $this->adminId); // unchanged: no new entry
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM chroma_audit_log WHERE action = "code.notes"'));

        $this->expectException(\Chroma\Core\ValidationException::class);
        $details->updateDescription('internal', $ids['e1'], '   ', $this->adminId);
    }

    public function testSdsUrlPattern(): void
    {
        $this->assertNull(CodeDetails::sdsUrl('', 9102));
        $this->assertNull(CodeDetails::sdsUrl(null, 9102));
        $this->assertSame('https://sds.example.com/E9102', CodeDetails::sdsUrl('https://sds.example.com/', 9102));
        $this->assertSame('https://sds.example.com/E9102', CodeDetails::sdsUrl('https://sds.example.com', 9102));
        $this->assertSame('https://sds.example.com/sheet?e=E9102', CodeDetails::sdsUrl('https://sds.example.com/sheet?e={code}', 9102));
        // Admin → System tells the administrator to use {E}; that must work too.
        $this->assertSame('http://sds.precisionink.local/sheet/E9102', CodeDetails::sdsUrl('http://sds.precisionink.local/sheet/{E}', 9102));
        $this->assertSame('http://x/sheet/E6374/view', CodeDetails::sdsUrl('http://x/sheet/{E}/view', 6374));
        $details = new CodeDetails($this->db, $this->app->audit);
        $ids = $this->seedProducts();
        $this->assertNull($details->internal($ids['e1'])['sds_url']);
        $this->assertSame('https://sds.example.com/E6374', $details->internal($ids['e1v'], 'https://sds.example.com')['sds_url'], 'versions share the base E number');
    }

    public function testSourceIsShownInPlainWordsInLogsAndExports(): void
    {
        $this->assertSame('Entered by hand', CodeSearch::sourceLabel('manual'));
        $this->assertSame('Imported from the spreadsheets', CodeSearch::sourceLabel('import'));
        $this->assertSame('Assigned in Chroma', CodeSearch::sourceLabel('assigned'));
        $this->assertSame('', CodeSearch::sourceLabel(null));
        $this->codes->manualSell('Y1000', ['description' => 'Legacy yellow'], $this->adminId);
        $rows = $this->search->logExportRows('Y', []);
        $this->assertSame('Entered by hand', $rows[0]['source']);
    }

    public function testAllDigitQueryUsesTheNumberIndex(): void
    {
        $plan = $this->db->all('EXPLAIN SELECT s.id FROM chroma_sell_numbers s WHERE s.number = ?', [6374]);
        $this->assertSame('ix_sell_number', $plan[0]['key'], 'the all-digit probe must hit an index, not scan the table');
    }

    public function testHistoryOnInternalIncludesLinksOfItsSellNumbers(): void
    {
        $ids = $this->seedProducts();
        $this->codes->linkSellToInternal($ids['lonely'], $ids['e1'], $this->adminId);
        $details = new CodeDetails($this->db, $this->app->audit);
        $labels = array_map(fn($h) => $h['label'], $details->internal($ids['e1'])['history']);
        $this->assertContains('Link changed', $labels);
        $this->assertContains('Entered by hand', $labels);
        $this->assertSame('Link changed', $labels[0], 'newest first');
    }

    public function testShortWordsAreMatchedAsWholeWordsNotDropped(): void
    {
        $y = $this->prefixId('Y');
        $b = $this->prefixId('B');
        $uv = $this->suffixId('UV');
        $one = $this->codes->assignSell(['prefix_id' => $y, 'description' => 'Pantone 100 C test 1'], $this->adminId);
        $hundred = $this->codes->assignSell(['prefix_id' => $y, 'description' => 'Pantone 107 C test 100'], $this->adminId);
        $ten = $this->codes->assignSell(['prefix_id' => $b, 'description' => 'Pantone 163 C test 10'], $this->adminId);
        $yellowUv = $this->codes->assignSell(['prefix_id' => $y, 'suffix_id' => $uv, 'description' => 'Process Yellow UV'], $this->adminId);
        $this->codes->assignSell(['prefix_id' => $y, 'description' => 'Process Yellow UVXL base'], $this->adminId);

        $this->assertSame('+test*', CodeSearch::parse('test 10')['words'], 'a two-character word is not sent to the fulltext index');
        $this->assertSame(['10'], CodeSearch::parse('test 10')['short']);

        $res = $this->search->search('test 10');
        $this->assertSame(['S' . $ten['id']], array_map(fn($p) => $p['key'], $res['products']), '"test 10" finds "test 10" only, not "test 1" or "test 100"');

        $res = $this->search->search('yellow uv');
        $this->assertSame(['S' . $yellowUv['id']], array_map(fn($p) => $p['key'], $res['products']), '"uv" must appear as a whole word');

        // The per-prefix logs use the same rule.
        $log = $this->search->log('Y', ['q' => 'test 1']);
        $this->assertSame(['Y1715'], array_map(fn($r) => $r['display'], $log['rows']));
        $this->assertSame(1, $this->search->log('Y', ['q' => 'uv'])['total'], 'a query made only of short words still works in a log');
        $this->assertSame(1, $this->search->search('uv')['total'], 'a query made only of short words still works in the lookup');
        $this->assertSame((int) $one['id'], (int) $one['id']);
        $this->assertSame((int) $hundred['id'], (int) $hundred['id']);
    }

    public function testEverySpellingOfAnExistingSellNumberGivesTheSameResults(): void
    {
        $ids = $this->seedProducts();
        // A description that happens to contain the digits of the code would otherwise appear for "y-1000" but not "Y1000".
        $this->codes->manualSell('G1157', ['description' => 'Color match 1000 Pantone 231'], $this->adminId);
        $expected = ['E' . $ids['e1']];
        foreach (['Y1000', 'y-1000', 'Y 1000', 'y 1000'] as $q) {
            $this->assertSame($expected, array_map(fn($p) => $p['key'], $this->search->search($q)['products']), "query $q");
        }
        // Free text that is not an existing code still searches descriptions.
        $this->assertSame(1, $this->search->search('match 1000')['total']);
        $this->assertSame(0, $this->search->search('Y9999')['total']);
    }

    public function testVoidedAndPrefixFiltersApplyToTheAliasesListed(): void
    {
        $ids = $this->seedProducts();
        $this->codes->voidSell($ids['y1000uv'], 'Typo', $this->adminId);

        $res = $this->search->search('Y1000', 1, 25, ['show' => 'voided']);
        $this->assertSame(1, $res['total']);
        $this->assertSame(['Y1000UV'], array_map(fn($s) => $s['display'], $res['products'][0]['sells']), 'voided only lists the voided alias, not its live siblings');
        $rows = $this->search->exportRows('Y1000', ['show' => 'voided']);
        $this->assertSame(['Voided'], array_column($rows, 'voided'));

        $res = $this->search->search('Y1000', 1, 25, ['show' => 'active']);
        $this->assertSame(['Y1000', 'R2156'], array_map(fn($s) => $s['display'], $res['products'][0]['sells']));

        // A prefix filter must not pull in an E number whose description matches but whose sell numbers are under other prefixes.
        $t = $this->prefixId('T');
        $e = $this->codes->assignInternal(['description' => 'Trace blue'], $this->adminId);
        $this->codes->assignSell(['prefix_id' => $t, 'description' => 'Trace blue tint', 'internal_code_id' => $e['id']], $this->adminId);
        $bk = $this->prefixId('BK');
        $e2 = $this->codes->assignInternal(['description' => 'Trace black'], $this->adminId);
        $this->codes->assignSell(['prefix_id' => $bk, 'description' => 'Trace black', 'internal_code_id' => $e2['id']], $this->adminId);
        $res = $this->search->search('Trace', 1, 25, ['prefix' => 'BK']);
        $this->assertSame(['E' . $e2['id']], array_map(fn($p) => $p['key'], $res['products']));
    }

    public function testExportNamesTheProjectPerSellNumberAndHidesConfidentialOnes(): void
    {
        $ids = $this->seedProducts();
        $typeId = (int) $this->db->value('SELECT id FROM chroma_project_types ORDER BY id LIMIT 1');
        $now = $this->db->now();
        $pid = $this->db->insert('chroma_projects', ['project_number' => 110, 'project_type_id' => $typeId, 'title' => 'Secret', 'confidential' => 1, 'status' => 'open', 'date_submitted' => '2026-10-01', 'created_by' => $this->adminId, 'created_at' => $now, 'updated_at' => $now]);
        $this->db->insert('chroma_project_items', ['project_id' => $pid, 'sequence' => 1, 'kind' => 'color', 'description' => 'Pantone 663', 'internal_code_id' => $ids['e1'], 'sell_number_id' => $ids['y1000'], 'created_at' => $now, 'updated_at' => $now]);

        $rows = $this->search->exportRows('Y1000');
        $byCode = array_column($rows, 'project', 'sell_number');
        $this->assertSame('Project 110', $byCode['Y1000']);
        $this->assertSame('', $byCode['Y1000UV'], 'an alias that was not assigned on the project does not claim it');
        $this->assertSame('', $byCode['R2156']);

        $rows = $this->search->exportRows('Y1000', [], fn(array $o) => (int) $o['confidential'] !== 1);
        $this->assertSame('Confidential project', array_column($rows, 'project', 'sell_number')['Y1000'], 'the number of a confidential project is not written out');
        $this->assertStringNotContainsString('110', implode(' ', array_column($rows, 'project')));

        // The E number's own row (no sell numbers) uses the E number's origin.
        $orphan = $this->codes->assignInternal(['description' => 'Orphan formula'], $this->adminId);
        $this->db->insert('chroma_project_items', ['project_id' => $pid, 'sequence' => 2, 'kind' => 'color', 'description' => 'Orphan', 'internal_code_id' => $orphan['id'], 'created_at' => $now, 'updated_at' => $now]);
        $rows = $this->search->exportRows('Orphan');
        $this->assertSame('Project 110', $rows[0]['project']);
    }
}
