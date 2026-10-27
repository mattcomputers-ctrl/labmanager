# Questions for Matt

Open questions, grouped by area. Answers mostly change settings or data, not code. Defaults chosen so far are stated with each question. The per-area notes in `docs/questions/` are the source; this file is assembled from them.


## General

1. **`UVV` → `UVVL` mapping.** The importer maps rows spelled `UVV` to the `UVVL` prefix and keeps the original text in `legacy_text`. The dry-run report lists them for confirmation. *Default: map.*
2. **Last-used numbers and starting values.** Seeded: first new E# = `E9102`, first project number = `101`, and the per-prefix numbers in SPEC.md §2.2. The import replaces prefix counters with max-in-use + 1. *Check Admin → Sequences and Admin → Prefixes after the import.*
3. **`UV` prefix vs `UV` suffix.** Kept as a prefix named `UV` (labelled "UV legacy series", sorted last) because renaming it would change existing product codes. If you prefer a distinct name such as `UVL`, rename it in Admin → Prefixes (existing codes keep their stored prefix row and display with the new name — tell me if they should not). *Default: keep `UV`.*
4. **Is the sell number always the iQC standard name?** *Default: yes* — the color row shows the sell number prominently with an "iQC standard" hint. Setting `sell_number_is_iqc_standard` in Admin → System turns the hint off.
5. **X-code (security) items**: any different description or approval workflow? *Default: none* — X codes work like any other prefix, with `min_digits` = 6.
6. **CMS customers**: which table and columns hold customers in the Mar-Kov `CMS` database? Nothing is guessed: Admin → CMS connection has fields for table, ID column, name column, optional active column and optional filter, and the "Test" button shows the first rows so you can confirm before syncing.
7. **R&D numbering**: shared project sequence (101, 102 …) with the type shown as a badge, or an `RD-0001` series? *Default: shared.* Which groups may see R&D and confidential projects? *Default: the `R&D` group, the project lead, team members and Administrators.*


## Module D questions for Matt

1. **SDS link for formula versions.** `E6374-10` currently links to the SDS for the base number (`…/E6374`) because the SDS system is keyed by E#. If versions have their own sheets, say so and the link will use the full code. *Default: base number.*
2. **SDS URL shape.** The link is base URL + `/E9102`. If the SDS system wants a query string (`…?code=E9102`), put `{code}` in the base URL under Admin → System and it is substituted. Confirm which shape the SDS system uses.
3. **Who may edit notes and descriptions on code pages?** Currently anyone with "Assign E numbers" or "Assign sell numbers". Sales (lookup only) cannot. Should Sales be able to add notes?
4. **Reason required for aliases.** Adding another sell number to an existing product requires a reason (relabel / customer-specific / new version / resale / other). For brand-new products it is optional. Is that the right balance, or should every sell number carry a reason?
5. **Prefix log order.** Logs show the highest number first (newest assigned number), which matches "newest first" for sequential counters. If you prefer date-assigned order (relevant after the legacy import, where dates and numbers may disagree), say so — it is a one-line change and the column is indexed.
6. **Export of the whole cross-reference.** Lookup export is capped at 20,000 rows per download; a full-table export is available per prefix from the logs. Is a single "everything" export needed?


## Questions for Matt — module F (installer / server)

1. **Backup copies off the server.** The nightly backup lands in `/var/backups/chroma/` on the Chroma server only. Do you want the installer to also copy it to a network share (SMB path + credentials) or is a weekly manual `scp` / download from Admin → System acceptable? *Default: manual.*
2. **Backup time.** Nightly at 02:17 (server local time) — fine, or should it run after the last shift instead?
3. **Static IP during install.** The installer offers to set a fixed address. Should IT reserve one (DHCP reservation) instead, so the "keep DHCP" default is the right answer? *Default: offer both, default to keeping DHCP.*
4. **Branch for production.** Updates follow `main`. If you want a slower "release" branch for the lab server, say so and we set `CHROMA_BRANCH=release` on the server (or `sudo chroma-update --branch release`). *Default: main.*
5. **HTTPS.** v1 serves plain HTTP on port 80 on the LAN. Do you want a self-signed certificate on port 443 as well (browsers will warn once), or wait for a proper internal CA? *Default: HTTP only.*


