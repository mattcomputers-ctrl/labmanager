<?php
declare(strict_types=1);

namespace Chroma\Http\Wizard;

use Chroma\Http\Tips;
use Chroma\Core\Permissions;
use Chroma\Core\Request;
use Chroma\Domain\Codes\CodeAssigner;
use Chroma\Domain\Codes\ManualEntry;
use Chroma\Domain\Codes\QuickAssign;

/**
 * Easy-mode manual entry: type of code → the exact code and description →
 * details → confirm. Calls ManualEntry::sell/internal (which checks the
 * limits and duplicates in plain language, then CodeAssigner::manual*),
 * the same operation the Expert /codes/manual page calls.
 */
final class ManualEntryWizard extends Wizard
{
    public function key(): string
    {
        return 'manual-entry';
    }

    public function title(): string
    {
        return 'Enter a code by hand';
    }

    public function permissions(): array
    {
        return [Permissions::CODES_MANUAL_ENTRY];
    }

    public function initialState(Request $request): array
    {
        $state = [];
        if (in_array($request->str('type'), ['sell', 'internal'], true)) {
            $state['type'] = $request->str('type');
        }
        if ($request->str('code') !== '') {
            $state['code'] = $request->str('code');
        }
        $state['date_assigned'] = today();
        return $state;
    }

    public function steps(array $state): array
    {
        $type = $state['type'] ?? 'sell';
        $steps = [
            new Step(
                key: 'type',
                title: 'What kind of code are you entering?',
                intro: 'Manual entry is for legacy items and odd cases where the code already exists on paper. For anything new, use Quick assign so Chroma picks the next free number.',
                fields: [
                    ['name' => 'type', 'type' => 'radio', 'label' => 'Kind of code', 'required' => true, 'options' => [
                        ['sell', 'A sell number', 'Prefix, number and optional suffix, like Y1714UV or X104043.'],
                        ['internal', 'An E number', 'E followed by digits, with an optional version after a dash, like E6374-10.'],
                    ]],
                ],
                help: 'Every manual entry is written to the log with your name.',
            ),
            new Step(
                key: 'code',
                title: $type === 'internal' ? 'Which E number?' : 'Which sell number?',
                intro: $type === 'internal' ? 'Type it exactly as it appears on the formula card.' : 'Type it as it appears on the product. Chroma tidies the spelling (y-1000 becomes Y1000) and keeps what you typed for reference.',
                fields: [
                    ['name' => 'code', 'label' => $type === 'internal' ? 'E number' : 'Sell number', 'required' => true, 'required_message' => 'Type the code you want to enter.', 'code' => true, 'placeholder' => $type === 'internal' ? 'E6374-10' : 'Y1714UV', 'maxlength' => 40, 'attrs' => ['style' => 'max-width:280px']],
                    ['name' => 'description', 'label' => 'Description', 'required' => true, 'required_message' => 'Give the code a short description so people can find it later.', 'maxlength' => 500, 'placeholder' => 'What the product is'],
                ],
            ),
        ];
        $fields = [];
        if ($type === 'sell') {
            $fields[] = ['name' => 'internal', 'type' => 'lookup', 'label' => 'E number it belongs to', 'optional_text' => true, 'lookup' => '/api/codes/search?q=', 'placeholder' => 'E number or another sell number of the product', 'help' => 'Leave blank if the formula has no E number yet.'];
            $fields[] = ['name' => 'reason', 'type' => 'select', 'label' => 'Reason', 'optional_text' => true, 'tip' => Tips::REASON, 'placeholder' => 'No particular reason', 'options' => array_map(fn($k, $v) => [$k, $v], array_keys(CodeAssigner::REASONS), CodeAssigner::REASONS), 'attrs' => ['x-model' => 'reason']];
            $fields[] = ['name' => 'reason_text', 'label' => 'Say why', 'required' => true, 'required_message' => 'Say in a few words why this code is needed.', 'maxlength' => 500, 'placeholder' => 'A few words on why this code is needed', 'help' => 'Needed when the reason is "Other". It is kept with the code.', 'show_when' => "reason === 'other'"];
        } else {
            $fields[] = ['name' => 'resale', 'type' => 'checkbox', 'label' => 'Purchased for resale', 'help' => 'Tick if this is bought in rather than made here.'];
            $fields[] = ['name' => 'vendor_name', 'label' => 'Vendor', 'optional_text' => true, 'maxlength' => 200];
            $fields[] = ['name' => 'vendor_code', 'label' => "Vendor's code", 'optional_text' => true, 'maxlength' => 100];
        }
        $fields[] = ['name' => 'original_customer_id', 'type' => 'lookup', 'label' => 'Customer', 'optional_text' => true, 'lookup' => '/api/customers?q=', 'placeholder' => 'Start typing the customer name'];
        $fields[] = ['name' => 'date_assigned', 'type' => 'date', 'label' => 'Date it was assigned', 'required' => true, 'help' => 'The date on the sheet, if you know it; otherwise today.'];
        $fields[] = ['name' => 'notes', 'type' => 'textarea', 'label' => 'Notes', 'optional_text' => true, 'rows' => 3, 'maxlength' => 2000];
        $steps[] = new Step(key: 'details', title: 'Anything else about ' . (($state['code'] ?? '') !== '' ? strtoupper((string) $state['code']) : 'it') . '?', intro: 'All of this is optional except the date.', fields: $fields, template: 'codes/wizard-details', nextLabel: 'Review');
        return $steps;
    }

