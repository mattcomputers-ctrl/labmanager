<?php
declare(strict_types=1);

namespace Chroma\Tests;

use Chroma\Core\Request;
use Chroma\Core\ValidationException;
use Chroma\Http\Wizard\LinkCodesWizard;
use Chroma\Http\Wizard\ManualEntryWizard;
use Chroma\Http\Wizard\QuickAssignWizard;
use Chroma\Http\Wizard\VoidCodeWizard;
use Chroma\Domain\Codes\CodeAssigner;
use Chroma\Domain\Codes\ManualEntry;
use Chroma\Domain\Codes\QuickAssign;

final class QuickAssignTest extends TestCase
{
    private CodeAssigner $codes;
    private QuickAssign $quick;

    protected function setUp(): void
    {
        parent::setUp();
        $this->codes = new CodeAssigner($this->db, $this->app->audit);
        $this->quick = new QuickAssign($this->db, $this->codes);
    }

    public function testAliasForExistingProductLinksToItsENumberAndDefaultsDescription(): void
    {
        $e = $this->codes->assignInternal(['description' => 'Blister purple'], $this->adminId);
        $y = $this->codes->assignSell(['prefix_id' => $this->prefixId('Y'), 'description' => 'Blister purple', 'internal_code_id' => $e['id']], $this->adminId);

        $product = $this->quick->product('S' . $y['id']);
        $this->assertSame((int) $e['id'], $product['internal_code_id']);
        $this->assertSame(['sell'], array_keys(QuickAssign::wantsFor($product)));

        $res = $this->quick->run([
            'product_mode' => 'existing', 'product' => 'S' . $y['id'], 'want' => 'sell',
            'prefix_id' => $this->prefixId('R'), 'description' => '', 'reason' => 'relabel',
        ], $this->adminId);
        $this->assertNull($res['internal']);
        $this->assertSame('R2324', $res['sell']['display']);
        $this->assertSame((int) $e['id'], (int) $res['sell']['internal_code_id']);
        $this->assertSame('Blister purple', $res['sell']['description'], 'description defaulted from the product');
        $this->assertSame('relabel', $res['sell']['reason']);
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM chroma_audit_log WHERE action = "code.assign_sell" AND row_id = ?', [$res['sell']['id']]));

        // Picking the E number itself works the same way, with a suffix.
        $res = $this->quick->run([
            'product_mode' => 'existing', 'product' => 'E' . $e['id'], 'want' => 'sell',
            'prefix_id' => $this->prefixId('Y'), 'suffix_id' => $this->suffixId('UV'), 'description' => 'Blister purple UV', 'reason' => 'new_version',
        ], $this->adminId);
        $this->assertSame('Y1716UV', $res['sell']['display']);
        $this->assertSame((int) $e['id'], (int) $res['sell']['internal_code_id']);
        $this->assertSame(3, (int) $this->db->value('SELECT COUNT(*) FROM chroma_sell_numbers WHERE internal_code_id = ?', [$e['id']]));
    }

    public function testAliasNeedsAReason(): void
    {
        $e = $this->codes->assignInternal(['description' => 'Blister purple'], $this->adminId);
        try {
            $this->quick->run(['product_mode' => 'existing', 'product' => 'E' . $e['id'], 'want' => 'sell', 'prefix_id' => $this->prefixId('R')], $this->adminId);
            $this->fail('expected refusal');
        } catch (ValidationException $ex) {
            $this->assertArrayHasKey('reason', $ex->errors);
        }
        try {
            $this->quick->run(['product_mode' => 'existing', 'product' => 'E' . $e['id'], 'want' => 'sell', 'prefix_id' => $this->prefixId('R'), 'reason' => 'other', 'reason_text' => ''], $this->adminId);
            $this->fail('expected refusal');
        } catch (ValidationException $ex) {
            $this->assertArrayHasKey('reason_text', $ex->errors);
        }
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM chroma_sell_numbers'));
    }

    public function testExistingProductWithENumberCannotGetAnother(): void
    {
        $e = $this->codes->assignInternal(['description' => 'Blister purple'], $this->adminId);
        try {
            $this->quick->run(['product_mode' => 'existing', 'product' => 'E' . $e['id'], 'want' => 'both', 'prefix_id' => $this->prefixId('R'), 'reason' => 'relabel'], $this->adminId);
            $this->fail('expected refusal');
        } catch (ValidationException $ex) {
            $this->assertStringContainsString('already has an E number', $ex->getMessage());
        }
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM chroma_internal_codes'));
    }

