<?php
declare(strict_types=1);

namespace Chroma\Http\Wizard;

use Chroma\Core\Permissions;
use Chroma\Core\Request;
use Chroma\Core\ValidationException;
use Chroma\Domain\Projects\Projects;

/**
 * Easy mode: new project. Type → customer → title and type fields → who/when
 * → defaults → first item(s) with "add another?" → review → done.
 * Calls Projects::create(), exactly like the Expert page.
 */
final class NewProjectWizard extends Wizard
{
    private ?Projects $projects = null;

    private function projects(): Projects
    {
        return $this->projects ??= new Projects($this->app->db, $this->app->audit, $this->app->settings);
    }

    public function key(): string
    {
        return 'new-project';
    }

    public function title(): string
    {
        return 'New project';
    }

    public function permissions(): array
    {
        return [Permissions::PROJECTS_CREATE];
    }

    /**
     * Seeds the wizard from query parameters: the Expert page's Easy link sends every field it holds
     * (the same names the Expert form posts), so switching modes mid-task keeps what was typed.
     */
    public function initialState(Request $request): array
    {
        $state = [];
        if ($t = $request->str('type')) {
            $state['type'] = $t;
        }
        if ($c = $request->int('customer_id')) {
            $state['customer_id'] = $c;
            $state['customer_id_label'] = (string) ($this->app->db->value('SELECT name FROM chroma_customers WHERE id = ?', [$c]) ?? '');
        } elseif (($text = $request->str('customer_text') ?: $request->str('customer_id_text')) !== '') {
            $state['customer_id'] = '';
            $state['customer_id_text'] = $text;
            $state['customer_id_label'] = $text;
        }
        foreach (['title', 'description', 'problem_statement', 'goal', 'date_submitted', 'target_date', 'stage_id', 'default_ink_series_id', 'default_substrate', 'default_coating_id'] as $k) {
            if (($v = $request->str($k)) !== '') {
                $state[$k] = $v;
            }
        }
        if (($u = $request->int('assigned_user_id')) !== null) {
            $state['assigned_user_id'] = (string) $u;
            $state['assigned_user_id_label'] = $this->lookupLabel('chroma_users', 'display_name', $u) ?? '';
        }
        if ($request->str('confidential') !== '') {
            $state['confidential'] = $request->bool('confidential') ? '1' : '0';
        }
        $n = 0;
        foreach ($request->arr('items') as $it) {
            if (!is_array($it) || $n >= $this->cap()) {
                continue;
            }
            $desc = trim((string) ($it['description'] ?? ''));
            $ref = trim((string) ($it['existing_ref'] ?? ''));
            if ($desc === '' && $ref === '') {
                continue;
            }
            $n++;
            $state['item_' . $n . '_description'] = $desc;
            $state['item_' . $n . '_pantone'] = trim((string) ($it['pantone'] ?? ''));
            if ($ref !== '') {
                $state['item_' . $n . '_existing_ref'] = $ref;
                $state['item_' . $n . '_existing_ref_label'] = trim((string) ($it['existing_label'] ?? '')) ?: $this->codeLabel($ref);
            }
            if ($n > 1) {
                $state['item_' . ($n - 1) . '_more'] = 'yes';
            }
        }
        return $state;
    }

    /** @param array<string,mixed> $state @return array<string,mixed>|null */
    private function type(array $state): ?array
    {
        return isset($state['type']) ? $this->projects()->types()->byCode((string) $state['type']) : null;
    }

    private function cap(): int
    {
        return $this->projects()->maxItems();
    }

    /** How many item steps exist given the "add another?" answers. @param array<string,mixed> $state */
    private function itemCount(array $state): int
    {
        $n = 1;
        while (($state['item_' . $n . '_more'] ?? '') === 'yes' && $n < $this->cap()) {
            $n++;
        }
        return $n;
    }

    /** The type decides which questions follow, so until it is chosen the first screen only knows itself. */
    public function stepCountKnown(array $state): bool
    {
        return $this->type($state) !== null;
    }