    public function validateStep(Step $step, array $input, array $state): array
    {
        if ($step->key === 'details') {
            // "Say why" is only required when the reason is Other; the generic
            // required check would demand it for every reason.
            $fields = array_map(function (array $f) use ($input) {
                if ($f['name'] === 'reason_text' && (string) ($input['reason'] ?? '') !== 'other') {
                    $f['required'] = false;
                }
                return $f;
            }, $step->fields);
            $step = new Step(key: $step->key, title: $step->title, intro: $step->intro, fields: $fields, help: $step->help, template: $step->template, nextLabel: $step->nextLabel);
        }
        $out = parent::validateStep($step, $input, $state);
        if ($step->key === 'code') {
            $problem = ManualEntry::problem($state['type'] ?? 'sell', (string) $out['code']);
            if ($problem !== null) {
                $this->fail(['code' => $problem]);
            }
        }
        if ($step->key === 'details' && ($state['type'] ?? 'sell') === 'sell') {
            if (($out['reason'] ?? '') === 'other' && trim((string) ($out['reason_text'] ?? '')) === '') {
                $this->fail(['reason_text' => 'Say in a few words why this code is needed.']);
            }
            if (($out['reason'] ?? '') !== 'other') {
                $out['reason_text'] = '';
            }
        }
        if ($step->key === 'details' && ($state['type'] ?? 'sell') === 'sell' && ($out['internal'] ?? '') !== '') {
            $product = (new QuickAssign($this->app->db, new CodeAssigner($this->app->db, $this->app->audit)))->product((string) $out['internal']);
            if ($product === null || $product['internal_code_id'] === null) {
                $this->fail(['internal' => 'Pick an E number from the list (or a sell number that already has one), or leave this blank.']);
            }
            $out['internal_id'] = (int) $product['internal_code_id'];
            $out['internal_label'] = $product['internal']['display'];
        } elseif ($step->key === 'details') {
            $out['internal_id'] = '';
            $out['internal_label'] = '';
        }
        if ($step->key === 'details') {
            $customerId = (int) ($out['original_customer_id'] ?? 0);
            $out['original_customer_id_label'] = $customerId > 0 ? (string) $this->app->db->value('SELECT name FROM chroma_customers WHERE id = ?', [$customerId]) : '';
            $out['original_customer_id'] = $out['original_customer_id_label'] !== '' ? (string) $customerId : '';
        }
        return $out;
    }