    public function testSellNumberWithoutENumberCanGetOneAndAnAliasInOneGo(): void
    {
        $t = $this->codes->assignSell(['prefix_id' => $this->prefixId('T'), 'description' => 'Lonely tint'], $this->adminId);
        $product = $this->quick->product('S' . $t['id']);
        $this->assertNull($product['internal_code_id']);
        $this->assertSame(['sell', 'internal', 'both'], array_keys(QuickAssign::wantsFor($product)));

        $res = $this->quick->run([
            'product_mode' => 'existing', 'product' => 'S' . $t['id'], 'want' => 'both',
            'prefix_id' => $this->prefixId('T'), 'suffix_id' => $this->suffixId('UV'), 'reason' => 'new_version',
        ], $this->adminId);
        $this->assertSame('E9102', $res['internal']['display']);
        $this->assertSame('T2451UV', $res['sell']['display']);
        $this->assertSame((int) $res['internal']['id'], (int) $res['sell']['internal_code_id']);
        $this->assertSame('T2450', $res['linked_sell']['display']);
        $this->assertSame((int) $res['internal']['id'], (int) $this->db->value('SELECT internal_code_id FROM chroma_sell_numbers WHERE id = ?', [$t['id']]), 'the existing sell number now points at the new E number');
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM chroma_audit_log WHERE action = "code.link" AND row_id = ?', [$t['id']]));
    }

    public function testBrandNewProductBothCodesInOneTransaction(): void
    {
        $res = $this->quick->run([
            'product_mode' => 'new', 'want' => 'both', 'prefix_id' => $this->prefixId('X'), 'description' => 'Security black', 'resale' => 1, 'vendor_name' => 'Vendor Co',
        ], $this->adminId);
        $this->assertSame('E9102', $res['internal']['display']);
        $this->assertSame('X104044', $res['sell']['display']);
        $this->assertSame((int) $res['internal']['id'], (int) $res['sell']['internal_code_id']);
        $this->assertSame(1, (int) $res['internal']['resale']);
        $this->assertSame('Vendor Co', $res['internal']['vendor_name']);

        // A failure on the sell number rolls the E number back too.
        try {
            $this->quick->run(['product_mode' => 'new', 'want' => 'both', 'prefix_id' => $this->prefixId('X'), 'suffix_id' => $this->suffixId('UV'), 'description' => 'Bad suffix'], $this->adminId);
            $this->fail('expected refusal');
        } catch (ValidationException $ex) {
            $this->assertStringContainsString('does not allow', $ex->getMessage());
        }
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM chroma_internal_codes'), 'no orphan E number after the rollback');
        $this->assertSame(9103, $this->codes->previewInternal());
    }

    public function testBrandNewSellNumberOnlyAndENumberOnly(): void
    {
        $res = $this->quick->run(['product_mode' => 'new', 'want' => 'sell', 'prefix_id' => $this->prefixId('AQ'), 'description' => 'Gloss coating', 'reason' => 'resale'], $this->adminId);
        $this->assertNull($res['internal']);
        $this->assertSame('AQ5255', $res['sell']['display']);
        $this->assertNull($res['sell']['internal_code_id']);

        $res = $this->quick->run(['product_mode' => 'new', 'want' => 'internal', 'description' => 'Formula only'], $this->adminId);
        $this->assertNull($res['sell']);
        $this->assertSame('E9102', $res['internal']['display']);
    }

    public function testInputShapeIsValidated(): void
    {
        try {
            $this->quick->run(['product_mode' => 'existing', 'product' => '', 'want' => 'sell'], $this->adminId);
            $this->fail();
        } catch (ValidationException $ex) {
            $this->assertArrayHasKey('product', $ex->errors);
        }
        try {
            $this->quick->run(['product_mode' => 'existing', 'product' => 'E999999', 'want' => 'sell'], $this->adminId);
            $this->fail();
        } catch (ValidationException $ex) {
            $this->assertArrayHasKey('product', $ex->errors);
        }
        try {
            $this->quick->run(['product_mode' => 'new', 'want' => 'nope', 'description' => 'x'], $this->adminId);
            $this->fail();
        } catch (ValidationException $ex) {
            $this->assertArrayHasKey('want', $ex->errors);
        }
        try {
            $this->quick->run(['product_mode' => 'new', 'want' => 'sell', 'prefix_id' => $this->prefixId('Y'), 'description' => ''], $this->adminId);
            $this->fail();
        } catch (ValidationException $ex) {
            $this->assertArrayHasKey('description', $ex->errors);
        }
        $this->assertNull($this->quick->product('nonsense'));
        $this->assertNull($this->quick->product(null));
    }

