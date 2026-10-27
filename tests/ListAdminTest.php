<?php
declare(strict_types=1);

namespace Chroma\Tests;

use Chroma\Core\ValidationException;
use Chroma\Domain\Codes\CodeAssigner;
use Chroma\Domain\Lists\ListAdmin;
use Chroma\Domain\Lists\ProjectTypeAdmin;
use Chroma\Domain\ProjectTypes\ProjectTypes;

final class ListAdminTest extends TestCase
{
    private ListAdmin $lists;
    private ProjectTypeAdmin $types;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanReferenceData();
        $this->lists = new ListAdmin($this->db, $this->app->audit);
        $this->types = new ProjectTypeAdmin($this->db, $this->app->audit);
    }

    protected function tearDown(): void
    {
        $this->cleanReferenceData();
        parent::tearDown();
    }

    /** Lists and project types are reference data (not truncated by TestCase), so clean what these tests add. */
    private function cleanReferenceData(): void
    {
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['chroma_ink_series', 'chroma_coatings', 'chroma_substrates'] as $t) {
            $this->db->exec("DELETE FROM $t");
        }
        $this->db->exec('DELETE FROM chroma_item_statuses WHERE project_type_id IS NOT NULL');
        $this->db->exec("DELETE FROM chroma_project_types WHERE code NOT IN ('color_match', 'customer_issue', 'customer_evaluation', 'rnd')");
        $this->db->exec("DELETE FROM chroma_project_stages WHERE project_type_id NOT IN (SELECT id FROM chroma_project_types)");
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function typeId(string $code): int
    {
        return (int) $this->db->value('SELECT id FROM chroma_project_types WHERE code = ?', [$code]);
    }

    private function makeProject(int $customerId, string $title = 'Test project', ?int $inkSeriesId = null): int
    {
        $now = $this->db->now();
        $n = (int) $this->db->value('SELECT COALESCE(MAX(project_number), 100) + 1 FROM chroma_projects');
        return $this->db->insert('chroma_projects', [
            'project_number' => $n, 'project_type_id' => $this->typeId('color_match'), 'customer_id' => $customerId, 'title' => $title,
            'status' => 'open', 'default_ink_series_id' => $inkSeriesId, 'created_by' => $this->adminId, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    // ------------------------------------------------------- simple lists

    public function testAddRenameAndDuplicateRules(): void
    {
        $id = $this->lists->add('ink-series', ['name' => '  UV  Flexo ', 'notes' => 'energy curable', 'sort_order' => '5']);
        $row = $this->lists->entry('ink-series', $id);
        $this->assertSame('UV Flexo', $row['name']);
        $this->assertSame(5, (int) $row['sort_order']);
        try {
            $this->lists->add('ink-series', ['name' => 'UV Flexo']);
            $this->fail('duplicate accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('already in the ink series list', $e->errors['name']);
        }
        $this->lists->update('ink-series', $id, ['name' => 'UV Flexo 2', 'notes' => '', 'sort_order' => '', 'active' => '0']);
        $row = $this->lists->entry('ink-series', $id);
        $this->assertSame('UV Flexo 2', $row['name']);
        $this->assertSame(0, (int) $row['active']);
        $this->assertSame(5, (int) $row['sort_order'], 'blank sort keeps the old value');
        $this->assertSame(2, (int) $this->db->value('SELECT COUNT(*) FROM chroma_audit_log WHERE table_name = "chroma_ink_series" AND row_id = ?', [$id]), 'add and update are audited; the refused duplicate is not');
    }

    public function testReferencedEntryCannotBeDeletedButCanBeDeactivated(): void
    {
        $customer = (int) (new \Chroma\Domain\Customers\Customers($this->db))->findOrCreate('Ref Co');
        $ink = $this->lists->add('ink-series', ['name' => 'Process set']);
        $coating = $this->lists->add('coatings', ['name' => 'Gloss']);
        $substrate = $this->lists->add('substrates', ['name' => 'SBS board']);
        $project = $this->makeProject($customer, 'Uses the ink series as default', $ink);
        $this->db->insert('chroma_project_items', ['project_id' => $project, 'sequence' => 1, 'kind' => 'color', 'description' => 'Red', 'coating_id' => $coating, 'substrate_id' => $substrate, 'created_at' => $this->db->now(), 'updated_at' => $this->db->now()]);

        foreach (['ink-series' => $ink, 'coatings' => $coating, 'substrates' => $substrate] as $kind => $id) {
            $this->assertSame(1, $this->lists->usageCount($kind, $id), "$kind usage");
            try {
                $this->lists->delete($kind, $id);
                $this->fail("$kind deleted while referenced");
            } catch (ValidationException $e) {
                $this->assertStringContainsString('cannot be deleted', $e->getMessage());
                $this->assertStringContainsString('Deactivate it instead', $e->getMessage());
            }
            $this->assertNotNull($this->lists->entry($kind, $id));
            $this->lists->deactivate($kind, $id);
            $this->assertSame(0, (int) $this->lists->entry($kind, $id)['active']);
            $this->lists->reactivate($kind, $id);
            $this->assertSame(1, (int) $this->lists->entry($kind, $id)['active']);
        }

        // An unused entry can go.
        $unused = $this->lists->add('coatings', ['name' => 'Matte']);
        $this->lists->delete('coatings', $unused);
        $this->assertNull($this->lists->entry('coatings', $unused));
    }

    // ------------------------------------------------------------ customers

    public function testMergeMovesEveryReferenceAndDeactivatesTheDuplicate(): void
    {
        $keep = $this->lists->addCustomer(['name' => 'Example Packaging']);
        $dup = $this->lists->addCustomer(['name' => 'Example Packaging Inc']);
        $p1 = $this->makeProject($dup, 'On the duplicate');
        $p2 = $this->makeProject($keep, 'On the kept one');
        $codes = new CodeAssigner($this->db, $this->app->audit);
        $e1 = $codes->assignInternal(['description' => 'Dup red', 'original_customer_id' => $dup], $this->adminId);
        $e2 = $codes->assignInternal(['description' => 'Keep blue', 'original_customer_id' => $keep], $this->adminId);
        $s1 = $codes->assignSell(['prefix_id' => $this->prefixId('Y'), 'description' => 'Dup yellow', 'original_customer_id' => $dup], $this->adminId);
        $this->db->update('chroma_customers', ['cms_customer_id' => 'CMS-42', 'source' => 'cms'], ['id' => $dup]);

        $moved = $this->lists->mergeCustomers($keep, $dup);
        $this->assertSame(['projects' => 1, 'internal_codes' => 1, 'sell_numbers' => 1], $moved);
        $this->assertSame($keep, (int) $this->db->value('SELECT customer_id FROM chroma_projects WHERE id = ?', [$p1]));
        $this->assertSame($keep, (int) $this->db->value('SELECT customer_id FROM chroma_projects WHERE id = ?', [$p2]));
        $this->assertSame($keep, (int) $this->db->value('SELECT original_customer_id FROM chroma_internal_codes WHERE id = ?', [$e1['id']]));
        $this->assertSame($keep, (int) $this->db->value('SELECT original_customer_id FROM chroma_internal_codes WHERE id = ?', [$e2['id']]));
        $this->assertSame($keep, (int) $this->db->value('SELECT original_customer_id FROM chroma_sell_numbers WHERE id = ?', [$s1['id']]));
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM chroma_projects WHERE customer_id = ?', [$dup]));

        $dupRow = $this->lists->customer($dup);
        $keepRow = $this->lists->customer($keep);
        $this->assertSame(0, (int) $dupRow['active'], 'duplicate is deactivated, not deleted');
        $this->assertStringContainsString('Merged into "Example Packaging"', (string) $dupRow['notes']);
        $this->assertSame('CMS-42', $keepRow['cms_customer_id'], 'the CMS id follows the kept customer');
        $this->assertNull($dupRow['cms_customer_id']);
        $this->assertSame(2, (int) $keepRow['project_count']);
        $this->assertSame(2, (int) $this->db->value('SELECT COUNT(*) FROM chroma_audit_log WHERE action IN ("customer.merge", "customer.merged_away")'));
    }

    public function testMergeRefusesNonsense(): void
    {
        $a = $this->lists->addCustomer(['name' => 'Alpha Print']);
        $b = $this->lists->addCustomer(['name' => 'Beta Print']);
        try {
            $this->lists->mergeCustomers($a, $a);
            $this->fail('merged into itself');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('cannot be merged into itself', $e->getMessage());
        }
        $this->lists->setCustomerActive($a, false);
        try {
            $this->lists->mergeCustomers($a, $b);
            $this->fail('merged into a deactivated customer');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('must be active', $e->getMessage());
        }
        $this->assertSame(1, (int) $this->lists->customer($b)['active']);
    }

    public function testCustomerNamesAreMatchedLoosely(): void
    {
        $this->lists->addCustomer(['name' => 'ACME Labels, Inc.']);
        try {
            $this->lists->addCustomer(['name' => 'acme labels inc']);
            $this->fail('loose duplicate accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('already in the customer list', $e->errors['name']);
        }
        $this->assertSame(1, $this->lists->customers(['q' => 'acme'])['total']);
        $this->assertSame(0, $this->lists->customers(['q' => 'zzz'])['total']);
    }

    public function testCustomerSearchMatchesAnyWordOfTheNameAndTheCmsId(): void
    {
        $this->lists->addCustomer(['name' => 'ACME Labels, Inc.']);
        $this->lists->addCustomer(['name' => 'Mercer Labels']);
        $this->lists->addCustomer(['name' => 'Mercer Packaging']);
        $id = $this->lists->addCustomer(['name' => 'Zed Printing']);
        $this->db->update('chroma_customers', ['cms_customer_id' => 'C-4471'], ['id' => $id]);

        $this->assertSame(2, $this->lists->customers(['q' => 'labels'])['total'], 'a later word of the name is found');
        $this->assertSame(2, $this->lists->customers(['q' => 'mercer'])['total']);
        $this->assertSame(1, $this->lists->customers(['q' => 'mercer pack'])['total'], 'every typed word must start a word');
        $this->assertSame(1, $this->lists->customers(['q' => 'C-4471'])['total'], 'the CMS id is matched exactly');
        $this->assertSame(2, $this->lists->customers(['q' => 'me'])['total'], 'short searches fall back to a prefix match');
        $this->assertSame('Mercer Labels', $this->lists->customers(['q' => 'me'])['rows'][0]['name']);
        $this->assertSame('+acme* +lab*', ListAdmin::fulltextQuery('acme lab'));
        $this->assertSame('', ListAdmin::fulltextQuery('ab'));
    }

    // -------------------------------------------------------- project types

    public function testCreateTypeKeepsOnlyCatalogueFieldsInCatalogueOrder(): void
    {
        $id = $this->types->create([
            'code' => 'press_trial', 'label' => 'Press trial', 'item_label' => 'Runs', 'item_label_singular' => 'Run',
            'has_customer' => '1', 'customer_required' => '0', 'has_stages' => '1', 'color' => 'amber', 'sort_order' => '50',
            'fields' => ['notes', 'bogus_field', 'goal', 'date_submitted'],
        ]);
        $t = $this->types->find($id);
        $this->assertSame(['goal', 'date_submitted', 'notes'], $t['fields']);
        $this->assertSame(0, (int) $t['customer_required']);
        $this->assertTrue($t['uses_global_statuses']);
        $this->assertSame('press_trial', (new ProjectTypes($this->db))->byCode('press_trial')['code']);
        try {
            $this->types->create(['code' => 'press_trial', 'label' => 'Again']);
            $this->fail('duplicate code accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('code', $e->errors);
        }
        try {
            $this->types->create(['code' => 'Bad Code', 'label' => 'Bad']);
            $this->fail('bad code accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('code', $e->errors);
        }
    }

    public function testStagesCanBeAddedReorderedAndOnlyRemovedWhenUnused(): void
    {
        $rnd = $this->typeId('rnd');
        $before = array_column($this->types->stages($rnd), 'code');
        $newId = $this->types->addStage($rnd, ['label' => 'Pilot run', 'is_terminal' => '0']);
        $stages = $this->types->stages($rnd);
        $this->assertSame('pilot_run', end($stages)['code']);
        $this->types->moveStage($newId, -1);
        $codes = array_column($this->types->stages($rnd), 'code');
        $this->assertSame('pilot_run', $codes[count($codes) - 2]);
        $this->types->moveStage($newId, +1);
        $this->assertSame(array_merge($before, ['pilot_run']), array_column($this->types->stages($rnd), 'code'));

        $customer = (int) (new \Chroma\Domain\Customers\Customers($this->db))->internalRndId();
        $project = $this->makeProject($customer, 'At pilot');
        $this->db->update('chroma_projects', ['stage_id' => $newId, 'project_type_id' => $rnd], ['id' => $project]);
        try {
            $this->types->deleteStage($newId);
            $this->fail('stage in use deleted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('cannot be removed', $e->getMessage());
        }
        $this->types->updateStage($newId, ['label' => 'Pilot run', 'active' => '0']);
        $this->assertSame(0, (int) $this->db->value('SELECT active FROM chroma_project_stages WHERE id = ?', [$newId]));
        $this->db->update('chroma_projects', ['stage_id' => null], ['id' => $project]);
        $this->types->deleteStage($newId);
        $this->assertSame($before, array_column($this->types->stages($rnd), 'code'));
    }

    public function testCustomizingStatusesCopiesTheSharedListAndRevertIsBlockedWhenUsed(): void
    {
        $cm = $this->typeId('color_match');
        $global = array_column($this->types->globalStatuses(), 'code');
        $this->assertTrue($this->types->find($cm)['uses_global_statuses']);
        try {
            $this->types->addStatus($cm, ['label' => 'Early']);
            $this->fail('added to a type on the shared list');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Customize for this type', $e->getMessage());
        }

        $this->types->customizeStatuses($cm);
        $own = $this->types->ownStatuses($cm);
        $this->assertSame($global, array_column($own, 'code'));
        $this->assertFalse($this->types->find($cm)['uses_global_statuses']);
        $pt = new ProjectTypes($this->db);
        $this->assertSame((int) $own[0]['id'], (int) $pt->itemStatuses($cm)[0]['id'], 'the lab now sees the type-specific list');

        $sent = $this->types->addStatus($cm, ['label' => 'Sent to customer', 'is_done' => '0', 'color' => 'blue']);
        $this->assertSame('sent_to_customer', $this->db->value('SELECT code FROM chroma_item_statuses WHERE id = ?', [$sent]));
        $this->types->moveStatus($sent, -1);
        $codes = array_column($this->types->ownStatuses($cm), 'code');
        $this->assertSame('sent_to_customer', $codes[count($codes) - 2]);

        // "success" is cleared for an unfinished status; the last not-done status must stay active.
        $this->types->updateStatus($sent, ['label' => 'Sent', 'is_done' => '0', 'is_success' => '1']);
        $this->assertSame(0, (int) $this->db->value('SELECT is_success FROM chroma_item_statuses WHERE id = ?', [$sent]));

        $customer = (int) (new \Chroma\Domain\Customers\Customers($this->db))->findOrCreate('Status Co');
        $project = $this->makeProject($customer);
        $this->db->insert('chroma_project_items', ['project_id' => $project, 'sequence' => 1, 'kind' => 'color', 'description' => 'Blue', 'status_id' => $sent, 'created_at' => $this->db->now(), 'updated_at' => $this->db->now()]);
        try {
            $this->types->revertStatuses($cm);
            $this->fail('reverted while a status is in use');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('cannot be removed', $e->getMessage());
        }
        try {
            $this->types->deleteStatus($sent);
            $this->fail('deleted a status in use');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('cannot be removed', $e->getMessage());
        }
        $this->db->update('chroma_project_items', ['status_id' => null], ['project_id' => $project]);
        $this->types->revertStatuses($cm);
        $this->assertTrue($this->types->find($cm)['uses_global_statuses']);
        $this->assertSame($global, array_column($this->types->globalStatuses(), 'code'), 'the shared list is untouched');
    }

    public function testTheOnlyActiveTypeCannotBeDeactivated(): void
    {
        $this->db->exec("UPDATE chroma_project_types SET active = 0 WHERE code <> 'color_match'");
        try {
            $this->types->update($this->typeId('color_match'), ['label' => 'Color match', 'active' => '0', 'fields' => ['description']]);
            $this->fail('last type deactivated');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('active', $e->errors);
        } finally {
            $this->db->exec('UPDATE chroma_project_types SET active = 1');
        }
    }
}