    public function steps(array $state): array
    {
        $types = $this->projects()->types()->all();
        $steps = [new Step('type', 'What kind of project is this?', 'Pick the one that fits. The type decides which questions come next.', [[
            'name' => 'type', 'type' => 'radio', 'label' => 'Project type', 'required' => true, 'required_message' => 'Pick a project type to go on.',
            'options' => array_values(array_map(fn($t) => [$t['code'], $t['label'], $t['description']], $types)),
        ]], nextLabel: 'Next ›')];
        $type = $this->type($state);
        if ($type === null) {
            return $steps;
        }
        $fields = $this->projects()->types()->fields($type, false);
        $byName = [];
        foreach ($fields as $f) {
            $byName[$f['name']] = $f;
        }
        if ((int) $type['has_customer'] === 1) {
            $steps[] = new Step('customer', 'Which customer is this for?', 'Start typing the name and pick it from the list. If it is a new customer, just keep typing the full name.', [[
                'name' => 'customer_id', 'type' => 'lookup', 'label' => 'Customer', 'lookup' => '/api/customers?q=', 'allow_new' => true,
                'placeholder' => 'Customer name', 'required' => (int) $type['customer_required'] === 1, 'required_message' => 'Choose the customer, or type a new name.',
            ]]);
        }
        $about = [['name' => 'title', 'type' => 'text', 'label' => 'Short title', 'required' => true, 'maxlength' => 200, 'placeholder' => 'e.g. Spring carton colors', 'help' => 'A few words that identify the job in the list.', 'required_message' => 'Give the project a short title so people can find it in the list.']];
        foreach (['description', 'problem_statement', 'goal'] as $n) {
            if (isset($byName[$n])) {
                $about[] = ['name' => $n, 'type' => 'textarea', 'label' => $byName[$n]['label'], 'help' => $byName[$n]['help'], 'rows' => 4];
            }
        }
        $steps[] = new Step('about', 'What is this project about?', '', $about);

        $me = $this->app->auth->user();
        $who = [];
        if (isset($byName['assigned_user_id'])) {
            $who[] = ['name' => 'assigned_user_id', 'type' => 'lookup', 'label' => $byName['assigned_user_id']['label'], 'lookup' => '/api/users?q=', 'help' => $byName['assigned_user_id']['help'], 'default' => $me['id'] ?? null, 'lookup_label' => (string) ($me['display_name'] ?? $me['username'] ?? '')];
        }
        if (isset($byName['date_submitted'])) {
            $who[] = ['name' => 'date_submitted', 'type' => 'date', 'label' => 'Date submitted', 'default' => today(), 'help' => $byName['date_submitted']['help']];
        }
        if (isset($byName['target_date'])) {
            $who[] = ['name' => 'target_date', 'type' => 'date', 'label' => 'Target date', 'help' => $byName['target_date']['help'], 'optional_text' => true];
        }
        if (isset($byName['stage_id']) && $type['stages']) {
            $who[] = ['name' => 'stage_id', 'type' => 'select', 'label' => 'Stage', 'placeholder' => 'Not set yet', 'options' => array_map(fn($s) => [(int) $s['id'], $s['label']], $type['stages']), 'help' => $byName['stage_id']['help']];
        }
        if (isset($byName['confidential'])) {
            $who[] = ['name' => 'confidential', 'type' => 'checkbox', 'label' => 'Confidential', 'help' => $byName['confidential']['help'], 'tip' => $byName['confidential']['tip'] ?? null];
        }
        if ($who) {
            $steps[] = new Step('who', 'Who is working on it, and when?', '', $who);
        }

        $defaults = [];
        if (isset($byName['default_ink_series_id'])) {
            $rows = $this->app->db->all('SELECT id, name FROM chroma_ink_series WHERE active = 1 ORDER BY sort_order, name');
            if ($rows) {
                $defaults[] = ['name' => 'default_ink_series_id', 'type' => 'select', 'label' => 'Ink series', 'placeholder' => 'Not set', 'options' => array_map(fn($r) => [(int) $r['id'], $r['name']], $rows), 'help' => $byName['default_ink_series_id']['help']];
            }
        }
        if (isset($byName['default_substrate'])) {
            $defaults[] = ['name' => 'default_substrate', 'type' => 'text', 'label' => 'Substrate', 'placeholder' => 'e.g. SBS board, PE film', 'help' => $byName['default_substrate']['help']];
        }
        if (isset($byName['default_coating_id'])) {
            $rows = $this->app->db->all('SELECT id, name FROM chroma_coatings WHERE active = 1 ORDER BY sort_order, name');
            if ($rows) {
                $defaults[] = ['name' => 'default_coating_id', 'type' => 'select', 'label' => 'Coating', 'placeholder' => 'Not set', 'options' => array_map(fn($r) => [(int) $r['id'], $r['name']], $rows), 'help' => $byName['default_coating_id']['help']];
            }
        }
        if ($defaults) {
            $steps[] = new Step('defaults', 'Defaults for the ' . mb_strtolower((string) $type['item_label']), 'These fill in on every ' . mb_strtolower((string) $type['item_label_singular']) . ' you add and can be changed per ' . mb_strtolower((string) $type['item_label_singular']) . '. Skip them if they vary.', $defaults);
        }

        $singular = mb_strtolower((string) $type['item_label_singular']);
        $kind = (string) ($type['default_item_kind'] ?? 'color');
        $count = $this->itemCount($state);
        for ($i = 1; $i <= $count; $i++) {
            $fields = [];
            if ($kind === 'existing_product') {
                $fields[] = ['name' => 'item_' . $i . '_existing_ref', 'type' => 'lookup', 'label' => 'Which product?', 'lookup' => '/api/codes/search?q=', 'placeholder' => 'E number or sell number', 'help' => 'Type an E number or sell number and pick it from the list. Leave blank if it has no code yet.'];
                $fields[] = ['name' => 'item_' . $i . '_description', 'type' => 'text', 'label' => 'Description', 'maxlength' => 500, 'help' => 'Fills in from the code you pick; type one if there is no code.'];
            } elseif ($kind === 'color') {
                // Two fields that must not look like the same question: the name the lab uses, then the optional Pantone reference.
                $fields[] = ['name' => 'item_' . $i . '_description', 'type' => 'text', 'label' => 'What do you call this color?', 'maxlength' => 500, 'placeholder' => 'e.g. Logo brown', 'help' => 'How it appears on the job sheet.', 'required' => $i === 1, 'required_message' => 'Name the first color to go on. You can add more after this one.'];
                $fields[] = ['name' => 'item_' . $i . '_pantone', 'type' => 'text', 'label' => 'Pantone or reference', 'maxlength' => 60, 'optional_text' => true, 'placeholder' => 'e.g. 300 C', 'help' => 'The Pantone number or the customer\'s own reference, if there is one.'];
            } else {
                $fields[] = ['name' => 'item_' . $i . '_description', 'type' => 'text', 'label' => ucfirst($singular) . ' ' . $i, 'maxlength' => 500, 'placeholder' => 'e.g. Trial ' . $i . ': 2% more wax', 'required' => $i === 1, 'required_message' => 'Describe the first ' . $singular . ' to go on. You can add more after this one.'];
            }
            $by = null;
            if ($i < $this->cap()) {
                $fields[] = ['name' => 'item_' . $i . '_more', 'type' => 'radio', 'label' => 'Add another ' . $singular . '?', 'default' => 'no', 'options' => [['no', 'No, that is all for now'], ['yes', 'Yes, add another']]];
                $by = ['field' => 'item_' . $i . '_more', 'labels' => ['yes' => 'Next: ' . $singular . ' ' . ($i + 1) . ' ›', 'no' => 'Review ›']];
            }
            $intro = $i === 1
                ? 'A project can hold up to ' . $this->cap() . ' ' . mb_strtolower((string) $type['item_label']) . '. Add the first one now; you can add the rest here or on the project page.'
                : '';
            $steps[] = new Step('item_' . $i, $i === 1 ? 'What is the first ' . $singular . '?' : ucfirst($singular) . ' ' . $i . ' of up to ' . $this->cap(), $intro, $fields, nextLabel: $by === null ? 'Review ›' : null, nextLabelBy: $by);
        }
        return $steps;
    }

