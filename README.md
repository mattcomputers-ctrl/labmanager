# Chroma — server guide for Precision Ink

Chroma is the color lab's project and code-management system. This guide is for the person who sets up and looks after the server. It assumes you have never installed a web application before, so it spells out every command. Type the commands exactly as shown, one line at a time, and press Enter after each.

Words in `this kind of box` are things you type or file names. When a command starts with `sudo`, the server will sometimes ask for **your** Ubuntu password first (not the Chroma password).

---

## 1. What you need before you start

- A computer or virtual machine with a fresh **Ubuntu Server 24.04 LTS** installed. Any newer LTS release (26.04 …) also works. During the Ubuntu install, choose the defaults, create your own user (for example `matt`), and tick **Install OpenSSH server** so you can log in from your desk.
- The server plugged into the lab network with internet access (the installer downloads packages).
- 10 minutes.
- A pen. You will write down three things: the server's address, the admin username and the admin password.

## 2. Install Chroma (first time)

1. Log in to the server (at the console, or from your PC with `ssh matt@<server address>`).
2. Paste this line and press Enter:

   ```
   curl -fsSL https://raw.githubusercontent.com/mattcomputers-ctrl/labmanager/main/install.sh | sudo bash
   ```

3. Type your Ubuntu password when asked. The installer then asks a few questions. Each one shows a default in square brackets; pressing Enter accepts the default.

   | Question | What to answer | Write it down |
   |---|---|---|
   | Administrator username `[admin]` | The sign-in name for the first Chroma administrator, e.g. `matt`. | yes |
   | Administrator password (typed twice, hidden) | At least 10 characters. Nothing shows while you type; that is normal. | yes |
   | Give this server a fixed IP address now? `[n]` | `y` if the lab should always reach Chroma at the same address (recommended). `n` keeps the address the network hands out (DHCP). | – |
   | Interface | Press Enter (the installer lists the network card and suggests the right one). | – |
   | IP address with network size, e.g. `10.10.5.20/24` | The address IT reserved for Chroma, with `/24` after it (ask IT if unsure). | yes |
   | Gateway | The router address, usually the one shown as the default. | – |
   | DNS servers | Press Enter to keep the current ones. | – |

4. Wait. The installer prints what it is doing (`==> Installing packages …`). It takes 3–10 minutes. One step, "Mar-Kov CMS driver", is allowed to fail: Chroma works without it and the Admin screen will say "driver not installed".
5. At the end it prints a green **Chroma is installed** box with the address, the admin username and where the config and logs are. **Write down the address.**
6. If you chose a fixed IP, the last line says the network changes in 10 seconds. Your SSH session drops; that is expected. Reconnect to the new address.

If anything goes wrong, the installer stops with a red `ERROR` line that says what happened. Fix that (usually network or a typo) and run the same `curl … | sudo bash` line again — every step is safe to repeat, and it carries on where it stopped.

### Unattended install (no questions)

For a scripted setup, put the answers in environment variables:

```
sudo CHROMA_ADMIN_USER=matt CHROMA_ADMIN_PASSWORD='choose-a-long-one' \
     CHROMA_NET_IFACE=ens18 CHROMA_NET_IP=10.10.5.20/24 CHROMA_NET_GATEWAY=10.10.5.1 CHROMA_NET_DNS=10.10.5.2,1.1.1.1 \
     bash -c "curl -fsSL https://raw.githubusercontent.com/mattcomputers-ctrl/labmanager/main/install.sh | bash -s -- --unattended"
```

Use `CHROMA_NET_SKIP=1` instead of the `CHROMA_NET_*` variables to keep DHCP. If `CHROMA_ADMIN_PASSWORD` is not set, a random one is generated and printed in the summary on the screen only (it is deliberately kept out of `/var/log/chroma/install.log`), so capture the screen output.

## 3. Open Chroma and sign in

1. On any PC in the lab open a browser and go to `http://<the address>/` — for example `http://10.10.5.20/`.
2. Sign in with the admin username and password you wrote down.
3. Chroma asks you to **choose a new password** straight away. Pick one you will remember; the installer password is now gone.
4. You land on the home page in **Easy mode** (one question at a time). Power users can switch to **Expert mode** with the switch at the top of every page.

## 4. Create users for the lab

1. Go to **Admin → Users → Add user**.
2. Give each person a username, their name and a starting password; tick "must change password at first sign-in".
3. Put them in a group: **Lab** (projects, colors and code assignment), **R&D** (the same plus confidential R&D projects), **Sales** (look up codes, read projects), **Read only**, or **Administrators** (everything, including updates and the network). What each group may do is listed under **Admin → Users → Groups**.

Everyone can look up codes; only Lab, R&D and Administrators can hand them out.

## 5. Cutover day checklist (moving off the spreadsheets)

Do this on a quiet day, in this order:

1. **Freeze the spreadsheets.** Tell everyone: from now on no new codes go into the internal-code log, the sell-number cross-reference or the security-ink log. Save final copies of the three files.
2. **Back up Chroma** (empty is fine): on the server run `sudo -u chroma /opt/chroma/bin/backup`.
3. **Import the legacy codes.** In Chroma go to **Admin → Import legacy codes**. Upload the three spreadsheets, run the **dry run** first and read the report: it lists rows it could not understand and the "UVV → UVVL" rows to confirm. Fix anything that needs fixing in the sheets, dry-run again, then **Import for real**.
4. **Check the next numbers.** Go to **Admin → Sequences** (the next E number — it should be one more than the last one in the log, `E9102` on launch day) and **Admin → Prefixes** (next number per prefix, e.g. `Y`, `T`, `X`, `UV`). The import sets each to "last used + 1"; correct any that look wrong.
5. **Try it.** Assign one E number and one sell number from a test project, then void them with the reason "cutover test".
6. **Go live.** Announce the address, and that codes now come only from Chroma.
7. That evening, check that the nightly backup appeared in `/var/backups/chroma/` (see section 7).

## 6. Updating Chroma

When the developer says a new version is ready, run on the server:

```
sudo chroma-update
```

It fetches the new version, **takes a backup first**, installs it, updates the database and **runs the test-suite** (2–3 minutes). While the tests run, the new version is already the one in `/opt/chroma`, so a page opened during those minutes is served by the new version. If every test passes it reloads the web server and prints `Update complete: 0.1.0 → 0.1.1`; if a test fails it puts the previous version back by itself. Users see the new version on their next click; nobody needs to sign out. Run it at a quiet moment.

- `sudo chroma-update --check` only tells you whether an update exists (nothing changes).
- If the tests fail, the update **rolls itself back** to the previous version and says so in red. Chroma keeps working on the old version; send the file it names (`/var/log/chroma/phpunit-<date>.log`, the complete test output) to the developer.
- If it says the working tree has local changes, someone edited files in `/opt/chroma` by hand. The message shows the exact commands to throw those edits away or keep them (`sudo git -C /opt/chroma checkout -- .` throws them away).
- If it says `refusing to update: … writable by someone other than root`, somebody changed the owner of `/opt/chroma`. Run `sudo chown -R root:root /opt/chroma && sudo chmod -R go-w /opt/chroma` and update again. (The code is deliberately read-only for the web app: that is what stops a bug in a web page from changing the scripts the updater runs as root.)
- `sudo chroma-update --force` re-runs the update steps even when nothing new was fetched (useful after fixing a problem by hand).
- Administrators can also update from **Admin → System** in Chroma; it runs the same thing and shows the log on screen.

Re-running the original `curl … | sudo bash` line does exactly the same as `sudo chroma-update`.

## 7. Backups and restoring one

Every night at about 02:17 the server writes two files into `/var/backups/chroma/` and deletes any older than 30 days:

- `chroma-db-2026-10-27_021700.sql.gz` — the whole database (every code, project and user)
- `chroma-files-2026-10-27_021700.tar.gz` — the attachments (only when there are any)

To take one now: `sudo -u chroma /opt/chroma/bin/backup` (or **Admin → System → Back up now**).

**Copy the backups off the server** at least weekly — to the file server or a USB stick — because a dead disk takes `/var/backups` with it:

```
scp matt@10.10.5.20:/var/backups/chroma/chroma-db-2026-10-27_021700.sql.gz .
```

Also keep a copy of `/etc/chroma/config.env` (it holds the database password): `sudo cat /etc/chroma/config.env`.

### Restoring a backup

Only do this when something has really gone wrong (wrong import, lost disk). It replaces **everything** in the database with the backup.

```
sudo -u chroma /opt/chroma/bin/backup                      # 1. back up what is there now, just in case
sudo systemctl stop php8.3-fpm                              # 2. stop Chroma so nobody writes meanwhile
DBPASS=$(sudo grep '^DB_PASS=' /etc/chroma/config.env | cut -d= -f2-)
zcat /var/backups/chroma/chroma-db-2026-10-27_021700.sql.gz \
  | mysql -h 127.0.0.1 -u chroma -p"$DBPASS" chroma         # 3. load the backup (takes seconds)
sudo systemctl start php8.3-fpm                             # 4. start Chroma again
```

To restore attachments as well: `sudo tar -C /var/lib/chroma -xzf /var/backups/chroma/chroma-files-2026-10-27_021700.tar.gz` then `sudo chown -R chroma:chroma /var/lib/chroma/files`.

On a **new server**: install Chroma with the steps in section 2, then run the restore above with the backup file copied over. The config file on the new server has a new database password; that is fine, the commands read it from the file.

## 8. Where things are

