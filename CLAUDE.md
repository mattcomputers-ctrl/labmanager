# Chroma — developer notes

Chroma is Precision Ink's color-lab project and code-management system. The full product spec is `SPEC.md`; decisions taken while building it are in `docs/DECISIONS.md`; open questions for Matt are in `docs/QUESTIONS.md`.

## Stack

- Ubuntu Server 24.04, nginx + PHP 8.3-FPM, MariaDB, Alpine.js (vendored in `public/assets/js`, no build step, no Node in production).
- Plain PHP 8.3 with a small router and PDO. No framework. Composer dependencies are kept minimal (`phpoffice/phpspreadsheet` for xls/xlsx, `chillerlan/php-qrcode`, `erusev/parsedown`; `phpunit` for dev).
- Config lives in `/etc/chroma/config.env` (never in the repo). Point `CHROMA_CONFIG` at another file for development: `CHROMA_CONFIG=./config.env` (relative paths resolve against the repo root). See `config.env.example`.
- Timezone: store UTC (`DATETIME` columns, `UTC_TIMESTAMP()`), display in `America/Chicago` via `fmt_datetime()`. Calendar dates (`date_submitted`, `date_assigned`) are plain `DATE` columns with no timezone shift.
- All tables are prefixed `chroma_` so the schema can share a database with other in-house systems later.

## Layout

```
public/            document root: index.php front controller, assets (css, js, fonts, img)
src/Core/          framework: App, Router, Request/Response, Db, Session, Auth, Csrf, Flash, View, Validator, Settings, Migrator, Permissions, helpers.php
src/Domain/        business rules only — NO HTTP, NO HTML. Codes/, Projects/, Import/, Users/, Audit/, Cms/ …
src/Http/          Controller base, Controllers/, Wizard/ (Easy-mode wizard framework)
routes/*.php       every file is loaded; one file per module (00-core, 10-codes, 20-projects, 30-admin …)
resources/views/   PHP templates, one folder per area; layouts/app.php and layouts/plain.php
resources/components/  reusable UI pieces rendered with component('name', [...]) — see /styleguide
resources/help/*.md    help pages written for the lab, served at /help/{slug}
db/migrations/     numbered .sql files, idempotent (IF NOT EXISTS), applied by bin/migrate
db/seeds/Seeder.php    reference data (groups, prefixes, suffixes, project types …), idempotent, run after migrations
bin/               CLI tools: migrate, seed, create-admin, import-legacy, stress-assign, backup, uninstall …
tests/             PHPUnit (needs the chroma_test database)
deploy/            nginx site, php-fpm pool, systemd units, sudoers, helper scripts used by install.sh
install.sh         installer AND updater (re-run = update)
```

## Running locally

```
composer install
cp config.env.example config.env   # edit DB_* ; create the chroma and chroma_test databases
CHROMA_CONFIG=./config.env php bin/migrate
CHROMA_CONFIG=./config.env php bin/create-admin admin --name="Your Name"
CHROMA_CONFIG=./config.env php -S 127.0.0.1:8080 -t public
```

## Migrations and tests

- `bin/migrate` applies pending `db/migrations/*.sql` then runs the seeder. Every statement must be re-runnable (`CREATE TABLE IF NOT EXISTS`, `ADD COLUMN IF NOT EXISTS`, `CREATE OR REPLACE VIEW`). Never edit an applied migration; add a new numbered file.
- Tests: `vendor/bin/phpunit`. They run against the database named by `TEST_DB_NAME` (default `chroma_test`) using the same host/user/password as the app config; `tests/bootstrap.php` migrates it and truncates data tables between tests. The updater runs the suite before switching to the new version, so keep it green and fast.
- `bin/stress-assign` hammers the assignment endpoint with parallel requests and checks for duplicate codes.

## Conventions that must hold

- **Permissions are checked server-side** on every route (`->permission('codes.assign_sell')` or `$this->auth->requirePermission()`); hiding a button is not a check. Keys live in `src/Core/Permissions.php` with plain-language labels.
- **Code assignment** goes through `src/Domain/Codes/CodeAssigner` only. It locks the counter row with `SELECT … FOR UPDATE` inside a transaction. Never insert into `chroma_internal_codes` / `chroma_sell_numbers` anywhere else.
- **Nothing that holds a code is hard-deleted.** Void with a reason. Log every assignment, void, counter change and admin change through `$app->audit->record(...)`.
- **Easy and Expert mode share one backend.** Each task is a domain operation (`src/Domain/...`). Easy mode wraps it in a `Wizard` subclass (`src/Http/Wizard/Wizard.php`, registered in a routes file with `WizardRegistry::register`); Expert mode is a one-page form posting to a controller that calls the same operation. Validation and permission checks live in the operation, not in the shells. Use `is_easy()` in views to pick the shell, never to change behaviour.
- **Plain language in the UI.** No "record", "entity", "null", "FK". Error messages say what happened and what to do next. Every list has an empty state that explains the page and offers the first action.
- **Components, not ad-hoc markup.** Buttons, fields, badges, code pills, callouts, confirm dialogs, pagination and the wizard shell are in `resources/components/`; `/styleguide` shows them all. One primary action per screen, 44px targets, verbs on buttons, red + confirmation + plain-words consequence for destructive actions.
- **Search** uses normalized columns (`sell_number_normalized`, E number int) with prefix matches, plus FULLTEXT on descriptions. No `LIKE '%…%'` over big tables.
- **Exports**: every list offers CSV and XLSX via `src/Domain/Export/Exporter`.
- Keep `docs/DECISIONS.md` current when you choose something the spec left open.