    public function validateStep(Step $step, array $input, array $state): array
    {
        if ($step->key === 'customer') {
            // The lookup posts customer_id (picked) or customer_id_text (typed new name); either satisfies "required".
            $id = trim((string) ($input['customer_id'] ?? ''));
            $text = trim((string) ($input['customer_id_text'] ?? ''));
            if ($id === '' && $text === '' && !empty($step->fields[0]['required'])) {
                $this->fail(['customer_id' => 'Choose the customer from the list, or type the full name of a new one.']);
            }
            return ['customer_id' => $id, 'customer_id_text' => $id === '' ? $text : '', 'customer_id_label' => $this->lookupLabel('chroma_customers', 'name', $id) ?? $text];
        }
        $clean = parent::validateStep($step, $input, $state);
        if ($step->key === 'type' && empty($this->projects()->types()->byCode((string) ($clean['type'] ?? '')))) {
            $this->fail(['type' => 'Pick a project type to go on.']);
        }
        if ($step->key === 'who' && isset($clean['assigned_user_id'])) {
            $clean['assigned_user_id_label'] = $this->lookupLabel('chroma_users', 'display_name', $clean['assigned_user_id'] ?? null) ?? '';
        }
        if (str_starts_with($step->key, 'item_')) {
            $n = (int) substr($step->key, 5);
            $ref = (string) ($clean['item_' . $n . '_existing_ref'] ?? '');
            if ($ref !== '') {
                $clean['item_' . $n . '_existing_ref_label'] = (string) ($input['item_' . $n . '_existing_ref_label'] ?? $this->codeLabel($ref));
            }
            if (trim((string) ($clean['item_' . $n . '_description'] ?? '')) === '' && $ref === '' && $n === 1 && isset($clean['item_1_existing_ref'])) {
                $this->fail(['item_1_existing_ref' => 'Pick the product from the list, or describe it.']);
            }
            // Going back to "no" removes later items' answers so the review stays honest.
            if (($clean['item_' . $n . '_more'] ?? 'no') !== 'yes') {
                for ($j = $n + 1; $j <= $this->cap(); $j++) {
                    foreach (['description', 'pantone', 'existing_ref', 'existing_ref_label', 'more'] as $k) {
                        $clean['item_' . $j . '_' . $k] = null;
                    }
                }
            }
        }
        return $clean;
    }