## Questions for Matt — Module A: Admin prefixes, suffixes, sequences

1. **Sort order of prefixes.** Active prefixes are shown by their "position in lists" number (colors 10–100, inherited 200+, legacy 300). Is that the order you want in the Assign dropdown too, or should the dropdown be alphabetical?
2. **Who may move a prefix counter?** Today it needs both "Manage prefixes" and "Set next numbers". If the same people always have both, we could drop the second check.
3. **Minimum digits on an existing prefix.** Changing it only changes how codes are *displayed* (`Y1716` vs `Y01716`); the stored number is untouched. Should changing it be locked once codes exist, like the letters are?
4. **Code logs link.** The prefix page links "see the log" to `/codes/logs?prefix=Y`. Confirm with the Code logs module that it reads a `prefix` query parameter.

### Round 2

- A prefix's link to a **switched-off** suffix is now kept automatically when the prefix is edited. To remove such a link you switch the suffix on, untick it, and switch it off again. Is that acceptable, or should the greyed box be untickable directly?
- Counters can be moved **down** as far as the number after the highest one used (never onto a used number). Is that what you want, or should they be strictly forward-only from the current setting?


## Questions for Matt — module E2

1. **CMS customer table and columns.** The CMS page has fields for the table, ID column, name column, optional active-flag column and an optional filter, and says "ask the CMS administrator" rather than guessing. What are they? (Spec §12 question 6.)
2. **Should customers that disappear from the CMS be marked inactive?** Default is no (they stay active). There is a tick box on the CMS page if you want yes.
3. **DHCP from the Network page.** Should changing the server to automatic (DHCP) addressing be allowed from Chroma at all? It is offered now; if a server should always be static we can hide the option.
4. **Backup download size.** Backups are streamed through PHP; if the database plus attachments grows past a few hundred MB, downloads from the browser may be slow and we should serve them through nginx instead. Fine for v1?
5. **API token scope.** Tokens are read-only and all-or-nothing today. Do you want per-system tokens limited to codes only vs projects too?


## Questions for Matt — module E1 (Admin: users & groups, lists, customers)

1. **Should people with "Manage users and groups" who are not Administrators be able to make someone an Administrator?**
   I said no (only an Administrator can add to, or remove from, the Administrators group). If you want office staff to onboard
   new technicians without being administrators themselves, this still works: they just cannot hand out the Administrators group.
2. **Temporary password style.** Chroma makes up passwords like `Amber-Harbor-Pilot-42`. Is a three-word style fine for reading
   over the phone, or would you rather have a plain random string?
3. **Customer merge and the CMS id.** When a duplicate was created by the CMS sync and the one you keep was typed by hand, the
   merge moves the CMS id to the kept customer so the sync does not re-create the duplicate. Is that the behaviour you expect, or
   should the CMS-sourced row always be the one kept?
4. **Who may edit the Administrators group's description or members?** Anyone with "Manage users and groups" can change its
   description; only Administrators can change its membership. Confirm that split is right.
5. **Lists: should "Delete" exist at all?** It is only offered for entries nothing uses yet (e.g. a typo you just added). If you
   would rather have deactivate only, it is one line to remove.
6. **Do you want a separate "Customers" tab in the main navigation for sales** (read-only list with search), or is the admin
   screen plus the typeahead on projects enough?

7. **Should an Administrator be able to take themselves out of the Administrators group?** I now refuse it (decision 16): the
   reviewer showed that an admin who removes themselves is locked out of every admin page on the very next click. If Matt would
   rather allow it with a warning, it is a one-line change in `UserAdmin::assertMayChangeAdminMembership` and the note on the
   group page.


## Questions for Matt — legacy import

