<?php
declare(strict_types=1);

namespace Chroma\Http\Wizard;

use Chroma\Core\App;
use Chroma\Core\Request;
use Chroma\Core\ValidationException;
use Chroma\Core\Validator;

/**
 * Base class for Easy-mode wizards.
 *
 * A wizard is a thin shell over a domain operation: it collects the same
 * fields the Expert-mode page collects, one question per screen, shows a
 * review screen, then calls the SAME domain code in commit(). Validation
 * belongs in the domain layer; wizards only do per-step "is this filled
 * in and well-formed" checks so the user gets feedback early.
 */
abstract class Wizard
{
    public function __construct(protected App $app)
    {
    }

    abstract public function key(): string;

    abstract public function title(): string;

    /** Permissions required to run this wizard. @return list<string> */
    public function permissions(): array
    {
        return [];
    }

    /** The help page (resources/help/{slug}.md) behind the "?" in the wizard progress bar. */
    public function helpSlug(): string
    {
        return match ($this->key()) {
            'quick-assign' => 'quick-assign',
            'new-project', 'add-item', 'assign-item-code', 'batch-assign', 'link-codes' => 'projects',
            'manual-entry', 'void-code' => 'codes',
            'add-user', 'add-group' => 'admin-users',
            'add-prefix' => 'admin-prefixes',
            default => 'index',
        };
    }

    /** Initial state from query params (e.g. ?project_id=12). @return array<string,mixed> */
    public function initialState(Request $request): array
    {
        return [];
    }

    /**
     * The steps, given what has been entered so far. Steps may appear or
     * disappear depending on earlier answers (e.g. the suffix step only
     * exists when the chosen prefix allows suffixes).
     * @param array<string,mixed> $state
     * @return list<Step>
     */
    abstract public function steps(array $state): array;

    /**
     * False while the next answer still decides how many steps there are (the project type picks the
     * questions that follow; "which code?" picks whether a prefix is asked). The shell then shows
     * "Step 1" without a total and keeps the button at "Next" instead of promising the review screen.
     * Steps that merely slot in between known ones (a suffix question) do not make the count unknown.
     * @param array<string,mixed> $state
     */
    public function stepCountKnown(array $state): bool
    {
        return true;
    }

    /**
     * Validate a step's input and return cleaned values to merge into state.
     * Default: required checks from the field specs.
     * @param array<string,mixed> $input
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    public function validateStep(Step $step, array $input, array $state): array
    {
        $v = new Validator($input);
        $out = [];
        foreach ($step->fields as $f) {
            $name = $f['name'];
            if (!empty($f['required'])) {
                $v->required($name, $f['required_message'] ?? 'Please fill this in before going on.');
            }
            if (($f['type'] ?? 'text') === 'number') {
                $v->integer($name, isset($f['min']) ? (int) $f['min'] : null, isset($f['max']) ? (int) $f['max'] : null);
            }
            if (($f['type'] ?? 'text') === 'date') {
                $v->date($name);
            }
            if (isset($f['maxlength'])) {
                $v->maxLength($name, (int) $f['maxlength']);
            }
            $val = $input[$name] ?? null;
            $out[$name] = is_string($val) ? trim($val) : $val;
            if (($f['type'] ?? '') === 'lookup' && isset($input[$name . '_text'])) {
                $out[$name . '_text'] = trim((string) $input[$name . '_text']);
            }
            if (($f['type'] ?? '') === 'lookup' && isset($input[$name . '_label'])) {
                $out[$name . '_label'] = trim((string) $input[$name . '_label']);
            }
        }
        $v->fail();
        return $out;
    }

    /**
     * Rows for the review screen.
     * @param array<string,mixed> $state
     * @return list<array{label:string,value:string,step?:string,html?:bool}>
     */
    abstract public function review(array $state): array;

    /**
     * Do the work (calls the domain operation). Throw ValidationException
     * for rule failures; the controller shows them on the review screen.
     * @param array<string,mixed> $state
     */
    abstract public function commit(array $state): WizardResult;

    /** Expert-mode page for the same task, carrying what has been entered. @param array<string,mixed> $state */
    public function expertUrl(array $state): ?string
    {
        return null;
    }

    /** @param array<string,mixed> $state */
    public function cancelUrl(array $state): string
    {
        return '/';
    }

    public function reviewTitle(): string
    {
        return 'Check and confirm';
    }

    public function commitLabel(): string
    {
        return 'Confirm';
    }

    /** Explain, in one sentence, what pressing the commit button will do. */
    public function commitExplanation(): string
    {
        return '';
    }

    /** Helper for subclasses. @param array<string,mixed> $errors */
    protected function fail(array $errors): never
    {
        throw new ValidationException($errors);
    }
}
