<?php
declare(strict_types=1);

namespace Chroma\Http\Wizard;

use Chroma\Http\Tips;
use Chroma\Core\NotFoundException;
use Chroma\Core\Permissions;
use Chroma\Core\Request;
use Chroma\Core\ValidationException;
use Chroma\Domain\ProjectTypes\ProjectTypes;
use Chroma\Domain\Projects\Projects;
use Chroma\Domain\Projects\Visibility;

/**
 * Easy mode: add one color / trial / item to a project (/wizard/add-item?project_id=N[&kind=existing_product]).
 * Calls Items::add(), like the Expert inline row.
 */
final class AddItemWizard extends Wizard
{
    private ?Projects $projects = null;

    private function projects(): Projects
    {
        return $this->projects ??= new Projects($this->app->db, $this->app->audit, $this->app->settings);
    }

    public function key(): string
    {
        return 'add-item';
    }

    public function title(): string
    {
        return 'Add to project';
    }

    public function permissions(): array
    {
        return [Permissions::COLORS_EDIT];
    }

    public function initialState(Request $request): array
    {
        $state = ['project_id' => $request->int('project_id')];
        $kind = $request->str('kind');
        if (isset(ProjectTypes::ITEM_KINDS[$kind])) {
            $state['kind'] = $kind;
        }
        $this->refuseClosed($state);
        return $state;
    }

