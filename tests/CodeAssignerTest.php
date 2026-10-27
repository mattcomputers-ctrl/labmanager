<?php
declare(strict_types=1);

namespace Chroma\Tests;

use Chroma\Core\Db;
use Chroma\Core\ValidationException;
use Chroma\Domain\Audit\AuditLog;
use Chroma\Domain\Codes\CodeAssigner;
use Chroma\Domain\Codes\CodeDetails;

final class CodeAssignerTest extends TestCase
{
    private CodeAssigner $codes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->codes = new CodeAssigner($this->db, $this->app->audit);
    }

    public function testFirstInternalCodeIsE9102ThenSequential(): void
    {
        $a = $this->codes->assignInternal(['description' => 'Blister purple'], $this->adminId);
        $b = $this->codes->assignInternal(['description' => 'Blister purple v2'], $this->adminId);
        $this->assertSame('E9102', $a['display']);
        $this->assertSame('E9103', $b['display']);
        $this->assertSame(9104, $this->codes->previewInternal());
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM chroma_audit_log WHERE action = "code.assign_internal" AND row_id = ?', [$a['id']]));
    }

    public function testSellNumbersPerPrefixSequenceAndSuffixSharesCounter(): void
    {
        $t = $this->prefixId('T');
        $uv = $this->suffixId('UV');
        $a = $this->codes->assignSell(['prefix_id' => $t, 'description' => 'Tint 1'], $this->adminId);
        $b = $this->codes->assignSell(['prefix_id' => $t, 'suffix_id' => $uv, 'description' => 'Tint 2 UV'], $this->adminId);
        $c = $this->codes->assignSell(['prefix_id' => $t, 'suffix_id' => $uv, 'description' => 'Tint 3 UV'], $this->adminId);
        $d = $this->codes->assignSell(['prefix_id' => $t, 'description' => 'Tint 4'], $this->adminId);
        $this->assertSame(['T2450', 'T2451UV', 'T2452UV', 'T2453'], [$a['display'], $b['display'], $c['display'], $d['display']]);
        $this->assertSame('T2451UV', $b['sell_number_normalized']);
        // Another prefix is untouched
        $y = $this->codes->assignSell(['prefix_id' => $this->prefixId('Y'), 'description' => 'Yellow'], $this->adminId);
        $this->assertSame('Y1715', $y['display']);
        $x = $this->codes->assignSell(['prefix_id' => $this->prefixId('X'), 'description' => 'Security'], $this->adminId);
        $this->assertSame('X104044', $x['display']);
    }

    public function testTwoHundredSequentialAssignmentsAreUnique(): void
    {
        $t = $this->prefixId('T');
        $seen = [];
        for ($i = 0; $i < 200; $i++) {
            $row = $this->codes->assignSell(['prefix_id' => $t, 'description' => "Tint $i", 'suffix_id' => $i % 3 === 0 ? $this->suffixId('UV') : null], $this->adminId);
            $this->assertArrayNotHasKey($row['number'], $seen, 'duplicate number ' . $row['number']);
            $seen[$row['number']] = true;
        }
        $this->assertSame(200, count($seen));
        $this->assertSame(2450 + 199, max(array_keys($seen)));
    }

    public function testRetiredPrefixRefused(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/retired/');
        $this->codes->assignSell(['prefix_id' => $this->prefixId('CS'), 'description' => 'x'], $this->adminId);
    }

    public function testSuffixNotAllowedForPrefixRefused(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/does not allow the UV suffix/');
        $this->codes->assignSell(['prefix_id' => $this->prefixId('X'), 'suffix_id' => $this->suffixId('UV'), 'description' => 'x'], $this->adminId);
    }

    public function testDescriptionRequired(): void
    {
        $this->expectException(ValidationException::class);
        $this->codes->assignSell(['prefix_id' => $this->prefixId('Y'), 'description' => '  '], $this->adminId);
    }

    public function testAdminCanRaiseNextNumberButNeverBelowHighestUsed(): void
    {
        $y = $this->prefixId('Y');
        $this->codes->assignSell(['prefix_id' => $y, 'description' => 'a'], $this->adminId); // Y1715
        $this->codes->setPrefixNextNumber($y, 1800, $this->adminId);
        $row = $this->codes->assignSell(['prefix_id' => $y, 'description' => 'b'], $this->adminId);
        $this->assertSame('Y1800', $row['display']);
        try {
            $this->codes->setPrefixNextNumber($y, 1800, $this->adminId);
            $this->fail('expected refusal');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Y1800', $e->getMessage());
        }
        try {
            $this->codes->setPrefixNextNumber($y, 1500, $this->adminId);
            $this->fail('expected refusal');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('never reused', $e->getMessage());
        }
        // Lowering an accidental high value back down (but still past max) is fine.
        $this->codes->setPrefixNextNumber($y, 1801, $this->adminId);
        $this->assertSame(1801, $this->codes->previewSell($y));
    }

    public function testInternalCounterRules(): void
    {
        $this->codes->assignInternal(['description' => 'a'], $this->adminId); // E9102
        $this->codes->setInternalNext(9500, $this->adminId);
        $this->assertSame('E9500', $this->codes->assignInternal(['description' => 'b'], $this->adminId)['display']);
        $this->expectException(ValidationException::class);
        $this->codes->setInternalNext(9500, $this->adminId);
    }

    public function testImportedAndVoidedCodesAreLoggedWithTheirProvenanceAndCode(): void
    {
        $imported = $this->codes->manualSell('Y1001', ['description' => 'Legacy from the sheet'], $this->adminId, 'import');
        $byHand = $this->codes->manualSell('Y1002', ['description' => 'Typed in'], $this->adminId);
        $e = $this->codes->manualInternal('E1010', ['description' => 'Old formula'], $this->adminId, 'import');
        $this->assertSame('code.import_sell', $this->db->value('SELECT action FROM chroma_audit_log WHERE table_name = "chroma_sell_numbers" AND row_id = ?', [$imported['id']]));
        $this->assertSame('Imported from the spreadsheets: Y1001', $this->db->value('SELECT note FROM chroma_audit_log WHERE table_name = "chroma_sell_numbers" AND row_id = ?', [$imported['id']]));
        $this->assertSame('code.manual_sell', $this->db->value('SELECT action FROM chroma_audit_log WHERE table_name = "chroma_sell_numbers" AND row_id = ?', [$byHand['id']]));
        $this->assertSame('code.import_internal', $this->db->value('SELECT action FROM chroma_audit_log WHERE table_name = "chroma_internal_codes" AND row_id = ?', [$e['id']]));
        $this->assertSame('Imported', CodeDetails::ACTIONS['code.import_internal']);
        $this->assertSame('Filled in by the import', CodeDetails::historyRow(['action' => 'import.fill_internal'])['label']);

        // Voids name the code in the note, so a search of the audit log for the code finds its void as well as its assignment.
        $this->codes->voidSell((int) $byHand['id'], 'Typo', $this->adminId);
        $this->codes->voidInternal((int) $e['id'], 'Mistake', $this->adminId);
        $this->assertSame('Voided Y1002: Typo', $this->db->value('SELECT note FROM chroma_audit_log WHERE action = "code.void_sell" AND row_id = ?', [$byHand['id']]));
        $this->assertSame('Voided E1010: Mistake', $this->db->value('SELECT note FROM chroma_audit_log WHERE action = "code.void_internal" AND row_id = ?', [$e['id']]));
    }

    public function testManualEntryChecksUniquenessAndIsLogged(): void
    {
        $row = $this->codes->manualSell('y-1000', ['description' => 'Legacy yellow'], $this->adminId);
        $this->assertSame('Y1000', $row['display']);
        $this->assertSame('y-1000', $row['legacy_text']);
        $this->assertSame('manual', $row['source']);
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM chroma_audit_log WHERE action = "code.manual_sell" AND row_id = ?', [$row['id']]));
        try {
            $this->codes->manualSell('Y1000', ['description' => 'dup'], $this->adminId);
            $this->fail('expected duplicate refusal');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('already used', $e->getMessage());
        }
        // A manual entry above the counter moves the counter past it.
        $this->codes->manualSell('Y2000', ['description' => 'High'], $this->adminId);
        $this->assertSame('Y2001', $this->codes->assignSell(['prefix_id' => $this->prefixId('Y'), 'description' => 'next'], $this->adminId)['display']);

        $e = $this->codes->manualInternal('E6374-10', ['description' => 'Old formula v10'], $this->adminId);
        $this->assertSame('E6374-10', $e['display']);
        $this->assertSame('10', $e['version']);
        $this->expectException(ValidationException::class);
        $this->codes->manualInternal('E6374-10', ['description' => 'dup'], $this->adminId);
    }

    public function testManualInternalDoesNotDisturbSequenceBelowCounter(): void
    {
        $this->codes->manualInternal('E5000', ['description' => 'legacy'], $this->adminId);
        $this->assertSame('E9102', $this->codes->assignInternal(['description' => 'new'], $this->adminId)['display']);
    }

    public function testVoidKeepsNumberAndNeverReuses(): void
    {
        $y = $this->prefixId('Y');
        $row = $this->codes->assignSell(['prefix_id' => $y, 'description' => 'oops'], $this->adminId);
        $this->codes->voidSell((int) $row['id'], 'Typed the wrong prefix', $this->adminId);
        $v = $this->codes->sellRow((int) $row['id']);
        $this->assertSame(1, (int) $v['voided']);
        $this->assertSame('Typed the wrong prefix', $v['void_reason']);
        $next = $this->codes->assignSell(['prefix_id' => $y, 'description' => 'again'], $this->adminId);
        $this->assertSame((int) $row['number'] + 1, (int) $next['number']);
        try {
            $this->codes->voidSell((int) $row['id'], 'twice', $this->adminId);
            $this->fail('expected refusal');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('already voided', $e->getMessage());
        }
        $this->expectException(ValidationException::class);
        $this->codes->voidSell((int) $next['id'], '   ', $this->adminId);
    }

    public function testLinkRules(): void
    {
        $e = $this->codes->assignInternal(['description' => 'formula'], $this->adminId);
        $s1 = $this->codes->assignSell(['prefix_id' => $this->prefixId('R'), 'description' => 'red', 'internal_code_id' => $e['id']], $this->adminId);
        $s2 = $this->codes->assignSell(['prefix_id' => $this->prefixId('R'), 'description' => 'red relabel', 'reason' => 'relabel'], $this->adminId);
        $this->codes->linkSellToInternal((int) $s2['id'], (int) $e['id'], $this->adminId);
        $linked = $this->db->column('SELECT id FROM chroma_sell_numbers WHERE internal_code_id = ? ORDER BY id', [$e['id']]);
        $this->assertSame([(int) $s1['id'], (int) $s2['id']], array_map('intval', $linked));
        $this->expectException(ValidationException::class);
        $this->codes->assignSell(['prefix_id' => $this->prefixId('R'), 'description' => 'bad link', 'internal_code_id' => 999999], $this->adminId);
    }

    public function testReasonOtherNeedsText(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/few words/');
        $this->codes->assignSell(['prefix_id' => $this->prefixId('R'), 'description' => 'x', 'reason' => 'other'], $this->adminId);
    }

    public function testAssignLinksProjectItemAndWritesActivity(): void
    {
        $now = $this->db->now();
        $typeId = (int) $this->db->value("SELECT id FROM chroma_project_types WHERE code = 'color_match'");
        $pid = $this->db->insert('chroma_projects', ['project_number' => 101, 'project_type_id' => $typeId, 'title' => 'Test', 'status' => 'open', 'created_at' => $now, 'updated_at' => $now]);
        $iid = $this->db->insert('chroma_project_items', ['project_id' => $pid, 'sequence' => 1, 'kind' => 'color', 'description' => 'Pantone 663', 'created_at' => $now, 'updated_at' => $now]);
        $row = $this->codes->assignSell(['prefix_id' => $this->prefixId('P'), 'description' => 'Pantone 663', 'project_item_id' => $iid], $this->adminId);
        $this->assertSame((int) $row['id'], (int) $this->db->value('SELECT sell_number_id FROM chroma_project_items WHERE id = ?', [$iid]));
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM chroma_project_activity WHERE project_id = ? AND kind = "code_assigned"', [$pid]));
    }

    public function testAnItemCannotReceiveASecondLiveCodeAndNoNumberIsBurned(): void
    {
        $now = $this->db->now();
        $typeId = (int) $this->db->value("SELECT id FROM chroma_project_types WHERE code = 'color_match'");
        $pid = $this->db->insert('chroma_projects', ['project_number' => 102, 'project_type_id' => $typeId, 'title' => 'Test', 'status' => 'open', 'created_at' => $now, 'updated_at' => $now]);
        $iid = $this->db->insert('chroma_project_items', ['project_id' => $pid, 'sequence' => 1, 'kind' => 'color', 'description' => 'Pantone 109', 'created_at' => $now, 'updated_at' => $now]);
        $y = $this->prefixId('Y');
        $first = $this->codes->assignSell(['prefix_id' => $y, 'description' => 'Pantone 109', 'project_item_id' => $iid], $this->adminId);
        try {
            $this->codes->assignSell(['prefix_id' => $y, 'description' => 'again', 'project_item_id' => $iid], $this->adminId);
            $this->fail('expected refusal');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($first['display'], $e->getMessage());
        }
        // The refused assignment rolled back: no second row, counter unchanged, item still points at the first code.
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM chroma_sell_numbers WHERE prefix_id = ?', [$y]));
        $this->assertSame((int) $first['number'] + 1, $this->codes->previewSell($y));
        $this->assertSame((int) $first['id'], (int) $this->db->value('SELECT sell_number_id FROM chroma_project_items WHERE id = ?', [$iid]));
        // After voiding the first code a new one may be assigned to the item.
        $this->codes->voidSell((int) $first['id'], 'wrong prefix', $this->adminId);
        $second = $this->codes->assignSell(['prefix_id' => $y, 'description' => 'Pantone 109', 'project_item_id' => $iid], $this->adminId);
        $this->assertSame((int) $second['id'], (int) $this->db->value('SELECT sell_number_id FROM chroma_project_items WHERE id = ?', [$iid]));
    }

    public function testLinkWithZeroClearsTheLinkInsteadOfFailing(): void
    {
        $e = $this->codes->assignInternal(['description' => 'formula'], $this->adminId);
        $s = $this->codes->assignSell(['prefix_id' => $this->prefixId('R'), 'description' => 'red', 'internal_code_id' => $e['id']], $this->adminId);
        $this->codes->linkSellToInternal((int) $s['id'], 0, $this->adminId);
        $this->assertNull($this->db->value('SELECT internal_code_id FROM chroma_sell_numbers WHERE id = ?', [$s['id']]));
    }

    public function testCountersRefuseAbsurdValues(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/too big/');
        $this->codes->setInternalNext(5000000000, $this->adminId);
    }

    /**
     * Real concurrency: fork N processes that each open their own connection
     * and assign K sell numbers under the same prefix at the same time.
     * Every process must get different numbers.
     */
    public function testParallelProcessesNeverGetTheSameNumber(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl not available');
        }
        $prefixId = $this->prefixId('B');
        $uvId = $this->suffixId('UV');
        $adminId = $this->adminId;
        $procs = 8;
        $each = 15;
        $pids = [];
        for ($p = 0; $p < $procs; $p++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('fork failed');
            }
            if ($pid === 0) {
                // Child: brand-new connection, so no shared PDO handle.
                $db = $this->db->fresh();
                $codes = new CodeAssigner($db, new AuditLog($db, $this->app->auth));
                $ok = 0;
                for ($i = 0; $i < $each; $i++) {
                    try {
                        $codes->assignSell(['prefix_id' => $prefixId, 'description' => "proc $p item $i", 'suffix_id' => $i % 2 ? $uvId : null], $adminId);
                        $ok++;
                    } catch (\Throwable $e) {
                        fwrite(STDERR, "child $p: " . $e->getMessage() . "\n");
                    }
                }
                exit($ok === $each ? 0 : 1);
            }
            $pids[] = $pid;
        }
        $failures = 0;
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            if (pcntl_wexitstatus($status) !== 0) {
                $failures++;
            }
        }
        // Children closed their copy of the inherited socket; reconnect the parent.
        $this->db->reconnect();
        $db = $this->db;
        $this->assertSame(0, $failures, 'some child processes failed to assign');
        $total = (int) $db->value('SELECT COUNT(*) FROM chroma_sell_numbers WHERE prefix_id = ?', [$prefixId]);
        $distinct = (int) $db->value('SELECT COUNT(DISTINCT number) FROM chroma_sell_numbers WHERE prefix_id = ?', [$prefixId]);
        $this->assertSame($procs * $each, $total);
        $this->assertSame($total, $distinct, 'duplicate sell numbers were handed out');
        $this->assertSame(2971 + $total - 1, (int) $db->value('SELECT MAX(number) FROM chroma_sell_numbers WHERE prefix_id = ?', [$prefixId]));
        $this->assertSame(2971 + $total, (int) $db->value('SELECT next_number FROM chroma_code_prefixes WHERE id = ?', [$prefixId]));
    }

    public function testParallelInternalAssignments(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl not available');
        }
        $procs = 6;
        $each = 10;
        $adminId = $this->adminId;
        $pids = [];
        for ($p = 0; $p < $procs; $p++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $db = $this->db->fresh();
                $codes = new CodeAssigner($db, new AuditLog($db, $this->app->auth));
                $ok = 0;
                for ($i = 0; $i < $each; $i++) {
                    try {
                        $codes->assignInternal(['description' => "proc $p item $i"], $adminId);
                        $ok++;
                    } catch (\Throwable $e) {
                        fwrite(STDERR, "child $p: " . $e->getMessage() . "\n");
                    }
                }
                exit($ok === $each ? 0 : 1);
            }
            $pids[] = $pid;
        }
        $failures = 0;
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $failures += pcntl_wexitstatus($status) === 0 ? 0 : 1;
        }
        $this->db->reconnect();
        $db = $this->db;
        $this->assertSame(0, $failures);
        $this->assertSame($procs * $each, (int) $db->value('SELECT COUNT(DISTINCT number) FROM chroma_internal_codes'));
        $this->assertSame(9102 + $procs * $each - 1, (int) $db->value('SELECT MAX(number) FROM chroma_internal_codes'));
    }
}
