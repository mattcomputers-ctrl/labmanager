<?php
declare(strict_types=1);

namespace Chroma\Http\Wizard;

use Chroma\Core\NotFoundException;
use Chroma\Core\Permissions;
use Chroma\Core\Request;
use Chroma\Domain\Codes\CodeAssigner;
use Chroma\Domain\Codes\CodeFormat;
use Chroma\Domain\Codes\QuickAssign;

/**
 * Easy-mode link: /wizard/link-codes?sell_id=… picks the E number a sell
 * number belongs to, then calls CodeAssigner::linkSellToInternal (the same
 * call the Expert inline typeahead makes).
 */
final class LinkCodesWizard extends Wizard
{
    public function key(): string
    {
        return 'link-codes';
    }

    public function title(): string
    {
        return 'Link a sell number to an E number';
    }

    public function permissions(): array
    {
        return [Permissions::CODES_LINK];
    }

    public function initialState(Request $request): array
    {
        $id = (int) $request->int('sell_id', 0);
        $row = $id > 0 ? $this->app->db->one(
            'SELECT s.*, p.code AS prefix_code, p.min_digits, x.code AS suffix_code, i.number AS inum, i.version AS iver
             FROM chroma_sell_numbers s JOIN chroma_code_prefixes p ON p.id = s.prefix_id LEFT JOIN chroma_code_suffixes x ON x.id = s.suffix_id LEFT JOIN chroma_internal_codes i ON i.id = s.internal_code_id
             WHERE s.id = ?',
            [$id]
        ) : null;
        if ($row === null) {
            throw new NotFoundException('That sell number does not exist. Open the sell number you want to link and press "Link" there.');
        }
        return [
            'sell_id' => $id,
            'sell' => CodeFormat::sell($row['prefix_code'], (int) $row['number'], (int) $row['min_digits'], $row['suffix_code']),
            'description' => $row['description'],
            'current' => $row['inum'] ? CodeFormat::internal((int) $row['inum'], $row['iver']) : '',
        ];
    }

    public function steps(array $state): array
    {
        if (empty($state['sell'])) {
            throw new NotFoundException('Open the sell number you want to link and press "Link" there.');
        }
        return [
            new Step(
                key: 'target',
                title: 'Which E number does ' . $state['sell'] . ' belong to?',
                intro: ($state['current'] !== '' ? $state['sell'] . ' is currently linked to ' . $state['current'] . '. ' : '') . 'Search by E number, or by one of the product\'s other sell numbers: the link goes to that product\'s E number.',
                fields: [
                    ['name' => 'internal', 'type' => 'lookup', 'label' => 'E number', 'required' => true, 'required_message' => 'Search for the E number and pick it from the list.', 'lookup' => '/api/codes/search?q=', 'placeholder' => 'E number, sell number or description'],
                ],
                help: 'A sell number belongs to one E number; an E number can have many sell numbers (relabels, customer codes, versions).',
                nextLabel: 'Review',
            ),
        ];
    }

    public function validateStep(Step $step, array $input, array $state): array
    {
        $product = (new QuickAssign($this->app->db, new CodeAssigner($this->app->db, $this->app->audit)))->product(isset($input['internal']) ? (string) $input['internal'] : null);
        if ($product === null) {
            $this->fail(['internal' => 'Search for the E number and pick it from the list.']);
        }
        if ($product['internal_code_id'] === null) {
            $this->fail(['internal' => $product['label'] . ' has no E number of its own yet, so nothing can be linked to it. Pick an E number, or a sell number that already has one.']);
        }
        if ((int) $product['internal_code_id'] === (int) $this->app->db->value('SELECT internal_code_id FROM chroma_sell_numbers WHERE id = ?', [(int) $state['sell_id']])) {
            $this->fail(['internal' => $state['sell'] . ' is already linked to ' . $product['internal']['display'] . '. Pick a different E number, or cancel.']);
        }
        return [
            'internal' => $product['ref'],
            'internal_label' => $product['label'],
            'internal_id' => (int) $product['internal_code_id'],
            'internal_display' => $product['internal']['display'],
            'internal_description' => $product['internal']['description'],
        ];
    }

    public function review(array $state): array
    {
        return [
            ['label' => 'Sell number', 'value' => $state['sell'] . ' — ' . $state['description']],
            ['label' => 'Currently linked to', 'value' => (string) ($state['current'] ?: 'nothing')],
            ['label' => 'Link to', 'value' => ($state['internal_display'] ?? '') . ' — ' . ($state['internal_description'] ?? ''), 'step' => 'target'],
        ];
    }

    public function commit(array $state): WizardResult
    {
        $codes = new CodeAssigner($this->app->db, $this->app->audit);
        $codes->linkSellToInternal((int) $state['sell_id'], (int) $state['internal_id'], $this->app->auth->id());
        return new WizardResult(title: $state['sell'] . ' is now linked to ' . $state['internal_display'] . '.', redirect: '/codes/s/' . (int) $state['sell_id']);
    }

    public function expertUrl(array $state): ?string
    {
        return url('/mode', ['set' => 'expert', 'return' => $this->cancelUrl($state)]);
    }

    public function cancelUrl(array $state): string
    {
        return !empty($state['sell_id']) ? '/codes/s/' . (int) $state['sell_id'] : '/codes';
    }

    public function commitLabel(): string
    {
        return 'Link them';
    }

    public function commitExplanation(): string
    {
        return 'The change is recorded in the history of the sell number. You can move the link again later.';
    }
}