    private function lookupLabel(string $table, string $column, mixed $id): ?string
    {
        if ($id === null || $id === '' || (int) $id <= 0) {
            return null;
        }
        $v = $this->app->db->value("SELECT $column FROM $table WHERE id = ?", [(int) $id]);
        return $v === null ? null : (string) $v;
    }

    private function codeLabel(string $ref): string
    {
        if (preg_match('/^E(\d+)$/i', $ref, $m)) {
            $r = $this->app->db->one('SELECT number, version, description FROM chroma_internal_codes WHERE id = ?', [(int) $m[1]]);
            return $r ? \Chroma\Domain\Codes\CodeFormat::internal((int) $r['number'], $r['version']) . ' · ' . $r['description'] : $ref;
        }
        if (preg_match('/^S(\d+)$/i', $ref, $m)) {
            $r = $this->app->db->one('SELECT s.number, s.description, p.code, p.min_digits, x.code AS sx FROM chroma_sell_numbers s JOIN chroma_code_prefixes p ON p.id = s.prefix_id LEFT JOIN chroma_code_suffixes x ON x.id = s.suffix_id WHERE s.id = ?', [(int) $m[1]]);
            return $r ? \Chroma\Domain\Codes\CodeFormat::sell($r['code'], (int) $r['number'], (int) $r['min_digits'], $r['sx']) . ' · ' . $r['description'] : $ref;
        }
        return $ref;
    }

