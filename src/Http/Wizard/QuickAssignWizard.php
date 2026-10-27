<?php
declare(strict_types=1);

namespace Chroma\Http\Wizard;

use Chroma\Core\ForbiddenException;
use Chroma\Core\Permissions;
use Chroma\Core\Request;
use Chroma\Core\ValidationException;
use Chroma\Core\Validator;
use Chroma\Domain\Codes\CodeAssigner;
use Chroma\Domain\Codes\QuickAssign;
use Chroma\Http\Controllers\Codes\QuickAssignController;

/**
 * Easy-mode quick assign: find the product → choose the code → confirm.
 * Calls QuickAssign::run(), the same operation as the Expert /assign page.
 */
final class QuickAssignWizard extends Wizard
{
    public function key(): string
    {
        return 'quick-assign';
    }

    public function title(): string
    {
        return 'Assign a code';
    }

    private function quick(): QuickAssign
    {
        return new QuickAssign($this->app->db, new CodeAssigner($this->app->db, $this->app->audit));
    }

    /** Either assign permission opens the wizard; commit() checks the one that matches the choice. */
    private function requireAny(): void
    {
        if (!$this->app->auth->canAny(Permissions::CODES_ASSIGN_INTERNAL, Permissions::CODES_ASSIGN_SELL)) {
            throw new ForbiddenException();
        }
    }

    /** Helpers for the custom step templates (resources/views/codes/wizard-*.php). */
    public function productFor(array $state): ?array
    {
        return !empty($state['product']) && ($state['product_mode'] ?? '') === 'existing' ? $this->quick()->product((string) $state['product']) : null;
    }

    /** @return list<array<string,mixed>> */
    public function prefixChoices(): array
    {
        return $this->quick()->prefixChoices();
    }

    /** The "what do you need" options this user may pick for this product. @return array<string,string> */
    public function wantsFor(?array $product): array
    {
        $out = [];
        foreach (QuickAssign::wantsFor($product) as $k => $label) {
            if ($k !== 'internal' && !$this->app->auth->can(Permissions::CODES_ASSIGN_SELL)) {
                continue;
            }
            if ($k !== 'sell' && !$this->app->auth->can(Permissions::CODES_ASSIGN_INTERNAL)) {
                continue;
            }
            $out[$k] = $label;
        }
        return $out;
    }

    public function nextInternal(): string
    {
        return 'E' . (new CodeAssigner($this->app->db, $this->app->audit))->previewInternal();
    }

    public function initialState(Request $request): array
    {
        $this->requireAny();
        $state = ['product_mode' => 'existing'];
        $product = $this->quick()->product($request->str('product'));
        if ($product !== null) {
            $state['product'] = $product['ref'];
            $state['product_label'] = $product['label'];
            $state['product_mode'] = 'existing';
        } elseif ($request->str('product_mode') === 'new') {
            $state['product_mode'] = 'new';
        }
        foreach (['want', 'prefix_id', 'suffix_id', 'description', 'reason', 'reason_text', 'original_customer_id', 'original_customer_id_label', 'resale', 'vendor_name', 'vendor_code', 'notes'] as $k) {
            if ($request->str($k) !== '') {
                $state[$k] = $request->str($k);
            }
        }
        return $state;
    }

    /**
     * Prefixes split into the everyday ones and the inherited/legacy ones,
     * which the prefix screen keeps folded away.
     * @return array{everyday:list<array<string,mixed>>,other:list<array<string,mixed>>}
     */
    public function prefixGroups(): array
    {
        $out = ['everyday' => [], 'other' => []];
        foreach ($this->prefixChoices() as $p) {
            $out[in_array((string) $p['tag'], ['inherited', 'legacy'], true) ? 'other' : 'everyday'][] = $p;
        }
        return $out;
    }

    /** What the shared prefix screen (codes/wizard-prefix) shows above the cards and starts on. @return array{default:?int,context:?array{label:string,description:string,note:string}} */
    public function prefixScreen(array $state): array
    {
        $product = $this->productFor($state);
        return [
            'default' => $this->defaultPrefixId($product),
            'context' => $product ? ['label' => $product['label'], 'description' => (string) $product['description'], 'note' => 'Its current prefix is picked for you; change it if the new code is a different kind.'] : null,
        ];
    }

