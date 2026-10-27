# Help: lists, project types and customers

Under **Admin → Lists** you keep the pick-lists that appear on projects and colors. You need the "Manage lists" permission.

## Ink series, coatings, substrates

These are the simple lists offered when a color is added. Each tab works the same way:

- **Add**: type the name in the box at the top and press **Add to list**. It appears in the pickers straight away.
- **Rename**: press **Edit** on the row, change the name or notes, press **Save**.
- **Sort**: lower numbers come first in the picker (Expert mode shows the sort box).
- **Deactivate**: hides the entry from pickers. Old projects and colors keep showing it. Press **Reactivate** to bring it back; deactivated entries are listed under "Show inactive entries".
- **Delete** is only offered for entries nothing uses yet. If a project or color refers to an entry, Chroma refuses to delete it and suggests deactivating instead, so old work always reads correctly.

## Project types

Chroma starts with four types: **Color match**, **Customer issue**, **Customer evaluation** and **R&D**. Open **Admin → Lists → Project types** to change how each one behaves:

- the name, one-line description and badge colour;
- what its items are called ("Colors", "Trials", "Products involved");
- whether it has a customer and whether the customer is required;
- whether it has stages, a lead and team, and whether it can be marked confidential;
- which fields a project of this type shows (ticked from the catalogue: description, problem statement, goal, dates, root cause, outcome and so on).

**Adding a brand-new type** (for example "Press trial"): press **Add a project type**, give it a name and a short code (letters, numbers and underscores; it never changes), set the behaviour and tick its fields. Then open it to add stages and statuses.

### Stages

Types with stages (R&D by default) have an ordered list such as Idea → Bench → Scale-up → Press trial → Released / Shelved. On the type page you can add a stage, rename it, move it up or down, mark it as a **final** stage (the project is finished when it gets there) and deactivate it. A stage a project is currently at cannot be removed, only deactivated.

### Item statuses

Every color or item has a status (Pending → In progress → Matched → Approved / Rejected / Not needed). Statuses marked **finished** count towards the project's progress bar; **success** says whether the finished item went well.

By default all types share one list, edited under **Project types → Shared item statuses**. To give a type its own list, open the type and press **Customize for this type**: it gets a copy of the shared list to change freely. **Use the shared list again** goes back, as long as no item is still carrying one of the type's own statuses.

## Customers

**Admin → Customers** lists the customer names used on projects and codes. Most arrive from the CMS sync or the legacy import; the source and CMS id are shown but cannot be edited here.

- **Search** by any word of the name ("labels" finds "ACME Labels, Inc.") or by the exact CMS id. One or two letters only match the start of the name, so type a little more if the list is long.
- **Add** a customer only when it is not in the CMS yet. Chroma ignores case and punctuation when checking for duplicates.
- **Rename** by opening the customer and changing the name.
- **Merge duplicates**: when the same company appears twice (for example "Example Packaging" and "Example Packaging Inc"), open the one to keep and press **Merge**. Pick the duplicate; every project, E number and sell number on it moves to the kept customer and the duplicate is deactivated. Nothing is deleted and the merge is logged.
- **Deactivate** a customer to stop it being offered on new projects. Existing projects and codes keep the name.
