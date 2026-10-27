# Help: projects, colors and items

A **project** is one request: "match these 25 colors", "find out why this ink is not drying", "trial our new white on their press". The pieces inside it are **items**. On a color match the items are **colors**; on a customer issue they are the **products involved**; on an evaluation they are the **items under evaluation**; on an R&D project they are **trials**.

Every project gets the next project number automatically. The number never changes and is what you quote to the customer.

## Project types

- **Color match** — a customer, one or more colors, codes assigned as each color is matched.
- **Customer issue** — a problem statement, the products involved (existing codes, linked rather than assigned), a root cause and a resolution. Usually no new codes.
- **Customer evaluation** — a goal, the items under evaluation, and an outcome. May end in new codes.
- **R&D** — internal work with a lead, an optional team, a goal, a target date and a stage (idea, bench, scale-up, press trial, released or shelved). It can be marked **confidential**: then only the lead, the team, the R&D group and administrators can open it; for everyone else it does not exist.

## Starting a project

- **Easy mode**: press **New project** on the Projects page. Chroma asks one question at a time: the type, the customer, a title and description, who is working on it, the defaults for the colors, then the first color with "add another?" until you are done. Nothing is saved until you press **Create project** on the review screen.
- **Expert mode**: **New project** opens one page with every field. The colors grid starts with one line; **+ Add a line**, or Tab/Enter from the last cell, adds another. Paste a list of color names into a description cell and one line is filled per row. Press × to drop a line you have not saved yet.
- The customer box searches as you type. If the customer is new, just keep typing the full name and it is added.
- A project can hold up to 50 items (an administrator can change this). Start another project for the rest.

## The project page

The top shows the project's details. In Expert mode click any value to change it; in Easy mode press **Edit details**. The bar on the right shows progress, for example "18 of 25 matched".

The **Colors** (or Items) table has one row per item with its description, the ink series, substrate, proofs, coating, whether it was a digital match, its **E number**, its **sell number**, its status and the tech. Project-level values fill down to new items and can be changed per item with **Edit**.

- **Assign E#** and **Assign sell number** reserve the next number and link it to the item. In Expert mode this happens in a small box on the row; in Easy mode it walks you through prefix, suffix and description. A color can get its E number and its sell number at different times.
- **The sell number is the name of the iQC standard**, so it is shown large on the row.
- **Assign codes to several** picks several items and assigns E numbers and/or sell numbers to all of them in one go. You check one review screen; each item is then handled on its own, so if one fails the others still get their codes. The result lists every code and can be copied as a block for the lab sheet.
- **Add an existing product** (or the "Existing product" kind) links an item to a code that already exists instead of assigning a new one. Type the E number or sell number and pick it from the list.
- **Status**: pending → in progress → matched → approved, or rejected / not needed. Change it from the dropdown. When every item is done or not needed the project completes itself. You can also press **Mark completed** at any time, **Cancel project** with a reason, or **Reopen** a finished project. A completed or cancelled project is read-only: adding colors, changing details, assigning codes and the like are refused with "Reopen it from its page first" until you press **Reopen**. An item that already has an E number or sell number keeps it; to change a code, void or unlink it from the code's own page.
- **Reorder** with the arrows or by dragging the handle. **Move** sends an item to another project if it was filed in the wrong place; it keeps its codes, status and notes, and the move is written to the log.
- **Remove** only works on an item that holds no code. An item with a code is never deleted: set it to **Not needed**, or void the code from the code page if it was a mistake.

## Activity, tasks and attachments

- **Activity** is the project's diary: notes you write (simple markdown works, and `@username` mentions someone) plus automatic entries such as "Sell number Y1715UV assigned", "Stage changed from Bench to Scale-up" or "Proofs for Pantone 300: 2 made". This is where R&D keeps its lab-notebook narrative.
- **Tasks** are next actions with an owner and a due date. Your open tasks are listed on your home page, so work that spans months does not go quiet. Tick the circle when a task is done.
- **Attachments** hold drawdown photos, iQC exports, customer artwork, press-trial reports and formula cards. Images show a preview. Files are kept in the nightly backup; removing one hides it from the project but does not destroy it.

## Finding projects

The Projects page shows open projects first. Use **Completed**, **Cancelled** or **All** to see the rest, **Mine** for projects you lead, created or are on the team of, and **More filters** for customer, tech, type and date. The search box matches a project number, words in the title or description or in any item, and **any code**: type `E9102` or `y-1715` to find the project that holds it. In Expert mode the **Columns** button lets you choose which columns to show. Every list can be exported to CSV or Excel.

## Printing

**Print** on a project opens a printable sheet with the project details and a formula-card summary for every item (E number, sell number, description, "Digital Match" in large letters when it applies, customer, substrate, coating). The QR code in the corner opens the project in Chroma when scanned with a phone.