    /** The prefix of the product's current sell number, so the prefix screen starts on it (Y1727 → Y). */
    public function defaultPrefixId(?array $product): ?int
    {
        if ($product === null) {
            return null;
        }
        $candidates = $product['sell'] ? array_merge([$product['sell']], $product['sells']) : $product['sells'];
        foreach ($candidates as $s) {
            if ((int) $s['voided'] === 0 && (int) $s['prefix_retired'] === 0) {
                return (int) $s['prefix_id'];
            }
        }
        return null;
    }

    /** What the user needs: the answer given, or the only possible answer when there is just one. */
    public function wantFor(array $state, ?array $product): string
    {
        $wants = $this->wantsFor($product);
        $want = (string) ($state['want'] ?? '');
        if (isset($wants[$want])) {
            return $want;
        }
        return count($wants) === 1 ? (string) array_key_first($wants) : '';
    }

    /** Suffixes the chosen prefix allows. @return list<array{id:int,code:string,label:string}> */
    public function suffixesFor(array $state): array
    {
        foreach ($this->prefixChoices() as $p) {
            if ($p['id'] === (int) ($state['prefix_id'] ?? 0)) {
                return $p['suffixes'];
            }
        }
        return [];
    }

    /** Which code is wanted decides whether a prefix is asked, so the count is open until that is answered (or implied). */
    public function stepCountKnown(array $state): bool
    {
        return $this->wantFor($state, $this->productFor($state)) !== '';
    }

    /** One question per screen: product → what → prefix → suffix → description and reason → the optional extras. */
    public function steps(array $state): array
    {
        $this->requireAny();
        $product = $this->productFor($state);
        $wants = $this->wantsFor($product);
        $want = $this->wantFor($state, $product);
        $steps = [new Step(
            key: 'product',
            title: 'Which product is this for?',
            intro: 'Most of the time you are adding another sell number to a product that already has one: a relabel, a customer-specific code or a new version. Search by E number, sell number or description.',
            fields: [
                ['name' => 'product_mode', 'type' => 'radio', 'label' => 'Product', 'required' => true, 'options' => [
                    ['existing', 'An existing product', 'Add a code to something that already has an E number or a sell number.'],
                    ['new', 'A brand-new product', 'Nothing has been coded for it yet (for example a resale item or a purchased product).'],
                ]],
                ['name' => 'product', 'type' => 'lookup', 'label' => 'Find the product', 'lookup' => '/api/codes/search?q=', 'placeholder' => 'E number, sell number or a word from the description'],
            ],
            template: 'codes/wizard-product',
        )];
        if (count($wants) !== 1) {
            $descriptions = [
                'sell' => $product ? 'Both sell numbers stay without an E number.' : 'The code the customer sees, for example a resale item.',
                'internal' => $product ? 'It has none yet; the sell number will point at the new E number.' : 'An internal formula code with no sell number yet.',
                'both' => $product ? 'The existing sell number and the new one will share the new E number.' : 'Assigned together; the sell number is linked to the new E number.',
            ];
            $steps[] = new Step(
                key: 'want',
                title: $product ? 'Which code does ' . $product['label'] . ' need?' : 'Which code do you need?',
                intro: 'Chroma gives out the next free number; you only say what kind.',
                fields: [['name' => 'want', 'type' => 'radio', 'label' => 'Code', 'required' => true, 'required_message' => 'Choose what you need from the list.',
                    'options' => array_map(fn($k, $label) => [$k, $label, $descriptions[$k] ?? ''], array_keys($wants), $wants)]],
            );
        }
        if ($want === '') {
            return $steps;
        }
        if ($want !== 'internal') {
            $steps[] = new Step(
                key: 'prefix',
                title: 'Which prefix?',
                intro: 'The prefix says what kind of product this is. The number after it is given out automatically; the next free one is shown on each card.',
                fields: [['name' => 'prefix_id', 'required' => true]],
                template: 'codes/wizard-prefix',
            );
            $suffixes = $this->suffixesFor($state);
            if ($suffixes !== []) {
                $steps[] = new Step(
                    key: 'suffix',
                    title: 'Add a suffix?',
                    intro: 'Only if this is a special version. "None" is the usual answer; a suffix never starts a new count.',
                    fields: [['name' => 'suffix_id', 'type' => 'radio', 'label' => 'Suffix', 'default' => '', 'tip' => \Chroma\Http\Tips::SUFFIX,
                        'options' => array_merge([['', '(none)', 'Just the number, no suffix.']], array_map(fn($s) => [$s['id'], $s['code'], $s['label']], $suffixes))]],
                );
            }
        }
        $steps[] = new Step(
            key: 'details',
            title: $want === 'internal' ? 'How should the E number be described?' : ($product ? 'Why does ' . $product['label'] . ' need another sell number?' : 'How should the code be described?'),
            intro: 'The description is what people see in code lookup, so keep it short and searchable.',
            fields: [['name' => 'description', 'required' => true, 'maxlength' => 500], ['name' => 'reason'], ['name' => 'reason_text', 'maxlength' => 500]],
            template: 'codes/wizard-quick-details',
        );
        $extras = [
            ['name' => 'original_customer_id', 'type' => 'lookup', 'label' => 'Customer', 'optional_text' => true, 'lookup' => '/api/customers?q=', 'placeholder' => 'Start typing the customer name',
                'help' => $product && $product['customer'] ? 'Leave blank to keep ' . $product['customer'] . '.' : 'Leave blank if there is no particular customer.'],
        ];
        if ($want !== 'sell') {
            $extras[] = ['name' => 'resale', 'type' => 'checkbox', 'label' => 'Purchased for resale', 'help' => 'Bought in rather than made here.'];
            $extras[] = ['name' => 'vendor_name', 'label' => 'Vendor', 'optional_text' => true, 'maxlength' => 200];
            $extras[] = ['name' => 'vendor_code', 'label' => "Vendor's code", 'optional_text' => true, 'maxlength' => 100];
        }
        $extras[] = ['name' => 'notes', 'type' => 'textarea', 'label' => 'Notes', 'optional_text' => true, 'rows' => 2, 'maxlength' => 2000, 'help' => 'Anything worth knowing about this code. Kept on the code page.'];
        $steps[] = new Step(
            key: 'extras',
            title: 'Anything else to record?',
            intro: 'All of this is optional. Press Review to skip it.',
            fields: $extras,
            nextLabel: 'Review',
        );
        return $steps;
    }

