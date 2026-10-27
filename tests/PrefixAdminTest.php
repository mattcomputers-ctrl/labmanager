<?php
declare(strict_types=1);

namespace Chroma\Tests;

use Chroma\Core\ValidationException;
use Chroma\Domain\Codes\CodeAssigner;
use Chroma\Domain\Codes\PrefixAdmin;

/** Admin rules for prefixes, suffixes and the shared sequences (module A). */
final class PrefixAdminTest extends TestCase
{
    private PrefixAdmin $admin;
    private CodeAssigner $codes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = new PrefixAdmin($this->db, $this->app->audit);
        $this->codes = new CodeAssigner($this->db, $this->app->audit);
    }

    private function auditCount(string $action, ?int $rowId = null): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM chroma_audit_log WHERE action = ?' . ($rowId !== null ? ' AND row_id = ' . $rowId : ''), [$action]);
    }

    // ------------------------------------------------------------------ prefixes

    public function testAddPrefixNormalizesCodeAndSeedsCounterAndSuffixes(): void
    {
        $uv = $this->suffixId('UV');
        $id = $this->admin->createPrefix([
            'code' => ' zq ', 'label' => 'Test family', 'description' => 'For tests.', 'min_digits' => 4, 'starting_number' => 1000, 'suffix_ids' => [$uv],
        ], $this->adminId);
        $p = $this->admin->prefix($id);
        $this->assertSame('ZQ', $p['code']);
        $this->assertSame(0, $p['retired']);
        $this->assertSame([$uv], $p['suffix_ids']);
        $this->assertSame('ZQ1000', $p['next_display']);
        $this->assertSame('', $p['last_used_display']);
        $this->assertSame(1, $this->auditCount('prefix.create', $id));

        // It is immediately assignable, with and without the allowed suffix, from one counter.
        $a = $this->codes->assignSell(['prefix_id' => $id, 'description' => 'first'], $this->adminId);
        $b = $this->codes->assignSell(['prefix_id' => $id, 'suffix_id' => $uv, 'description' => 'second'], $this->adminId);
        $this->assertSame(['ZQ1000', 'ZQ1001UV'], [$a['display'], $b['display']]);
        $this->assertSame('ZQ1001', $this->admin->prefix($id)['last_used_display']); // last used shows the number; the suffix belongs to the code, not the counter
    }

    public function testDuplicateCodeIsRefusedEvenInDifferentCase(): void
    {
        try {
            $this->admin->createPrefix(['code' => 'bk', 'label' => 'Another black', 'min_digits' => 4, 'starting_number' => 1], $this->adminId);
            $this->fail('expected refusal');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('code', $e->errors);
            $this->assertStringContainsString('BK already exists', $e->errors['code']);
        }
        // A retired duplicate says to un-retire instead.
        try {
            $this->admin->createPrefix(['code' => 'A', 'label' => 'A again', 'min_digits' => 4, 'starting_number' => 1], $this->adminId);
            $this->fail('expected refusal');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('retired', $e->errors['code']);
        }
        $this->assertSame(0, $this->auditCount('prefix.create'));
    }

    public function testCodeMustBeLettersOnlyAndShort(): void
    {
        foreach (['Y1', 'B-K', 'A B', '', 'ABCDEFGHIJKLM'] as $bad) {
            try {
                $this->admin->createPrefix(['code' => $bad, 'label' => 'x', 'min_digits' => 4, 'starting_number' => 1], $this->adminId);
                $this->fail("expected refusal for '$bad'");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('code', $e->errors, $bad);
            }
        }
        $this->assertNull($this->admin->codeProblem('ABCDEFGHIJKL'));
        $this->assertNotNull($this->admin->codeProblem('Y'));
    }

    public function testOtherFieldsAreValidatedWithFieldKeys(): void
    {
        try {
            $this->admin->createPrefix(['code' => 'ZZ', 'label' => '', 'min_digits' => 0, 'starting_number' => 'abc', 'suffix_ids' => [999999]], $this->adminId);
            $this->fail('expected refusal');
        } catch (ValidationException $e) {
            $this->assertSame(['label', 'min_digits', 'starting_number', 'suffix_ids'], array_keys($e->errors));
        }
    }

    public function testRetireAndUnretireAreLoggedAndAffectAssignmentOnly(): void
    {
        $y = $this->prefixId('Y');
        $before = $this->codes->assignSell(['prefix_id' => $y, 'description' => 'Yellow one'], $this->adminId);

        $this->admin->retirePrefix($y, $this->adminId);
        $p = $this->admin->prefix($y);
        $this->assertSame(1, $p['retired']);
        $this->assertNotNull($p['retired_at']);
        $this->assertSame($this->adminId, (int) $p['retired_by']);
        $this->assertSame(1, $this->auditCount('prefix.retire', $y));
        // Existing code untouched; new assignment refused.
        $this->assertSame($before['display'], $this->codes->sellRow((int) $before['id'])['display']);
        $this->assertSame(0, (int) $this->db->value('SELECT voided FROM chroma_sell_numbers WHERE id = ?', [$before['id']]));
        try {
            $this->codes->assignSell(['prefix_id' => $y, 'description' => 'nope'], $this->adminId);
            $this->fail('expected refusal');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('retired', $e->getMessage());
        }
        // Retiring twice is refused in plain words.
        try {
            $this->admin->retirePrefix($y, $this->adminId);
            $this->fail('expected refusal');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('already retired', $e->getMessage());
        }

        $this->admin->unretirePrefix($y, $this->adminId);
        $p = $this->admin->prefix($y);
        $this->assertSame(0, $p['retired']);
        $this->assertNull($p['retired_at']);
        $this->assertSame(1, $this->auditCount('prefix.unretire', $y));
        $after = $this->codes->assignSell(['prefix_id' => $y, 'description' => 'Yellow two'], $this->adminId);
        $this->assertSame((int) $before['number'] + 1, (int) $after['number']);

        // Inherited and legacy prefixes can be retired too.
        $wbc = $this->prefixId('WBC');
        $this->admin->retirePrefix($wbc, $this->adminId);
        $this->assertSame(1, $this->admin->prefix($wbc)['retired']);
        $this->admin->unretirePrefix($wbc, $this->adminId);
        $this->assertSame(0, $this->admin->prefix($wbc)['retired']);
    }

    public function testRetiredPrefixesSortAfterActiveOnes(): void
    {
        $y = $this->prefixId('Y');
        $this->admin->retirePrefix($y, $this->adminId);
        $rows = $this->admin->listPrefixes([], 100, 0)['rows'];
        $codes = array_column($rows, 'code');
        $retiredFlags = array_column($rows, 'retired');
        $firstRetired = array_search(1, $retiredFlags, true);
        $this->assertNotFalse($firstRetired);
        $this->assertSame([], array_filter(array_slice($retiredFlags, $firstRetired), fn($f) => $f === 0), 'no active prefix after the first retired one');
        $this->assertContains('Y', array_slice($codes, $firstRetired));
        $this->assertSame(1, $this->admin->listPrefixes(['q' => 'Yell', 'status' => 'retired'], 100, 0)['total']);
        $this->assertSame(0, $this->admin->listPrefixes(['q' => 'Yell', 'status' => 'active'], 100, 0)['total']);
    }

    public function testAllowedSuffixesControlCodeAssigner(): void
    {
        $y = $this->prefixId('Y');
        $uv = $this->suffixId('UV');
        $nd = $this->suffixId('ND');
        // ND is not allowed on Y by default.
        try {
            $this->codes->assignSell(['prefix_id' => $y, 'suffix_id' => $nd, 'description' => 'x'], $this->adminId);
            $this->fail('expected refusal');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('does not allow the ND suffix', $e->errors['suffix_id']);
        }
        // Allow ND, disallow UV.
        $this->admin->updatePrefix($y, ['suffix_ids' => [$nd]], $this->adminId);
        $this->assertSame([$nd], $this->admin->prefix($y)['suffix_ids']);
        $this->assertSame(1, $this->auditCount('prefix.update', $y));
        $ok = $this->codes->assignSell(['prefix_id' => $y, 'suffix_id' => $nd, 'description' => 'now fine'], $this->adminId);
        $this->assertStringEndsWith('ND', $ok['display']);
        try {
            $this->codes->assignSell(['prefix_id' => $y, 'suffix_id' => $uv, 'description' => 'x'], $this->adminId);
            $this->fail('expected refusal');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('suffix_id', $e->errors);
        }
        // No suffix is always allowed.
        $plain = $this->codes->assignSell(['prefix_id' => $y, 'description' => 'plain'], $this->adminId);
        $this->assertSame('Y' . $plain['number'], $plain['sell_number_normalized']);
        // Untick everything: no suffix at all.
        $this->admin->updatePrefix($y, ['suffix_ids' => []], $this->adminId);
        $this->assertSame([], $this->admin->prefix($y)['suffix_ids']);
    }

    public function testRenamingAPrefixWithCodesIsRefusedButDetailsCanChange(): void
    {
        $t = $this->prefixId('T');
        $this->codes->assignSell(['prefix_id' => $t, 'description' => 'tint'], $this->adminId);
        try {
            $this->admin->updatePrefix($t, ['code' => 'TT', 'label' => 'Tint'], $this->adminId);
            $this->fail('expected refusal');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('cannot be renamed', $e->errors['code']);
            $this->assertStringContainsString('product codes', $e->errors['code']);
        }
        $this->assertSame('T', $this->admin->prefix($t)['code']);
        // Same code, new label and digits: fine and audited with before/after.
        $this->admin->updatePrefix($t, ['code' => 't', 'label' => 'Tints', 'description' => 'Tint colors.', 'min_digits' => 5, 'sort_order' => 111], $this->adminId);
        $p = $this->admin->prefix($t);
        $this->assertSame(['T', 'Tints', 'Tint colors.', 5, 111], [$p['code'], $p['label'], $p['description'], (int) $p['min_digits'], (int) $p['sort_order']]);
        $this->assertStringStartsWith('T0', $p['next_display']);
        $log = $this->db->one('SELECT * FROM chroma_audit_log WHERE action = "prefix.update" AND row_id = ? ORDER BY id DESC', [$t]);
        $this->assertSame('Tint', json_decode((string) $log['before_json'], true)['label']);
        $this->assertSame('Tints', json_decode((string) $log['after_json'], true)['label']);
        // A prefix with no codes can be renamed.
        $w = $this->prefixId('W');
        $this->admin->updatePrefix($w, ['code' => 'wh'], $this->adminId);
        $this->assertSame('WH', $this->admin->prefix($w)['code']);
        // ...but not to an existing code.
        try {
            $this->admin->updatePrefix($w, ['code' => 'Y'], $this->adminId);
            $this->fail('expected refusal');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('code', $e->errors);
        }
    }

    public function testNextNumberIsForwardOnlyAndAudited(): void
    {
        $b = $this->prefixId('B');
        $row = $this->codes->assignSell(['prefix_id' => $b, 'description' => 'blue'], $this->adminId);
        $max = (int) $row['number'];
        foreach ([$max, $max - 5, 0, 'abc', '', '12.5'] as $bad) {
            try {
                $this->admin->setNextNumber($b, $bad, $this->adminId);
                $this->fail("expected refusal for " . var_export($bad, true));
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('next_number', $e->errors);
            }
        }
        $this->admin->setNextNumber($b, $max + 100, $this->adminId);
        $this->assertSame($max + 100, $this->admin->prefix($b)['next_number_effective']);
        $this->assertSame(1, $this->auditCount('prefix.set_next', $b));
        $next = $this->codes->assignSell(['prefix_id' => $b, 'description' => 'skipped ahead'], $this->adminId);
        $this->assertSame($max + 100, (int) $next['number']);
        // Setting the same value again is a no-op, not an extra log row.
        $this->admin->setNextNumber($b, $max + 101, $this->adminId);
        $this->assertSame(1, $this->auditCount('prefix.set_next', $b));
    }

    // ------------------------------------------------------------------ suffixes

    public function testSuffixCreateEditAndDeactivateRules(): void
    {
        $id = $this->admin->createSuffix(['code' => 'xl ', 'label' => 'Extra long'], $this->adminId);
        $s = $this->admin->suffix($id);
        $this->assertSame(['XL', 1], [$s['code'], $s['active']]);
        $this->assertSame(1, $this->auditCount('suffix.create', $id));
        try {
            $this->admin->createSuffix(['code' => 'uv', 'label' => 'dup'], $this->adminId);
            $this->fail('expected refusal');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('UV already exists', $e->errors['code']);
        }
        try {
            $this->admin->createSuffix(['code' => 'U2', 'label' => 'bad'], $this->adminId);
            $this->fail('expected refusal');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('code', $e->errors);
        }

        // Deactivating UV: existing codes untouched, new codes refused, prefixes still list it as allowed.
        $y = $this->prefixId('Y');
        $uv = $this->suffixId('UV');
        $existing = $this->codes->assignSell(['prefix_id' => $y, 'suffix_id' => $uv, 'description' => 'before'], $this->adminId);
        $this->admin->setSuffixActive($uv, false, $this->adminId);
        $this->assertSame(0, $this->admin->suffix($uv)['active']);
        $this->assertSame(1, $this->auditCount('suffix.deactivate', $uv));
        $this->assertSame($existing['display'], $this->codes->sellRow((int) $existing['id'])['display']);
        try {
            $this->codes->assignSell(['prefix_id' => $y, 'suffix_id' => $uv, 'description' => 'after'], $this->adminId);
            $this->fail('expected refusal');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('switched off', $e->errors['suffix_id']);
        }
        $this->assertContains($uv, $this->admin->prefix($y)['suffix_ids']);
        $this->admin->setSuffixActive($uv, true, $this->adminId);
        $this->assertSame(1, $this->auditCount('suffix.activate', $uv));
        $this->codes->assignSell(['prefix_id' => $y, 'suffix_id' => $uv, 'description' => 'again'], $this->adminId);

        // Renaming a suffix that is in use is refused.
        try {
            $this->admin->updateSuffix($uv, ['code' => 'UVX'], $this->adminId);
            $this->fail('expected refusal');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('cannot be renamed', $e->errors['code']);
        }
        $this->admin->updateSuffix($id, ['code' => 'XXL', 'label' => 'Extra extra long', 'sort_order' => 5], $this->adminId);
        $this->assertSame('XXL', $this->admin->suffix($id)['code']);
        $this->assertSame(1, $this->auditCount('suffix.update', $id));

        $list = $this->admin->listSuffixes([], 100, 0);
        $uvRow = array_values(array_filter($list['rows'], fn($r) => $r['code'] === 'UV'))[0];
        $this->assertContains('Y', array_column($uvRow['prefixes'], 'code'));
        $this->assertSame(2, $uvRow['code_count']);
    }

    public function testEditingAPrefixKeepsLinksToSwitchedOffSuffixes(): void
    {
        $y = $this->prefixId('Y');
        $uv = $this->suffixId('UV');
        $nd = $this->suffixId('ND');
        $this->assertSame([$uv], $this->admin->prefix($y)['suffix_ids']);
        $this->admin->setSuffixActive($uv, false, $this->adminId);

        // The edit form only offers switched-on suffixes, so an unrelated edit posts a set without UV.
        $this->admin->updatePrefix($y, ['label' => 'Yellow', 'description' => 'Changed only the description.', 'suffix_ids' => []], $this->adminId);
        $this->assertSame([$uv], $this->admin->prefix($y)['suffix_ids'], 'the link to the switched-off suffix survives');
        $log = $this->db->one('SELECT note FROM chroma_audit_log WHERE action = "prefix.update" AND row_id = ? ORDER BY id DESC', [$y]);
        $this->assertStringNotContainsString('allowed suffixes', (string) $log['note']);

        // Ticking another suffix adds it next to the kept one.
        $this->admin->updatePrefix($y, ['suffix_ids' => [$nd]], $this->adminId);
        $this->assertSame([$uv, $nd], $this->admin->prefix($y)['suffix_ids']);

        // Once UV is switched on again it is allowed straight away, and can then be unticked for real.
        $this->admin->setSuffixActive($uv, true, $this->adminId);
        $this->codes->assignSell(['prefix_id' => $y, 'suffix_id' => $uv, 'description' => 'uv again'], $this->adminId);
        $this->admin->updatePrefix($y, ['suffix_ids' => [$nd]], $this->adminId);
        $this->assertSame([$nd], $this->admin->prefix($y)['suffix_ids']);
    }

    public function testWizardFormatStepUsesTheSameWordsAsTheExpertForm(): void
    {
        $w = new \Chroma\Http\Wizard\AddPrefixWizard($this->app);
        $steps = $w->steps(['min_digits' => '4', 'starting_number' => '1']);
        $format = $steps[2];
        try {
            $this->admin->createPrefix(['code' => 'ZF', 'label' => 'x', 'min_digits' => '0', 'starting_number' => '0'], $this->adminId);
            $this->fail('expected refusal');
        } catch (ValidationException $expert) {
        }
        try {
            $w->validateStep($format, ['min_digits' => '0', 'starting_number' => '0'], []);
            $this->fail('expected refusal');
        } catch (ValidationException $easy) {
        }
        $this->assertSame($expert->errors['min_digits'], $easy->errors['min_digits']);
        $this->assertSame($expert->errors['starting_number'], $easy->errors['starting_number']);
        $this->assertSame(['min_digits' => '6', 'starting_number' => '104000'], $w->validateStep($format, ['min_digits' => ' 6 ', 'starting_number' => '104000'], []));
    }

    // ------------------------------------------------------------------ sequences

    public function testProjectSequenceIsForwardOnlyAndAudited(): void
    {
        $status = $this->admin->sequenceStatus();
        $this->assertSame(101, $status['project']['next']);
        $this->assertSame(0, $status['project']['highest']);
        $typeId = (int) $this->db->value('SELECT id FROM chroma_project_types ORDER BY id LIMIT 1');
        $now = $this->db->now();
        $this->db->insert('chroma_projects', ['project_number' => 150, 'project_type_id' => $typeId, 'title' => 'x', 'status' => 'open', 'created_at' => $now, 'updated_at' => $now]);
        $this->assertSame(151, $this->admin->sequenceStatus()['project']['next']);
        foreach ([150, 120, 0, 'x', ''] as $bad) {
            try {
                $this->admin->setProjectNext($bad, $this->adminId);
                $this->fail('expected refusal for ' . var_export($bad, true));
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('project_next', $e->errors);
            }
        }
        $this->admin->setProjectNext(200, $this->adminId);
        $this->assertSame(200, (int) $this->db->value("SELECT next_value FROM chroma_sequences WHERE name = 'project_number'"));
        $this->assertSame(200, $this->admin->sequenceStatus()['project']['next']);
        $this->assertSame(1, $this->auditCount('sequence.set_project'));
        $this->admin->setProjectNext(200, $this->adminId); // no-op
        $this->assertSame(1, $this->auditCount('sequence.set_project'));
    }

    public function testInternalSequenceIsForwardOnlyAndAudited(): void
    {
        $e = $this->codes->assignInternal(['description' => 'formula'], $this->adminId);
        $this->assertSame('E9102', $e['display']);
        $s = $this->admin->sequenceStatus()['internal'];
        $this->assertSame(['E9102', 'E9103'], [$s['highest_display'], $s['next_display']]);
        foreach ([9102, 9000, 'E9200', ''] as $bad) {
            try {
                $this->admin->setInternalNext($bad, $this->adminId);
                $this->fail('expected refusal for ' . var_export($bad, true));
            } catch (ValidationException $e2) {
                $this->assertArrayHasKey('next_value', $e2->errors);
            }
        }
        $this->admin->setInternalNext(9500, $this->adminId);
        $this->assertSame('E9500', $this->admin->sequenceStatus()['internal']['next_display']);
        $this->assertSame(1, $this->auditCount('sequence.set_internal'));
        $this->assertSame('E9500', $this->codes->assignInternal(['description' => 'next block'], $this->adminId)['display']);
    }

    // ------------------------------------------------------------------ Easy-mode wizard (same operation underneath)

    public function testAddPrefixWizardWalksThroughAndCommitsViaPrefixAdmin(): void
    {
        $w = new \Chroma\Http\Wizard\AddPrefixWizard($this->app);
        $this->assertSame('add-prefix', $w->key());
        $this->assertSame(['admin.prefixes'], $w->permissions());
        $state = $w->initialState(new \Chroma\Core\Request('GET', '/wizard/add-prefix', [], [], [], [], [], '127.0.0.1'));
        $steps = $w->steps($state);
        $this->assertSame(['code', 'label', 'format', 'suffixes'], array_map(fn($s) => $s->key, $steps));

        // Step 1 refuses a duplicate or malformed code before going on.
        foreach (['bk', 'Y1', ''] as $bad) {
            try {
                $w->validateStep($steps[0], ['code' => $bad], $state);
                $this->fail("expected refusal for '$bad'");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('code', $e->errors);
            }
        }
        $state = array_merge($state, $w->validateStep($steps[0], ['code' => 'zw'], $state));
        $this->assertSame('ZW', $state['code']);
        $state = array_merge($state, $w->validateStep($steps[1], ['label' => 'Wizard family', 'description' => 'From the wizard.'], $state));
        $state = array_merge($state, $w->validateStep($steps[2], ['min_digits' => '3', 'starting_number' => '20'], $state));
        $uv = $this->suffixId('UV');
        $state = array_merge($state, $w->validateStep($steps[3], ['suffix_' . $uv => '1', 'suffix_' . $this->suffixId('ND') => '0'], $state));

        $rows = $w->review($state);
        $byLabel = array_column($rows, 'value', 'label');
        $this->assertSame('ZW', $byLabel['Prefix letters']);
        $this->assertSame('ZW020', $byLabel['First number']);
        $this->assertSame('UV', $byLabel['Allowed suffixes']);
        $this->assertStringContainsString('/admin/prefixes/new?code=ZW', urldecode((string) $w->expertUrl($state)));

        $result = $w->commit($state);
        $this->assertSame('Prefix ZW added', $result->title);
        $this->assertSame('ZW020', $result->codes[0]['code']);
        $p = $this->admin->prefixByCode('ZW');
        $this->assertNotNull($p);
        $this->assertSame([$uv], $p['suffix_ids']);
        $this->assertSame(3, (int) $p['min_digits']);
        $this->assertSame(1, $this->auditCount('prefix.create', (int) $p['id']));
        // Committing the same state again is refused as a duplicate (nothing is double-created).
        try {
            $w->commit($state);
            $this->fail('expected refusal');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('code', $e->errors);
        }
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM chroma_code_prefixes WHERE code = 'ZW'"));
    }
}