    /**
     * A completed or cancelled project is refused at the start (the same message the domain gives on commit),
     * so the lab does not walk through the steps for nothing. Only called from start(): the error flashes back
     * to the project page.
     * @param array<string,mixed> $state
     */
    private function refuseClosed(array $state): void
    {
        $p = !empty($state['project_id']) ? $this->projects()->find((int) $state['project_id']) : null;
        if ($p !== null && (new Visibility($this->app->db, $this->app->auth))->canView($p)) {
            Projects::assertOpen($p, 'add to it');
        }
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function project(array $state): array
    {
        $p = !empty($state['project_id']) ? $this->projects()->find((int) $state['project_id']) : null;
        if ($p === null || !(new Visibility($this->app->db, $this->app->auth))->canView($p)) {
            throw new NotFoundException('Open a project first, then choose "Add" from its page.');
        }
        return $p;
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $project */
    private function kind(array $state, array $project): string
    {
        $type = $this->projects()->types()->byId((int) $project['project_type_id']);
        return (string) ($state['kind'] ?? $type['default_item_kind'] ?? 'color');
    }

    public function steps(array $state): array
    {
        $project = $this->project($state);
        $kind = $this->kind($state, $project);
        $singular = mb_strtolower((string) $project['item_label_singular']);
        $what = [];
        if ($kind === 'existing_product') {
            $what[] = ['name' => 'existing_ref', 'type' => 'lookup', 'label' => 'Which product?', 'lookup' => '/api/codes/search?q=', 'placeholder' => 'E number or sell number', 'help' => 'Type an E number or sell number and pick it from the list.'];
            $what[] = ['name' => 'description', 'type' => 'text', 'label' => 'Description', 'maxlength' => 500, 'help' => 'Fills in from the code you pick; type one if it has no code.'];
        } else {
            if ($kind === 'color') {
                $what[] = ['name' => 'description', 'type' => 'text', 'label' => 'What do you call this color?', 'required' => true, 'maxlength' => 500, 'placeholder' => 'e.g. Logo brown', 'help' => 'How it appears on the job sheet.', 'required_message' => 'Name the color in a few words.'];
                $what[] = ['name' => 'pantone', 'type' => 'text', 'label' => 'Pantone or reference', 'maxlength' => 60, 'optional_text' => true, 'placeholder' => 'e.g. 300 C', 'help' => 'The Pantone number or the customer\'s own reference, if there is one.'];
                $what[] = ['name' => 'hex_color', 'type' => 'text', 'label' => 'Color swatch', 'maxlength' => 7, 'optional_text' => true, 'placeholder' => '#8B5A2B', 'help' => 'A web colour code like #8B5A2B, if you have one; it draws a small swatch next to the name.'];
            } else {
                $what[] = ['name' => 'description', 'type' => 'text', 'label' => ucfirst($singular) . ' description', 'required' => true, 'maxlength' => 500, 'placeholder' => 'e.g. Trial 3: 2% more wax', 'required_message' => 'Describe the ' . $singular . ' in a few words.'];
            }
        }
        $steps = [new Step('what', $kind === 'existing_product' ? 'Which product is involved?' : 'What is the ' . $singular . '?', 'Project ' . $project['project_number'] . ' · ' . $project['title'], $what)];

        $details = [];
        $db = $this->app->db;
        $ink = $db->all('SELECT id, name FROM chroma_ink_series WHERE active = 1 ORDER BY sort_order, name');
        if ($ink && $kind !== 'existing_product') {
            $details[] = ['name' => 'ink_series_id', 'type' => 'select', 'label' => 'Ink series', 'placeholder' => 'Not set', 'options' => array_map(fn($r) => [(int) $r['id'], $r['name']], $ink), 'default' => $project['default_ink_series_id']];
        }
        if ($kind !== 'existing_product') {
            $details[] = ['name' => 'substrate', 'type' => 'text', 'label' => 'Substrate', 'placeholder' => 'e.g. SBS board, PE film', 'default' => $project['default_substrate_text'], 'optional_text' => true];
        }
        $coat = $db->all('SELECT id, name FROM chroma_coatings WHERE active = 1 ORDER BY sort_order, name');
        if ($coat && $kind !== 'existing_product') {
            $details[] = ['name' => 'coating_id', 'type' => 'select', 'label' => 'Coating', 'placeholder' => 'Not set', 'options' => array_map(fn($r) => [(int) $r['id'], $r['name']], $coat), 'default' => $project['default_coating_id']];
        }
        if ($kind === 'color') {
            $details[] = ['name' => 'proof_count', 'type' => 'number', 'label' => 'Proofs made so far', 'min' => 0, 'max' => 999, 'default' => 0, 'help' => 'Leave at 0 if none yet.'];
            $details[] = ['name' => 'is_digital_match', 'type' => 'checkbox', 'label' => 'Digital match', 'tip' => Tips::DIGITAL_MATCH, 'help' => 'Matched digitally; the formula card will say "Digital Match".'];
        }
        $details[] = ['name' => 'notes', 'type' => 'textarea', 'label' => 'Notes', 'rows' => 3, 'optional_text' => true];
        $steps[] = new Step('details', 'Any details?', 'Everything here is optional. Defaults come from the project.', $details);
        return $steps;
    }

    public function validateStep(Step $step, array $input, array $state): array
    {
        $clean = parent::validateStep($step, $input, $state);
        if ($step->key === 'what') {
            $ref = (string) ($clean['existing_ref'] ?? '');
            if (isset($clean['existing_ref']) && $ref === '' && trim((string) ($clean['description'] ?? '')) === '') {
                $this->fail(['existing_ref' => 'Pick the product from the list, or describe it.']);
            }
            if ($ref !== '') {
                $clean['existing_ref_label'] = (string) ($input['existing_ref_label'] ?? $ref);
            }
        }
        return $clean;
    }

    public function review(array $state): array
    {
        $project = $this->project($state);
        $rows = [['label' => 'Project', 'value' => $project['project_number'] . ' · ' . $project['title']]];
        if (!empty($state['existing_ref'])) {
            $rows[] = ['label' => 'Existing product', 'value' => (string) ($state['existing_ref_label'] ?? $state['existing_ref']), 'step' => 'what'];
        }
        $rows[] = ['label' => 'Description', 'value' => (string) ($state['description'] ?? ''), 'step' => 'what'];
        foreach (['pantone' => 'Pantone', 'hex_color' => 'Hex color', 'substrate' => 'Substrate', 'proof_count' => 'Proofs', 'notes' => 'Notes'] as $k => $l) {
            if (!empty($state[$k]) && $state[$k] !== '0') {
                $rows[] = ['label' => $l, 'value' => (string) $state[$k], 'step' => $k === 'pantone' || $k === 'hex_color' ? 'what' : 'details'];
            }
        }
        foreach (['ink_series_id' => ['chroma_ink_series', 'Ink series'], 'coating_id' => ['chroma_coatings', 'Coating']] as $k => [$table, $l]) {
            if (!empty($state[$k])) {
                $rows[] = ['label' => $l, 'value' => (string) ($this->app->db->value("SELECT name FROM $table WHERE id = ?", [(int) $state[$k]]) ?? ''), 'step' => 'details'];
            }
        }
        if (!empty($state['is_digital_match']) && $state['is_digital_match'] !== '0') {
            $rows[] = ['label' => 'Digital match', 'value' => 'Yes', 'step' => 'details'];
        }
        return $rows;
    }

    public function commit(array $state): WizardResult
    {
        $project = $this->project($state);
        $data = ['kind' => $this->kind($state, $project)];
        foreach (['description', 'pantone', 'hex_color', 'existing_ref', 'ink_series_id', 'substrate', 'coating_id', 'proof_count', 'is_digital_match', 'notes'] as $k) {
            if (array_key_exists($k, $state)) {
                $data[$k] = $state[$k];
            }
        }
        $item = $this->projects()->items()->add((int) $project['id'], $data, $this->app->auth->id());
        $singular = mb_strtolower((string) $project['item_label_singular']);
        $progress = $this->projects()->progress((int) $project['id'], $project['type_code']);
        $actions = [['label' => 'Open the project', 'href' => '/projects/' . $project['id'] . '#items', 'primary' => true]];
        if ($progress['total'] < $this->projects()->maxItems()) {
            $actions[] = ['label' => 'Add another ' . $singular, 'href' => url('/wizard/add-item', ['project_id' => $project['id'], 'kind' => $state['kind'] ?? null])];
        }
        if ($this->app->auth->can(Permissions::CODES_ASSIGN_INTERNAL) && $item['internal_code_id'] === null) {
            $actions[] = ['label' => 'Assign an E number to it', 'href' => url('/wizard/assign-item-code', ['item_id' => $item['id'], 'which' => 'internal'])];
        }
        return new WizardResult(
            ucfirst($singular) . ' added',
            sprintf('"%s" is now %s %d of %d on project %d.', $item['description'], $singular, $item['sequence'], $progress['total'], $project['project_number']),
            [],
            [],
            $actions
        );
    }

    public function expertUrl(array $state): ?string
    {
        return !empty($state['project_id']) ? url('/mode', ['set' => 'expert', 'return' => '/projects/' . (int) $state['project_id'] . '#items']) : null;
    }

    public function cancelUrl(array $state): string
    {
        return !empty($state['project_id']) ? '/projects/' . (int) $state['project_id'] : '/projects';
    }

    public function commitLabel(): string
    {
        return 'Add it';
    }
}