    public function validateStep(Step $step, array $input, array $state): array
    {
        if ($step->key === 'product') {
            $mode = (string) ($input['product_mode'] ?? '');
            if (!in_array($mode, ['existing', 'new'], true)) {
                $this->fail(['product_mode' => 'Say whether this is for an existing product or a brand-new one.']);
            }
            if ($mode === 'new') {
                return ['product_mode' => 'new', 'product' => '', 'product_label' => '', 'want' => $state['want'] ?? '', 'reason' => $state['reason'] ?? ''];
            }
            $product = $this->quick()->product(isset($input['product']) ? (string) $input['product'] : null);
            if ($product === null) {
                $this->fail(['product' => 'Search for the product and pick it from the list. If it is not there, choose "a brand-new product".']);
            }
            $out = ['product_mode' => 'existing', 'product' => $product['ref'], 'product_label' => $product['label']];
            if (($state['product'] ?? '') !== $product['ref']) {
                // A different product: forget answers that depended on the old one.
                $out['want'] = '';
                $out['description'] = '';
                $out['prefix_id'] = '';
                $out['suffix_id'] = '';
            }
            return $out;
        }
        $product = $this->productFor($state);
        if ($step->key === 'want') {
            $want = (string) ($input['want'] ?? '');
            if (!isset($this->wantsFor($product)[$want])) {
                $this->fail(['want' => 'Choose what you need from the list.']);
            }
            $out = ['want' => $want];
            if ($want === 'internal') {
                $out['prefix_id'] = '';
                $out['suffix_id'] = '';
                $out['reason'] = '';
                $out['reason_text'] = '';
            }
            return $out;
        }
        if ($step->key === 'prefix') {
            $id = (int) ($input['prefix_id'] ?? 0);
            $known = array_filter($this->prefixChoices(), fn($p) => $p['id'] === $id);
            if ($id <= 0 || $known === []) {
                $this->fail(['prefix_id' => 'Choose a prefix. The prefix says what kind of product this is.']);
            }
            $out = ['prefix_id' => (string) $id];
            if ((string) ($state['prefix_id'] ?? '') !== (string) $id) {
                $out['suffix_id'] = '';
            }
            return $out;
        }
        if ($step->key === 'suffix') {
            $suffix = trim((string) ($input['suffix_id'] ?? ''));
            if ($suffix !== '' && !in_array((int) $suffix, array_map(fn($s) => $s['id'], $this->suffixesFor($state)), true)) {
                $this->fail(['suffix_id' => 'Pick one of the suffixes this prefix allows, or "(none)".']);
            }
            return ['suffix_id' => $suffix === '' ? '' : (string) (int) $suffix];
        }
        $want = $this->wantFor($state, $product);
        if ($step->key === 'details') {
            $v = new Validator($input);
            $description = trim((string) ($input['description'] ?? ''));
            if ($description === '' && $product !== null) {
                $description = (string) $product['description'];
            }
            if ($description === '') {
                $v->error('description', 'Give the code a short description so people can find it later.');
            }
            $v->maxLength('description', 500);
            if ($want !== 'internal') {
                if ($product !== null && trim((string) ($input['reason'] ?? '')) === '') {
                    $v->error('reason', 'Say why this product needs another sell number. Pick a reason from the list.');
                }
                $v->in('reason', array_keys(CodeAssigner::REASONS), 'Pick a reason from the list.');
                if (($input['reason'] ?? '') === 'other' && trim((string) ($input['reason_text'] ?? '')) === '') {
                    $v->error('reason_text', 'Say in a few words why this code is needed.');
                }
                $v->maxLength('reason_text', 500);
            }
            $v->fail();
            return [
                'description' => $description,
                'reason' => $want === 'internal' ? '' : trim((string) ($input['reason'] ?? '')),
                'reason_text' => $want === 'internal' ? '' : trim((string) ($input['reason_text'] ?? '')),
            ];
        }
        if ($step->key === 'extras') {
            $v = new Validator($input);
            $v->maxLength('notes', 2000);
            $v->maxLength('vendor_name', 200);
            $v->maxLength('vendor_code', 100);
            $v->fail();
            $customerId = (int) ($input['original_customer_id'] ?? 0);
            $customerName = $customerId > 0 ? (string) $this->app->db->value('SELECT name FROM chroma_customers WHERE id = ?', [$customerId]) : '';
            return [
                'original_customer_id' => $customerName !== '' ? (string) $customerId : '',
                'original_customer_id_label' => $customerName,
                'resale' => $want === 'sell' ? '' : (string) ($input['resale'] ?? ''),
                'vendor_name' => $want === 'sell' ? '' : trim((string) ($input['vendor_name'] ?? '')),
                'vendor_code' => $want === 'sell' ? '' : trim((string) ($input['vendor_code'] ?? '')),
                'notes' => trim((string) ($input['notes'] ?? '')),
            ];
        }
        return parent::validateStep($step, $input, $state);
    }

