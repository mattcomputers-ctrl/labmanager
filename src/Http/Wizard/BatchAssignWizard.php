<?php
declare(strict_types=1);

namespace Chroma\Http\Wizard;

use Chroma\Core\ForbiddenException;
use Chroma\Core\NotFoundException;
use Chroma\Core\Permissions;
use Chroma\Core\Request;
use Chroma\Core\ValidationException;
use Chroma\Domain\Codes\CodeAssigner;
use Chroma\Domain\Projects\Projects;
use Chroma\Domain\Projects\Visibility;

/**
 * Easy mode: assign codes to several items at once
 * (/wizard/batch-assign?project_id=N[&items=1,2,3]). Pick items → what to
 * assign → prefix (+ suffix) → review → one transaction per item via
 * Items::batchAssign, then a summary with every code, copyable as a block.
 */
final class BatchAssignWizard extends Wizard
{
    private ?Projects $projects = null;

    private function projects(): Projects
    {
        return $this->projects ??= new Projects($this->app->db, $this->app->audit, $this->app->settings);
    }

    public function key(): string
    {
        return 'batch-assign';
    }

    public function title(): string
    {
        return 'Assign codes to several items';
    }

    public function initialState(Request $request): array
    {
        $items = array_values(array_filter(array_map('intval', explode(',', $request->str('items'))), fn($i) => $i > 0));
        $state = ['project_id' => $request->int('project_id'), 'items' => $items];
        // Refused at the start when the project is completed or cancelled (same message as the domain gives on commit).
        $p = !empty($state['project_id']) ? $this->projects()->find((int) $state['project_id']) : null;
        if ($p !== null && (new Visibility($this->app->db, $this->app->auth))->canView($p)) {
            Projects::assertOpen($p, 'assign codes from it');
        }
        return $state;
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function project(array $state): array
    {
        if (!$this->app->auth->canAny(Permissions::CODES_ASSIGN_INTERNAL, Permissions::CODES_ASSIGN_SELL)) {
            throw new ForbiddenException();
        }
        $p = !empty($state['project_id']) ? $this->projects()->find((int) $state['project_id']) : null;
        if ($p === null || !(new Visibility($this->app->db, $this->app->auth))->canView($p)) {
            throw new NotFoundException('Open a project first, then choose "Assign codes to several" from its page.');
        }
        return $p;
    }

    /** Items that can still get a code. @param array<string,mixed> $project @return list<array<string,mixed>> */
    private function candidates(array $project): array
    {
        return array_values(array_filter($this->projects()->items()->forProject((int) $project['id']), fn($i) => $i['internal_code_id'] === null || $i['sell_number_id'] === null));
    }

    public function steps(array $state): array
    {
        $project = $this->project($state);
        $canI = $this->app->auth->can(Permissions::CODES_ASSIGN_INTERNAL);
        $canS = $this->app->auth->can(Permissions::CODES_ASSIGN_SELL);
        $steps = [new Step('items', 'Which ' . mb_strtolower((string) $project['item_label']) . ' need codes?', 'Project ' . $project['project_number'] . ' · ' . $project['title'] . '. Items that already have both codes are not listed.', [], null, 'projects/wizard-batch-items')];
        $options = array_values(array_filter([
            $canI ? ['internal', 'E numbers', 'One internal formula code per item, next in sequence.'] : null,
            $canS ? ['sell', 'Sell numbers', 'One customer-facing code per item under a prefix you choose.'] : null,
            $canI && $canS ? ['both', 'Both', 'An E number and a sell number for each item, linked together.'] : null,
        ]));
        $steps[] = new Step('what', 'What should each one get?', 'Items that already have one of the codes keep it; only the missing one is added.', [[
            'name' => 'what', 'type' => 'radio', 'label' => 'Codes to assign', 'required' => true, 'options' => $options, 'default' => count($options) === 1 ? $options[0][0] : null, 'required_message' => 'Pick which codes to assign.',
        ]]);
        $what = $state['what'] ?? (count($options) === 1 ? $options[0][0] : null);
        if (in_array($what, ['sell', 'both'], true)) {
            $prefixes = $this->app->db->all('SELECT id, code, label, description FROM chroma_code_prefixes WHERE retired = 0 ORDER BY sort_order, code');
            $steps[] = new Step('prefix', 'Which prefix for the sell numbers?', 'One prefix for all of them. If they need different prefixes, assign them one at a time or use Expert mode, where the prefix is chosen per row.', [[
                'name' => 'prefix_id', 'type' => 'select', 'label' => 'Prefix', 'required' => true, 'placeholder' => 'Choose a prefix', 'required_message' => 'Choose a prefix for the sell numbers.',
                'options' => array_map(fn($p) => [(int) $p['id'], $p['code'] . ' — ' . $p['label'] . ($p['description'] ? ' · ' . $p['description'] : '')], $prefixes),
                'default' => $project['default_prefix_id'],
            ]]);
            $allowed = !empty($state['prefix_id']) ? $this->app->db->all('SELECT s.id, s.code, s.label FROM chroma_code_prefix_suffixes ps JOIN chroma_code_suffixes s ON s.id = ps.suffix_id WHERE ps.prefix_id = ? AND s.active = 1 ORDER BY s.sort_order', [(int) $state['prefix_id']]) : [];
            if ($allowed) {
                $steps[] = new Step('suffix', 'Add a suffix?', 'Applies to all of them. "None" is the usual answer.', [[
                    'name' => 'suffix_id', 'type' => 'radio', 'label' => 'Suffix', 'default' => '',
                    'options' => array_merge([['', '(none)', 'Plain numbers under this prefix.']], array_map(fn($s) => [(int) $s['id'], $s['code'], $s['label']], $allowed)),
                ]]);
            }
        }
        return $steps;
    }

    public function validateStep(Step $step, array $input, array $state): array
    {
        if ($step->key === 'items') {
            $project = $this->project($state);
            $valid = array_map(fn($i) => (int) $i['id'], $this->candidates($project));
            $picked = array_values(array_filter(array_map('intval', (array) ($input['items'] ?? [])), fn($i) => in_array($i, $valid, true)));
            if ($picked === []) {
                $this->fail(['items' => 'Tick at least one ' . mb_strtolower((string) $project['item_label_singular']) . ' to go on.']);
            }
            return ['items' => $picked];
        }
        return parent::validateStep($step, $input, $state);
    }

    public function review(array $state): array
    {
        $project = $this->project($state);
        $what = (string) ($state['what'] ?? '');
        $chosen = array_filter($this->candidates($project), fn($i) => in_array((int) $i['id'], (array) ($state['items'] ?? []), true));
        $rows = [
            ['label' => 'Project', 'value' => $project['project_number'] . ' · ' . $project['title']],
            ['label' => 'Codes', 'value' => ['internal' => 'E numbers', 'sell' => 'Sell numbers', 'both' => 'E numbers and sell numbers'][$what] ?? '', 'step' => 'what'],
        ];
        if (in_array($what, ['sell', 'both'], true)) {
            $p = !empty($state['prefix_id']) ? $this->app->db->one('SELECT code, label FROM chroma_code_prefixes WHERE id = ?', [(int) $state['prefix_id']]) : null;
            $rows[] = ['label' => 'Prefix', 'value' => $p ? $p['code'] . ' — ' . $p['label'] : '', 'step' => 'prefix'];
            if (!empty($state['suffix_id'])) {
                $rows[] = ['label' => 'Suffix', 'value' => (string) $this->app->db->value('SELECT code FROM chroma_code_suffixes WHERE id = ?', [(int) $state['suffix_id']]), 'step' => 'suffix'];
            }
        }
        $list = '<ol class="review-items">';
        foreach ($chosen as $i) {
            $gets = [];
            if (in_array($what, ['internal', 'both'], true) && $i['internal_code_id'] === null) {
                $gets[] = 'E#';
            }
            if (in_array($what, ['sell', 'both'], true) && $i['sell_number_id'] === null) {
                $gets[] = 'sell number';
            }
            $list .= '<li>' . e($i['description']) . ' <span class="muted">— ' . ($gets ? 'gets ' . implode(' + ', $gets) : 'already has it, skipped') . '</span></li>';
        }
        $list .= '</ol>';
        $rows[] = ['label' => plural(count($chosen), mb_strtolower((string) $project['item_label_singular']), mb_strtolower((string) $project['item_label'])), 'value' => $list, 'html' => true, 'step' => 'items'];
        return $rows;
    }

    public function commit(array $state): WizardResult
    {
        $project = $this->project($state);
        $what = (string) ($state['what'] ?? '');
        if ($what === '') {
            throw ValidationException::single('what', 'Pick which codes to assign.');
        }
        $plan = [];
        $byId = [];
        foreach ($this->candidates($project) as $c) {
            $byId[(int) $c['id']] = $c;
        }
        foreach ((array) ($state['items'] ?? []) as $id) {
            $item = $byId[(int) $id] ?? null;
            if ($item === null) {
                continue;
            }
            // Only the missing code is added; an item that already has one keeps it (as the review said).
            $plan[] = [
                'item_id' => (int) $id,
                'internal' => in_array($what, ['internal', 'both'], true) && $item['internal_code_id'] === null,
                'sell' => in_array($what, ['sell', 'both'], true) && $item['sell_number_id'] === null,
                'prefix_id' => (int) ($state['prefix_id'] ?? 0),
                'suffix_id' => !empty($state['suffix_id']) ? (int) $state['suffix_id'] : null,
            ];
        }
        $result = $this->projects()->items()->batchAssign((int) $project['id'], $plan, $this->app->auth->id(), new CodeAssigner($this->app->db, $this->app->audit));
        $codes = array_map(fn($a) => ['code' => $a['code'], 'label' => $a['type'] === 'sell' ? 'Sell number' : 'E number', 'description' => $a['description']], $result['assigned']);
        $details = [];
        foreach ($result['errors'] as $err) {
            $details[] = ['Not assigned: ' . $err['description'], $err['message']];
        }
        return new WizardResult(
            count($result['assigned']) . ' code' . (count($result['assigned']) === 1 ? '' : 's') . ' assigned',
            $result['errors'] ? count($result['errors']) . ' could not be assigned; see below. The others are saved.' : 'All saved and linked to their items. Copy the block below for the lab sheet.',
            $codes,
            $details,
            [['label' => 'Back to the project', 'href' => '/projects/' . $project['id'] . '#items', 'primary' => true]]
        );
    }

    public function expertUrl(array $state): ?string
    {
        if (empty($state['project_id'])) {
            return null;
        }
        return url('/mode', ['set' => 'expert', 'return' => url('/projects/' . (int) $state['project_id'] . '/batch', ['items' => implode(',', (array) ($state['items'] ?? [])) ?: null])]);
    }

    public function cancelUrl(array $state): string
    {
        return !empty($state['project_id']) ? '/projects/' . (int) $state['project_id'] : '/projects';
    }

    public function commitLabel(): string
    {
        return 'Assign the codes';
    }

    public function commitExplanation(): string
    {
        return 'Each item is handled on its own, so if one fails the others still get their codes. Numbers are reserved permanently.';
    }
}