    public function testWizardPrefixScreenFoldsLegacyPrefixesAndStartsOnTheProductsPrefix(): void
    {
        $y = $this->codes->assignSell(['prefix_id' => $this->prefixId('Y'), 'description' => 'Yellow'], $this->adminId);
        $w = new QuickAssignWizard($this->app);
        $groups = $w->prefixGroups();
        $this->assertContains('Y', array_column($groups['everyday'], 'code'));
        $this->assertNotContains('UV', array_column($groups['everyday'], 'code'));
        $this->assertSame(['UV', 'UVXL', 'UVVL', 'UVNG', 'WBC'], array_column($groups['other'], 'code'));
        $this->assertSame($this->prefixId('Y'), $w->defaultPrefixId($w->productFor(['product_mode' => 'existing', 'product' => 'S' . $y['id']])));
        $this->assertNull($w->defaultPrefixId(null));
    }

    public function testPrefixChoicesShowNextNumberAndSuffixes(): void
    {
        $choices = $this->quick->prefixChoices();
        $byCode = [];
        foreach ($choices as $c) {
            $byCode[$c['code']] = $c;
        }
        $this->assertSame('Y1715', $byCode['Y']['next']);
        $this->assertSame('X104044', $byCode['X']['next']);
        $this->assertSame(['UV'], array_map(fn($s) => $s['code'], $byCode['Y']['suffixes']));
        $this->assertSame([], $byCode['X']['suffixes']);
        $this->assertArrayNotHasKey('CS', $byCode, 'retired prefixes are not offered');
    }

    // ------------------------------------------------------------------
    // Easy-mode shells: the wizards collect the same fields and call the same operations.
    // ------------------------------------------------------------------

    private function request(array $query = []): Request
    {
        return new Request('GET', '/wizard/x', $query, [], [], [], [], '127.0.0.1');
    }

    public function testQuickAssignWizardWalksFindChooseConfirmAndLinksAlias(): void
    {
        $e = $this->codes->assignInternal(['description' => 'Blister purple'], $this->adminId);
        $w = new QuickAssignWizard($this->app);
        $state = $w->initialState($this->request(['product' => 'E' . $e['id']]));
        $this->assertSame('E' . $e['id'], $state['product']);
        $steps = $w->steps($state);
        $this->assertSame(['product', 'prefix', 'details', 'extras'], array_map(fn($s) => $s->key, $steps));
        $this->assertSame('codes/wizard-product', $steps[0]->template);

        // Step 1 refuses "existing" without a pick, accepts a pick.
        try {
            $w->validateStep($steps[0], ['product_mode' => 'existing', 'product' => ''], $state);
            $this->fail();
        } catch (ValidationException $ex) {
            $this->assertArrayHasKey('product', $ex->errors);
        }
        $state = array_merge($state, $w->validateStep($steps[0], ['product_mode' => 'existing', 'product' => 'E' . $e['id']], $state));
        $this->assertSame('E9102', $state['product_label']);
        $this->assertSame(['sell'], array_keys($w->wantsFor($w->productFor($state))));

        // One question per screen: the product has an E number, so "what" has one answer and is skipped.
        $steps = $w->steps($state);
        $this->assertSame(['product', 'prefix', 'details', 'extras'], array_map(fn($s) => $s->key, $steps));
        $this->assertSame('codes/wizard-prefix', $steps[1]->template);
        $this->assertSame('sell', $w->wantFor($state, $w->productFor($state)));
        try {
            $w->validateStep($steps[1], ['prefix_id' => ''], $state);
            $this->fail();
        } catch (ValidationException $ex) {
            $this->assertArrayHasKey('prefix_id', $ex->errors);
        }
        $state = array_merge($state, $w->validateStep($steps[1], ['prefix_id' => (string) $this->prefixId('Y')], $state));
        // Y allows UV, so a suffix screen appears; the description/reason screen refuses an alias without a reason.
        $steps = $w->steps($state);
        $this->assertSame(['product', 'prefix', 'suffix', 'details', 'extras'], array_map(fn($s) => $s->key, $steps));
        $state = array_merge($state, $w->validateStep($steps[2], ['suffix_id' => (string) $this->suffixId('UV')], $state));
        try {
            $w->validateStep($steps[3], ['description' => '', 'reason' => ''], $state);
            $this->fail();
        } catch (ValidationException $ex) {
            $this->assertArrayHasKey('reason', $ex->errors);
            $this->assertArrayNotHasKey('description', $ex->errors);
        }
        $state = array_merge($state, $w->validateStep($steps[3], ['description' => '', 'reason' => 'new_version'], $state));
        $this->assertSame('Blister purple', $state['description']);
        $state = array_merge($state, $w->validateStep($steps[4], [], $state));
        $rows = $w->review($state);
        $labels = array_column($rows, 'value', 'label');
        $this->assertStringContainsString('Y1715UV', $labels['Sell number']);
        $this->assertSame('UV', $labels['Suffix']);
        $this->assertSame('New version of the formula', $labels['Reason']);

        $result = $w->commit($state);
        $this->assertSame('Y1715UV', $result->codes[0]['code']);
        $this->assertSame((int) $e['id'], (int) $this->db->value('SELECT internal_code_id FROM chroma_sell_numbers WHERE sell_number_normalized = "Y1715UV"'));
        $this->assertStringContainsString('return=%2Fassign%3Fexpert%3D1', (string) $w->expertUrl($state));
    }

