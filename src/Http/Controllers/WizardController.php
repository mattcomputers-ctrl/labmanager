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
        return $this->redirect('/wizard/' . $w->key() . '/step/1');
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
        if ($request->input('_back') !== null) {
            // Going back keeps whatever was typed, without validating it.
            foreach ($step->fields as $f) {
                if (array_key_exists($f['name'], $request->body)) {
                    $state[$f['name']] = is_string($request->body[$f['name']]) ? trim($request->body[$f['name']]) : $request->body[$f['name']];
                }
            }
            $this->save($w, $state);
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
        foreach ($steps as $i => $step) {
            try {
                $w->validateStep($step, $state, $state);
            } catch (ValidationException) {
                $this->app->flash->info('A few answers are still missing.');
                return $this->redirect('/wizard/' . $w->key() . '/step/' . ($i + 1));
            }
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

    public function done(Request $request): Response
    {
        $w = $this->wizard($request);
        $raw = $this->app->session->get('_wizard_result.' . $w->key());
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
