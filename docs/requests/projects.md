# Requests from the Projects module (C)

## 1. `Controller::done()` clashes with `WizardController::done()` — every wizard is a fatal error (blocking)

`src/Http/Controller.php` declares `protected function done(Request $request, string $redirectTo, array $payload = []): Response`
and `src/Http/Controllers/WizardController.php` declares `public function done(Request $request): Response`.
PHP refuses to load `WizardController` ("Declaration ... must be compatible"), so every `/wizard/*` URL is a 500 for all modules.

Suggested fix (one of):
- rename the base helper to `protected function finish(Request $request, string $redirectTo, array $payload = []): Response` in `src/Http/Controller.php`, or
- rename `WizardController::done()` to `finished()` and update the route in `routes/00-core.php` (`/wizard/{key}/done`) and the redirect in `WizardController::commit()`.

The Projects module does **not** call `Controller::done()` (it has its own `ProjectController::finish()`), so either rename is safe for module C.
During development the wizards were tested with a private, test-only shim loaded from a custom `php -S` router script; nothing in the repo depends on it.

## 2. `LookupApiController::upperBound()` (not mine, FYI)

`storage/logs/app.log` shows `Return value must be of type int, float returned` from `/api/codes/search` (line 93) for some inputs — probably a very long digit string typed into the code picker (the concatenation overflows to float). Suggest clamping: `if ($len >= 6) return $n + 1;` already exists, so the overflow comes from `$n` itself being huge; `CodeFormat::parseInternal` could cap at 9 digits. The project item picker and the quick "existing product" lookup both use this endpoint.

## Status (review round 2)

Request 1 (`WizardController::done()` clash) is resolved in the tree: the method is now `finished()` and `routes/00-core.php` points `/wizard/{key}/done` at it. Every `/wizard/*` URL of this module was re-verified without any shim (200 on start, all four wizards). The test-only router script in `/tmp/projects/router.php` is no longer used.
