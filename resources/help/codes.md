# Help: E numbers and sell numbers

## E numbers (internal codes)

An **E number** such as `E9102` identifies a formula. Chroma hands them out one after another from a single counter, so two people can never get the same number. A formula version is written after a dash: `E6374-10`.

One E number can have **several sell numbers** — a relabel, a customer-specific code, the UV version of the same formula. The lookup always shows them together.

## Sell numbers

A **sell number** such as `Y1715UV` is the code the customer sees. It is made of:

- a **prefix** that says what kind of product it is (`Y` yellow, `BK` black, `T` tint, `X` security ink, `AQ` aqueous coating …),
- a **number** that counts up separately for each prefix,
- an optional **suffix** such as `UV` for the energy-curable version.

The suffix never starts a new count: `T2445`, `T2446UV` and `T2447` all come from the T counter. A sell number can exist before its formula has an E number; link them later.

## Looking up a code

Open **Code lookup** (or type in the search box at the top of any page; on a phone it is inside the ☰ menu) and type what you have:

- any spelling of a sell number — `y-1000`, `Y 1000` and `y1000` all find `Y1000`;
- an E number as `E6374`, `E6374-10` or just `6374`;
- a word from the description (`blister purple`), or a customer name.

Each result is one **product**: the E number with every sell number that belongs to it, the customer, dates, whether it is a resale item, and the project it came from. Voided codes are shown greyed out with a "Voided" mark. The "Active only" and "Voided only" buttons narrow the list, and **Export CSV / Excel** downloads what you see.

Click any code to open its page: all its details, the other sell numbers of the same formula, where it came from, notes, and the full history of who did what. On an E number page, **Open SDS** opens the safety data sheet in the SDS system (an administrator sets the SDS address under Admin → System).

## Code logs

**Code logs** replace the Security log, the UV log and the other per-prefix sheets. Pick a prefix to see its numbers newest first, with description, E number, customer, date and who assigned it. The **E numbers** log lists every formula code. Each log can be searched, narrowed to active or voided codes, and exported.

## Getting a code

1. Open the project and find the color, or use **Quick assign** if there is no project (see the [Quick assign](/help/quick-assign) page).
2. Press **Assign E#** or **Assign sell number**.
3. Pick the prefix (and suffix if the prefix allows one), check the description, and confirm.
4. Chroma shows the number it reserved, large, with a **Copy** button.

You cannot pick the number yourself; the next free one is used.

## Entering a code by hand

For legacy items and odd cases where a code already exists on paper, someone with the "Enter a specific code by hand" permission can type the exact code under **Code lookup → Enter a code by hand**. Chroma refuses a code that is already used and tells you who has it (for example "BN0777 is already used by E6374"), refuses numbers that are too long (sell numbers have at most 6 digits, E numbers at most 6 digits and a version of up to 20 characters), and every manual entry is written to the log with your name. If the number is above the counter, the counter moves past it so it is never given out again. If you pick the reason **Other**, say why in a few words — it is kept with the code.

## Linking a sell number to an E number

Open the sell number and press **Link to an E number** (or **Move to another E number**). Search for the E number — or for one of the product's other sell numbers; the link goes to that product's E number. A sell number belongs to one E number; an E number can have many sell numbers.

## Made a mistake?

Codes are never deleted or reused. Instead the code is **voided** with a short reason: it stays in the logs, greyed out, so anyone looking it up sees what happened. Voiding needs the *void codes* permission, which administrators have: if you do not see a **Void** button on the code page, ask an administrator to void it for you (the code page says so). Voiding an E number does not void its sell numbers; void those separately if they are wrong too.

## Notes and descriptions

Anyone who can assign codes can add notes to a code page and correct its description. Every change is recorded in the history.
