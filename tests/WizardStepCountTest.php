<?php
declare(strict_types=1);

namespace Chroma\Tests;

use Chroma\Domain\Projects\Projects;
use Chroma\Http\Wizard\AssignItemCodeWizard;
use Chroma\Http\Wizard\BatchAssignWizard;
use Chroma\Http\Wizard\NewProjectWizard;
use Chroma\Http\Wizard\QuickAssignWizard;
use Chroma\Http\Wizard\VoidCodeWizard;

/**
 * The wizard shell shows "Step n of total" and labels the last step's button "Review". When the step list
 * still depends on the next answer (the project type decides which questions follow), the wizard must say so,
 * or the very first screen reads "Step 1 of 2" with a "Review" button and the total jumps to 7 a click later.
 */
final class WizardStepCountTest extends TestCase
{
    private Projects $projects;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projects = new Projects($this->db, $this->app->audit, $this->app->settings);
    }

    public function testNewProjectCountIsOpenUntilTheTypeIsChosen(): void
    {
        $w = new NewProjectWizard($this->app);
        $this->assertCount(1, $w->steps([]), 'only the type question is known before a type is chosen');
        $this->assertFalse($w->stepCountKnown([]));
        $this->assertTrue($w->stepCountKnown(['type' => 'color_match']));
        $this->assertGreaterThan(2, count($w->steps(['type' => 'color_match'])));
    }

    public function testAssignItemCodeCountIsOpenWhileWhichCodeIsUnanswered(): void
    {
        $p = $this->projects->create(['type' => 'color_match', 'customer_text' => 'Acme', 'title' => 'P', 'items' => [['description' => 'Logo brown']]], $this->adminId);
        $itemId = (int) $this->projects->items()->forProject((int) $p['id'])[0]['id'];
        $w = new AssignItemCodeWizard($this->app);
        // The admin may assign both kinds, so the first screen asks which; that answer decides whether a prefix is asked.
        $state = ['item_id' => $itemId, 'which' => null];
        $this->assertSame(['which'], array_map(fn($s) => $s->key, $w->steps($state)));
        $this->assertFalse($w->stepCountKnown($state));
        $this->assertTrue($w->stepCountKnown(['item_id' => $itemId, 'which' => 'internal']));
        $this->assertTrue($w->stepCountKnown(['item_id' => $itemId, 'which' => 'sell']));
    }

    public function testQuickAssignCountIsOpenUntilTheWantedCodeIsKnown(): void
    {
        $w = new QuickAssignWizard($this->app);
        $this->assertFalse($w->stepCountKnown([]));
        $this->assertFalse($w->stepCountKnown(['product_mode' => 'new']));
        $this->assertTrue($w->stepCountKnown(['product_mode' => 'new', 'want' => 'internal']));
        $this->assertTrue($w->stepCountKnown(['product_mode' => 'new', 'want' => 'sell']));
    }

    public function testBatchAssignCountIsOpenUntilWhatAndPrefixAreChosen(): void
    {
        $p = $this->projects->create(['type' => 'color_match', 'customer_text' => 'Acme', 'title' => 'P', 'items' => [['description' => 'A'], ['description' => 'B']]], $this->adminId);
        $w = new BatchAssignWizard($this->app);
        $base = ['project_id' => (int) $p['id'], 'items' => []];
        $this->assertFalse($w->stepCountKnown($base));
        $this->assertTrue($w->stepCountKnown($base + ['what' => 'internal']));
        // Sell numbers: the prefix is the last known step and may add a suffix question after itself.
        $this->assertFalse($w->stepCountKnown($base + ['what' => 'sell']));
        $this->assertTrue($w->stepCountKnown($base + ['what' => 'sell', 'prefix_id' => $this->prefixId('Y')]));
    }

    public function testSingleQuestionWizardsKeepAKnownCount(): void
    {
        // A wizard with a fixed list says so by default, so its last step still offers "Review".
        $this->assertTrue((new VoidCodeWizard($this->app))->stepCountKnown([]));
    }
}
