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

    public function steps(array $state): array
    {
        $this->requireAny();
        $product = $this->productFor($state);
        return [
            new Step(
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
            ),
            new Step(
                key: 'code',
                title: $product ? 'Which code does ' . $product['label'] . ' need?' : 'Which code do you need?',
                intro: $product ? 'Chroma gives out the next free number. You only choose the prefix, an optional suffix and the reason.' : 'Chroma gives out the next free number under the prefix you choose.',
                fields: [
                    ['name' => 'want', 'required' => true], ['name' => 'prefix_id'], ['name' => 'suffix_id'], ['name' => 'description', 'maxlength' => 500],
                    ['name' => 'reason'], ['name' => 'reason_text', 'maxlength' => 500], ['name' => 'original_customer_id', 'type' => 'lookup'],
                    ['name' => 'resale'], ['name' => 'vendor_name', 'maxlength' => 200], ['name' => 'vendor_code', 'maxlength' => 100],
                    ['name' => 'notes', 'maxlength' => 2000],
                ],
                template: 'codes/wizard-code',
            ),
        ];
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
            }
            return $out;
        }
        if ($step->key === 'code') {
            $product = ($state['product_mode'] ?? '') === 'existing' ? $this->quick()->product((string) ($state['product'] ?? '')) : null;
            $allowed = QuickAssign::wantsFor($product);
            $v = new Validator($input);
            $want = (string) ($input['want'] ?? '');
            if (!isset($allowed[$want])) {
                $v->error('want', 'Choose what you need from the list.');
            }
            if ($want !== 'internal' && (int) ($input['prefix_id'] ?? 0) <= 0) {
                $v->error('prefix_id', 'Choose a prefix. The prefix says what kind of product this is.');
            }
            $description = trim((string) ($input['description'] ?? ''));
            if ($description === '' && $product !== null) {
                $description = (string) $product['description'];
            }
            if ($description === '') {
                $v->error('description', 'Give the code a short description so people can find it later.');
            }
            $v->maxLength('description', 500);
            $v->maxLength('notes', 2000);
            if ($want !== 'internal') {
                if ($product !== null && trim((string) ($input['reason'] ?? '')) === '') {
                    $v->error('reason', 'Say why this product needs another sell number. Pick a reason from the list.');
                }
                $v->in('reason', array_keys(CodeAssigner::REASONS), 'Pick a reason from the list.');
                if (($input['reason'] ?? '') === 'other' && trim((string) ($input['reason_text'] ?? '')) === '') {
                    $v->error('reason_text', 'Say in a few words why this code is needed.');
                }
            }
            $v->fail();
            $customerId = (int) ($input['original_customer_id'] ?? 0);
            $customerName = $customerId > 0 ? (string) $this->app->db->value('SELECT name FROM chroma_customers WHERE id = ?', [$customerId]) : '';
            return [
                'want' => $want,
                'prefix_id' => $want === 'internal' ? '' : (string) (int) ($input['prefix_id'] ?? 0),
                'suffix_id' => $want === 'internal' ? '' : trim((string) ($input['suffix_id'] ?? '')),
                'description' => $description,
                'reason' => $want === 'internal' ? '' : trim((string) ($input['reason'] ?? '')),
                'reason_text' => trim((string) ($input['reason_text'] ?? '')),
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
        $want = (string) ($state['want'] ?? '');
        $rows[] = ['label' => 'What', 'value' => QuickAssign::wantsFor($product)[$want] ?? QuickAssign::WANTS[$want] ?? $want, 'step' => 'code'];
        if ($want !== 'sell') {
            $rows[] = ['label' => 'E number', 'value' => 'Next free: E' . (new CodeAssigner($this->app->db, $this->app->audit))->previewInternal(), 'step' => 'code'];
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
            $rows[] = ['label' => 'Sell number', 'value' => $prefix ? $prefix['code'] . ' — ' . $prefix['label'] . ' (next free: ' . $prefix['next'] . $suffix . ')' : '—', 'step' => 'code'];
            $rows[] = ['label' => 'Suffix', 'value' => $suffix !== '' ? $suffix : 'none', 'step' => 'code'];
            $reason = (string) ($state['reason'] ?? '');
            $rows[] = ['label' => 'Reason', 'value' => $reason !== '' ? (CodeAssigner::REASONS[$reason] ?? $reason) . (($state['reason_text'] ?? '') !== '' ? ' — ' . $state['reason_text'] : '') : '', 'step' => 'code'];
        }
        $rows[] = ['label' => 'Description', 'value' => (string) ($state['description'] ?? ''), 'step' => 'code'];
        $rows[] = ['label' => 'Customer', 'value' => (string) ($state['original_customer_id_label'] ?? '') ?: ($product['customer'] ?? ''), 'step' => 'code'];
        if (($state['notes'] ?? '') !== '') {
            $rows[] = ['label' => 'Notes', 'value' => (string) $state['notes'], 'step' => 'code'];
        }
        if ($want !== 'sell' && !empty($state['resale'])) {
            $rows[] = ['label' => 'Resale item', 'value' => 'Yes' . (($state['vendor_name'] ?? '') !== '' ? ' — ' . $state['vendor_name'] . (($state['vendor_code'] ?? '') !== '' ? ' (' . $state['vendor_code'] . ')' : '') : ''), 'step' => 'code'];
        }
        return $rows;
    }

    public function commit(array $state): WizardResult
    {
        $want = (string) ($state['want'] ?? '');
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
        return 'Pressing the button reserves the next free number and writes it to the cross-reference. Numbers are never reused; if this is a mistake, void it afterwards.';
    }
}
