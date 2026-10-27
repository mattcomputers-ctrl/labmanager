# Requests from module E1 (Admin: users & groups, lists, customers)

## 1. Every Easy-mode wizard is broken by a method-name clash (blocking, not mine to fix)

`src/Http/Controller.php` declares `done(Request $request, string $redirectTo, array $payload = []): Response`
and `src/Http/Controllers/WizardController.php` (line 165) declares `done(Request $request): Response`.
PHP refuses to load `WizardController` at all:

    PHP Fatal error: Declaration of Chroma\Http\Controllers\WizardController::done(Chroma\Core\Request $request): Chroma\Core\Response
    must be compatible with Chroma\Http\Controller::done(Chroma\Core\Request $request, string $redirectTo, array $payload = []): Chroma\Core\Response

So every `/wizard/*` URL returns 500 for every module (add-user, add-group, new-project, quick-assign …). Both files are
committed in HEAD and both are on the do-not-edit list.

Suggested fix (two lines): rename the wizard success screen action, e.g. in `WizardController.php`
`public function done(Request $request)` → `public function finished(Request $request)` and in `routes/00-core.php`
`$router->get('/wizard/{key}/done', [WizardController::class, 'done'])` → `[WizardController::class, 'finished']`.
I verified my two wizards (`/wizard/add-user`, `/wizard/add-group`) end to end against a scratch copy of the repo with
exactly that patch applied (`/tmp/e1/repo`, served on port 8086). Nothing else in my module depends on it.

Ready-to-apply patch (still not applied in the shared tree as of this review pass; both files remain off-limits to me):

```diff
--- a/src/Http/Controllers/WizardController.php
+++ b/src/Http/Controllers/WizardController.php
@@
-    public function done(Request $request): Response
+    public function finished(Request $request): Response
     {
         $w = $this->wizard($request);
--- a/routes/00-core.php
+++ b/routes/00-core.php
@@
-$router->get('/wizard/{key}/done', [WizardController::class, 'done']);
+$router->get('/wizard/{key}/done', [WizardController::class, 'finished']);
```

Check: `cd /home/user/labmanager && CHROMA_CONFIG=./config.env php -r 'require "vendor/autoload.php"; var_dump(class_exists("Chroma\\Http\\Controllers\\WizardController"));'`
prints `bool(true)` once applied (today it is a fatal error).

## 1b. Wizard success screens keep the result in the session until it expires (small, same file)

`WizardController::done()` reads `_wizard_result.{key}` with `session->get()`, so reloading `/wizard/add-user/done` shows the
temporary password again and the plain-text password sits in `chroma_sessions.data` for the rest of the admin's session. The
Expert path (`UsersController::pullPassword`) shows it once. Suggested one-word change in the same method, when you apply the
patch above: `$raw = $this->app->session->pull('_wizard_result.' . $w->key());` (reload then goes to the wizard's cancel URL,
which is what "shown only once" promises). Other wizards' done screens would behave the same way; their results (codes,
project numbers) are stored anyway. Nothing in my files can change this, since the `/wizard/*` routes are registered before mine.

## 2. `AdminController::areas()` links "Lists" and "Customers" — nothing needed, FYI

My routes match the paths already listed there (`/admin/lists`, `/admin/customers`, `/admin/users`), so the admin
home and `admin/_nav` work without changes. Groups live under `/admin/groups` and are reached from the Users page.
