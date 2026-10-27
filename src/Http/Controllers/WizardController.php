<?php
declare(strict_types=1);

namespace Chroma\Http\Controllers;

use Chroma\Core\ForbiddenException;
use Chroma\Core\Request;
use Chroma\Core\Response;
use Chroma\Core\ValidationException;
use Chroma\Http\Controller;
use Chroma\Http\Wizard\Wizard;
use Chroma\Http\Wizard\WizardRegistry;

/**
 * Drives every Easy-mode wizard: /wizard/{key}, /wizard/{key}/step/{n},
 * /wizard/{key}/review, /wizard/{key}/commit, /wizard/{key}/done.
 * State lives in the session under _wizard.{key}.
 */
final class WizardController extends Controller
{
    private function wizard(Request $request): Wizard
    {
        $w = WizardRegistry::make($this->app, (string) $request->params['key']);
        if ($w === null) {
            $this->notFound('That wizard does not exist.');
        }
        foreach ($w->permissions() as $p) {
            if (!$this->auth->can($p)) {
                throw new ForbiddenException();
            }
        }
        return $w;
    }

    /** @return array<string,mixed> */
    private function state(Wizard $w): array
    {
        $s = $this->app->session->get('_wizard.' . $w->key(), []);
        return is_array($s) ? $s : [];
    }

    /** @param array<string,mixed> $state */
    private function save(Wizard $w, array $state): void
    {
        $this->app->session->set('_wizard.' . $w->key(), $state);
    }

    public function start(Request $request): Response
    {
        $w = $this->wizard($request);
        $state = $w->initialState($request);
        if ($request->bool('keep')) {
            $state = $this->state($w) + $state;
        }
        $this->save($w, $state);
        if ($request->bool('jump')) {
            // Coming from the Expert page with its values: skip what is already answered and land on the first open question
            // (or the review when everything is there) instead of starting over at step 1.
            $n = $this->firstIncomplete($w, $state);
            return $this->redirect('/wizard/' . $w->key() . '/' . ($n === null ? 'review' : 'step/' . $n));
        }
        return $this->redirect('/wizard/' . $w->key() . '/step/1');
    }

    /** 1-based number of the first step whose answers are missing or invalid, or null when every step is complete. @param array<string,mixed> $state */
    private function firstIncomplete(Wizard $w, array $state): ?int
    {
        try {
            foreach ($w->steps($state) as $i => $step) {
                try {
                    $w->validateStep($step, $state, $state);
                } catch (ValidationException) {
                    return $i + 1;
                }
            }
        } catch (\Throwable) {
            return 1;
        }
        return null;
    }

    public function step(Request $request): Response
    {
        $w = $this->wizard($request);
        $state = $this->state($w);
        $steps = $w->steps($state);
        $n = max(1, (int) $request->params['n']);
        if ($n > count($steps)) {
            return $this->redirect('/wizard/' . $w->key() . '/review');
        }
        $step = $steps[$n - 1];
        return $this->view($step->template ?? 'wizard/step', [
            'title' => $w->title(),
            'wizard' => $w,
            'step' => $step,
            'n' => $n,
            'total' => count($steps) + 1,
            // False while the next answer decides how many steps there are: the shell then shows no total and never says "Review".
            'known' => $w->stepCountKnown($state),
            'state' => $state,
            'expertUrl' => $w->expertUrl($state) ?? url('/mode', ['set' => 'expert', 'return' => $w->cancelUrl($state)]),
            'easyUrl' => $request->fullUrl(),
        ]);
    }