    public function testQuickAssignWizardBrandNewProductBothCodes(): void
    {
        $w = new QuickAssignWizard($this->app);
        $state = $w->initialState($this->request(['product_mode' => 'new']));
        $steps = $w->steps($state);
        $state = array_merge($state, $w->validateStep($steps[0], ['product_mode' => 'new'], $state));
        $this->assertSame(['sell', 'internal', 'both'], array_keys($w->wantsFor(null)));
        // Brand-new product: three possible answers, so the "what" question is asked and the rest waits for it.
        $this->assertSame(['product', 'want'], array_map(fn($s) => $s->key, $w->steps($state)));
        $state = array_merge($state, $w->validateStep($w->steps($state)[1], ['want' => 'both'], $state));
        $this->assertSame(['product', 'want', 'prefix', 'details', 'extras'], array_map(fn($s) => $s->key, $w->steps($state)));
        $byKey = fn(array $state) => array_combine(array_map(fn($s) => $s->key, $w->steps($state)), $w->steps($state));
        $state = array_merge($state, $w->validateStep($byKey($state)['prefix'], ['prefix_id' => (string) $this->prefixId('AQ')], $state));
        $state = array_merge($state, $w->validateStep($byKey($state)['details'], ['description' => 'Gloss coating'], $state));
        $this->assertSame('resale', $byKey($state)['extras']->fields[1]['name'], 'an E number asks about resale');
        $state = array_merge($state, $w->validateStep($byKey($state)['extras'], ['resale' => '1', 'vendor_name' => 'Vendor Co'], $state));
        $this->assertSame('Yes — Vendor Co', array_column($w->review($state), 'value', 'label')['Resale item']);
        $result = $w->commit($state);
        $this->assertSame(['E9102', 'AQ5255'], array_map(fn($c) => $c['code'], $result->codes));
        $this->assertSame(1, (int) $this->db->value('SELECT resale FROM chroma_internal_codes WHERE number = 9102'));
    }

    public function testQuickAssignWizardRefusesKindsTheUserMayNotAssign(): void
    {
        $sales = $this->makeUser('sales', 'Sales'); // codes.view only
        $this->app->auth->actAs($sales);
        $w = new QuickAssignWizard($this->app);
        $this->expectException(\Chroma\Core\ForbiddenException::class);
        $w->initialState($this->request());
    }

