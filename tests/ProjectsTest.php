<?php
declare(strict_types=1);

namespace Chroma\Tests;

use Chroma\Core\ValidationException;
use Chroma\Domain\Projects\Projects;
use Chroma\Domain\Projects\Tasks;
use Chroma\Domain\Projects\Visibility;

final class ProjectsTest extends TestCase
{
    private Projects $projects;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projects = new Projects($this->db, $this->app->audit, $this->app->settings);
    }

    /** @param array<string,mixed> $extra */
    private function colorMatch(array $extra = []): array
    {
        return $this->projects->create($extra + [
            'type' => 'color_match',
            'customer_text' => 'Acme Cartons',
            'title' => 'Spring carton colors',
            'description' => 'Match three Pantone colors for the spring carton run.',
            'items' => [['description' => 'Pantone 300 C'], ['description' => 'Pantone 485 C'], ['description' => 'Warm Red']],
        ], $this->adminId);
    }

    public function testProjectNumbersStartAt101AndFollowTheSequence(): void
    {
        $a = $this->colorMatch();
        $b = $this->colorMatch(['title' => 'Second']);
        $this->assertSame(101, (int) $a['project_number']);
        $this->assertSame(102, (int) $b['project_number']);
        $this->assertSame('103', (string) $this->db->value("SELECT next_value FROM chroma_sequences WHERE name = 'project_number'"));

        // An admin-raised sequence wins over MAX+1; MAX+1 wins over a stale sequence.
        $this->db->exec("UPDATE chroma_sequences SET next_value = 500 WHERE name = 'project_number'");
        $c = $this->colorMatch(['title' => 'Third']);
        $this->assertSame(500, (int) $c['project_number']);
        $this->db->exec("UPDATE chroma_sequences SET next_value = 1 WHERE name = 'project_number'");
        $d = $this->colorMatch(['title' => 'Fourth']);
        $this->assertSame(501, (int) $d['project_number']);
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM chroma_audit_log WHERE action = "project.create" AND row_id = ?', [$a['id']]));
    }

    public function testDefaultsFillDownToItemsAndCanBeOverridden(): void
    {
        $now = $this->db->now();
        $this->db->exec('DELETE FROM chroma_ink_series WHERE name = "Aqua Flex"');
        $this->db->exec('DELETE FROM chroma_coatings WHERE name = "Gloss AQ"');
        $this->db->exec('DELETE FROM chroma_substrates WHERE name = "SBS board"');
        $ink = $this->db->insert('chroma_ink_series', ['name' => 'Aqua Flex', 'active' => 1, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $coat = $this->db->insert('chroma_coatings', ['name' => 'Gloss AQ', 'active' => 1, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $this->db->insert('chroma_substrates', ['name' => 'SBS board', 'active' => 1, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $tech = $this->makeUser('tech', 'Lab');
        $p = $this->colorMatch([
            'default_ink_series_id' => $ink, 'default_coating_id' => $coat, 'default_substrate' => 'sbs board', 'assigned_user_id' => $tech,
            'items' => [['description' => 'Uses defaults'], ['description' => 'Overrides', 'coating_id' => '', 'substrate' => 'PE film', 'assigned_user_id' => $this->adminId]],
        ]);
        $items = $this->projects->items()->forProject((int) $p['id']);
        $this->assertCount(2, $items);
        $this->assertSame($ink, (int) $items[0]['ink_series_id']);
        $this->assertSame($coat, (int) $items[0]['coating_id']);
        $this->assertSame('sbs board', $items[0]['substrate_text']);
        $this->assertNotNull($items[0]['substrate_id'], 'typed substrate matches the admin list case-insensitively');
        $this->assertSame($tech, (int) $items[0]['assigned_user_id']);
        $this->assertNull($items[1]['coating_id']);
        $this->assertSame('PE film', $items[1]['substrate_text']);
        $this->assertNull($items[1]['substrate_id']);
        $this->assertSame($this->adminId, (int) $items[1]['assigned_user_id']);
        $this->assertSame('pending', $items[0]['status_code']);
        $this->assertSame(today(), $p['date_submitted']);
        $this->assertSame('Acme Cartons', $p['customer_name']);
    }

    public function testCustomerRequiredAndTitleRequiredWithPlainMessages(): void
    {
        try {
            $this->projects->create(['type' => 'color_match', 'title' => '', 'items' => []], $this->adminId);
            $this->fail('expected validation');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('title', $e->errors);
            $this->assertArrayHasKey('customer_id', $e->errors);
            $this->assertStringContainsString('Choose the customer', $e->errors['customer_id']);
        }
    }

    public function testRndProjectUsesInternalCustomerStageTeamAndConfidential(): void
    {
        $lead = $this->makeUser('lead', 'R&D');
        $mate = $this->makeUser('mate', 'Lab');
        $type = $this->projects->types()->byCode('rnd');
        $stage = (int) $type['stages'][1]['id'];
        $p = $this->projects->create([
            'type' => 'rnd', 'title' => 'Low-migration white', 'goal' => 'A white that passes migration tests.',
            'assigned_user_id' => $lead, 'team' => [$mate], 'stage_id' => $stage, 'confidential' => '1', 'target_date' => '2027-01-15',
            'items' => [['description' => 'Trial 1: TiO2 swap']],
        ], $this->adminId);
        $this->assertSame('Precision Ink R&D', $p['customer_name']);
        $this->assertSame(1, (int) $p['confidential']);
        $this->assertSame('Bench', $p['stage_label']);
        $this->assertSame([$mate], array_map(fn($u) => (int) $u['id'], $this->projects->team((int) $p['id'])));
        $items = $this->projects->items()->forProject((int) $p['id']);
        $this->assertSame('trial', $items[0]['kind']);

        $this->projects->setStage((int) $p['id'], (int) $type['stages'][2]['id'], $this->adminId);
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM chroma_project_activity WHERE project_id = ? AND kind = "stage_changed"', [$p['id']]));
        try {
            $this->projects->setStage((int) $p['id'], 999999, $this->adminId);
            $this->fail('bad stage accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('stage_id', $e->errors);
        }
    }

    public function testVisibilityOfConfidentialProjects(): void
    {
        $lead = $this->makeUser('lead', 'Lab');
        $mate = $this->makeUser('mate', 'Lab');
        $other = $this->makeUser('other', 'Lab');
        $rnd = $this->makeUser('rndperson', 'R&D');
        $sales = $this->makeUser('sales', 'Sales');
        $secret = $this->projects->create(['type' => 'rnd', 'title' => 'Secret', 'goal' => 'x', 'assigned_user_id' => $lead, 'team' => [$mate], 'confidential' => '1'], $this->adminId);
        $open = $this->colorMatch(['title' => 'Public']);

        $check = function (int $uid) use ($secret, $open): array {
            $this->app->auth->actAs($uid);
            $vis = new Visibility($this->db, $this->app->auth);
            $ids = array_map(fn($r) => (int) $r['id'], $this->projects->list(['status' => 'all'], $vis, 100)['rows']);
            return ['secret' => $vis->canView($secret), 'open' => $vis->canView($open), 'listed' => in_array((int) $secret['id'], $ids, true)];
        };
        $this->assertSame(['secret' => true, 'open' => true, 'listed' => true], $check($lead), 'lead');
        $this->assertSame(['secret' => true, 'open' => true, 'listed' => true], $check($mate), 'team member');
        $this->assertSame(['secret' => true, 'open' => true, 'listed' => true], $check($rnd), 'R&D group');
        $this->assertSame(['secret' => true, 'open' => true, 'listed' => true], $check($this->adminId), 'admin');
        $this->assertSame(['secret' => false, 'open' => true, 'listed' => false], $check($other), 'other lab user');
        $this->assertSame(['secret' => false, 'open' => true, 'listed' => false], $check($sales), 'sales');
        $this->app->auth->actAs($this->adminId);
    }

    public function testListFiltersAndSearchByNumberTextAndCode(): void
    {
        $tech = $this->makeUser('tech', 'Lab');
        $a = $this->colorMatch(['title' => 'Blister purple', 'assigned_user_id' => $tech]);
        $b = $this->colorMatch(['title' => 'Green labels', 'customer_text' => 'Other Co', 'date_submitted' => '2024-01-10', 'items' => [['description' => 'Forest green']]]);
        $items = $this->projects->items();
        $codes = new \Chroma\Domain\Codes\CodeAssigner($this->db, $this->app->audit);
        $first = $items->forProject((int) $a['id'])[0];
        $items->assignInternal((int) $first['id'], [], $this->adminId, $codes);          // E9102
        $items->assignSell((int) $first['id'], ['prefix_id' => $this->prefixId('Y')], $this->adminId, $codes); // Y1715
        $this->projects->complete((int) $b['id'], $this->adminId);
        $vis = new Visibility($this->db, $this->app->auth);

        $ids = fn(array $f) => array_map(fn($r) => (int) $r['project_number'], $this->projects->list($f, $vis, 50)['rows']);
        $this->assertSame([101], $ids([]), 'open by default');
        $this->assertSame([102], $ids(['status' => 'completed']));
        $this->assertSame([101, 102], $ids(['status' => 'all']));
        $this->assertSame([101], $ids(['status' => 'all', 'tech' => $tech]));
        $this->assertSame([102], $ids(['status' => 'all', 'customer_id' => (int) $b['customer_id']]));
        $this->assertSame([102], $ids(['status' => 'all', 'from' => '2024-01-01', 'to' => '2024-01-31']));
        $this->assertSame([101], $ids(['status' => 'all', 'mine' => $tech]));
        $this->assertSame([101], $ids(['status' => 'all', 'q' => '101']));
        $this->assertSame([102], $ids(['status' => 'all', 'q' => 'green']));
        $this->assertSame([101], $ids(['status' => 'all', 'q' => 'E9102']));
        $this->assertSame([101], $ids(['status' => 'all', 'q' => 'y-1715']));
        $this->assertSame([101], $ids(['status' => 'all', 'q' => 'Pantone 300']), 'item descriptions are searched too');
        $this->assertSame([], $ids(['status' => 'all', 'q' => 'nothing-like-this']));
        $this->assertSame(2, $this->projects->list(['status' => 'all'], $vis, 1)['total']);
        $this->assertCount(1, $this->projects->list(['status' => 'all'], $vis, 1)['rows']);
    }

    public function testStatusProgressAndAutoComplete(): void
    {
        $p = $this->colorMatch();
        $items = $this->projects->items();
        $rows = $items->forProject((int) $p['id']);
        $matched = (int) $this->db->value('SELECT id FROM chroma_item_statuses WHERE code = "matched" AND project_type_id IS NULL');
        $notNeeded = (int) $this->db->value('SELECT id FROM chroma_item_statuses WHERE code = "not_needed" AND project_type_id IS NULL');
        $items->setStatus((int) $rows[0]['id'], $matched, $this->adminId);
        $items->setStatus((int) $rows[1]['id'], $matched, $this->adminId);
        $prog = $this->projects->progress((int) $p['id'], 'color_match');
        $this->assertSame(['total' => 3, 'done' => 2, 'pct' => 67, 'word' => 'matched', 'label' => '2 of 3 matched'], $prog);
        $this->assertSame('open', $this->projects->get((int) $p['id'])['status']);
        $items->setStatus((int) $rows[2]['id'], $notNeeded, $this->adminId);
        $done = $this->projects->get((int) $p['id']);
        $this->assertSame('completed', $done['status']);
        $this->assertSame(today(), $done['date_completed']);
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM chroma_project_activity WHERE project_id = ? AND kind = "completed"', [$p['id']]));

        // Manual reopen then complete again, with permission checks living in the HTTP layer.
        $this->projects->reopen((int) $p['id'], $this->adminId);
        $this->assertSame('open', $this->projects->get((int) $p['id'])['status']);
        $this->projects->complete((int) $p['id'], $this->adminId);
        $this->assertSame('completed', $this->projects->get((int) $p['id'])['status']);
        $this->projects->reopen((int) $p['id'], $this->adminId);
        $this->projects->cancel((int) $p['id'], 'Customer withdrew the job', $this->adminId);
        $c = $this->projects->get((int) $p['id']);
        $this->assertSame('cancelled', $c['status']);
        $this->assertSame('Customer withdrew the job', $c['cancelled_reason']);
        try {
            $this->projects->cancel((int) $p['id'], '', $this->adminId);
            $this->fail('cancel without reason');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors);
        }
        $this->assertGreaterThanOrEqual(4, (int) $this->db->value('SELECT COUNT(*) FROM chroma_audit_log WHERE table_name = "chroma_projects" AND row_id = ? AND action IN ("project.complete","project.reopen","project.cancel")', [$p['id']]));
    }

    public function testUpdateIsPartialAndIgnoresFieldsTheTypeDoesNotUse(): void
    {
        $p = $this->colorMatch();
        $after = $this->projects->update((int) $p['id'], ['title' => 'Renamed', 'goal' => 'should be ignored'], $this->adminId);
        $this->assertSame('Renamed', $after['title']);
        $this->assertNull($after['goal']);
        $this->assertSame($p['description'], $after['description']);
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM chroma_audit_log WHERE action = "project.update" AND row_id = ?', [$p['id']]));
        $same = $this->projects->update((int) $p['id'], ['title' => 'Renamed'], $this->adminId);
        $this->assertSame('Renamed', $same['title']);
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM chroma_audit_log WHERE action = "project.update" AND row_id = ?', [$p['id']]), 'no-op update is not audited');
        $this->projects->update((int) $p['id'], ['customer_text' => 'Brand New Customer'], $this->adminId);
        $this->assertSame('Brand New Customer', $this->projects->get((int) $p['id'])['customer_name']);
    }

    public function testTasks(): void
    {
        $p = $this->colorMatch();
        $tasks = new Tasks($this->db, $this->app->audit);
        $t = $tasks->add((int) $p['id'], ['title' => 'Send drawdowns', 'owner_user_id' => $this->adminId, 'due_date' => '2026-12-01'], $this->adminId);
        $this->assertSame(0, (int) $t['done']);
        $done = $tasks->setDone((int) $t['id'], true, $this->adminId);
        $this->assertSame(1, (int) $done['done']);
        $this->assertSame($this->adminId, (int) $done['done_by']);
        $this->assertNotNull($done['done_at']);
        $this->assertSame(0, (int) $tasks->setDone((int) $t['id'], false, $this->adminId)['done']);
        try {
            $tasks->add((int) $p['id'], ['title' => '', 'due_date' => 'tomorrow'], $this->adminId);
            $this->fail('bad task accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('title', $e->errors);
            $this->assertArrayHasKey('due_date', $e->errors);
        }
        $this->assertSame(3, (int) $this->db->value('SELECT COUNT(*) FROM chroma_audit_log WHERE table_name = "chroma_project_tasks" AND row_id = ?', [$t['id']]));
        $tasks->remove((int) $t['id'], $this->adminId);
        $this->assertSame([], $tasks->forProject((int) $p['id']));
    }

    public function testNotesAndMentions(): void
    {
        $p = $this->colorMatch();
        $act = $this->projects->activity();
        $act->note((int) $p['id'], "Drawdowns look good, @admin please check **E9102**", $this->adminId);
        $feed = $act->forProject((int) $p['id']);
        $this->assertSame('note', $feed[0]['kind']);
        $this->assertSame(['admin'], json_decode((string) $feed[0]['meta_json'], true)['mentions']);
        $this->assertSame('created', end($feed)['kind']);
        try {
            $act->note((int) $p['id'], '   ', $this->adminId);
            $this->fail('empty note accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('body', $e->errors);
        }
    }

    public function testTypedInCustomerFromTheLookupComponentIsCreated(): void
    {
        // The Expert page's lookup posts a new name as customer_id_text; the wizard posts customer_text. Both must work.
        $p = $this->projects->create(['type' => 'color_match', 'customer_id' => '', 'customer_id_text' => 'Reviewer Customer Z', 'title' => 'Expert typed customer'], $this->adminId);
        $this->assertSame('Reviewer Customer Z', $p['customer_name']);
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM chroma_customers WHERE name = ?', ['Reviewer Customer Z']));
        $again = $this->projects->create(['type' => 'color_match', 'customer_text' => 'reviewer customer z', 'title' => 'Wizard typed customer'], $this->adminId);
        $this->assertSame((int) $p['customer_id'], (int) $again['customer_id'], 'same customer, not a duplicate');
        $this->projects->update((int) $p['id'], ['customer_id' => '', 'customer_id_text' => 'Another New One'], $this->adminId);
        $this->assertSame('Another New One', $this->projects->get((int) $p['id'])['customer_name']);
    }

    public function testTeamCanBeClearedCompletely(): void
    {
        $mate = $this->makeUser('mate', 'Lab');
        $p = $this->projects->create(['type' => 'rnd', 'title' => 'Team test', 'goal' => 'g', 'team' => [$mate], 'confidential' => '1'], $this->adminId);
        $this->assertSame([$mate], array_map(fn($u) => (int) $u['id'], $this->projects->team((int) $p['id'])));
        // What the Easy edit page sends when every option is deselected: team present, but empty.
        $this->projects->update((int) $p['id'], ['title' => 'Team test', 'team' => []], $this->adminId);
        $this->assertSame([], $this->projects->team((int) $p['id']), 'the last team member can be removed');
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM chroma_project_activity WHERE project_id = ? AND kind = "team_changed"', [$p['id']]));
        $this->app->auth->actAs($mate);
        $this->assertFalse((new Visibility($this->db, $this->app->auth))->canView($this->projects->get((int) $p['id'])), 'a removed member loses access to a confidential project');
        $this->app->auth->actAs($this->adminId);
    }

    public function testCompletedAndCancelledProjectsRefuseChangesUntilReopened(): void
    {
        $p = $this->colorMatch();
        $items = $this->projects->items();
        $first = $items->forProject((int) $p['id'])[0];
        $matched = (int) $this->db->value('SELECT id FROM chroma_item_statuses WHERE code = "matched" AND project_type_id IS NULL');
        $this->projects->complete((int) $p['id'], $this->adminId);
        foreach ([
            fn() => $this->projects->update((int) $p['id'], ['title' => 'Changed after completion'], $this->adminId),
            fn() => $items->add((int) $p['id'], ['description' => 'Late color'], $this->adminId),
            fn() => $items->setStatus((int) $first['id'], $matched, $this->adminId),
            fn() => $items->update((int) $first['id'], ['notes' => 'late note'], $this->adminId),
            fn() => $items->remove((int) $first['id'], $this->adminId),
            fn() => $items->reorder((int) $p['id'], null, (int) $first['id'], 'down', $this->adminId),
            fn() => $items->assignInternal((int) $first['id'], [], $this->adminId, new \Chroma\Domain\Codes\CodeAssigner($this->db, $this->app->audit)),
        ] as $i => $write) {
            try {
                $write();
                $this->fail("write $i accepted on a completed project");
            } catch (ValidationException $e) {
                $this->assertStringContainsString('Project ' . $p['project_number'] . ' is completed', $e->getMessage(), "write $i");
                $this->assertStringContainsString('Reopen it', $e->getMessage());
            }
        }
        $this->assertSame($p['title'], $this->projects->get((int) $p['id'])['title']);
        $this->assertSame(3, $items->count((int) $p['id']));
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM chroma_internal_codes'));
        // Moving an item into a closed project is refused too.
        $other = $this->colorMatch();
        try {
            $items->move((int) $items->forProject((int) $other['id'])[0]['id'], (int) $p['id'], $this->adminId);
            $this->fail('moved into a completed project');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('to_project_id', $e->errors);
        }
        // Reopen: everything works again. Cancelled behaves the same way.
        $this->projects->reopen((int) $p['id'], $this->adminId);
        $this->assertSame('Changed', $this->projects->update((int) $p['id'], ['title' => 'Changed'], $this->adminId)['title']);
        $this->projects->cancel((int) $p['id'], 'Customer withdrew', $this->adminId);
        try {
            $items->add((int) $p['id'], ['description' => 'Late color'], $this->adminId);
            $this->fail('add accepted on a cancelled project');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('is cancelled', $e->getMessage());
        }
        // Status changes do not go through update(): complete/cancel/reopen keep their own rules.
        $this->projects->reopen((int) $p['id'], $this->adminId);
        $this->assertSame('open', $this->projects->get((int) $p['id'])['status']);
    }

    public function testMoveTypeaheadListsOpenProjectsFirst(): void
    {
        $open = $this->colorMatch();
        $done = $this->colorMatch();
        $this->projects->complete((int) $done['id'], $this->adminId);
        $vis = new Visibility($this->db, $this->app->auth);
        $rows = $this->projects->search('', $vis, 15);
        $this->assertSame([(int) $open['id']], array_map(fn($r) => $r['id'], $rows), 'no words: only open projects');
        $rows = $this->projects->search((string) $done['project_number'], $vis, 15);
        $this->assertSame((int) $done['id'], $rows[0]['id']);
        $this->assertStringContainsString('Completed', $rows[0]['sub']);
    }
}
