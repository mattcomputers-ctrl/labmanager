# Help: importing the legacy spreadsheets

Chroma replaces three spreadsheets: the **Code Cross Reference**, the **Security Product Code Log** (X codes) and the **UV Product Code Log** (UV25xxx codes). **Admin → Import legacy codes** brings them in. You do this once at cutover, and you can do it again later to catch up — running it twice never creates duplicates.

## Before you start

- Keep using the spreadsheets until the day you switch. The import is run by you, when you are ready, not by the installer.
- Save each sheet as an Excel file (`.xls` or `.xlsx`). Chroma recognises each one by its **header row**, not by the file name, so the names do not matter and the order does not matter. The header row does not have to be the first row.
- You can do a trial run on a test install first. Nothing about the trial has to be undone.

## The three steps

1. **Upload the sheets.** Choose up to three files. Leave *Import UVV rows under the UVVL prefix* ticked unless you want UVV to stay a separate prefix.
2. **Check the dry run.** Chroma reads every row and shows what *would* happen: how many E numbers and sell numbers would be created, how many existing codes would get empty fields filled in, how many rows are skipped, and lists of anything odd (see below). **Nothing is written yet.** Read the lists, tick the confirmation boxes, then press **Commit the import**.
3. **Commit.** The import runs in one go, usually well under a minute. You then see a summary and a button to resolve any issues.

## What the import does with each row

- **Matching.** A sell number matches on its normalized form: `y-1000`, `Y 1000` and `Y1000` are the same code. An E number matches on number and version (`E6374-10` and `E6374-12` are two codes). The original spelling from the sheet is kept as *legacy text*.
- **Existing codes are never overwritten.** If a code is already in Chroma, only its empty fields are filled in from the sheet (a missing date, customer, vendor or E number link). Nothing is deleted.
- **One E number, many sell numbers.** A second row with the same E number and a different sell number is a relabel or an alias, and both sell numbers are linked to that E number.
- **Pre-filled empty rows** (E numbers with nothing else, blank X or UV codes) are skipped.
- **Descriptions.** A row with a code but no description gets the placeholder *No description in legacy sheet*, which a later import can fill in.
- **Comments** in the code logs ("Same E#, new formula version", "Also coded as R2156") become notes on the sell number.
- **Legacy internal codes** such as `2UY0480` or `3UC4779` are kept as text on the sell number. They never become E numbers.
- **Resale rows** keep their vendor name, vendor code and Handschy number on the E number.
- **Original Customer** names are matched to Chroma's customers (ignoring case and punctuation) or created.
- **Dates** are read from Excel dates and from text like `3/14/2001`. A date Chroma cannot read is left blank and listed in the report.
- **Counters.** After a commit, every prefix's next number and the next E number move past the highest number imported — unless you had already set them higher under Admin → Prefixes or Admin → Sequences.

## Things the dry run asks you to look at

- **Unknown prefixes** (for example an old `CS` or `EG` that is not in Chroma yet) are added as **retired** prefixes: visible in lookup and the logs, but nobody can assign new numbers under them. Un-retire one under Admin → Prefixes if it should be active.
- **UVV rows.** Many cross-reference rows spell the inherited `UVVL` prefix as `UVV`. By default they are imported as `UVVL` codes with the original spelling kept; the dry run lists them and asks you to confirm.
- **Suffixes found per prefix.** Each prefix is allowed only the suffixes actually used on it in the sheets (for example `Y` + `UV`). Change this later under Admin → Prefixes.
- **Duplicate sell numbers.** About fifty sell numbers appear twice in the cross-reference. The first row is kept; each later row that names a different E number becomes an *import issue* (see below). The same applies when a sheet disagrees with Chroma: if a sell number is already in Chroma linked to one E number and a catch-up sheet puts it under another, Chroma's row is left exactly as it is and the sheet row goes to issues — nothing is changed quietly. The dry run lists every one of these under *Duplicate sell numbers*, saying which row (or which Chroma code) was kept.
- **One commit at a time.** Each dry run can be committed once. If Commit is pressed twice (a second tab, a double click, a refresh while it runs), the second attempt is simply refused with a note; nothing is imported twice.
- **Codes that could not be read**, such as `38-5000` or `PURCHASED ONLY`, and **unknown suffixes** become import issues. The E number on the same row is still imported.

## Resolving import issues

**Admin → Import legacy codes → Import issues** lists every row that needs a decision. Open one and choose:

- **Enter the corrected code** — for typos. Type the code the row should have had; Chroma creates it with the row's description, customer, date and E number, and checks it is not already in use.
- **Merge into the existing code** — for genuine duplicates. The row's description and comments are added to the notes of the code that was kept, and a missing E number link is filled in.
- **Discard** — for junk. Say why; the reason stays with the issue.

Each choice marks the issue resolved and is recorded in the log. A resolved issue can be reopened, which only puts it back on the list.

## Running it again

Upload the sheets again whenever you like — for example a catch-up import the day after cutover. Rows already in Chroma are left alone (or get empty fields filled in), new rows are added, and issues you have already seen are not listed twice — the dry run shows them separately as *already on the issues list* so the counts match what the commit will add. **Past imports** keeps every dry run and import with its full report.

## From the command line

Administrators can run the same import from the server: `bin/import-legacy --dry-run file1.xls file2.xlsx file3.xlsx`, then `--commit`. It prints the same report as text.