    /** @param array<string,mixed> $state @return list<array<string,mixed>> */
    private function itemsFromState(array $state): array
    {
        $items = [];
        for ($i = 1; $i <= $this->cap(); $i++) {
            $desc = trim((string) ($state['item_' . $i . '_description'] ?? ''));
            $ref = trim((string) ($state['item_' . $i . '_existing_ref'] ?? ''));
            if ($desc === '' && $ref === '') {
                continue;
            }
            $items[] = array_filter(['description' => $desc, 'pantone' => $state['item_' . $i . '_pantone'] ?? null, 'existing_ref' => $ref ?: null], fn($v) => $v !== null && $v !== '');
        }
        return $items;
    }

    public function review(array $state): array
    {
        $type = $this->type($state);
        $rows = [['label' => 'Type', 'value' => (string) ($type['label'] ?? ''), 'step' => 'type']];
        if ($type && (int) $type['has_customer'] === 1) {
            $customer = (string) ($state['customer_id_label'] ?: ($state['customer_id_text'] ?? ''));
            if (empty($state['customer_id']) && !empty($state['customer_id_text'])) {
                $customer .= ' (new customer, will be added)';
            }
            $rows[] = ['label' => 'Customer', 'value' => $customer, 'step' => 'customer'];
        }
        $rows[] = ['label' => 'Title', 'value' => (string) ($state['title'] ?? ''), 'step' => 'about'];
        foreach (['description' => 'Description', 'problem_statement' => 'Problem', 'goal' => 'Goal'] as $k => $l) {
            if (!empty($state[$k])) {
                $rows[] = ['label' => $l, 'value' => (string) $state[$k], 'step' => 'about'];
            }
        }
        if (array_key_exists('assigned_user_id', $state)) {
            $rows[] = ['label' => 'Tech / lead', 'value' => (string) ($state['assigned_user_id_label'] ?? ''), 'step' => 'who'];
        }
        if (!empty($state['date_submitted'])) {
            $rows[] = ['label' => 'Submitted', 'value' => fmt_date($state['date_submitted']), 'step' => 'who'];
        }
        if (!empty($state['target_date'])) {
            $rows[] = ['label' => 'Target date', 'value' => fmt_date($state['target_date']), 'step' => 'who'];
        }
        if (!empty($state['stage_id']) && $type) {
            foreach ($type['stages'] as $s) {
                if ((int) $s['id'] === (int) $state['stage_id']) {
                    $rows[] = ['label' => 'Stage', 'value' => $s['label'], 'step' => 'who'];
                }
            }
        }
        if (!empty($state['confidential']) && $state['confidential'] !== '0') {
            $rows[] = ['label' => 'Confidential', 'value' => 'Yes — only the lead, team, R&D group and administrators can open it', 'step' => 'who'];
        }
        if (!empty($state['default_ink_series_id'])) {
            $rows[] = ['label' => 'Ink series', 'value' => (string) $this->lookupLabel('chroma_ink_series', 'name', $state['default_ink_series_id']), 'step' => 'defaults'];
        }
        if (!empty($state['default_substrate'])) {
            $rows[] = ['label' => 'Substrate', 'value' => (string) $state['default_substrate'], 'step' => 'defaults'];
        }
        if (!empty($state['default_coating_id'])) {
            $rows[] = ['label' => 'Coating', 'value' => (string) $this->lookupLabel('chroma_coatings', 'name', $state['default_coating_id']), 'step' => 'defaults'];
        }
        $items = $this->itemsFromState($state);
        $label = $type ? mb_strtolower((string) ($type['item_label'] ?? 'items')) : 'items';
        $n = 0;
        foreach ($items as $it) {
            $n++;
            $v = $it['description'] ?? '';
            if (!empty($it['existing_ref'])) {
                $v = trim(($state['item_' . $n . '_existing_ref_label'] ?? $it['existing_ref']) . ($v !== '' && !str_contains((string) ($state['item_' . $n . '_existing_ref_label'] ?? ''), $v) ? ' — ' . $v : ''));
            }
            if (!empty($it['pantone'])) {
                $v .= ' (' . $it['pantone'] . ')';
            }
            $rows[] = ['label' => ucfirst(mb_strtolower((string) ($type['item_label_singular'] ?? 'item'))) . ' ' . $n, 'value' => $v, 'step' => 'item_' . $n];
        }
        if ($items === []) {
            $rows[] = ['label' => ucfirst($label), 'value' => 'None yet — add them on the project page', 'step' => 'item_1'];
        }
        return $rows;
    }

