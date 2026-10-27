# Decisions

Choices made while building Chroma where the spec said "decide" or left room. Newest at the bottom.

1. **No framework; small custom core.** `src/Core` is ~1,500 lines: router, PDO wrapper, DB-backed sessions, auth, CSRF, flash, views, validator. It matches the other in-house PHP systems and keeps Composer dependencies to four packages.
2. **Table for project items is `chroma_project_items`** (the spec calls it `project_colors`). It carries a `kind` column (`color`, `trial`, `sample`, `existing_product`) so R&D trials and customer-issue products share the table; the UI still says "Colors" on color-match projects (the label comes from `chroma_project_types.item_label`).
3. **E numbers are unique on (number, version)**, with `version` stored as an empty string rather than NULL so the unique index works in MariaDB. The cross-reference may hold `E6374-10` and `E6374-12` as separate rows; both map to the same base number. Assigning a new E# always creates version `''`.
4. **Sell number normalization** = uppercase prefix + number without leading zeros + uppercase suffix, no hyphens or spaces (`y-1000` → `Y1000`, `UVNG072` → `UVNG72`). The original text is kept in `legacy_text`. Display re-pads to the prefix's `min_digits`.
5. **The `UV` prefix stays `UV`** (not renamed to `UVL`) because it is the code printed on existing products; renaming would change product codes. It is labelled "UV legacy series", tagged "inherited" and sorted to the bottom of the prefix list. Parsing is unambiguous because a prefix is always the leading letters and a suffix the trailing letters. See QUESTIONS.md #3 — easy to rename later from Admin → Prefixes.
6. **Sessions in the database** (`chroma_sessions`) so an admin can see who is signed in and sessions survive PHP-FPM restarts.
7. **Login rate limit**: 6 failed attempts per username or IP in 15 minutes, then a 15-minute wait. Attempts are stored in `chroma_login_attempts` and pruned after a day.
8. **Easy/Expert mode is stored on the user row** (`ui_mode`) and mirrored in a one-year cookie (`chroma_mode`) so the shared lab PC remembers it before anyone signs in. Theme (light/dark/system) is a cookie plus localStorage; it is a device preference, not a user one.
9. **Wizard state lives in the PHP session** under `_wizard.{key}`; the review screen is the only place that commits. Switching to Expert mid-wizard carries the entered values as query-string defaults where the Expert page supports them.
10. **Help pages are markdown files** in `resources/help/`, rendered with Parsedown in safe mode, so the lab can edit them without touching PHP.
11. **Seeded data is reference only.** Prefix `next_number` values from the spec table are seeded, but the legacy import overrides them with max-in-use + 1 unless an admin has already set something higher.