    public function review(array $state): array
    {
        $quick = $this->quick();
        $product = ($state['product_mode'] ?? '') === 'existing' ? $quick->product((string) ($state['product'] ?? '')) : null;
        $rows = [['label' => 'Product', 'value' => $product ? $product['label'] . ' — ' . $product['description'] : 'A brand-new product', 'step' => 'product']];
        $want = $this->wantFor($state, $product);
        $rows[] = ['label' => 'What', 'value' => QuickAssign::wantsFor($product)[$want] ?? QuickAssign::WANTS[$want] ?? $want, 'step' => count($this->wantsFor($product)) === 1 ? 'product' : 'want'];
        if ($want !== 'sell') {
            $rows[] = ['label' => 'E number', 'value' => 'Next free: E' . (new CodeAssigner($this->app->db, $this->app->audit))->previewInternal()];
        }
        if ($want !== 'internal') {
            $prefix = null;
            foreach ($quick->prefixChoices() as $p) {
                if ($p['id'] === (int) ($state['prefix_id'] ?? 0)) {
                    $prefix = $p;
                }
            }
            $suffix = '';
            if ($prefix && ($state['suffix_id'] ?? '') !== '') {
                foreach ($prefix['suffixes'] as $s) {
                    if ($s['id'] === (int) $state['suffix_id']) {
                        $suffix = $s['code'];
                    }
                }
            }
            $rows[] = ['label' => 'Sell number', 'value' => $prefix ? $prefix['code'] . ' — ' . $prefix['label'] . ' (next free: ' . $prefix['next'] . $suffix . ')' : '—', 'step' => 'prefix'];
            if ($prefix && $prefix['suffixes'] !== []) {
                $rows[] = ['label' => 'Suffix', 'value' => $suffix !== '' ? $suffix : 'none', 'step' => 'suffix'];
            }
            $reason = (string) ($state['reason'] ?? '');
            $rows[] = ['label' => 'Reason', 'value' => $reason !== '' ? (CodeAssigner::REASONS[$reason] ?? $reason) . (($state['reason_text'] ?? '') !== '' ? ' — ' . $state['reason_text'] : '') : '', 'step' => 'details'];
        }
        $rows[] = ['label' => 'Description', 'value' => (string) ($state['description'] ?? ''), 'step' => 'details'];
        $rows[] = ['label' => 'Customer', 'value' => (string) ($state['original_customer_id_label'] ?? '') ?: ($product['customer'] ?? ''), 'step' => 'extras'];
        if (($state['notes'] ?? '') !== '') {
            $rows[] = ['label' => 'Notes', 'value' => (string) $state['notes'], 'step' => 'extras'];
        }
        if ($want !== 'sell' && !empty($state['resale'])) {
            $rows[] = ['label' => 'Resale item', 'value' => 'Yes' . (($state['vendor_name'] ?? '') !== '' ? ' — ' . $state['vendor_name'] . (($state['vendor_code'] ?? '') !== '' ? ' (' . $state['vendor_code'] . ')' : '') : ''), 'step' => 'extras'];
        }
        return $rows;
    }

