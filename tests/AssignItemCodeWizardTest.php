<?php
declare(strict_types=1);

namespace Chroma\Tests;

use Chroma\Core\Request;
use Chroma\Domain\Codes\CodeAssigner;
use Chroma\Domain\Projects\Projects;
use Chroma\Http\Wizard\AssignItemCodeWizard;

/**
 * The "which code?" question must stay in the step list after it is answered. Dropping it renumbered the
 * following steps (the prefix screen was skipped), Back landed on the wrong screen and the review's
 * "Change" link had nowhere to go.
 */
final class AssignItemCodeWizardTest extends TestCase
{
    private Projects $projects;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projects = new Projects($this->db, $this->app->audit, $this->app->settings);
    }

    private function itemId(): int
    {
        $p = $this->projects->create(['type' => 'color_match', 'customer_text' => 'Acme', 'title' => 'P', 'items' => [['description' => 'Logo brown']]], $this->adminId);
        return (int) $this->projects->items()->forProject((int) $p['id'])[0]['id'];
    }

    private function request(array $query): Request
    {
        return new Request('GET', '/wizard/assign-item-code', $query, [], [], [], [], '127.0.0.1');
    }

    public function testTheWhichQuestionStaysFirstAfterItIsAnswered(): void
    {
        $w = new AssignItemCodeWizard($this->app);
        $state = $w->initialState($this->request(['item_id' => (string) $this->itemId()]));
        $this->assertSame(['which'], array_map(fn($s) => $s->key, $w->steps($state)));

        $state = array_merge($state, $w->validateStep($w->steps($state)[0], ['which' => 'sell'], $state));
        $keys = array_map(fn($s) => $s->key, $w->steps($state));
        $this->assertSame('which', $keys[0], 'the answered question must remain step 1');
        $this->assertSame('prefix', $keys[1], 'the prefix screen must be step 2, not skipped');
        $this->assertContains('description', $keys);

        $state['which'] = 'internal';
        $this->assertSame(['which', 'description'], array_map(fn($s) => $s->key, $w->steps($state)));
    }

    public function testTheQuestionIsSkippedWhenTheKindWasChosenOnTheProjectPage(): void
    {
        $w = new AssignItemCodeWizard($this->app);
        $state = $w->initialState($this->request(['item_id' => (string) $this->itemId(), 'which' => 'sell']));
        $keys = array_map(fn($s) => $s->key, $w->steps($state));
        $this->assertSame('prefix', $keys[0]);
        $this->assertNotContains('which', $keys);
    }

    public function testTheQuestionIsSkippedWhenOnlyOneKindIsStillPossible(): void
    {
        $itemId = $this->itemId();
        (new CodeAssigner($this->db, $this->app->audit))->assignInternal(['description' => 'Logo brown', 'project_item_id' => $itemId], $this->adminId);
        $w = new AssignItemCodeWizard($this->app);
        $state = $w->initialState($this->request(['item_id' => (string) $itemId]));
        $keys = array_map(fn($s) => $s->key, $w->steps($state));
        $this->assertNotContains('which', $keys, 'the item already has an E number, so only a sell number can follow');
        $this->assertSame('prefix', $keys[0]);
        $this->assertTrue($w->stepCountKnown($state));
    }
}
