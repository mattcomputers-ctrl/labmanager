<?php
declare(strict_types=1);

namespace Chroma\Http\Wizard;

use Chroma\Core\NotFoundException;
use Chroma\Core\Permissions;
use Chroma\Core\Request;
use Chroma\Domain\Codes\CodeAssigner;
use Chroma\Domain\Codes\CodeFormat;

/**
 * Easy-mode void: /wizard/void-code?type=sell|internal&id=… Asks for the
 * reason, shows the consequence, then calls CodeAssigner::voidSell/voidInternal
 * (the same calls the Expert confirm dialog makes).
 */
final class VoidCodeWizard extends Wizard
{
    public function key(): string
    {
        return 'void-code';
    }

    public function title(): string
    {
        return 'Void a code';
    }

    public function permissions(): array
    {
        return [Permissions::CODES_VOID];
    }

    public function initialState(Request $request): array
    {
        $type = $request->str('type') === 'internal' ? 'internal' : 'sell';
        $id = $request->int('id', 0);
        $state = ['type' => $type, 'id' => $id];
        $row = $this->load($type, (int) $id);
        if ($row === null) {
            throw new NotFoundException('That code does not exist, so there is nothing to void.');
        }
        $state['code'] = $row['display'];
        $state['description'] = $row['description'];
        $state['already_void'] = (int) $row['voided'] === 1;
        return $state;
    }

    /** @return array<string,mixed>|null */
    private function load(string $type, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        if ($type === 'internal') {
            $row = $this->app->db->one('SELECT * FROM chroma_internal_codes WHERE id = ?', [$id]);
            if ($row) {
                $row['display'] = CodeFormat::internal((int) $row['number'], $row['version']);
            }
            return $row;
        }
        $row = $this->app->db->one('SELECT s.*, p.code AS prefix_code, p.min_digits, x.code AS suffix_code FROM chroma_sell_numbers s JOIN chroma_code_prefixes p ON p.id = s.prefix_id LEFT JOIN chroma_code_suffixes x ON x.id = s.suffix_id WHERE s.id = ?', [$id]);
        if ($row) {
            $row['display'] = CodeFormat::sell($row['prefix_code'], (int) $row['number'], (int) $row['min_digits'], $row['suffix_code']);
        }
        return $row;
    }

    public function steps(array $state): array
    {
        if (empty($state['code'])) {
            throw new NotFoundException('Open the code you want to void and press "Void" there.');
        }
        $what = ($state['type'] ?? 'sell') === 'internal' ? 'E number' : 'sell number';
        if (!empty($state['already_void'])) {
            // Reachable from history or a bookmark: say so up front instead of
            // asking for a reason and refusing at the end.
            return [
                new Step(
                    key: 'already',
                    title: $state['code'] . ' is already voided',
                    intro: 'This ' . $what . ' was voided earlier. It stays in the logs, greyed out, and the number will not be given out again.',
                    help: 'Nothing more needs doing. Open the code to see who voided it, when, and why.',
                    template: 'codes/wizard-already-void',
                ),
            ];
        }
        return [
            new Step(
                key: 'reason',
                title: 'Why is ' . $state['code'] . ' being voided?',
                intro: 'Voiding marks the ' . $what . ' as a mistake. It stays in the logs, greyed out, so anyone looking it up sees what happened. The number is never given out again.',
                fields: [
                    ['name' => 'reason', 'type' => 'textarea', 'label' => 'Reason', 'required' => true, 'required_message' => 'Say why this code is being voided. The reason is kept in the log.', 'maxlength' => 500, 'rows' => 3, 'placeholder' => 'For example: assigned twice by mistake, wrong prefix, customer cancelled'],
                ],
                help: $state['description'] !== '' ? $state['code'] . ' is "' . $state['description'] . '".' : null,
                nextLabel: 'Review',
            ),
        ];
    }

    public function validateStep(Step $step, array $input, array $state): array
    {
        if ($step->key === 'already') {
            $this->fail(['code' => $state['code'] . ' is already voided; there is nothing to do.']);
        }
        return parent::validateStep($step, $input, $state);
    }

    public function review(array $state): array
    {
        return [
            ['label' => 'Code', 'value' => (string) $state['code']],
            ['label' => 'Description', 'value' => (string) ($state['description'] ?? '')],
            ['label' => 'Reason', 'value' => (string) ($state['reason'] ?? ''), 'step' => 'reason'],
        ];
    }

    public function commit(array $state): WizardResult
    {
        $codes = new CodeAssigner($this->app->db, $this->app->audit);
        $id = (int) ($state['id'] ?? 0);
        if (($state['type'] ?? 'sell') === 'internal') {
            $codes->voidInternal($id, (string) ($state['reason'] ?? ''), $this->app->auth->id());
            $url = '/codes/e/' . $id;
        } else {
            $codes->voidSell($id, (string) ($state['reason'] ?? ''), $this->app->auth->id());
            $url = '/codes/s/' . $id;
        }
        return new WizardResult(title: $state['code'] . ' was voided.', message: 'It stays in the logs, greyed out, and the number will not be reused.', redirect: $url);
    }

    public function expertUrl(array $state): ?string
    {
        return url('/mode', ['set' => 'expert', 'return' => $this->cancelUrl($state)]);
    }

    public function cancelUrl(array $state): string
    {
        $id = (int) ($state['id'] ?? 0);
        if ($id <= 0) {
            return '/codes';
        }
        return (($state['type'] ?? 'sell') === 'internal' ? '/codes/e/' : '/codes/s/') . $id;
    }

    public function reviewTitle(): string
    {
        return 'Void this code?';
    }

    public function commitLabel(): string
    {
        return 'Yes, void it';
    }

    public function commitExplanation(): string
    {
        return 'This cannot be undone. The code stays visible, marked as voided, and the number is never reused.';
    }
}