    public function commit(array $state): WizardResult
    {
        $want = $this->wantFor($state, $this->productFor($state));
        QuickAssignController::requireFor($this->app->auth, $want);
        $res = $this->quick()->run([
            'product_mode' => $state['product_mode'] ?? 'existing',
            'product' => $state['product'] ?? '',
            'want' => $want,
            'prefix_id' => $state['prefix_id'] ?? null,
            'suffix_id' => $state['suffix_id'] ?? null,
            'description' => $state['description'] ?? '',
            'reason' => $state['reason'] ?? null,
            'reason_text' => $state['reason_text'] ?? null,
            'original_customer_id' => $state['original_customer_id'] ?? null,
            'resale' => !empty($state['resale']),
            'vendor_name' => $state['vendor_name'] ?? null,
            'vendor_code' => $state['vendor_code'] ?? null,
            'notes' => $state['notes'] ?? null,
        ], $this->app->auth->id());
        $codes = [];
        $actions = [];
        if ($res['internal']) {
            $codes[] = ['code' => $res['internal']['display'], 'label' => 'E number', 'description' => $res['internal']['description']];
            $actions[] = ['label' => 'Open ' . $res['internal']['display'], 'href' => '/codes/e/' . (int) $res['internal']['id']];
        }
        if ($res['sell']) {
            $codes[] = ['code' => $res['sell']['display'], 'label' => 'Sell number', 'description' => $res['sell']['description']];
            $actions[] = ['label' => 'Open ' . $res['sell']['display'], 'href' => '/codes/s/' . (int) $res['sell']['id']];
        }
        $details = [];
        if ($res['sell'] && $res['sell']['internal_code_id']) {
            $details[] = ['Linked to', $res['internal']['display'] ?? ($res['product']['internal']['display'] ?? '')];
        }
        if ($res['linked_sell']) {
            $details[] = [$res['linked_sell']['display'], 'now points at ' . $res['internal']['display']];
        }
        if ($res['sell'] && $res['sell']['reason']) {
            $details[] = ['Reason', CodeAssigner::REASONS[$res['sell']['reason']] ?? $res['sell']['reason']];
        }
        array_unshift($actions, ['label' => 'Assign another code', 'href' => '/wizard/quick-assign', 'primary' => true]);
        $actions[] = ['label' => 'Code lookup', 'href' => '/codes'];
        return new WizardResult(
            title: count($codes) > 1 ? 'Codes reserved' : 'Code reserved',
            message: 'Write it on the sample or the job sheet. It is in the cross-reference now.',
            codes: $codes,
            details: $details,
            nextActions: $actions,
        );
    }

    public function expertUrl(array $state): ?string
    {
        $q = ['expert' => 1];
        $state['want'] = $this->wantFor($state, $this->productFor($state));
        foreach (['product', 'product_mode', 'want', 'prefix_id', 'suffix_id', 'description', 'reason', 'reason_text', 'original_customer_id', 'resale', 'vendor_name', 'vendor_code', 'notes'] as $k) {
            if (($state[$k] ?? '') !== '') {
                $q[$k] = $state[$k];
            }
        }
        if (($state['original_customer_id_label'] ?? '') !== '') {
            $q['original_customer_label'] = $state['original_customer_id_label'];
        }
        return url('/mode', ['set' => 'expert', 'return' => url('/assign', $q)]);
    }

    public function cancelUrl(array $state): string
    {
        return '/codes';
    }

    public function commitLabel(): string
    {
        return 'Assign the code';
    }

    public function commitExplanation(): string
    {
        return 'Pressing the button reserves the next free number and writes it to the cross-reference. Numbers are never reused; if this is a mistake, '
            . ($this->app->auth->can(Permissions::CODES_VOID) ? 'void it afterwards.' : 'an administrator can void it afterwards.');
    }
}
