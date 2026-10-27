# Help: System, Settings, Network and the audit log

These pages are under **Admin** and need the "Update, back up and view system status" permission (Network needs "Change network settings"). Everything you do on them is written to the audit log.

## System

- **Version** — the version tag and the Git commit Chroma is running, with the date. Say these when you report a problem.
- **Check for updates** — asks the server whether a newer version exists. Nothing changes.
- **Update now** — takes a backup, fetches the new version, runs the tests and restarts. The log scrolls on the page while it runs. If the tests fail, the previous version is put back automatically and the log says so. Anyone using Chroma may see an error for a few seconds; wait a minute and reload.
- **Back up now** — makes a copy of the database and attachments in `/var/backups/chroma`. One is also taken every night and before every update. Press **Download** next to a backup to save it somewhere else, for example before cutover.
- **App log** — the last 100 lines of errors Chroma ran into. A developer may ask you to read a line from here.
- **API tokens** — let another Precision Ink system (the ERP, Insights) read codes and project status. Give the token a name that says what will use it, press **Create token**, and copy it straight away: it is shown only once. **Revoke** stops a token for good; make a new one instead.

On a developer's machine, where the system helper is not installed, the update, backup and network buttons say "not available on this machine". Everything else still works.

## Settings

- **Company name** and **app name** appear on printouts and in the browser tab.
- **Internal customer for R&D projects** — R&D projects have no real customer; this name is used instead.
- **Time zone** — Precision Ink uses Central time. Chroma stores times in UTC and shows them in this zone.
- **SDS system link** — the web address where an E number's safety data sheet lives. Put `{E}` where the E number goes.
- **Most colors or items in one project** — the lab asked for 50.
- **Largest attachment** — in megabytes.
- **The sell number is the name of the iQC standard** — when on, the sell number is shown large on each color row.

## Network

Shows the server's address, gateway and DNS, and lets you change them. **Only change these with numbers from your IT contact.** After you confirm, the server moves to the new address about 10 seconds later; your browser stops working on the old one and you (and the lab) must open Chroma at the new address, which is shown before you confirm. If the numbers are wrong, Chroma becomes unreachable until someone fixes the network at the server itself.

In Easy mode the change is a short wizard (interface, fixed or automatic, address, gateway, DNS, then a review). In Expert mode it is one form with the same confirmation.

**Automatic (DHCP)** hands the choice of address to the router, so you will have to ask IT (or look at the router) for the new address before you can open Chroma again. It needs the current system helper on the server: if Chroma says the helper is an older version, nothing was changed; re-run install.sh (or **Update now** on the System page) or choose a fixed address instead.

## Audit log

Every code assignment, void, counter change, setting change and admin action, with who did it and when. Open an entry's **Change** column to see each field before and after. Filter by person, action, table or date, and export the result to CSV or Excel. The **Note or row number** box finds entries whose note contains every word you type (whole words or the start of a word, three letters or more, for example `voided wrong`), or the row with that number. On a phone each entry is shown as a card instead of a table.
