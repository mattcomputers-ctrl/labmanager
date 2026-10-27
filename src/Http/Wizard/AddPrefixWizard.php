<?php
declare(strict_types=1);

namespace Chroma\Http\Wizard;

use Chroma\Core\Permissions;
use Chroma\Core\Request;
use Chroma\Domain\Codes\CodeFormat;
use Chroma\Domain\Codes\PrefixAdmin;

/**
 * Easy-mode "Add a prefix": code → name → number format → allowed suffixes
 * → review → done. Commits through PrefixAdmin::createPrefix, the same
 * operation the Expert form at /admin/prefixes/new uses.
 */
final class AddPrefixWizard extends Wizard
{
    private ?PrefixAdmin $admin = null;

    private function admin(): PrefixAdmin
    {
        return $this->admin ??= new PrefixAdmin($this->app->db, $this->app->audit);
    }

    public function key(): string
    {
        return 'add-prefix';
    }

    public function title(): string
    {
        return 'Add a prefix';
    }

    public function permissions(): array
    {
        return [Permissions::ADMIN_PREFIXES];
    }

    public function initialState(Request $request): array
    {
        return ['min_digits' => '4', 'starting_number' => '1'];
    }

    public function steps(array $state): array
    {
        $steps = [
            new Step(
                'code',
                'What letters start the new prefix?',
                'Every sell number under this prefix will begin with these letters, like Y in Y1715 or BK in BK1377.',
                [[
                    'name' => 'code', 'label' => 'Prefix letters', 'required' => true, 'maxlength' => PrefixAdmin::CODE_MAX, 'code' => true,
                    'placeholder' => 'OR', 'attrs' => ['style' => 'text-transform:uppercase;max-width:240px', 'autocapitalize' => 'characters', 'spellcheck' => 'false'],
                    'help' => 'Letters only, up to 12. Chroma makes them capitals for you. The letters cannot be changed once codes have been given out under the prefix.',
                    'required_message' => 'Enter the letters that start every sell number under this prefix, like Y or BK.',
                ]],
            ),
            new Step(
                'label',
                'What is this prefix for?',
                'The name shows next to the letters wherever a prefix is chosen, so people pick the right one.',
                [
                    ['name' => 'label', 'label' => 'Name', 'required' => true, 'maxlength' => 100, 'placeholder' => 'e.g. Orange', 'required_message' => 'Give the prefix a short name people will recognise, like "Yellow" or "Security ink".'],
                    ['name' => 'description', 'label' => 'What it is used for', 'type' => 'textarea', 'rows' => 2, 'maxlength' => 255, 'optional_text' => true, 'placeholder' => 'One line, e.g. Standard color prefix for oranges.'],
                ],
            ),
            new Step(
                'format',
                'How should the numbers look?',
                'Numbers are padded with zeros to a minimum width, and count up from the starting number you give.',
                [
                    ['name' => 'min_digits', 'label' => 'Minimum digits', 'type' => 'number', 'min' => 1, 'max' => PrefixAdmin::MIN_DIGITS_MAX, 'required' => true, 'attrs' => ['style' => 'max-width:160px'], 'help' => 'Color prefixes use 4 (Y0001). Security ink uses 6 (X104043).', 'required_message' => 'Enter how many digits the number is padded to, from 1 to 9.'],
                    ['name' => 'starting_number', 'label' => 'Starting number', 'type' => 'number', 'min' => 1, 'required' => true, 'attrs' => ['style' => 'max-width:200px'], 'help' => 'The first code will get this number. Usually 1, or 1000 if you want four-digit codes from the start.', 'required_message' => 'Enter the number the first code should get, 1 or more.'],
                ],
                'You can change the counter later from the prefix page to skip a block. It can never be set to a number that has already been given out, and numbers are never reused.',
            ),
        ];
        $suffixes = $this->admin()->activeSuffixes();
        if ($suffixes) {
            $fields = [];
            foreach ($suffixes as $s) {
                $fields[] = ['name' => 'suffix_' . (int) $s['id'], 'label' => $s['code'] . ' — ' . $s['label'], 'type' => 'checkbox'];
            }
            $steps[] = new Step(
                'suffixes',
                'Which suffixes may be added to these codes?',
                'Tick a suffix to let people add it when assigning a code under this prefix. Allowing a suffix never requires it: the plain number is always available too. Leave everything unticked if this prefix never takes a suffix.',
                $fields,
                'Suffixes do not start a new count. Y1715 and Y1716UV come from the same Y counter. The master list of suffixes is under Admin → Suffixes.',
            );
        }
        return $steps;
    }