    public function commit(array $state): WizardResult
    {
        $type = $this->type($state);
        if ($type === null) {
            throw ValidationException::single('type', 'Pick a project type first.');
        }
        $data = [
            'type' => $type['code'],
            'customer_id' => $state['customer_id'] ?? null,
            'customer_text' => $state['customer_id_text'] ?? null,
            'title' => $state['title'] ?? '',
            'items' => $this->itemsFromState($state),
        ];
        foreach (['description', 'problem_statement', 'goal', 'assigned_user_id', 'date_submitted', 'target_date', 'stage_id', 'confidential', 'default_ink_series_id', 'default_substrate', 'default_coating_id'] as $k) {
            if (array_key_exists($k, $state)) {
                $data[$k] = $state[$k];
            }
        }
        $project = $this->projects()->create($data, $this->app->auth->id());
        $count = count($data['items']);
        $singular = mb_strtolower((string) $type['item_label_singular']);
        return new WizardResult(
            'Project ' . $project['project_number'] . ' created',
            $count ? sprintf('%s saved with %s. Next: assign codes as each %s is matched.', $project['title'], plural($count, $singular, mb_strtolower((string) $type['item_label'])), $singular) : $project['title'] . ' saved. Add the first ' . $singular . ' from the project page.',
            [],
            [['Project number', (string) $project['project_number']], ['Type', (string) $type['label']], ['Customer', (string) ($project['customer_name'] ?? '')]],
            [
                ['label' => 'Open the project', 'href' => '/projects/' . $project['id'], 'primary' => true],
                ['label' => 'Start another project', 'href' => '/wizard/new-project'],
                ['label' => 'Back to the projects list', 'href' => '/projects'],
            ]
        );
    }

    public function expertUrl(array $state): ?string
    {
        $q = ['expert' => 1];
        foreach (['type', 'customer_id', 'title', 'description', 'problem_statement', 'goal', 'assigned_user_id', 'date_submitted', 'target_date', 'stage_id', 'confidential', 'default_ink_series_id', 'default_substrate', 'default_coating_id'] as $k) {
            if (isset($state[$k]) && $state[$k] !== '') {
                $q[$k] = $state[$k];
            }
        }
        if (!empty($state['customer_id_text'])) {
            $q['customer_text'] = $state['customer_id_text'];
        }
        foreach ($this->itemsFromState($state) as $i => $it) {
            foreach ($it as $k => $v) {
                $q['items'][$i][$k] = $v;
            }
        }
        return url('/mode', ['set' => 'expert', 'return' => '/projects/new?' . http_build_query($q)]);
    }

    public function cancelUrl(array $state): string
    {
        return '/projects';
    }

    public function reviewTitle(): string
    {
        return 'Check the project before saving';
    }

    public function commitLabel(): string
    {
        return 'Create project';
    }

    public function commitExplanation(): string
    {
        return 'This gives the project the next number and opens it. No codes are assigned yet.';
    }
}