    public function testVoidCodeWizardVoidsThroughCodeAssigner(): void
    {
        $y = $this->codes->assignSell(['prefix_id' => $this->prefixId('Y'), 'description' => 'Oops'], $this->adminId);
        $w = new VoidCodeWizard($this->app);
        $state = $w->initialState($this->request(['type' => 'sell', 'id' => $y['id']]));
        $this->assertSame('Y1715', $state['code']);
        $step = $w->steps($state)[0];
        try {
            $w->validateStep($step, ['reason' => '  '], $state);
            $this->fail();
        } catch (ValidationException $ex) {
            $this->assertArrayHasKey('reason', $ex->errors);
        }
        $state = array_merge($state, $w->validateStep($step, ['reason' => 'Assigned twice'], $state));
        $result = $w->commit($state);
        $this->assertSame('/codes/s/' . $y['id'], $result->redirect);
        $this->assertSame(1, (int) $this->db->value('SELECT voided FROM chroma_sell_numbers WHERE id = ?', [$y['id']]));
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM chroma_audit_log WHERE action = "code.void_sell" AND row_id = ?', [$y['id']]));

        // Opened again (history, bookmark): says so up front, no reason asked, cannot proceed.
        $state = $w->initialState($this->request(['type' => 'sell', 'id' => $y['id']]));
        $this->assertTrue($state['already_void']);
        $steps = $w->steps($state);
        $this->assertCount(1, $steps);
        $this->assertSame('already', $steps[0]->key);
        $this->assertSame('codes/wizard-already-void', $steps[0]->template);
        $this->assertSame([], $steps[0]->fields);
        try {
            $w->validateStep($steps[0], [], $state);
            $this->fail();
        } catch (ValidationException $ex) {
            $this->assertStringContainsString('already voided', $ex->errors['code']);
        }

        $this->expectException(\Chroma\Core\NotFoundException::class);
        $w->initialState($this->request(['type' => 'internal', 'id' => 999]));
    }

    public function testLinkCodesWizardResolvesSiblingSellNumbersToTheirENumber(): void
    {
        $e = $this->codes->assignInternal(['description' => 'Formula'], $this->adminId);
        $linked = $this->codes->assignSell(['prefix_id' => $this->prefixId('Y'), 'description' => 'Linked', 'internal_code_id' => $e['id']], $this->adminId);
        $lonely = $this->codes->assignSell(['prefix_id' => $this->prefixId('T'), 'description' => 'Lonely'], $this->adminId);
        $w = new LinkCodesWizard($this->app);
        $state = $w->initialState($this->request(['sell_id' => $lonely['id']]));
        $this->assertSame('T2450', $state['sell']);
        $step = $w->steps($state)[0];
        try {
            $w->validateStep($step, ['internal' => 'S' . $lonely['id']], $state);
            $this->fail('a sell number without an E number cannot be a target');
        } catch (ValidationException $ex) {
            $this->assertStringContainsString('has no E number', $ex->errors['internal']);
        }
        // Typing the sibling sell number links to its E number.
        $state = array_merge($state, $w->validateStep($step, ['internal' => 'S' . $linked['id']], $state));
        $this->assertSame((int) $e['id'], $state['internal_id']);
        $this->assertSame('E9102', $state['internal_display']);
        $result = $w->commit($state);
        $this->assertSame('/codes/s/' . $lonely['id'], $result->redirect);
        $this->assertSame((int) $e['id'], (int) $this->db->value('SELECT internal_code_id FROM chroma_sell_numbers WHERE id = ?', [$lonely['id']]));
        // Linking again to the same E number is refused as pointless.
        $this->expectException(ValidationException::class);
        $w->validateStep($step, ['internal' => 'E' . $e['id']], $state);
    }

    public function testManualEntryWizardEntersSpecificCodes(): void
    {
        $w = new ManualEntryWizard($this->app);
        $state = $w->initialState($this->request(['type' => 'sell', 'code' => 'y-1000']));
        $steps = $w->steps($state);
        $this->assertSame(['type', 'code', 'details'], array_map(fn($s) => $s->key, $steps));
        $state = array_merge($state, $w->validateStep($steps[0], ['type' => 'sell'], $state));
        try {
            $w->validateStep($steps[1], ['code' => '38-5000', 'description' => 'not a code'], $state);
            $this->fail();
        } catch (ValidationException $ex) {
            $this->assertArrayHasKey('code', $ex->errors);
        }
        $state = array_merge($state, $w->validateStep($steps[1], ['code' => 'y-1000', 'description' => 'Legacy yellow'], $state));
        $state = array_merge($state, $w->validateStep($steps[2], ['internal' => '', 'reason' => 'relabel', 'original_customer_id' => '', 'date_assigned' => '2019-03-12', 'notes' => ''], $state));
        $this->assertSame('Legacy yellow', array_column($w->review($state), 'value', 'label')['Description']);
        $result = $w->commit($state);
        $this->assertSame('Y1000', $result->codes[0]['code']);
        $row = $this->db->one('SELECT * FROM chroma_sell_numbers WHERE sell_number_normalized = "Y1000"');
        $this->assertSame('manual', $row['source']);
        $this->assertSame('2019-03-12', $row['date_assigned']);
        $this->assertSame('y-1000', $row['legacy_text']);

        // E number path
        $state = $w->initialState($this->request(['type' => 'internal']));
        $steps = $w->steps($state);
        $state = array_merge($state, $w->validateStep($steps[1], ['code' => 'E5000', 'description' => 'Old formula'], $state));
        $state = array_merge($state, $w->validateStep($w->steps($state)[2], ['resale' => '0', 'vendor_name' => '', 'original_customer_id' => '', 'date_assigned' => '2020-01-01', 'notes' => 'from the card'], $state));
        $this->assertSame('E5000', $w->commit($state)->codes[0]['code']);
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM chroma_audit_log WHERE action = "code.manual_internal"'));
    }

