# Help: prefixes and sequences

These three admin pages decide how sell numbers and E numbers are built. You need the "Manage prefixes and suffixes" permission for the first two and "Set next numbers" for the counters.

## Prefixes

A **prefix** is the letters at the start of a sell number: `Y` for yellow, `BK` for black, `T` for tints, `X` for security ink. **Each prefix has its own counter**, so `Y1716` and `B2971` can be given out at the same time without getting in each other's way.

**Admin → Prefixes** shows every prefix in one table, active ones first and retired ones greyed at the bottom. For each you can see the last number used, the next number that will be handed out, how many codes exist under it, and which suffixes it allows.

### Adding a prefix

Press **Add a prefix**. In Easy mode Chroma asks one question at a time; in Expert mode everything is on one page. Either way you give:

- **Prefix letters** – letters only, up to 12. Chroma makes them capitals. They must not already be in use.
- **Name** and a one-line **description** so people pick the right prefix.
- **Minimum digits** – how many digits the number is padded to. Colors use 4 (`Y0001`), security ink uses 6 (`X104043`).
- **Starting number** – the number the first code gets. Usually 1, or 1000 if you want four-digit codes straight away.
- **Allowed suffixes** – tick the suffixes people may add. Leave them all unticked if this prefix never takes a suffix.

Nothing is saved until you confirm the review screen (Easy) or press **Add this prefix** (Expert).

### Why can't I change the letters?

Once a sell number exists under a prefix, the letters are locked. Changing `Y` to `YL` would silently rename every yellow product code, including ones printed on cans and orders. If you really need different letters, retire the old prefix and add a new one.

### Retiring and un-retiring

**Retire** takes a prefix out of the dropdowns people see when assigning a code. Nothing else changes: the codes already given out stay in lookup, in the logs and on projects. **Un-retire** puts it back, and the counter carries on from where it stopped. Both are one click plus a confirmation, both work on every prefix (including the inherited and legacy ones), and both are logged with your name.

### Allowed suffixes

On each prefix you tick which suffixes may be added. A ticked suffix is an **option, never a requirement**: `Y1716` and `Y1716UV` are both valid, and they come from the same counter. If nothing is ticked, the suffix field does not appear at all for that prefix.

### Next number

The prefix page shows where its counter stands. You can move it **up** to start a new block (for example `X105000` after the `104xxx` block), or back down as far as the number after the highest one used, but **never to a number that has already been given out**. Numbers are never reused; a mistaken code is voided instead.

## Suffixes

**Admin → Suffixes** is the master list: `UV` for the energy-curable version, plus `ND`, `F` and `HS`. Add one with its letters and a short name. The page shows which prefixes allow each suffix; to change that, open the prefix.

**Switching a suffix off** stops new codes using it. Codes that already end in it are untouched, and the prefixes that allow it keep allowing it (it shows greyed on the prefix page) so it comes straight back when switched on. Like a prefix, a suffix's letters cannot change once a code carries it.

## Sequences

**Admin → Sequences** holds the two counters shared by everyone:

- the **next E number** (internal code), one sequence for the whole lab;
- the **next project number**.

Each page shows the highest number already used and the next one to be given out. You can set either counter to a higher number; Chroma refuses anything at or below the highest used. After the legacy import both counters are set to "highest imported + 1" automatically unless you had already set something higher.

## Every change is logged

Adding, editing, retiring, un-retiring, switching suffixes and moving counters all land in the audit log with who did it and when. The **History** box on each prefix and suffix page shows the recent entries.