    public function validateStep(Step $step, array $input, array $state): array
    {
        if ($step->key === 'format') {
            // Same checks and the same words as the Expert form (PrefixAdmin).
            $out = ['min_digits' => trim((string) ($input['min_digits'] ?? '')), 'starting_number' => trim((string) ($input['starting_number'] ?? ''))];
            if ($problems = $this->admin()->formatProblems($out['min_digits'], $out['starting_number'])) {
                $this->fail($problems);
            }
            return $out;
        }
        $out = parent::validateStep($step, $input, $state);
        if ($step->key === 'code') {
            $out['code'] = strtoupper((string) $out['code']);
            if ($problem = $this->admin()->codeProblem($out['code'])) {
                $this->fail(['code' => $problem]);
            }
        }
        return $out;
    }

    public function review(array $state): array
    {
        $code = strtoupper((string) ($state['code'] ?? ''));
        $digits = max(1, (int) ($state['min_digits'] ?? 4));
        $start = max(1, (int) ($state['starting_number'] ?? 1));
        $codes = $this->chosenSuffixCodes($state);
        $rows = [
            ['label' => 'Prefix letters', 'value' => $code, 'step' => 'code'],
            ['label' => 'Name', 'value' => (string) ($state['label'] ?? ''), 'step' => 'label'],
            ['label' => 'Used for', 'value' => (string) ($state['description'] ?? ''), 'step' => 'label'],
            ['label' => 'Number format', 'value' => sprintf('%d digits, e.g. %s', $digits, CodeFormat::sell($code, $start, $digits)), 'step' => 'format'],
            ['label' => 'First number', 'value' => CodeFormat::sell($code, $start, $digits), 'step' => 'format'],
        ];
        if ($this->admin()->activeSuffixes()) {
            $rows[] = ['label' => 'Allowed suffixes', 'value' => $codes ? implode(', ', $codes) : 'None — no suffix field for this prefix', 'step' => 'suffixes'];
        }
        return $rows;
    }

    public function commit(array $state): WizardResult
    {
        $admin = $this->admin();
        $id = $admin->createPrefix([
            'code' => (string) ($state['code'] ?? ''),
            'label' => (string) ($state['label'] ?? ''),
            'description' => (string) ($state['description'] ?? ''),
            'min_digits' => $state['min_digits'] ?? 4,
            'starting_number' => $state['starting_number'] ?? 1,
            'suffix_ids' => $this->chosenSuffixIds($state),
        ], $this->app->auth->id());
        $p = $admin->prefix($id);
        return new WizardResult(
            title: sprintf('Prefix %s added', $p['code']),
            message: sprintf('%s is ready to use. It now appears in the prefix list when anyone assigns a sell number.', $p['code']),
            codes: [['code' => $p['next_display'], 'label' => 'The first number that will be given out']],
            details: [
                ['Name', (string) $p['label']],
                ['Used for', (string) $p['description']],
                ['Allowed suffixes', $p['suffix_codes'] ? implode(', ', $p['suffix_codes']) : 'None'],
            ],
            nextActions: [
                ['label' => 'Back to prefixes', 'href' => '/admin/prefixes', 'primary' => true],
                ['label' => 'Open ' . $p['code'], 'href' => '/admin/prefixes/' . $id],
                ['label' => 'Add another prefix', 'href' => '/wizard/add-prefix'],
            ],
        );
    }

    public function expertUrl(array $state): ?string
    {
        $query = [
            'code' => $state['code'] ?? null,
            'label' => $state['label'] ?? null,
            'description' => $state['description'] ?? null,
            'min_digits' => $state['min_digits'] ?? null,
            'starting_number' => $state['starting_number'] ?? null,
        ];
        $ids = $this->chosenSuffixIds($state);
        if ($ids) {
            $query['suffix_ids'] = $ids;
        }
        return url('/mode', ['set' => 'expert', 'return' => url('/admin/prefixes/new', $query)]);
    }

    public function cancelUrl(array $state): string
    {
        return '/admin/prefixes';
    }

    public function commitLabel(): string
    {
        return 'Add this prefix';
    }

    public function commitExplanation(): string
    {
        return 'The prefix is added to the list and becomes available for new sell numbers straight away. You can edit or retire it later from Admin → Prefixes.';
    }

    /** @param array<string,mixed> $state @return list<int> */
    private function chosenSuffixIds(array $state): array
    {
        $ids = [];
        foreach ($state as $k => $v) {
            if (str_starts_with((string) $k, 'suffix_') && $v && $v !== '0') {
                $ids[] = (int) substr((string) $k, 7);
            }
        }
        return $ids;
    }

    /** @param array<string,mixed> $state @return list<string> */
    private function chosenSuffixCodes(array $state): array
    {
        $ids = $this->chosenSuffixIds($state);
        $out = [];
        foreach ($this->admin()->activeSuffixes() as $s) {
            if (in_array((int) $s['id'], $ids, true)) {
                $out[] = (string) $s['code'];
            }
        }
        return $out;
    }
}