    public function testManualEntryRefusesWhatTheDatabaseCouldNotStoreInPlainWords(): void
    {
        $this->assertNull(ManualEntry::problem('sell', 'y-1000'));
        $this->assertNull(ManualEntry::problem('sell', 'X104043'));
        $this->assertNull(ManualEntry::problem('internal', 'E6374-10'));
        $this->assertStringContainsString('too long', (string) ManualEntry::problem('sell', 'Y99999999999999999999'));
        $this->assertStringContainsString('too long', (string) ManualEntry::problem('sell', 'Y1234567'));
        $this->assertNull(ManualEntry::problem('sell', 'Y000123'), 'leading zeros do not count');
        $this->assertStringContainsString('too long', (string) ManualEntry::problem('internal', 'E99999999999999999999'));
        $this->assertStringContainsString('version', (string) ManualEntry::problem('internal', 'E1234-abcdefghijklmnopqrstuvwxyzabcdefghijkl'));
        $this->assertStringContainsString('like E6374-10', (string) ManualEntry::problem('internal', 'Y1000'));
        $this->assertStringContainsString('like Y1714UV', (string) ManualEntry::problem('sell', '38-5000'));

        $entry = new ManualEntry($this->db, $this->codes);
        foreach ([['sell', 'Y99999999999999999999'], ['internal', 'E1234-abcdefghijklmnopqrstuvwxyzabcdefghijkl']] as [$type, $code]) {
            try {
                $type === 'sell' ? $entry->sell($code, ['description' => 'x'], $this->adminId) : $entry->internal($code, ['description' => 'x'], $this->adminId);
                $this->fail('should refuse ' . $code);
            } catch (ValidationException $ex) {
                $this->assertArrayHasKey('code', $ex->errors);
            }
        }
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM chroma_sell_numbers') + (int) $this->db->value('SELECT COUNT(*) FROM chroma_internal_codes'));

        // The "already used" message shows the padded display form and who has it.
        $e = $this->codes->assignInternal(['description' => 'Brown base'], $this->adminId);
        $entry->sell('bn-0777', ['description' => 'Brown', 'internal_code_id' => $e['id']], $this->adminId);
        try {
            $entry->sell('BN777', ['description' => 'Brown again'], $this->adminId);
            $this->fail();
        } catch (ValidationException $ex) {
            $this->assertStringStartsWith('BN0777 is already used by E9102', $ex->errors['code']);
        }
        // The wizard's code step refuses the same things, before the details are asked.
        $w = new ManualEntryWizard($this->app);
        $state = $w->initialState($this->request(['type' => 'internal']));
        try {
            $w->validateStep($w->steps($state)[1], ['code' => 'E99999999999999999999', 'description' => 'x'], $state);
            $this->fail();
        } catch (ValidationException $ex) {
            $this->assertStringContainsString('too long', $ex->errors['code']);
        }
    }

