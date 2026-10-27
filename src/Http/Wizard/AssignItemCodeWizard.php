<?php
declare(strict_types=1);

namespace Chroma\Http\Wizard;

use Chroma\Http\Tips;
use Chroma\Core\ForbiddenException;
use Chroma\Core\NotFoundException;
use Chroma\Core\Permissions;
use Chroma\Core\Request;
use Chroma\Core\ValidationException;
use Chroma\Domain\Codes\CodeAssigner;
use Chroma\Domain\Codes\CodeFormat;
use Chroma\Domain\Codes\QuickAssign;
use Chroma\Domain\Projects\Projects;
use Chroma\Domain\Projects\Visibility;

/**
 * Easy mode: assign an E number or a sell number to one project item
 * (/wizard/assign-item-code?item_id=N&which=internal|sell). Goes through
 * Items::assignInternal / assignSell → CodeAssigner, like the Expert popover.
 */
final class AssignItemCodeWizard extends Wizard
{
    private ?Projects $projects = null;

    private function projects(): Projects
    {
        return $this->projects ??= new Projects($this->app->db, $this->app->audit, $this->app->settings);
    }

    public function key(): string
    {
        return 'assign-item-code';
    }

    public function title(): string
    {
        return 'Assign a code';
    }

    public function initialState(Request $request): array
    {
        $which = $request->str('which');
        $state = ['item_id' => $request->int('item_id'), 'which' => in_array($which, ['internal', 'sell'], true) ? $which : null];
        // Chosen with the "Assign E#" / "Assign sell number" button on the project page: no need to ask again.
        $state['which_preset'] = $state['which'] !== null;
        // Refused at the start when the project is completed or cancelled (same message as the domain gives on commit).
        $item = !empty($state['item_id']) ? $this->projects()->items()->find((int) $state['item_id']) : null;
        $project = $item ? $this->projects()->find((int) $item['project_id']) : null;
        if ($project !== null && (new Visibility($this->app->db, $this->app->auth))->canView($project)) {
            Projects::assertOpen($project, 'assign codes from it');
        }
        return $state;
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function item(array $state): array
    {
        $item = !empty($state['item_id']) ? $this->projects()->items()->find((int) $state['item_id']) : null;
        $project = $item ? $this->projects()->find((int) $item['project_id']) : null;
        if ($item === null || $project === null || !(new Visibility($this->app->db, $this->app->auth))->canView($project)) {
            throw new NotFoundException('Open the project first, then choose "Assign" on a color or item.');
        }
        return $item + ['project' => $project];
    }

    private function canInternal(): bool
    {
        return $this->app->auth->can(Permissions::CODES_ASSIGN_INTERNAL);
    }

    private function canSell(): bool
    {
        return $this->app->auth->can(Permissions::CODES_ASSIGN_SELL);
    }

    /** Which kind of code this run assigns, once known. @param array<string,mixed> $state */
    private function which(array $state): ?string
    {
        $w = $state['which'] ?? null;
        if ($w === null) {
            if ($this->canInternal() && !$this->canSell()) {
                return 'internal';
            }
            if ($this->canSell() && !$this->canInternal()) {
                return 'sell';
            }
            // Both allowed: when the item already carries one kind of code, only the other can be assigned.
            if (!empty($state['item_id'])) {
                $item = $this->item($state);
                if ($item['internal_code_id'] !== null && $item['sell_number_id'] === null) {
                    return 'sell';
                }
                if ($item['sell_number_id'] !== null && $item['internal_code_id'] === null) {
                    return 'internal';
                }
            }
            return null;
        }
        if (($w === 'internal' && !$this->canInternal()) || ($w === 'sell' && !$this->canSell())) {
            throw new ForbiddenException();
        }
        return $w;
    }

    /** Someone who may assign both kinds is first asked which; the answer decides whether a prefix is asked. */
    public function stepCountKnown(array $state): bool
    {
        return $this->which($state) !== null;
    }

    public function steps(array $state): array
    {
        if (!$this->canInternal() && !$this->canSell()) {
            throw new ForbiddenException();
        }
        $item = $this->item($state);
        $which = $this->which($state);
        $steps = [];
        // Ask which kind of code only when there is a real choice: both permissions, both slots still free, and the kind
        // was not already chosen on the project page. The step stays in the list after it is answered; dropping it would
        // renumber the later steps (skipping the prefix screen) and leave Back and the review's "Change" with nothing to go to.
        $askWhich = empty($state['which_preset']) && $this->canInternal() && $this->canSell()
            && $item['internal_code_id'] === null && $item['sell_number_id'] === null;
        if ($askWhich) {
            $steps[] = new Step('which', 'Which code does "' . $item['description'] . '" need?', 'Project ' . $item['project_number'] . '. A color can get its E number and its sell number at different times.', [[
                'name' => 'which', 'type' => 'radio', 'label' => 'Code', 'required' => true, 'required_message' => 'Pick which code to assign.',
                'options' => array_values(array_filter([
                    $item['internal_code_id'] === null ? ['internal', 'E number', 'The internal formula code (next in sequence, e.g. E9102).'] : null,
                    $item['sell_number_id'] === null ? ['sell', 'Sell number', 'The customer-facing product code with a prefix (e.g. Y1715UV).'] : null,
                ])),
            ]]);
        }
        if ($which === 'sell') {
            $steps[] = new Step('prefix', 'Which prefix?', 'The prefix says what kind of product this is. The number after it is given out automatically; the next free one is shown on each card.', [[
                'name' => 'prefix_id', 'required' => true, 'required_message' => 'Choose a prefix; it says what kind of product this is.',
            ]], template: 'codes/wizard-prefix');
            $allowed = !empty($state['prefix_id']) ? $this->app->db->all('SELECT s.id, s.code, s.label FROM chroma_code_prefix_suffixes ps JOIN chroma_code_suffixes s ON s.id = ps.suffix_id WHERE ps.prefix_id = ? AND s.active = 1 ORDER BY s.sort_order', [(int) $state['prefix_id']]) : [];
            if ($allowed) {
                $steps[] = new Step('suffix', 'Add a suffix?', 'Only if this is a special version. "None" is the usual answer.', [[
                    'name' => 'suffix_id', 'type' => 'radio', 'label' => 'Suffix', 'default' => '', 'tip' => Tips::SUFFIX,
                    'options' => array_merge([['', '(none)', 'Just the number, no suffix.']], array_map(fn($s) => [(int) $s['id'], $s['code'], $s['label']], $allowed)),
                ]]);
            }
        }
        if ($which !== null) {
            $steps[] = new Step('description', 'How should the code be described?', 'This is what people see in code lookup. It starts from the ' . mb_strtolower((string) $item['project']['item_label_singular']) . ' description.', [[
                'name' => 'description', 'type' => 'text', 'label' => 'Description', 'required' => true, 'maxlength' => 500, 'default' => $item['description'], 'required_message' => 'Give the code a short description so people can find it later.',
            ]]);
        }
        return $steps;
    }

    /** Everyday prefixes and the inherited/legacy ones, for the shared prefix screen. @return array{everyday:list<array<string,mixed>>,other:list<array<string,mixed>>} */
    public function prefixGroups(): array
    {
        $out = ['everyday' => [], 'other' => []];
        $quick = new QuickAssign($this->app->db, new CodeAssigner($this->app->db, $this->app->audit));
        foreach ($quick->prefixChoices() as $p) {
            $out[in_array((string) $p['tag'], ['inherited', 'legacy'], true) ? 'other' : 'everyday'][] = $p;
        }
        return $out;
    }

    /** Colour words that name a prefix, so "Pantone 300 blue" starts on B. */
    private const COLOUR_PREFIXES = [
        'yellow' => 'Y', 'red' => 'R', 'blue' => 'B', 'black' => 'BK', 'green' => 'G', 'purple' => 'P', 'violet' => 'P',
        'brown' => 'BN', 'orange' => 'OR', 'white' => 'W', 'gray' => 'GY', 'grey' => 'GY',
    ];

    /** The colour shown above the prefix cards, and the prefix to start on: the project's default, else one named by a colour word in the description. @return array{default:?int,context:?array{label:string,description:string,note:string}} */
    public function prefixScreen(array $state): array
    {
        $item = $this->item($state);
        $default = (int) ($item['project']['default_prefix_id'] ?? 0) ?: null;
        $note = 'The project\'s usual prefix is picked for you; change it if this one is different.';
        if ($default === null && preg_match('/\b(' . implode('|', array_keys(self::COLOUR_PREFIXES)) . ')\b/i', (string) $item['description'], $m)) {
            $code = self::COLOUR_PREFIXES[strtolower($m[1])];
            foreach ($this->prefixGroups()['everyday'] as $p) {
                if ($p['code'] === $code) {
                    $default = $p['id'];
                    $note = 'Picked from the word "' . $m[1] . '" in the description; change it if that is not right.';
                }
            }
        }
        $label = (string) ($item['description'] ?? '');
        $pantone = (string) ($item['pantone'] ?? '');
        return [
            'default' => $default,
            'context' => ['label' => 'Project ' . $item['project_number'], 'description' => $label . ($pantone !== '' && !str_contains(mb_strtolower($label), mb_strtolower($pantone)) ? ' · Pantone ' . $pantone : ''), 'note' => $note],
        ];
    }

    public function review(array $state): array
    {
        $item = $this->item($state);
        $which = $this->which($state);
        $codes = new CodeAssigner($this->app->db, $this->app->audit);
        $rows = [
            ['label' => 'Project', 'value' => $item['project_number'] . ' · ' . $item['project']['title']],
            ['label' => ucfirst(mb_strtolower((string) $item['project']['item_label_singular'])), 'value' => (string) $item['description']],
            ['label' => 'Code type', 'value' => $which === 'sell' ? 'Sell number' : 'E number', 'step' => 'which'],
        ];
        if ($which === 'sell') {
            $p = !empty($state['prefix_id']) ? $this->app->db->one('SELECT code, label, min_digits FROM chroma_code_prefixes WHERE id = ?', [(int) $state['prefix_id']]) : null;
            $rows[] = ['label' => 'Prefix', 'value' => $p ? $p['code'] . ' — ' . $p['label'] : '', 'step' => 'prefix'];
            $sx = !empty($state['suffix_id']) ? $this->app->db->value('SELECT code FROM chroma_code_suffixes WHERE id = ?', [(int) $state['suffix_id']]) : null;
            if ($sx !== null) {
                $rows[] = ['label' => 'Suffix', 'value' => (string) $sx, 'step' => 'suffix'];
            }
            if ($p) {
                $rows[] = ['label' => 'Next number', 'value' => CodeFormat::sell($p['code'], $codes->previewSell((int) $state['prefix_id']), (int) $p['min_digits'], $sx) . ' (expected; the exact number is reserved when you confirm)'];
            }
        } else {
            $rows[] = ['label' => 'Next number', 'value' => 'E' . $codes->previewInternal() . ' (expected; the exact number is reserved when you confirm)'];
        }
        $rows[] = ['label' => 'Description', 'value' => (string) ($state['description'] ?? ''), 'step' => 'description'];
        return $rows;
    }

    public function commit(array $state): WizardResult
    {
        $item = $this->item($state);
        $which = $this->which($state);
        if ($which === null) {
            throw ValidationException::single('which', 'Pick which code to assign.');
        }
        $items = $this->projects()->items();
        $codes = new CodeAssigner($this->app->db, $this->app->audit);
        $actor = $this->app->auth->id();
        if ($which === 'sell') {
            $row = $items->assignSell((int) $item['id'], ['prefix_id' => $state['prefix_id'] ?? 0, 'suffix_id' => $state['suffix_id'] ?? null, 'description' => $state['description'] ?? ''], $actor, $codes);
            $label = 'Sell number';
            $codeUrl = '/codes/s/' . $row['id'];
        } else {
            $row = $items->assignInternal((int) $item['id'], ['description' => $state['description'] ?? ''], $actor, $codes);
            $label = 'E number';
            $codeUrl = '/codes/e/' . $row['id'];
        }
        $fresh = $items->get((int) $item['id']);
        $actions = [['label' => 'Back to the project', 'href' => '/projects/' . $item['project_id'] . '#items', 'primary' => true]];
        if ($which === 'internal' && $fresh['sell_number_id'] === null && $this->canSell()) {
            $actions[] = ['label' => 'Assign its sell number too', 'href' => url('/wizard/assign-item-code', ['item_id' => $item['id'], 'which' => 'sell'])];
        }
        if ($which === 'sell' && $fresh['internal_code_id'] === null && $this->canInternal()) {
            $actions[] = ['label' => 'Assign its E number too', 'href' => url('/wizard/assign-item-code', ['item_id' => $item['id'], 'which' => 'internal'])];
        }
        $actions[] = ['label' => 'Open the code', 'href' => $codeUrl];
        return new WizardResult(
            $label . ' ' . $row['display'] . ' assigned',
            'It is linked to "' . $item['description'] . '" on project ' . $item['project_number'] . ' and is in the cross-reference now.',
            [['code' => $row['display'], 'label' => $label, 'description' => (string) $row['description']]],
            [],
            $actions
        );
    }

    public function expertUrl(array $state): ?string
    {
        try {
            $item = $this->item($state);
            return url('/mode', ['set' => 'expert', 'return' => '/projects/' . $item['project_id'] . '#items']);
        } catch (\Throwable) {
            return null;
        }
    }

    public function cancelUrl(array $state): string
    {
        try {
            return '/projects/' . $this->item($state)['project_id'];
        } catch (\Throwable) {
            return '/projects';
        }
    }

    public function commitLabel(): string
    {
        return 'Assign the code';
    }

    public function commitExplanation(): string
    {
        return 'This reserves the next number permanently. A mistaken code can be voided but never reused.';
    }
}
