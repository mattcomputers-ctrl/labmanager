# Help: reports

The **Reports** page shows how the lab is doing, using the projects, colors and codes that are already in Chroma. Reports never change anything; they only read. Anyone in a group with "See reports" can open them.

Every report works the same way:

1. **Filters at the top.** Pick a date range, a customer, a tech or a project type, then press **Apply filters**. Press **Clear** to go back to the defaults. If a date is typed in a way Chroma cannot read, it tells you and ignores it rather than hiding the page.
2. **Headline numbers**, then one or two tables. The bars next to the numbers are there to make the big and small values obvious at a glance; the exact figure is always printed beside them.
3. **CSV and Excel buttons** on the filter bar download the table exactly as filtered. You need "Export lists" for those buttons to appear.

Dates are the calendar dates on the project or code (the submitted date, the completed date, the date a code was assigned), shown in the lab's own timezone.

## The reports

**Turnaround time** — how many days a project took from its submitted date to its completed date. Only projects that are marked completed with both dates count. Group by customer, tech or project type. *Average* is the mean; *median* is the middle value (half the projects were faster), which is a better guide when one project took far longer than the rest.

**Open projects aging** — every open project, counted by how many days it has been open: 0-7, 8-14, 15-30, 31-60 and more than 60 days. Press a bucket to see only those projects. Oldest first, so the ones that need attention are at the top.

**Codes assigned per month** — a grid of the last 12 months against every prefix: how many sell numbers were given out under each. The last row is E numbers. A voided code still counts as assigned (the number was used up and will never be reused); the last column says how many were voided afterwards. Use "Last month shown" to look at an earlier year.

**Proofs generated** — the proof counts entered on colors and items, totalled per month and per tech, plus how many items had proofs at all. An item is dated by the day it was completed, or started, or by its project's submitted date if neither is filled in. The tech is the item's tech, or the project's tech if the item has none.

**R&D projects by stage** — where the R&D work sits: idea, bench, scale-up, press trial, released or shelved. Open projects by default; switch to Completed or All to include finished work. Confidential R&D projects only appear for people allowed to see them.

**Items done vs total** — progress on the open projects: how many colors or items are done out of the total, by project type, tech or customer. "Done" means matched, approved, rejected or not needed. The green part of the bar is done; the grey part is still to do.

## Something looks wrong?

- **A project is missing from turnaround.** It is probably still open, or its submitted or completed date is blank. Open the project and check the dates.
- **A project is in the wrong aging bucket.** The age counts from the submitted date. If the project was entered late, correct the submitted date on the project.
- **The codes grid shows zero for a month you remember being busy.** Codes imported from the old spreadsheets keep the date from the sheet; codes assigned in Chroma use the day they were assigned. Check the date on the code itself in Code lookup.

For other systems that need these numbers, Chroma also has a read-only API; ask an administrator for an API token (Admin → API tokens).