| What | Where |
|---|---|
| Application code | `/opt/chroma` (a git checkout owned by root, read-only for the web app; do not edit files here, updates would refuse) |
| Settings (database password, app key, CMS connection) | `/etc/chroma/config.env` — readable only by root and the `chroma` user; never overwritten by updates |
| Attachments uploaded to projects | `/var/lib/chroma/files` |
| Logs | `/var/log/chroma/` — `app.log` (Chroma), `php-errors.log`, `nginx-access.log`, `nginx-error.log`, `install.log` (every install/update run), `phpunit-<date>.log` (the complete test output of each update), `backup.log`, `sqlsrv-install.log` (CMS driver). Rotated daily, kept 30 days. |
| Backups | `/var/backups/chroma/` |
| Web server and PHP | nginx (`/etc/nginx/sites-available/chroma`) and PHP-FPM pool (`/etc/php/8.3/fpm/pool.d/chroma.conf`) |
| Scheduled jobs | `systemctl list-timers chroma-*` — nightly backup and nightly CMS customer sync |
| Privileged helper used by Admin → System/Network | `/usr/local/sbin/chroma-helper` (only the `chroma` user may run it, via `/etc/sudoers.d/chroma`) |

Useful commands:

```
sudo tail -f /var/log/chroma/app.log          # watch Chroma's own log
sudo systemctl status nginx php8.3-fpm mariadb  # are the services running?
sudo systemctl list-timers 'chroma-*'         # when does the next backup run?
sudo journalctl -u chroma-backup.service      # output of the last backups
```

## 9. Changing the server's IP address later

Either in Chroma: **Admin → Network**, enter the new address, gateway and DNS, and press Apply. It validates the settings, then switches after a 10-second warning and shows the new address.

Or on the server:

```
sudo chroma-helper set-network ens18 10.10.5.21/24 10.10.5.1 10.10.5.2,1.1.1.1
```

(`ens18` is the network card name; `sudo chroma-helper network-status` shows it.) The settings are written to `/etc/netplan/60-chroma.yaml`; the previous file is kept as `60-chroma.yaml.previous`.

## 10. Removing Chroma

```
sudo chroma-uninstall            # removes the app and services; KEEPS the database, config, attachments and backups
sudo chroma-uninstall --purge    # also deletes the database, config, attachments, backups and logs — no undo
```

`chroma-uninstall` is the same script as `/opt/chroma/bin/uninstall`; the installer puts a copy in `/usr/local/sbin` so that `--purge` still works after a normal uninstall has removed `/opt/chroma`.

## 11. Troubleshooting

**The page does not load / "connection refused".**
`sudo systemctl status nginx php8.3-fpm mariadb`. Anything not `active (running)`: `sudo systemctl restart nginx php8.3-fpm mariadb`. Check the server address with `ip -4 addr`.

**"502 Bad Gateway".**
PHP is not running: `sudo systemctl restart php8.3-fpm`, then look at `sudo tail -50 /var/log/chroma/php-errors.log`.

**"Something went wrong" page in Chroma.**
Look at `sudo tail -50 /var/log/chroma/app.log`; the line with the time of the error says what failed. Send it to the developer.

**Forgot the admin password.**
On the server: `sudo -u chroma CHROMA_CONFIG=/etc/chroma/config.env php /opt/chroma/bin/create-admin matt` — it asks for a new password and makes `matt` an administrator (replace `matt` with the username).

**The update refuses because of local changes.**
Someone edited files under `/opt/chroma`. Run the commands the message shows (`sudo git -C /opt/chroma checkout -- .` throws the edits away) and update again.

**The update rolled back.**
The new version failed its tests on this server. Chroma is still on the previous version and fully usable. Send the file the red message names, `/var/log/chroma/phpunit-<date>.log` (the complete test output; the reason is often near the top), plus `/var/log/chroma/install.log`, to the developer. If the developer says it was a one-off, simply run `sudo chroma-update` again.

**The nightly backup did not appear.**
`sudo systemctl status chroma-backup.timer` should say `active (waiting)`. `sudo journalctl -u chroma-backup.service -n 30` shows why the last run failed (usually a full disk: `df -h`).

**Admin → CMS connection says "driver not installed".**
The Microsoft SQL Server driver could not be installed during setup (no internet, or the Microsoft package server was unreachable). Run `sudo chroma-update --force` with internet access; the installer tries again. Details are in `/var/log/chroma/sqlsrv-install.log`.

**Lost the server's address.**
At the server console: `ip -4 addr show` and look for the line starting `inet` on the network card (not `lo`).

**I need to start over.**
`sudo chroma-uninstall --purge`, then section 2 again.

---

## For developers

`CLAUDE.md` describes the code layout, conventions, migrations and tests. `SPEC.md` is the product spec. Installer decisions are in `docs/decisions/F.md`; what was and was not tested in `docs/INSTALL-TESTING.md`.
