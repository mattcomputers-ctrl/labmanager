# Build prompt: Chroma — Precision Ink lab project & code management system

> Paste this whole file into Claude Code as the opening prompt for the empty repo at
> https://github.com/mattcomputers-ctrl/labmanager.git. It can also live in the repo as `SPEC.md`.
> The product is named **Chroma**. The repo name stays `labmanager`.

---

You are building **Chroma**, a self-hosted web application for Precision Ink Corporation's color lab. It replaces three spreadsheets (an internal-code log, a sell-number cross-reference, and a security-ink code log) and a project-tracking sheet with one multi-user system that:

1. Tracks lab projects — color matches, customer issues, customer evaluations and internal R&D — where **one project can have many colors or items**.
2. **Assigns codes** — sequential internal codes (E#) and prefixed sequential sell numbers — safely, with no duplicates, even with several people working at once.
3. Keeps the full **cross-reference** between internal codes, sell numbers, customers and descriptions, searchable by anyone in the company.
4. Installs on a fresh Ubuntu Server with one script, and updates itself by re-running that script.

Work through this spec in order. Where it says "ask Matt", stop and ask before building that part. Where it says "decide", make a sensible choice and note it in `docs/DECISIONS.md`. Commit in small, working increments.

## 1. Stack and conventions

- **Ubuntu Server 24.04 LTS**, nginx + PHP 8.3-FPM, **MariaDB** (MySQL-compatible), Alpine.js on the front end. This matches Precision Ink's other in-house systems (Insights, the SDS system, the ERP). Plain PHP with a small router and PDO; no heavy framework. Composer for dependencies, kept minimal.
- Server-rendered pages with Alpine.js for interactivity. No build step for the front end (no Node in production). Vendor Alpine locally; the server has no guaranteed internet access from the browser side.
- The app lives in `/opt/chroma`, runs as its own system user, and is served on port 80 (HTTPS later via admin setting, not required for v1).
- Config in `/etc/chroma/config.env` (DB credentials, app key, CMS connection), never in the repo. The installer creates it and the updater never overwrites it.
- Schema managed by numbered SQL migrations in `db/migrations/`, applied by `bin/migrate`. Every migration is idempotent and re-runnable.
- Timezone America/Chicago for display; store UTC.
- Tests: PHPUnit for the code-assignment logic (this is the part that must be correct). Run them in the updater before switching to the new version.
- Keep a `CLAUDE.md` in the repo describing the layout, how to run migrations and tests, and the conventions above.

## 2. How Precision Ink's codes work (from the current spreadsheets)

Read this carefully; the import and the assignment rules depend on it.

### 2.1 Internal codes (E numbers)

- Format `E` + integer, one global sequence: `E1010` … `E9101`. Last used: **E9101 on 2026-10-26**, so the system's first new E# is **E9102** (admin-settable, see §5).
- An E# identifies a **formula**. A formula version is written as a suffix: `E6374-10`, `E7564-23`, `E6731-50`. Store the base E# and the version separately; display them joined.
- One E# can carry **more than one sell number** (relabels, a new sell number for a formaldehyde-free version of the same formula, "also coded as R2156", etc.). A sell number can exist with no E# yet (code reserved, formula pending).

### 2.2 Sell numbers (external codes)

- Format: **PREFIX + number + optional suffix**. Examples: `Y1714UV`, `B2970`, `T2446UV`, `BN1439`, `X104043`, `UV25672`.
- **Each prefix has its own sequence.** Suffixes do **not** start a new sequence: `T2445`, then `T2446UV`, then `T2447UV`, then `T2448` all come from the T counter.
- Historical rows use a hyphen (`Y-1000`, `BK-1048`) and some are lowercase (`b2978uv`). Current practice is no hyphen, uppercase. **Normalize on input and on import**: uppercase, strip hyphens, store a `sell_number_normalized` column with a unique index, and keep the original text in a `legacy_text` column for reference.
- Known suffixes: `UV` (energy-curable version, by far the most common), `ND`, `F`, `HS`. Suffixes are admin-managed (§5), and **each prefix carries its own list of allowed suffixes** — many prefixes have none (e.g. `X`, `UV`, `AQ`), the color prefixes typically allow `UV`. Seed the allowed list from what the import actually finds per prefix, and let the admin edit it.
- Standard color prefixes (per Matt): `Y` yellow, `R` red, `B` blue, `BK` black, `G` green, `P` purple/violet, `BN` brown, `OR` orange, `W` white, `MS` miscellaneous (varnishes etc.).
- Security-ink products get **`X`** codes, six digits, currently in the `104xxx` block: last used `X104043`. Earlier blocks (`X102xxx`, `X103xxx`) exist in history.
- **`UV` prefix** (`UV25500`-style, last used `UV25672`) is a legacy system inherited from another company. Rarely used, but it must stay assignable.
- **`T`** = tint (1,200+ codes, e.g. `T2449 Blister Pantone 663 Purple`). Active.
- **`AQ`** = aqueous coatings. Active. **`WBC`** = older prefix for aqueous coatings, rarely used now: active but sorted to the bottom and labelled "(legacy)".
- **`UVXL`, `UVVL`, `UVNG`** = prefixes inherited from another company. Active, labelled "(inherited)". Note the cross-reference spells one of them `UVV` on many rows — import `UVV` rows under `UVVL` and record the original text in `legacy_text`; confirm with Matt in the dry-run report.
- Other small prefixes in the history (`A`, `UVC`, `UVD`, `UVR`, `UVMS`, `UVMQ`, `UVXLCG`, `CS`, `EG`, `HBK`, `UK`, `UC`, `CTA`, `AV`) and a few rows that are not codes at all (`38-5000`, `PURCHASED ONLY`): import what parses as **retired** prefixes (visible in lookup, not assignable), send the rest to `import_issues`.

Last used number per prefix as of 2026-10-26, for reference while building and testing (the real values come from the import at cutover; the sheets are still in use):

| Prefix | Last used | Prefix | Last used |
|---|---|---|---|
| Y | 1714 | BN | 1439 |
| R | 2323 | OR | 1453 |
| B | 2970 | W | 1098 |
| BK | 1376 | MS | 1461 |
| G | 2101 | T | 2449 |
| P | 1555 | X | 104043 |
| UV | 25672 | AQ | 5254 |
| WBC | 1030 | UVXL | 428 |
| UVVL (as `UVV`) | 185 | UVNG | 72 |

### 2.3 The three source spreadsheets (for import)

1. **Code Cross Reference** (`.xls`, ~8,100 rows): `Internal Code, Sell Number, Date, Original Customer, Description, Resale, Vendor Name, Vendor Code, Handschy Number`. One row per E#, pre-filled with empty E# rows up to E11009 (skip rows with no sell number and no description). `Resale` is a yes/no flag for purchased-for-resale items; vendor fields go with it. `Handschy Number` is a legacy predecessor-company code. ~48 sell numbers appear twice (typos or genuine relabels) — the importer must report these, not crash, and must not create duplicate normalized sell numbers (keep the first, log the rest to an import-issues table for Matt to resolve in the UI).
2. **Security Product Code Log** (`.xlsx`): `Code (X…), Description, Internal Code, Comments`, pre-filled with blank codes to X104500. Several X codes have no E# yet. Comments hold facts worth keeping (e.g. "Same E#, new formula version", "Also coded as R2156") — import as notes on the sell number.
3. **UV Product Code Log** (`.xlsx`): same layout, `UV25500`–`UV25900`, ~170 used. Some "internal codes" here are legacy non-E codes (`2UY0480`, `3UC4779`) — store those in a `legacy_internal_code` text field, not as E# rows.

**The spreadsheets stay in daily use until the day of cutover**, so the import is done by Matt, from the admin UI, when he's ready — not during install, and not from a snapshot checked into the repo.

Build **Admin → Import legacy codes** as a wizard:
1. Upload the three files (accept `.xls` and `.xlsx`; identify each by its header row, not by file name).
2. **Dry run**: show counts of what would be created, updated and skipped; list duplicates, unparseable codes, unknown prefixes and the `UVV`→`UVVL` mapping for confirmation. Nothing is written.
3. Review, then **Commit**. Then show a summary and any rows sent to `import_issues`.

Rules: the import is **idempotent and re-runnable**. Matching is on the normalized code (E# number+version, or normalized sell number): existing rows are updated only in fields that are empty in Chroma, never overwritten; new rows are added; nothing is deleted. So Matt can do a trial import on a test install, keep using the spreadsheets, and run the final import at cutover (or a second catch-up import a day later) without harm. After a commit, set each prefix's `next_number` and the E# sequence to max-in-use + 1 **unless the admin has already set something higher**. The numbers in §2.2 are reference values only — the import is the source of truth. Keep `bin/import-legacy` as a CLI wrapper around the same code for testing.

## 3. Data model

Use these names (adjust columns as needed; keep the relationships):

- `customers` — id, name, cms_customer_id (nullable), active. Synced from the Mar-Kov CMS (§8) with manual additions allowed; imported "Original Customer" strings get matched or created.
- `projects` — project_number (sequential, admin-set start, default 101), customer_id, title/description, date_submitted, date_completed, assigned_user_id, status (open / completed / cancelled), notes, created_by, timestamps.
- `project_colors` (the general *project items* table — see §11) — project_id, sequence within project, color description, ink_series_id, substrate (free text + optional list), proofs_generated (bool), proof_count, coating_id, is_digital_match (bool — when true the formula card must say "Digital Match" and the iQC Delta E test is 0–1), internal_code_id (nullable), sell_number_id (nullable), notes, status.
- `internal_codes` — number (int, unique), version (nullable string), description, resale (bool), vendor_name, vendor_code, legacy_handschy_number, legacy_internal_code, date_assigned, assigned_by, original_customer_id, voided (bool + reason), notes.
- `sell_numbers` — prefix_id, number (int), suffix_id (nullable), sell_number_normalized (unique), legacy_text, description, internal_code_id (nullable), original_customer_id, date_assigned, assigned_by, voided, notes. Unique on (prefix_id, number, suffix_id).
- `code_prefixes` — code (`Y`, `BK`, `X` …), label (`Yellow`), description (one line: what it's used for), next_number, min_digits (e.g. 4 for color codes, 6 for X), retired (bool), retired_at/by, sort_order.
- `code_suffixes` — code (`UV`, `ND`…), label, active.
- `code_prefix_suffixes` — prefix_id, suffix_id: **which suffixes a given prefix allows**. A prefix with no rows here has no suffix option at all (the suffix field is hidden for it). The suffix dropdown on Assign shows only the suffixes allowed for the chosen prefix, and **always includes "(none)" as the default**: allowing a suffix never requires it — `Y1715` and `Y1715UV` are both valid for a prefix that allows `UV`, and they draw from the same counter.
- `sequences` — name (`internal_code`, `project_number`), next_value. Prefix sequences live on `code_prefixes.next_number`.
- `ink_series`, `coatings`, `substrates` — simple admin lists with active flag.
- `users`, `groups`, `group_permissions`, `user_groups`, `sessions`.
- `audit_log` — who, when, what table/row, before/after JSON. Every code assignment, void, edit of a counter, and admin change is logged. Nothing that holds a code is ever hard-deleted.
- `import_issues` — rows the legacy importer could not place cleanly.
- `settings` — key/value for app settings (company name, CMS connection on/off, network settings mirror, etc.).

## 4. Code assignment rules (the part that must be right)

- Assigning any code happens inside a **database transaction with `SELECT … FOR UPDATE`** on the counter row. Two users clicking "Assign" at the same instant must get different numbers. Write a PHPUnit test that proves it with concurrent-style sequential calls, and a `bin/stress-assign` script that hammers the endpoint with parallel curl requests and verifies no duplicates.
- Numbers are never reused and never reassigned. A mistaken code is **voided** with a reason; it stays visible, greyed, in the logs.
- The next number for a prefix is `MAX(number)+1` for that prefix **or** the admin-set `next_number`, whichever is higher. The admin can raise `next_number` (to skip a block) but can never set it to a number already used — validate and refuse.
- Same rule for E#: admin sets the starting E#; it can only move forward past the highest existing one.
- Manually entering a specific code (needed for legacy items and odd cases) is a separate permission (`codes.manual_entry`). It checks uniqueness and logs who did it.
- Creating a sell number on a project color: user picks the **prefix** (dropdown of active prefixes, defaulting from the color's last-used prefix or none), then an optional **suffix** — the field appears only if that prefix allows any, listing only those —, enters description (defaults from the color description), and clicks Assign. The system shows the number it reserved. Same flow for E#. A color can get its E# and its sell number at different times.
- Codes can also be assigned **outside a project** (a quick "Assign code" page) for items that never go through the lab, e.g. resale items and relabels. Those rows still land in the cross-reference.
- Link rules: a sell number may point to one E#; an E# may have many sell numbers. Linking an existing sell number to an existing E# is allowed with permission `codes.link`.
- Display rules: E# shown as `E9102` or `E6374-10`; sell numbers shown as `PREFIX` + zero-padded number to `min_digits` + suffix, no hyphen. Search must accept any historical spelling (`y-1000`, `Y1000`, `Y 1000`).

## 5. Design and usability — this matters as much as correctness

Many users are not comfortable on computers. Chroma has to be **beautiful and obvious**. Treat these as requirements, not polish:

- **Two modes, one toggle.** A persistent **Easy / Expert** switch sits in the top bar of every page, and the choice is remembered per user (and in the browser for the lab PC).
  - **Easy mode** (the default for every new user): anything with more than one decision — new project, add a color, assign codes, void a code, link codes, and the admin tasks (add a prefix, add a user, import) — runs as a **wizard**: one question per screen, big Next/Back buttons, a progress indicator ("Step 2 of 4"), a review screen before anything is committed, and a clear success screen that shows the result (e.g. the code just assigned, large, with a Copy button) and the obvious next action. Lists show fewer columns and larger rows.
  - **Expert mode**: the same task on **one page with every field visible**, keyboard-friendly, defaults pre-filled, no confirmation screens except for destructive actions. Project page lets you add several colors and assign codes inline without leaving the page. Lists show all columns, denser rows, column chooser, bulk actions.
  - Both modes call the **same backend** and apply the same validation and permissions; the mode only changes the presentation. Build each task as one server-side operation and two front-end shells over it, so they can never drift apart. Flipping the toggle mid-task keeps what's been entered where that's possible.
- **One primary action per screen**, large (44px+ targets), labelled with a verb ("Assign sell number", not "Submit"). Secondary actions are visibly secondary. Destructive actions are red, ask for confirmation, and explain the consequence in plain words.
- **Plain-language everywhere.** No jargon from the database or the code (no "entity", "record", "null", "FK"). Error messages say what happened and what to do next ("That sell number is already used by E7982 — pick a different suffix or search for it"). Empty states explain what the page is for and offer the first action.
- **Help where you need it.** Short helper text under fields, a "?" that opens a one-paragraph explanation, and a Help page per area written for the lab, not for developers.
- **Visual design:** a real, considered design system — one accent color family (chroma: use a saturated, slightly warm blue as the primary; keep the full spectrum for status and color chips), neutral grays, generous whitespace, a clear type scale (16px body minimum), consistent spacing. Use a single quality open-source font (e.g. Inter or IBM Plex Sans), vendored locally. Light theme by default, dark theme available. Color chips next to color descriptions where a Pantone or hex is known. Tables with zebra rows, sticky headers and comfortable row height. Build reusable components (buttons, inputs, cards, tables, toasts, modals, wizard shell) once and use them everywhere; put them in `resources/components/` with a style guide page at `/styleguide` so the look stays consistent as the app grows.
- **Keyboard and touch both work.** Enter submits, Esc cancels, Tab order is sensible; everything works on a tablet.
- **Fast feedback**: inline validation, saving indicators, toast on success, never a silent failure.
- **Accessibility basics**: real labels, 4.5:1 contrast, focus rings, no color-only meaning.

### Screens

**Lab side**
- **Projects list** — open projects by default; filter by customer, tech, status, date; search box that also matches any code or description. Columns: project #, type, customer, description, submitted, completed, tech, items done / total, status.
- **New project** — customer (type-ahead from `customers`), description, date submitted (defaults today), tech (defaults to current user), notes. Saves and opens the project.
- **Project page** — header with project fields (inline editable), the progress bar, and a **Colors** (or Items) table: one row per item with description, ink series, substrate, proofs Y/N + count, coating, digital match flag, E#, sell number, notes. "Add color" adds a row. On each color: **Assign E#** and **Assign sell number** buttons (hidden/disabled by permission). Mark project completed sets date_completed.
- **Code lookup** — the replacement for the cross-reference spreadsheet. One search box; results show E#, sell number(s), description, customer, dates, resale/vendor, which project it came from. Anyone with `codes.view` can use it (sales will use this constantly).
- **Code logs** — per-prefix list (the replacement for the Security and UV logs), newest first, with export to CSV/XLSX.
- **Quick assign** — assign an E# or sell number **without a project** (§4). The main case is adding a **sell-number alias to an existing product**: search for the existing E# or sell number, pick a prefix and optional suffix, enter the description (defaults from the existing one), reason (dropdown: relabel / customer-specific code / new version / other + text), Assign. The new sell number links to the existing E#. Easy mode is a three-step wizard (find product → choose code → confirm); Expert mode is one row. The result lands in the cross-reference and the product's history, and the lookup page shows all aliases of a product together. Also covers a brand-new E# or sell number with no project (resale items, purchased products).

**Admin side** (`admin.*` permissions)
- **Prefixes** — the full list, active and retired, in one table. Add a new prefix (code, label, **brief description** of what it's for, min digits, starting number); edit any of those; **retire** a prefix (it stays visible in lookup and logs, disappears from the Assign dropdowns) and **un-retire** it again with one click. Both directions are allowed on any prefix, including the inherited and legacy ones, and are logged. Set the **next number** (with the validation in §4). On each prefix, tick the **allowed suffixes** from the suffix list (none ticked = no suffix field for that prefix). Codes already assigned under a retired prefix are untouched. Seed with Matt's list in §2.2 (with the descriptions given there: tint, aqueous coatings, legacy aqueous, inherited…) and the retired historical ones.
- **Suffixes** — the master list (code, label, active); which prefixes use each one is set on the Prefixes screen. **Ink series**, **Coatings**, **Substrates** — simple list editors.
- **Sequences** — starting/next E# and project number, with the same forward-only validation.
- **Users & groups** — create users (username, display name, password, active), create groups, assign users to groups, tick permissions per group. Seed groups: `Administrators` (everything), `Lab` (projects + assign codes), `Sales` (view codes, view projects), `Read only`.
- **Network** — show current IP/subnet/gateway/DNS, allow changing them (writes netplan via the privileged helper in §7, warns that the browser will need to reconnect at the new address).
- **CMS connection** — MSSQL host, database, read-only credentials, test button, enable/disable, "sync customers now".
- **System** — current version (git commit + date), "Check for updates" / "Update now" (runs the updater via the privileged helper, streams the log), backup now, download last backup.
- **Import legacy codes** — the wizard in §2.3, plus the history of past imports and the `import_issues` list with a way to resolve each row (assign, merge, or discard).

## 6. Users, groups, permissions

- Local accounts, passwords hashed with `password_hash` (Argon2id), session cookies, login rate-limiting, "must change password on first login" flag. No external SSO in v1, but keep the auth layer small enough to add Microsoft 365 login later.
- Permission keys (string constants, checked server-side on every request, not just hidden in the UI): `projects.view`, `projects.create`, `projects.edit`, `projects.complete`, `colors.edit`, `codes.view`, `codes.assign_internal`, `codes.assign_sell`, `codes.manual_entry`, `codes.link`, `codes.void`, `codes.export`, `admin.lists`, `admin.prefixes`, `admin.sequences`, `admin.users`, `admin.network`, `admin.cms`, `admin.system`.
- A user's effective permissions are the union of their groups'. The admin created by the installer is in `Administrators` and cannot be removed from it or deactivated by anyone but another administrator.

## 7. Installer and updater — `install.sh`

One script at the repo root, run as root on a **fresh Ubuntu Server 24.04** install with network access. It must be safe to run again at any time: a re-run is the **update**.

```
curl -fsSL https://raw.githubusercontent.com/mattcomputers-ctrl/labmanager/main/install.sh | sudo bash
```
and, once installed, simply `sudo chroma-update` (a wrapper the installer places in `/usr/local/sbin`) or re-running the same curl line.

Behaviour on **first run**:
1. Detect Ubuntu version; refuse on anything other than 24.04 (or newer LTS) with a clear message.
2. `apt` install: git, nginx, mariadb-server, php8.3-fpm + extensions (mysql, mbstring, xml, curl, zip, intl, bcmath), composer, unzip, the Microsoft ODBC driver 18 and `pdo_sqlsrv` via PECL for the CMS connection (make this step non-fatal: if it fails, log it, continue, and the CMS feature shows "driver not installed" in admin).
3. Clone the repo to `/opt/chroma` (or pull if present). Check out `main` unless `chroma_BRANCH` is set.
4. Create the `chroma` system user, the MariaDB database and user with a generated password, write `/etc/chroma/config.env` (mode 600), generate the app key.
5. **Prompt** (with defaults, and all overridable by environment variables for unattended installs):
   - Admin **username** and **password** (password typed twice, hidden; minimum 10 characters).
   - **Network**: list interfaces; ask which one, then static **IP address/CIDR** (e.g. `10.10.5.20/24`), **gateway**, **DNS servers**. Offer "keep DHCP / skip". Write `/etc/netplan/60-chroma.yaml`, run `netplan generate` to validate, and apply **last**, after everything else succeeded, with a 10-second warning that an SSH session on the old address will drop and printing the URL at the new address.
6. `composer install --no-dev`, run migrations, seed prefixes/suffixes/groups/permissions, create the admin user.
7. nginx site config, PHP-FPM pool, file permissions, enable and start services, UFW allow 80 (and 22) if UFW is active.
8. Install `/usr/local/sbin/chroma-update` and `/usr/local/sbin/chroma-helper` (the privileged helper) plus a sudoers drop-in that lets **only** the app user run those two scripts, with fixed arguments, without a password. The helper exposes exactly: `update`, `set-network <iface> <ip/cidr> <gw> <dns…>`, `backup`, `restart-services`. Nothing else, no shell passthrough.
9. Nightly `mysqldump` backup to `/var/backups/chroma/` via a systemd timer, keep 30 days.
10. Print a summary: URL, admin username, where config and logs are, how to update.

Behaviour on **re-run / update**:
1. Detect the existing install (config file present). Skip all prompts.
2. `git fetch` and compare; if nothing new, say so and exit 0 (unless `--force`).
3. Take a backup first.
4. `git pull --ff-only` (refuse and explain if the working tree is dirty), `composer install --no-dev`, run new migrations, run the PHPUnit suite — if tests fail, roll back to the previous commit and restore services, and say so loudly.
5. Reload PHP-FPM and nginx. Print old → new version.
6. Flags: `--check` (report only), `--force`, `--branch <name>`, `--unattended`.

Also provide `bin/uninstall` (keeps the database unless `--purge`).

Document all of this in `README.md` with the exact commands Matt runs, in order, on the server, including the cutover day: stop using the spreadsheets, run Admin → Import legacy codes, check the next-number settings, go live. Assume the reader has never installed a web app before.

## 8. Mar-Kov CMS integration (optional at install, important in use)

- Read-only MSSQL connection to the existing Mar-Kov CMS (SQL Server 2016, database `CMS`, a read-only account). Credentials entered in admin, stored in `config.env`, never in the database.
- v1 uses it for exactly one thing: **customer list sync** (nightly + on demand) into `customers`, matching on name/ID so the lab picks real customer names. **Ask Matt** for the CMS customer table/columns before writing the query; do not guess the schema.
- Everything must work with the CMS connection disabled.

## 9. Non-functional

- Mobile/tablet-friendly layouts (the lab has a shared PC by the iQC station; people will also use phones).
- Every list exports to CSV and XLSX.
- Keep page loads fast with 10k+ code rows: proper indexes, server-side pagination, search via indexed normalized columns (no full-table scans on LIKE '%…%' — use a prefix match plus a FULLTEXT index on descriptions).
- Logs to `/var/log/chroma/`, rotated.
- No external network calls from the app at runtime except the CMS connection and the updater's git fetch.

## 10. Order of work

1. Repo skeleton, `CLAUDE.md`, migrations for everything in §3, seed data, auth, groups/permissions, admin user bootstrap. Commit.
2. Code assignment service + tests + stress script (§4). Commit.
3. Prefix/suffix/sequence admin screens. Commit.
4. Legacy importer: admin wizard with dry run + CLI wrapper, tested against copies of the three spreadsheets (ask Matt to place them in `legacy/` locally; that folder is in `.gitignore`). Commit.
5. Projects (all types), items/colors, assign-from-project flow, activity feed, attachments, tasks, code lookup, code logs, quick assign. Commit.
6. Users/groups admin, remaining lists. Commit.
7. `install.sh`, helper, sudoers, netplan, backup timer, update path. Test on a clean Ubuntu 24.04 VM or container from scratch, then test a re-run. Commit.
8. CMS customer sync, reports, read-only API/views, print stylesheet. Commit.
9. README for the server operator, DECISIONS.md, then a **usability pass**: walk every task in Easy mode as a first-time user, then in Expert mode as a power user; fix anything that needs explaining or takes too many clicks.

## 11. R&D projects and other scope to build in from the start

Chroma is also the tracker for **internal R&D projects** (new products, reformulations, raw-material trials). Treat these as first-class, not as customer projects with a blank customer:

- `projects.type`, seeded with four types and extendable in admin (**no QC in Chroma** — QC lives in the future ERP):
  - **Color match** — the lab workflow this spec mostly describes: a customer, one or more colors, codes assigned.
  - **Customer issue** — diagnosing a problem a customer is having (print defect, drying, adhesion, a batch complaint). Has a customer, a problem statement, the products/E#s involved (existing codes, linked not assigned), a root cause, a resolution, and a closed/open status. Usually assigns no codes.
  - **Customer evaluation** — testing or recommending products for a specific customer's use (new substrate, new press, a product trial). Has a customer, a goal, items under evaluation (existing codes or trials), an outcome, and may end in new codes.
  - **R&D** — internal, no customer (see below).
  **Every type holds many items.** A project is the unit of work the customer asked for; items are the pieces inside it: a 4-color process set under evaluation is one project with four items, and a request to match 25 colors is one project with 25 items, not 25 projects. Design for the 25-item case:
  - **Adding items in Expert mode**: the Colors/Items grid starts with **one empty line** and a **+** button that adds another line (also Tab/Enter from the last cell of the last row), up to a **maximum of 50** per project — the + button disables at 50 with a short note. Each line has a remove (×) control until it's saved. Pasting a multi-line list into the description cell fills one line per pasted row (still capped at 50). In Easy mode, the wizard asks "add another?" and loops, with the same cap.
  - **Per-item status** (pending → in progress → matched → approved / rejected, admin-editable per type) and a **project progress bar** ("18 of 25 matched"); the project auto-completes only when every item is done or explicitly marked not needed, and the user can still complete it manually.
  - **Batch code assignment**: select several items and assign E#s and/or sell numbers to all of them in one confirmed step (one transaction per item, but one review screen), with the prefix chosen per item (a row can default to the prefix of the row above) and a summary showing every code that was just assigned, copyable as a block.
  - Each item carries its own tech, dates, proofs, coating, notes and codes; project-level values act as defaults that fill down to new items and can be overridden per item.
  - Items can be reordered, and an item can be moved to another project (with permission and an audit entry) if it was filed in the wrong place.
  Each type has its own wizard steps, field set, stage list and default visibility, all defined in one place (`src/Domain/ProjectTypes`) so adding a type later is a data change, not a rewrite. Project items (§3) are the common building block for all of them: a color, a trial, a sample, or an *existing product under investigation*. The Projects list filters by type, and a project's type shows as a badge. Type drives which fields show, which wizard steps appear and the default group that can see it. An R&D project has no customer (or an internal "Precision Ink R&D" customer), has a **lead** and optional **team**, a goal statement, a **stage** (idea → bench → scale-up → press trial → released / shelved; stages admin-editable per type), target date, and optional `confidential` flag (visible only to the lead, admins and the `R&D` group).
- Customer issue and evaluation projects typically **link to existing codes** rather than assign new ones; the item picker must make "pick an existing E# or sell number" as easy as "assign a new one". R&D projects may produce E#s with no sell number for a long time, and may hold many **trials** rather than "colors". Model `project_colors` generally as **project items** (a color, a trial, a sample) with a `kind` and keep the per-item fields optional, so a trial can carry its E# and notes without pretending to be a customer color. Same table, one extra column; the lab UI still says "Colors" for customer projects.
- **Activity feed** on every project: dated, attributed notes plus automatic entries (code assigned, stage changed, proof count changed). This is where R&D keeps its lab notebook-style narrative. Notes support simple markdown and @mentions of users.
- **Attachments** on projects and items: drawdown photos, iQC exports, customer artwork, press-trial reports, formula card PDFs. Store under `/var/lib/chroma/files/`, size-limited, image previews inline, included in the nightly backup.
- **Tasks / next actions** per project (owner, due date, done), surfaced on the user's home page ("your open items") so R&D work that spans months doesn't go quiet.
- **Reports** page (`reports.view`): turnaround time (submitted → completed) by customer and tech, open projects aging, codes assigned per month per prefix, proofs generated, R&D projects by stage. Each exportable.
- **Integration readiness — Chroma is a future ERP module.** Precision Ink's in-house systems (Insights, the SDS system, this one, and a CMS replacement) will eventually be joined into one ERP. Build Chroma so that join is cheap: expose a small read-only JSON API (token-authenticated) for code lookup and project status, plus read-only MariaDB views; keep all business rules in a `src/Domain/` layer with no HTTP or HTML in it so it can be lifted into a shared codebase; use the same auth/user/group table shapes as the other systems where known (ask Matt) so single sign-on across them is a merge, not a rewrite; namespace tables with a `chroma_` prefix so they can share a database later. The SDS system is keyed by E#, so an E# links out to its SDS (base URL an admin setting). Never let Chroma write to CMS.
- **Printing**: a print stylesheet for the project page and the formula card summary (E#, sell number, description, "Digital Match" flag large when set, customer, substrate, coating). A QR code on the printout that opens the project in Chroma.
- **Separate project number sequence for R&D?** Ask Matt: one shared sequence (simplest; 101, 102…) or a prefixed one (`RD-0001`). Default: shared sequence, type shown as a badge.

## 12. Questions for Matt before step 2 is finished

1. Confirm the `UVV` → `UVVL` mapping. (Retiring/un-retiring is in the admin UI, so no decision is needed up front.)
2. Confirm the last-used numbers in §2.2 and the first E# (`E9102`) and first project number (`101`).
3. Should `UV` be modelled as a suffix only, with the legacy `UV25xxx` series as a prefix named something like `UVL` to avoid confusion, or keep both as `UV` and rely on digit count? (Recommend the former.)
4. Is the sell number always the name of the iQC standard? (The lab sign says yes.) If so, show it prominently on the color row.
5. Do X-code items need a different description/approval workflow for the security division?
6. Which CMS table and columns hold customers?
7. R&D numbering: shared sequence or `RD-` prefixed? Which groups may see R&D and confidential projects?