    public function review(array $state): array
    {
        $type = $state['type'] ?? 'sell';
        $rows = [
            ['label' => 'Kind', 'value' => $type === 'internal' ? 'E number' : 'Sell number', 'step' => 'type'],
            ['label' => 'Code', 'value' => strtoupper((string) ($state['code'] ?? '')), 'step' => 'code'],
            ['label' => 'Description', 'value' => (string) ($state['description'] ?? ''), 'step' => 'code'],
        ];
        if ($type === 'sell') {
            $rows[] = ['label' => 'E number', 'value' => (string) ($state['internal_label'] ?? ''), 'step' => 'details'];
            $rows[] = ['label' => 'Reason', 'value' => (CodeAssigner::REASONS[$state['reason'] ?? ''] ?? '') . (($state['reason'] ?? '') === 'other' && ($state['reason_text'] ?? '') !== '' ? ' — ' . $state['reason_text'] : ''), 'step' => 'details'];
        } else {
            $vendor = trim((string) ($state['vendor_name'] ?? '') . ((($state['vendor_code'] ?? '') !== '') ? ' (' . $state['vendor_code'] . ')' : ''));
            $rows[] = ['label' => 'Resale', 'value' => !empty($state['resale']) ? 'Yes' . ($vendor !== '' ? ' — ' . $vendor : '') : 'No', 'step' => 'details'];
        }
        $rows[] = ['label' => 'Customer', 'value' => (string) ($state['original_customer_id_label'] ?? ''), 'step' => 'details'];
        $rows[] = ['label' => 'Date assigned', 'value' => fmt_date($state['date_assigned'] ?? ''), 'step' => 'details'];
        $rows[] = ['label' => 'Notes', 'value' => (string) ($state['notes'] ?? ''), 'step' => 'details'];
        return $rows;
    }

    public function commit(array $state): WizardResult
    {
        $entry = new ManualEntry($this->app->db, new CodeAssigner($this->app->db, $this->app->audit));
        $data = [
            'description' => $state['description'] ?? '',
            'original_customer_id' => ($state['original_customer_id'] ?? '') !== '' ? (int) $state['original_customer_id'] : null,
            'date_assigned' => ($state['date_assigned'] ?? '') !== '' ? $state['date_assigned'] : today(),
            'notes' => ($state['notes'] ?? '') !== '' ? $state['notes'] : null,
        ];
        if (($state['type'] ?? 'sell') === 'internal') {
            $row = $entry->internal((string) $state['code'], $data + ['resale' => !empty($state['resale']), 'vendor_name' => ($state['vendor_name'] ?? '') !== '' ? $state['vendor_name'] : null, 'vendor_code' => ($state['vendor_code'] ?? '') !== '' ? $state['vendor_code'] : null], $this->app->auth->id());
            $url = '/codes/e/' . (int) $row['id'];
            $label = 'E number';
        } else {
            $row = $entry->sell((string) $state['code'], $data + ['internal_code_id' => ($state['internal_id'] ?? '') !== '' ? (int) $state['internal_id'] : null, 'reason' => ($state['reason'] ?? '') !== '' ? $state['reason'] : null, 'reason_text' => ($state['reason_text'] ?? '') !== '' ? $state['reason_text'] : null], $this->app->auth->id());
            $url = '/codes/s/' . (int) $row['id'];
            $label = 'Sell number';
        }
        return new WizardResult(
            title: $row['display'] . ' was entered',
            message: 'This manual entry is recorded in the log with your name.',
            codes: [['code' => $row['display'], 'label' => $label, 'description' => $row['description']]],
            nextActions: [
                ['label' => 'Open ' . $row['display'], 'href' => $url, 'primary' => true],
                ['label' => 'Enter another code', 'href' => '/wizard/manual-entry'],
                ['label' => 'Code lookup', 'href' => '/codes'],
            ],
        );
    }

    public function expertUrl(array $state): ?string
    {
        $q = ['expert' => 1];
        foreach (['type', 'code', 'description', 'internal', 'reason', 'reason_text', 'resale', 'vendor_name', 'vendor_code', 'original_customer_id', 'date_assigned', 'notes'] as $k) {
            if (($state[$k] ?? '') !== '') {
                $q[$k] = $state[$k];
            }
        }
        if (($state['original_customer_id_label'] ?? '') !== '') {
            $q['original_customer_label'] = $state['original_customer_id_label'];
        }
        if (($state['internal_label'] ?? '') !== '') {
            $q['internal_label'] = $state['internal_label'];
        }
        return url('/mode', ['set' => 'expert', 'return' => url('/codes/manual', $q)]);
    }

    public function cancelUrl(array $state): string
    {
        return '/codes';
    }

    public function commitLabel(): string
    {
        return 'Enter this code';
    }

    public function commitExplanation(): string
    {
        return 'Chroma checks the code is not already used, writes it to the cross-reference and logs that you entered it by hand.';
    }
}