1. **UVV → UVVL.** The dry run lists every `UVV` row and asks you to tick a box before committing. If `UVV` should stay a separate prefix instead, untick *Import UVV rows under the UVVL prefix* on the upload screen (they then become a retired `UVV` prefix). *Default: map to UVVL.*
2. **Duplicate sell numbers (~48).** Each one after the first is an import issue. Typos: *Enter the corrected code*. Genuine relabels: *Merge into the existing code* (adds the description to its notes and links a missing E number). Is that enough, or should a genuine relabel get its own new sell number automatically? *Default: you decide per row.*
3. **E numbers that appear only in the X or UV log** (an "Internal Code" the cross-reference does not have) are created from the log row with the X/UV description. Should they instead be left unlinked and listed as issues? *Default: create them.*
4. **Rows with a code but no description** get *No description in legacy sheet* so the code is not lost. Fine, or should those rows be issues? *Default: import with the placeholder.*
5. **Suffixes found in the sheets** are allowed per prefix automatically (e.g. `Y` + `UV`). Letters the importer does not recognise as a suffix (anything other than `UV`, `ND`, `F`, `HS` or one you add under Admin → Suffixes) are issues. Are there other suffixes in the real sheets?
6. **Resale flag.** The importer treats `Yes`, `Y`, `X`, `TRUE`, `1`, `R` and `resale` in the Resale column as "yes". What does the real column contain?
7. **Upload size.** The real cross-reference `.xls` (~8,100 rows) is probably 1–3 MB; the limit is 25 MB per file, but PHP's own limit on the server must be raised to match (see `docs/requests/import.md`). Saving as `.xlsx` makes the file much smaller if that is ever a problem.
8. **The server address at cutover.** A full import takes about 40 s on the test box (about 20 s when Chroma talks to MariaDB over its socket). Fine for a one-off, but tell me if you want it running in the background with a progress bar instead.
9. **Catch-up sheets that disagree with Chroma.** If a later cross-reference puts an existing sell number under a different E number, Chroma keeps its own link and lists the row as an issue (merge, correct or discard). Should such a row instead *re-link* the sell number when the E number in the sheet exists and the old one has no other sell numbers? *Default: never change an existing link automatically.*


## Questions for Matt — Projects module (C)

1. **R&D numbering**: the module uses one shared project-number sequence for all types (so an R&D project is "Project 104" with a purple R&D badge). If you would rather see `RD-0001`, say so; it is a display change plus a second sequence.
2. **Who may see confidential R&D projects?** Currently: the lead, the team, whoever created it, the "R&D" group, administrators, and anyone given the "See confidential R&D" permission. Should Sales never see R&D projects at all, even non-confidential ones?
3. **Is the sell number always the iQC standard name?** The row shows an "iQC standard" hint under the sell number while the setting `sell_number_is_iqc_standard` is on (it is on by default). Turn it off in settings if that is wrong.
4. **Item cap of 50**: the setting `max_items_per_project` defaults to 50. Is 50 right for the biggest jobs you see?
5. **Attachment size**: `max_upload_mb` defaults to 25 MB. Are iQC exports or press-trial photos ever bigger?
6. **Auto-complete**: a project completes itself when every item is matched/approved/rejected/not needed. Should "rejected" count as done, or should a rejected color keep the project open?
7. **Who completes the project when the tech is not here?** Right now "Complete or cancel projects" is its own permission (Lab and R&D groups have it). Should Sales be able to cancel a project the customer withdrew?


## Questions for Matt: Reports and the API

1. **Proof dates.** Items do not record when a proof was made, so "Proofs generated" dates an item by its completed date, then its started date, then the project's submitted date. If you want proofs per calendar month to be exact, we can add a `proofs_date` on the item (or log each proof in the activity feed). Worth it?
2. **Who may export reports?** Right now the CSV/Excel buttons on reports need "Export lists" (`codes.export`) as well as "See reports". The seeded *Read only* group can see reports but not export them. Should "See reports" alone allow export?
3. **Turnaround on cancelled projects.** Cancelled projects are ignored. Do you want a separate count of cancellations per customer/tech on that report?
4. **API token scope.** Every token can read every endpoint. Is a per-token restriction needed (e.g. a token that can read codes but not projects), or is one level of access enough for the ERP and the SDS system?
5. **API over HTTPS.** Tokens travel in a header, so on the plant network HTTP is tolerable, but once HTTPS is set up the API should be HTTPS-only. OK to enforce that when HTTPS is switched on?
6. **Codes per month for the imported history.** Imported codes keep the date from the spreadsheet; some rows have no date at all and will never appear on the monthly grid. Fine, or should undated codes be shown as a separate "no date" column?
