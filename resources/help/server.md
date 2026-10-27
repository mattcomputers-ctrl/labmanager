# Help: the server, backups and updates

This page is for whoever looks after the Chroma server. The full step-by-step guide, written for someone who has never installed a web app, is `README.md` in the Chroma folder on the server (`/opt/chroma/README.md`).

## Where Chroma lives

Chroma runs on one Ubuntu server in the building. Everyone uses it through a browser at the address the installer printed (for example `http://10.10.5.20/`). Nothing is stored on your PC; if the server is off, Chroma is off.

## Backups

- Every night at about 02:17 the server saves a copy of the whole database and of all attachments into `/var/backups/chroma/`. Copies older than 30 days are removed automatically.
- A backup is also taken automatically before every update.
- An administrator can take one at any time from **Admin → System → Back up now**, and download it from the list on that page.
- Once a week, copy the newest backup file somewhere else (the file server or a USB stick). A backup that only lives on the server disappears with the server.
- To put a backup back, follow "Restoring a backup" in `README.md`. It is four commands, but it replaces everything, so ask before doing it.

## Updates

- **Admin → System → Check for updates** asks whether a newer version of Chroma exists. Nothing changes.
- **Admin → System → Update now** (or `sudo chroma-update` on the server) installs it. Chroma takes a backup first, installs the new version, checks the database and runs its own tests (two to three minutes, during which the new version is already the one being served). If every test passes the update is finished; if one fails, Chroma puts the previous version back by itself and says so. Run updates at a quiet moment, not while the lab is handing out codes.
- Nobody needs to sign out for an update; the next page they open is the new version.

## Changing the server's address

**Admin → Network** shows the network card, the current address, gateway and DNS. Enter the new fixed address and press Apply. Chroma checks the values, then switches after a 10-second warning and tells you the new address. If you choose DHCP instead, the address will be whatever the network hands out — ask IT to reserve one.

## Logs

The server writes what happened to `/var/log/chroma/`:

- `app.log` — Chroma's own messages, including every error shown to a user,
- `install.log` — every install and update run,
- `phpunit-<date>.log` — the complete test output of each update (the file to send when an update rolled back),
- `backup.log` — every backup,
- `php-errors.log`, `nginx-access.log`, `nginx-error.log` — the web server.

When something goes wrong, the time of the problem and the last lines of `app.log` are what the developer needs.

## Who can do this

Updates, backups and system status need the "Update, back up and view system status" permission; the network screen needs "Change network settings". Both belong to the Administrators group by default. Everything done on those pages is recorded in the audit log.