    public function submitStep(Request $request): Response
    {
        $w = $this->wizard($request);
        $state = $this->state($w);
        $steps = $w->steps($state);
        $n = max(1, (int) $request->params['n']);
        if ($n > count($steps)) {
            return $this->redirect('/wizard/' . $w->key() . '/review');
        }
        $step = $steps[$n - 1];
        if ($request->input('_back') !== null || $request->input('_mode') === 'expert') {
            // Going back (or switching to Expert mid-step) keeps whatever was typed, without validating it.
            foreach ($step->fields as $f) {
                foreach ([$f['name'], $f['name'] . '_text', $f['name'] . '_label'] as $k) {
                    if (array_key_exists($k, $request->body)) {
                        $state[$k] = is_string($request->body[$k]) ? trim($request->body[$k]) : $request->body[$k];
                    }
                }
            }
            $this->save($w, $state);
            if ($request->input('_mode') === 'expert') {
                // The Expert page for the same task, carrying every answer so far including this half-filled step.
                return $this->redirect($w->expertUrl($state) ?? url('/mode', ['set' => 'expert', 'return' => $w->cancelUrl($state)]));
            }
            return $this->redirect('/wizard/' . $w->key() . '/step/' . max(1, $n - 1));
        }
        try {
            $clean = $w->validateStep($step, $request->body, $state);
        } catch (ValidationException $e) {
            $this->app->flash->withInput($request->body, $e->errors);
            return $this->redirect('/wizard/' . $w->key() . '/step/' . $n);
        }
        $state = array_merge($state, $clean);
        $this->save($w, $state);
        $steps = $w->steps($state); // steps may change after this answer
        $target = $request->input('_jump') === 'review' && $n >= count($steps) ? 'review' : ($n >= count($steps) ? 'review' : 'step/' . ($n + 1));
        if ($request->input('_jump') === 'review') {
            // Jumping back to review from an edited step: only if later steps are still valid.
            $target = 'review';
        }
        return $this->redirect('/wizard/' . $w->key() . '/' . $target);
    }

    public function review(Request $request): Response
    {
        $w = $this->wizard($request);
        $state = $this->state($w);
        $steps = $w->steps($state);
        // Make sure every step has been answered; if not, send the user there.
        $missing = $this->firstIncomplete($w, $state);
        if ($missing !== null) {
            $this->app->flash->info('A few answers are still missing.');
            return $this->redirect('/wizard/' . $w->key() . '/step/' . $missing);
        }
        return $this->view('wizard/review', [
            'title' => $w->title(),
            'wizard' => $w,
            'rows' => $w->review($state),
            'n' => count($steps) + 1,
            'total' => count($steps) + 1,
            'state' => $state,
            'steps' => $steps,
            'expertUrl' => $w->expertUrl($state) ?? url('/mode', ['set' => 'expert', 'return' => $w->cancelUrl($state)]),
            'easyUrl' => $request->fullUrl(),
        ]);
    }

    public function commit(Request $request): Response
    {
        $w = $this->wizard($request);
        $state = $this->state($w);
        try {
            $result = $w->commit($state);
        } catch (ValidationException $e) {
            $this->app->flash->withInput([], $e->errors);
            $this->app->flash->error($e->getMessage());
            return $this->redirect('/wizard/' . $w->key() . '/review');
        }
        $this->app->session->forget('_wizard.' . $w->key());
        if ($result->redirect !== null) {
            $this->success($result->title, $result->message ?: null);
            return $this->redirect($result->redirect);
        }
        $this->app->session->set('_wizard_result.' . $w->key(), serialize($result));
        return $this->redirect('/wizard/' . $w->key() . '/done');
    }

    public function finished(Request $request): Response
    {
        $w = $this->wizard($request);
        $raw = $this->app->session->pull('_wizard_result.' . $w->key());
        $result = is_string($raw) ? unserialize($raw, ['allowed_classes' => [\Chroma\Http\Wizard\WizardResult::class]]) : null;
        if (!$result instanceof \Chroma\Http\Wizard\WizardResult) {
            return $this->redirect($w->cancelUrl([]));
        }
        return $this->view('wizard/done', ['title' => $w->title(), 'wizard' => $w, 'result' => $result]);
    }

    public function cancel(Request $request): Response
    {
        $w = $this->wizard($request);
        $state = $this->state($w);
        $this->app->session->forget('_wizard.' . $w->key());
        return $this->redirect($w->cancelUrl($state));
    }
}
