<?php
declare(strict_types=1);

namespace Chroma\Tests;

use Chroma\Core\ValidationException;
use Chroma\Domain\Codes\CodeAssigner;
use Chroma\Domain\Projects\Attachments;
use Chroma\Domain\Projects\Items;
use Chroma\Domain\Projects\Projects;

final class ProjectItemsTest extends TestCase
{
    private Projects $projects;
    private Items $items;
    private CodeAssigner $codes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projects = new Projects($this->db, $this->app->audit, $this->app->settings);
        $this->items = $this->projects->items();
        $this->codes = new CodeAssigner($this->db, $this->app->audit);
    }

    /** @param list<array<string,mixed>> $items */
    private function project(array $items = [], string $type = 'color_match', array $extra = []): array
    {
        return $this->projects->create($extra + ['type' => $type, 'customer_text' => 'Acme', 'title' => 'P', 'items' => $items], $this->adminId);
    }

    public function testItemCapOnCreateAndOnAdd(): void
    {
        $this->app->settings->set('max_items_per_project', '3');
        $rows = array_map(fn($i) => ['description' => 'Color ' . $i], range(1, 4));
        try {
            $this->project($rows);
            $this->fail('cap not enforced on create');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items', $e->errors);
            $this->assertStringContainsString('at most 3', $e->errors['items']);
        }
        $p = $this->project(array_slice($rows, 0, 3));
        $this->assertSame(3, $this->items->count((int) $p['id']));
        try {
            $this->items->add((int) $p['id'], ['description' => 'One too many'], $this->adminId);
            $this->fail('cap not enforced on add');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items', $e->errors);
        }
        // Blank rows from the Expert grid are skipped, not counted.
        $p2 = $this->project([['description' => 'A'], ['description' => ''], ['description' => '  '], ['description' => 'B']]);
        $this->assertSame(2, $this->items->count((int) $p2['id']));
    }

    public function testAddReorderAndMove(): void
    {
        $p = $this->project([['description' => 'One'], ['description' => 'Two']]);
        $three = $this->items->add((int) $p['id'], ['description' => 'Three', 'hex_color' => '1f6feb', 'pantone' => '300 C', 'proof_count' => '2'], $this->adminId);
        $this->assertSame(3, (int) $three['sequence']);
        $this->assertSame('#1F6FEB', $three['hex_color']);
        $this->assertSame(1, (int) $three['proofs_generated']);
        $this->assertSame('color', $three['kind']);

        $this->items->reorder((int) $p['id'], null, (int) $three['id'], 'up', $this->adminId);
        $this->assertSame(['One', 'Three', 'Two'], array_column($this->items->forProject((int) $p['id']), 'description'));
        $ids = array_column($this->items->forProject((int) $p['id']), 'id');
        $this->items->reorder((int) $p['id'], array_reverse($ids), null, null, $this->adminId);
        $this->assertSame(['Two', 'Three', 'One'], array_column($this->items->forProject((int) $p['id']), 'description'));
        $this->assertSame([1, 2, 3], array_map('intval', array_column($this->items->forProject((int) $p['id']), 'sequence')));

        $other = $this->project([['description' => 'Elsewhere']], 'customer_evaluation', ['goal' => 'g']);
        $moved = $this->items->move((int) $three['id'], (int) $other['id'], $this->adminId);
        $this->assertSame((int) $other['id'], (int) $moved['project_id']);
        $this->assertSame(2, (int) $moved['sequence']);
        $this->assertSame(['Two', 'One'], array_column($this->items->forProject((int) $p['id']), 'description'));
        $this->assertSame([1, 2], array_map('intval', array_column($this->items->forProject((int) $p['id']), 'sequence')));
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM chroma_audit_log WHERE action = "item.move" AND row_id = ?', [$three['id']]));
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM chroma_project_activity WHERE project_id = ? AND kind = "item_moved"', [$other['id']]));
        try {
            $this->items->move((int) $moved['id'], (int) $other['id'], $this->adminId);
            $this->fail('moved to same project');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('to_project_id', $e->errors);
        }
    }

    public function testExistingProductPickerLinksCodesInsteadOfAssigning(): void
    {
        $e = $this->codes->assignInternal(['description' => 'Process blue'], $this->adminId);
        $s = $this->codes->assignSell(['prefix_id' => $this->prefixId('B'), 'description' => 'Process blue', 'internal_code_id' => (int) $e['id']], $this->adminId);
        $p = $this->project([['existing_ref' => 'S' . $s['id']]], 'customer_issue', ['problem_statement' => 'Not drying']);
        $item = $this->items->forProject((int) $p['id'])[0];
        $this->assertSame('existing_product', $item['kind']);
        $this->assertSame('Process blue', $item['description'], 'description defaults from the code');
        $this->assertSame((int) $s['id'], (int) $item['sell_number_id']);
        $this->assertSame((int) $e['id'], (int) $item['internal_code_id'], 'the E# behind a sell number is linked too');
        $this->assertSame('E9102', $item['internal_display']);
        $this->assertSame('B2971', $item['sell_display']);
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM chroma_internal_codes'), 'nothing new was assigned');
        try {
            $this->items->add((int) $p['id'], ['existing_ref' => 'S999999'], $this->adminId);
            $this->fail('bad ref accepted');
        } catch (ValidationException $e2) {
            $this->assertArrayHasKey('existing_ref', $e2->errors);
        }
    }

    public function testAssignFromItemLinksTheItemAndWritesActivity(): void
    {
        $p = $this->project([['description' => 'Pantone 300 C']]);
        $item = $this->items->forProject((int) $p['id'])[0];
        $e = $this->items->assignInternal((int) $item['id'], [], $this->adminId, $this->codes);
        $s = $this->items->assignSell((int) $item['id'], ['prefix_id' => $this->prefixId('B'), 'suffix_id' => $this->suffixId('UV')], $this->adminId, $this->codes);
        $this->assertSame('E9102', $e['display']);
        $this->assertSame('B2971UV', $s['display']);
        $this->assertSame('Pantone 300 C', $e['description'], 'description defaults from the item');
        $this->assertSame((int) $e['id'], (int) $s['internal_code_id'], 'sell number links to the item E#');
        $this->assertSame((int) $p['customer_id'], (int) $e['original_customer_id']);
        $after = $this->items->get((int) $item['id']);
        $this->assertSame((int) $e['id'], (int) $after['internal_code_id']);
        $this->assertSame((int) $s['id'], (int) $after['sell_number_id']);
        $this->assertSame(2, (int) $this->db->value('SELECT COUNT(*) FROM chroma_project_activity WHERE project_id = ? AND kind = "code_assigned"', [$p['id']]));
        $this->assertSame($this->prefixId('B'), (int) $this->projects->get((int) $p['id'])['default_prefix_id']);
        try {
            $this->items->assignInternal((int) $item['id'], [], $this->adminId, $this->codes);
            $this->fail('double assign');
        } catch (ValidationException $ex) {
            $this->assertStringContainsString('already has E9102', $ex->getMessage());
        }
        try {
            $this->items->remove((int) $item['id'], $this->adminId);
            $this->fail('item with codes removed');
        } catch (ValidationException $ex) {
            $this->assertStringContainsString('cannot be removed', $ex->getMessage());
        }
        $this->assertSame(1, $this->items->count((int) $p['id']));
    }

    public function testBatchAssignOneTransactionPerItemWithSummary(): void
    {
        $p = $this->project([['description' => 'A'], ['description' => 'B'], ['description' => 'C']]);
        $rows = $this->items->forProject((int) $p['id']);
        $y = $this->prefixId('Y');
        $retired = $this->prefixId('A');
        $plan = [
            ['item_id' => $rows[0]['id'], 'internal' => true, 'sell' => true, 'prefix_id' => $y],
            ['item_id' => $rows[1]['id'], 'internal' => false, 'sell' => true, 'prefix_id' => $retired], // fails: retired prefix
            ['item_id' => $rows[2]['id'], 'internal' => true, 'sell' => false],
        ];
        $r = $this->items->batchAssign((int) $p['id'], $plan, $this->adminId, $this->codes);
        $this->assertSame(['E9102', 'Y1715', 'E9103'], array_column($r['assigned'], 'code'));
        $this->assertCount(1, $r['errors']);
        $this->assertSame('B', $r['errors'][0]['description']);
        $this->assertStringContainsString('retired', $r['errors'][0]['message']);
        $after = $this->items->forProject((int) $p['id']);
        $this->assertSame('E9102', $after[0]['internal_display']);
        $this->assertSame('Y1715', $after[0]['sell_display']);
        $this->assertNull($after[1]['sell_number_id'], 'the failed item is untouched');
        $this->assertSame('E9103', $after[2]['internal_display']);
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM chroma_audit_log WHERE action = "item.batch_assign" AND row_id = ?', [$p['id']]));
        try {
            $this->items->batchAssign((int) $p['id'], [['item_id' => $rows[1]['id']]], $this->adminId, $this->codes);
            $this->fail('empty plan accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Nothing was selected', $e->getMessage());
        }
    }

    public function testStatusChangeIsValidatedAndAudited(): void
    {
        $p = $this->project([['description' => 'A']]);
        $item = $this->items->forProject((int) $p['id'])[0];
        $inProgress = (int) $this->db->value('SELECT id FROM chroma_item_statuses WHERE code = "in_progress" AND project_type_id IS NULL');
        $after = $this->items->setStatus((int) $item['id'], $inProgress, $this->adminId);
        $this->assertSame('In progress', $after['status_label']);
        $this->assertSame('open', $this->projects->get((int) $p['id'])['status']);
        try {
            $this->items->setStatus((int) $item['id'], 999999, $this->adminId);
            $this->fail('bad status accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status_id', $e->errors);
        }
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM chroma_audit_log WHERE action = "item.update" AND row_id = ?', [$item['id']]));
        $this->items->update((int) $item['id'], ['proof_count' => '4', 'is_digital_match' => '1', 'notes' => 'ok'], $this->adminId);
        $u = $this->items->get((int) $item['id']);
        $this->assertSame(4, (int) $u['proof_count']);
        $this->assertSame(1, (int) $u['proofs_generated']);
        $this->assertSame(1, (int) $u['is_digital_match']);
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM chroma_project_activity WHERE project_id = ? AND kind = "proofs_changed"', [$p['id']]));
    }

    public function testAttachmentsMetadataAndSoftDelete(): void
    {
        $p = $this->project([['description' => 'A']]);
        $base = sys_get_temp_dir() . '/chroma-test-files-' . getmypid();
        $att = new Attachments($this->db, $this->app->audit, $this->app->settings, $base);
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp, str_repeat('x', 2048));
        $row = $att->store((int) $p['id'], ['name' => '../drawdown report.txt', 'tmp_name' => $tmp, 'size' => 2048, 'error' => UPLOAD_ERR_OK], $this->adminId, null, 'First drawdown');
        $this->assertSame('drawdown report.txt', $row['original_name']);
        $this->assertSame(2048, (int) $row['size_bytes']);
        $this->assertSame('2 KB', $row['size_human']);
        $this->assertSame('First drawdown', $row['caption']);
        $this->assertFileExists($att->path($row));
        $this->assertStringStartsWith($base . '/projects/' . $p['id'] . '/', $att->path($row));
        $this->assertCount(1, $att->forProject((int) $p['id']));

        $this->app->settings->set('max_upload_mb', '1');
        $big = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($big, 'x');
        try {
            $att->store((int) $p['id'], ['name' => 'huge.pdf', 'tmp_name' => $big, 'size' => 2 * 1024 * 1024, 'error' => UPLOAD_ERR_OK], $this->adminId);
            $this->fail('oversize accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('1 MB limit', $e->errors['file']);
        }
        try {
            $att->store((int) $p['id'], ['name' => 'evil.php', 'tmp_name' => $big, 'size' => 1, 'error' => UPLOAD_ERR_OK], $this->adminId);
            $this->fail('php accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('file', $e->errors);
        }
        $att->remove((int) $row['id'], $this->adminId);
        $this->assertCount(0, $att->forProject((int) $p['id']));
        $this->assertNotNull($att->get((int) $row['id'])['deleted_at']);
        $this->assertFileExists($att->path($row), 'soft delete keeps the file');
        $this->assertSame(2, (int) $this->db->value('SELECT COUNT(*) FROM chroma_audit_log WHERE table_name = "chroma_project_attachments" AND row_id = ?', [$row['id']]));
        @unlink($big);
        exec('rm -rf ' . escapeshellarg($base));
    }

    public function testAttachmentsAreCheckedByContentNotOnlyByName(): void
    {
        $p = $this->project([['description' => 'A']]);
        $base = sys_get_temp_dir() . '/chroma-test-files-' . getmypid();
        $att = new Attachments($this->db, $this->app->audit, $this->app->settings, $base);
        $upload = function (string $name, string $content): array {
            $tmp = tempnam(sys_get_temp_dir(), 'up');
            file_put_contents($tmp, $content);
            return ['name' => $name, 'tmp_name' => $tmp, 'size' => strlen($content), 'error' => UPLOAD_ERR_OK];
        };
        // A web page called .jpg is refused: the detected type wins over the name.
        try {
            $att->store((int) $p['id'], $upload('page.jpg', "<!DOCTYPE html><html><head><title>x</title></head><body><script>alert(1)</script></body></html>\n"), $this->adminId);
            $this->fail('html named .jpg accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('file', $e->errors);
        }
        // PHP source with a harmless extension is refused too.
        try {
            $att->store((int) $p['id'], $upload('x.php.txt', "<?php\necho 'hi';\n"), $this->adminId);
            $this->fail('php source named .txt accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('file', $e->errors);
        }
        $this->assertCount(0, $att->forProject((int) $p['id']));
        // A real image and a plain text note still go through.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        $row = $att->store((int) $p['id'], $upload('drawdown.png', $png), $this->adminId);
        $this->assertSame('image/png', $row['mime']);
        $this->assertTrue($row['is_image']);
        $txt = $att->store((int) $p['id'], $upload('notes.txt', "Drawdown looked fine.\n"), $this->adminId);
        $this->assertStringStartsWith('text/plain', $txt['mime']);
        $this->assertCount(2, $att->forProject((int) $p['id']));
        $this->assertTrue(Attachments::dangerousMime('application/x-sh'));
        $this->assertTrue(Attachments::dangerousMime('application/x-dosexec'));
        $this->assertFalse(Attachments::dangerousMime('application/pdf'));
        $this->assertFalse(Attachments::dangerousMime('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));
        exec('rm -rf ' . escapeshellarg($base));
    }

    public function testProjectCompletesWhenTheLastUnfinishedItemIsRemovedOrAddedDone(): void
    {
        $matched = (int) $this->db->value('SELECT id FROM chroma_item_statuses WHERE code = "matched" AND project_type_id IS NULL');
        $p = $this->project([['description' => 'Pantone 300 C'], ['description' => 'Pantone 301 C']]);
        $rows = $this->items->forProject((int) $p['id']);
        $this->items->setStatus((int) $rows[0]['id'], $matched, $this->adminId);
        $this->assertSame('open', $this->projects->get((int) $p['id'])['status']);
        $this->items->remove((int) $rows[1]['id'], $this->adminId);
        $after = $this->projects->get((int) $p['id']);
        $this->assertSame('completed', $after['status'], 'only a matched color is left, so the project completes itself');
        $this->assertSame('1 of 1 matched', $after['progress']['label']);

        // Adding an item that is already "done" (an existing product that was matched elsewhere) counts too.
        $p2 = $this->project([['description' => 'Pantone 302 C']]);
        $r2 = $this->items->forProject((int) $p2['id']);
        $this->items->setStatus((int) $r2[0]['id'], $matched, $this->adminId);
        $this->assertSame('completed', $this->projects->get((int) $p2['id'])['status']);
        $this->projects->reopen((int) $p2['id'], $this->adminId);
        $this->items->add((int) $p2['id'], ['description' => 'Pantone 303 C', 'status_id' => $matched], $this->adminId);
        $this->assertSame('completed', $this->projects->get((int) $p2['id'])['status']);
        // … while adding a pending item keeps it open.
        $this->projects->reopen((int) $p2['id'], $this->adminId);
        $this->items->add((int) $p2['id'], ['description' => 'Pantone 304 C'], $this->adminId);
        $this->assertSame('open', $this->projects->get((int) $p2['id'])['status']);
    }

    public function testAnItemThatHoldsACodeCannotBeRepointedToAnotherCode(): void
    {
        $p = $this->project([['description' => 'Pantone 300 C'], ['description' => 'Pantone 301 C']]);
        [$a, $b] = $this->items->forProject((int) $p['id']);
        $e = $this->items->assignInternal((int) $a['id'], [], $this->adminId, $this->codes);
        $s = $this->items->assignSell((int) $a['id'], ['prefix_id' => $this->prefixId('B')], $this->adminId, $this->codes);
        $otherE = $this->codes->assignInternal(['description' => 'Something else'], $this->adminId);
        $otherS = $this->codes->assignSell(['prefix_id' => $this->prefixId('B'), 'description' => 'Something else', 'internal_code_id' => (int) $otherE['id']], $this->adminId);
        foreach ([
            fn() => $this->items->linkExisting((int) $a['id'], 'E' . $otherE['id'], $this->adminId),
            fn() => $this->items->linkExisting((int) $a['id'], 'S' . $otherS['id'], $this->adminId),
            fn() => $this->items->update((int) $a['id'], ['internal_code_id' => (int) $otherE['id']], $this->adminId),
            fn() => $this->items->update((int) $a['id'], ['sell_number_id' => (int) $otherS['id']], $this->adminId),
        ] as $i => $attempt) {
            try {
                $attempt();
                $this->fail("re-pointing attempt $i accepted");
            } catch (ValidationException $ex) {
                $this->assertArrayHasKey('existing_ref', $ex->errors, "attempt $i");
                $this->assertStringContainsString('already has', $ex->errors['existing_ref']);
                $this->assertStringContainsString('code page', $ex->errors['existing_ref']);
            }
        }
        $fresh = $this->items->get((int) $a['id']);
        $this->assertSame((int) $e['id'], (int) $fresh['internal_code_id'], 'E# unchanged');
        $this->assertSame((int) $s['id'], (int) $fresh['sell_number_id'], 'sell number unchanged');
        $this->assertSame('color', $fresh['kind'], 'kind unchanged');
        $this->assertSame(2, (int) $this->db->value('SELECT COUNT(*) FROM chroma_internal_codes'), 'nothing new was assigned by the attempts');
        // Picking the code it already holds is not a change; an item without codes can still link one, and that is audited at code level.
        $same = $this->items->linkExisting((int) $a['id'], 'E' . $e['id'], $this->adminId);
        $this->assertSame((int) $e['id'], (int) $same['internal_code_id']);
        $linked = $this->items->linkExisting((int) $b['id'], 'S' . $otherS['id'], $this->adminId);
        $this->assertSame((int) $otherS['id'], (int) $linked['sell_number_id']);
        $this->assertSame((int) $otherE['id'], (int) $linked['internal_code_id']);
        $this->assertSame('existing_product', $linked['kind']);
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM chroma_audit_log WHERE action = "item.link_code" AND row_id = ?', [$b['id']]));
    }
}
