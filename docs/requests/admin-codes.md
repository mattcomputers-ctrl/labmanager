# Requests to the lead — Module A

## 1. `WizardController::done()` clashes with `Controller::done()` (every wizard is a 500)

`src/Http/Controller.php` declares `protected function done(Request $request, string $redirectTo, array $payload = []): Response`, and `src/Http/Controllers/WizardController.php` declares `public function done(Request $request): Response`. PHP refuses to load `WizardController`:

```
PHP Fatal error: Declaration of Chroma\Http\Controllers\WizardController::done(Chroma\Core\Request $request): Chroma\Core\Response
must be compatible with Chroma\Http\Controller::done(Chroma\Core\Request $request, string $redirectTo, array $payload = []): Chroma\Core\Response
in src/Http/Controllers/WizardController.php on line 165
```

So `/wizard/<any-key>` returns 500 for every module, including `/wizard/add-prefix`. Both files are off-limits to module agents. Suggested fix (one line each): rename the wizard action to `finished()` in `WizardController` and in `routes/00-core.php` (`$router->get('/wizard/{key}/done', [WizardController::class, 'finished'])`), leaving the URL `/wizard/{key}/done` unchanged. Other modules' controllers already call `$this->done($request, …)`, so renaming the base method instead would break them.

The `AddPrefixWizard` logic (steps, validation, review, commit) is covered by `tests/PrefixAdminTest.php::testAddPrefixWizardWalksThroughAndCommitsViaPrefixAdmin` so it is ready as soon as the shell loads.