    public function testManualEntryWizardAsksWhyWhenTheReasonIsOtherAndCollectsVendorCode(): void
    {
        $w = new ManualEntryWizard($this->app);
        $state = $w->initialState($this->request(['type' => 'sell', 'code' => 'Y9992']));
        $steps = $w->steps($state);
        $state = array_merge($state, $w->validateStep($steps[1], ['code' => 'Y9992', 'description' => 'Odd case'], $state));
        $details = $w->steps($state)[2];
        $this->assertSame('codes/wizard-details', $details->template);
        $names = array_column($details->fields, 'name');
        $this->assertContains('reason_text', $names);
        $reasonText = $details->fields[array_search('reason_text', $names, true)];
        $this->assertSame("reason === 'other'", $reasonText['show_when']);
        $base = ['internal' => '', 'original_customer_id' => '', 'date_assigned' => '2021-05-05', 'notes' => ''];
        // Other without a word: refused on the step, not on the review screen.
        try {
            $w->validateStep($details, $base + ['reason' => 'other', 'reason_text' => ''], $state);
            $this->fail();
        } catch (ValidationException $ex) {
            $this->assertArrayHasKey('reason_text', $ex->errors);
        }
        // Another reason: "say why" is not required.
        $clean = $w->validateStep($details, $base + ['reason' => 'relabel', 'reason_text' => 'ignored'], $state);
        $this->assertSame('', $clean['reason_text']);
        $state = array_merge($state, $w->validateStep($details, $base + ['reason' => 'other', 'reason_text' => 'Sample for the trade show'], $state));
        $this->assertSame('Other — Sample for the trade show', array_column($w->review($state), 'value', 'label')['Reason']);
        $result = $w->commit($state);
        $this->assertSame('Y9992', $result->codes[0]['code']);
        $row = $this->db->one('SELECT reason, reason_text FROM chroma_sell_numbers WHERE sell_number_normalized = "Y9992"');
        $this->assertSame(['reason' => 'other', 'reason_text' => 'Sample for the trade show'], $row);

        // E numbers: vendor code is collected like on the Expert page.
        $state = $w->initialState($this->request(['type' => 'internal']));
        $steps = $w->steps($state);
        $state = array_merge($state, $w->validateStep($steps[1], ['code' => 'E5001', 'description' => 'Bought-in white'], $state));
        $details = $w->steps($state)[2];
        $this->assertContains('vendor_code', array_column($details->fields, 'name'));
        $state = array_merge($state, $w->validateStep($details, ['resale' => '1', 'vendor_name' => 'Acme Inks', 'vendor_code' => 'AW-12', 'original_customer_id' => '', 'date_assigned' => '2021-05-05', 'notes' => ''], $state));
        $this->assertSame('Yes — Acme Inks (AW-12)', array_column($w->review($state), 'value', 'label')['Resale']);
        $w->commit($state);
        $row = $this->db->one('SELECT resale, vendor_name, vendor_code FROM chroma_internal_codes WHERE number = 5001');
        $this->assertSame(['resale' => 1, 'vendor_name' => 'Acme Inks', 'vendor_code' => 'AW-12'], ['resale' => (int) $row['resale']] + $row);
    }

    public function testQuickAssignWizardCollectsNotesLikeTheExpertPage(): void
    {
        $w = new QuickAssignWizard($this->app);
        $state = $w->initialState($this->request(['product_mode' => 'new']));
        $steps = $w->steps($state);
        $state = array_merge($state, $w->validateStep($steps[0], ['product_mode' => 'new'], $state));
        $byKey = fn(array $state) => array_combine(array_map(fn($s) => $s->key, $w->steps($state)), $w->steps($state));
        $state = array_merge($state, $w->validateStep($byKey($state)['want'], ['want' => 'sell'], $state));
        $state = array_merge($state, $w->validateStep($byKey($state)['prefix'], ['prefix_id' => (string) $this->prefixId('T')], $state));
        $state = array_merge($state, $w->validateStep($byKey($state)['details'], ['description' => 'Tint sample', 'reason' => ''], $state));
        $this->assertContains('notes', array_column($byKey($state)['extras']->fields, 'name'));
        $this->assertNotContains('resale', array_column($byKey($state)['extras']->fields, 'name'), 'a sell number alone does not ask about resale');
        $state = array_merge($state, $w->validateStep($byKey($state)['extras'], ['notes' => 'Trial for Acme'], $state));
        $this->assertSame('Trial for Acme', array_column($w->review($state), 'value', 'label')['Notes']);
        $result = $w->commit($state);
        $this->assertSame('T2450', $result->codes[0]['code']);
        $this->assertSame('Trial for Acme', $this->db->value('SELECT notes FROM chroma_sell_numbers WHERE sell_number_normalized = "T2450"'));
    }
}
