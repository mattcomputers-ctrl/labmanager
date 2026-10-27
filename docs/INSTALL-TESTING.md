# Installer testing — what was tested and what could not be

Module F (install.sh, bin/chroma-helper, bin/chroma-update, bin/backup, bin/uninstall, deploy/*) was exercised for real
inside the build container on 2026-10-27. The container is **Ubuntu 24.04.5 LTS with root and apt, but without systemd,
netplan or ufw**, and its outbound HTTPS goes through a corporate-style proxy. Every service/netplan/ufw action in the
scripts goes through a small function that prints `WARN … skipped` when the tool is missing, so the run completes and
the skipped parts are visible in the log.

## How it was run

A git repository was made from a snapshot of the working tree (`/var/tmp/chroma-F/src-repo`, branch `main`) so the
installer could clone from a local path (`CHROMA_SOURCE=<path>`) instead of GitHub, and new commits could be made to
drive the update path. Database names were overridden so the shared dev database `chroma` was never touched:

```
sudo CHROMA_SOURCE=/var/tmp/chroma-F/src-repo CHROMA_BRANCH=main \
     CHROMA_DB_NAME=chroma_f2 CHROMA_DB_USER=chroma_f2 \
     CHROMA_ADMIN_USER=matt CHROMA_ADMIN_PASSWORD='matt-test-password-1' CHROMA_NET_SKIP=1 \
     bash install.sh --unattended
```

`CHROMA_SOURCE=<local path>`: the clone is made by root (with `safe.directory=*` for a local path only), so the path
just has to be readable by root; it no longer has to belong to the `chroma` user.

**Sandbox-only workaround**: the build container's proxy blocks GitHub zip downloads, so `composer install` falls back
to cloning every package over git and runs for a very long time (and fills the disk, as the reviewer saw). The composer
process was stopped, `/opt/chroma/vendor` was seeded from the dev checkout (minus the packages' `.git` directories),
and the installer was simply run again: it resumed, `composer install` found everything in place ("Nothing to install"),
and continued. On a real server with internet access this step just downloads from packagist.org.

## Re-test after the review (2026-10-27, second pass)

The review found that the web-app user owned `/opt/chroma`, so `sudo -n chroma-helper update` ran a chroma-writable
`install.sh` as root. The fix (root-owned checkout, see docs/decisions/F.md #19) and the other findings were re-tested
here in the same way:

| Check | Result |
|---|---|
| Ownership after install | `find /opt/chroma ! -user root` → nothing; `/opt/chroma`, `install.sh`, `bin/`, `deploy/`, `vendor/` root-owned, no group/world write; `/etc/chroma/config.env` chroma 0600; `/var/lib/chroma/tmp` chroma 0700; `/var/cache/chroma/composer` root. |
| Reviewer's exploit | As chroma: `sed -i '2i id -un > /tmp/pwn; exit 0' /opt/chroma/install.sh` → `Permission denied`. |
| Belt and braces | `chmod o+w /opt/chroma/install.sh` as root, then as chroma `sudo -n chroma-helper update` → exit 1 `refusing to update: /opt/chroma/install.sh is writable by someone other than root …`; `chroma-update --check` as root refuses the same way; nothing executed. |
| sudoers | `sudo -n chroma-update` as chroma → "a password is required"; only `chroma-helper` is allowed. |
| Helper via `sudo -n` as chroma | `check-update` (`up-to-date <hash>`), `backup` (prints the dump path), `list-backups` (dump **and** attachment tarball, newest first), `network-status` (JSON), `restart-services` (WARN without systemd), `update`. |
| Error messages | `chroma-helper check-update` as non-root → `…through sudo)` with no trailing ` 1`; `install.sh --branch` → `--branch needs a branch name` with no trailing ` 2`. |
| Installed site served by nginx + PHP-FPM (started by hand) | `/login` 200, `/` 303, `/assets/css/chroma.css` 200, `/.git/config` 403, `/foo.php` 404; `php-errors.log` empty. |
| `chroma-update --check` | `update-available c16110b 3c3b57d` + commit list. |
| Real update with a failing test (rollback) | Twice, for real: the first snapshot carried a mid-edit `CodeFormatTest` failure and a `LegacyImporterTest` that could not write into the now read-only tree; the second carried an outdated README. Each time: red box, `git reset --hard`, composer, `VERSION` back to 0.1.0, exit 1, and the message names `/var/log/chroma/phpunit-<date>.log` (root 0640) which holds the complete output including the early PHP warning lines that the 40-line tail used to hide. |
| Real update, green | `chroma-update` 0.1.0 → 0.1.1 as root (230 tests green on the installed copy, `Update complete: 0.1.0 (c16110b) -> 0.1.1 (14492e6)`), then 0.1.1 → 0.1.2 as the **chroma** user through `sudo -n chroma-helper update` (the Admin → System path; exit 0, 230 tests, `/opt/chroma` still 100 % root-owned afterwards). No-op re-run says "already up to date" (exit 0); `--check` says `up-to-date`. One extra rollback was my own doing: I ran the dev-tree suite at the same time and the importer's server-wide MariaDB `GET_LOCK` collided ("Another legacy import is running right now"); alone, the same update passed. |
| Test-suite as `chroma` | Runs with `TMPDIR=/var/lib/chroma/tmp` and `--do-not-cache-result`; no `/tmp` permission errors; the importer fixtures go to `tests/fixtures/legacy`, the one chroma-owned directory inside the tree (request #6 asks the importer module to use the temp dir instead). |
| Generated password | Fresh unattended install without `CHROMA_ADMIN_PASSWORD`: the summary on the screen shows `Generated password: …`; `/var/log/chroma/install.log` (root 0644) contains only `Generated password: (shown on the screen only; it is not written to the log)`. |
| Dirty working tree | `chroma-update --force` with an edited README refuses and prints the three `sudo git -C /opt/chroma …` commands (no `sudo -u chroma` any more; the tree is root's). |
| Keep-uninstall, then purge | `sudo chroma-uninstall --yes` removes `/opt/chroma`, services, helper, sudoers; keeps DB `chroma_f2`+`_test`, `/etc/chroma/config.env`, attachments, backups, logs — and leaves `/usr/local/sbin/chroma-uninstall` so that `sudo chroma-uninstall --purge` still works (it did: databases and DB user dropped, directories and `/var/cache/chroma` gone, `chroma-uninstall` removed last). First purge attempt could not `userdel chroma` because the hand-started PHP-FPM master kept respawning pool workers (no systemd reload here); `bin/uninstall` now waits for the workers and falls back to `userdel -f`, and the message says to stop php-fpm. |
| Prompt-time network checks | Not interactively testable here; the env-var path was exercised: `CHROMA_NET_IP=10.10.5.20/24 CHROMA_NET_GATEWAY=10.10.6.1` dies at the prompt step with "Gateway 10.10.6.1 is not inside the network …" before anything is installed (same function the interactive loop uses). |
| Tests | `tests/InstallerTest.php`: 20 tests (1 skipped as root) incl. the new root-owned/app-writable refusal, both-files `list-backups`, message-without-exit-code, wrapper-accepts-comma and uninstall/README checks; full suite 230 tests green in the dev tree. shellcheck `-S warning` clean on all five scripts. |

## Tested and working

| Step | Result |
|---|---|
| OS check | Ubuntu 24.04 accepted (refuses non-Ubuntu and < 24; warns on non-LTS). |
| apt install (git, nginx, mariadb-server/client, php8.3-fpm + mysql/mbstring/xml/curl/zip/intl/bcmath/gd, composer, unzip) | Installed from scratch in the container (`policy-rc.d` prevented service starts, as expected without systemd). |
| Microsoft ODBC Driver 18 + pdo_sqlsrv via PECL | **Installed for real**: Microsoft apt repo added, `msodbcsql18` installed, `sqlsrv`/`pdo_sqlsrv` compiled with PECL, `php -m` lists `pdo_sqlsrv`; `cms_driver_status = installed` written to `chroma_settings`. A first attempt failed because pecl's own downloader got "426 Upgrade Required" through the proxy; the script now fetches the archives with curl. The failure path was also seen: it is non-fatal, logged to `/var/log/chroma/sqlsrv-install.log`, and the half-enabled extension is removed so `php` prints no warnings. |
| System user, directories | `chroma` system user, `/var/lib/chroma/{files,.composer}`, `/var/log/chroma`, `/var/backups/chroma` owned by chroma. |
| Clone to /opt/chroma, branch selection | Cloned as the chroma user; `--branch` / `CHROMA_BRANCH` honoured. |
| MariaDB already running (mysqld_safe, no systemd) | Detected with `mysqladmin ping`; databases `chroma_install` and `chroma_install_test`, user with generated 28-char password, grants. |
| `/etc/chroma/config.env` | Written once, mode 600, owner chroma; left untouched on every later run (ownership/mode re-asserted). |
| composer install (as chroma, clean env, proxy vars passed through) | Worked (packages came from the composer VCS cache because GitHub zip downloads are blocked in this sandbox; on a normal server they come from packagist). |
| bin/migrate + seed, bin/create-admin | Tables created, `matt` created in Administrators with must-change-password. |
| nginx site + PHP-FPM pool | `nginx -t` and `php-fpm8.3 -t` pass. nginx and php-fpm were then **started by hand** in the container and the site was served: `/login` 200, `/` 303 to login, `/assets/css/chroma.css` 200, dot-files 403, other `.php` paths 404. Help page screenshots were taken through this real nginx + PHP-FPM stack. |
| Helper, updater, uninstaller, sudoers | Installed to `/usr/local/sbin` (chroma-helper, chroma-update, chroma-uninstall); `visudo -cf` passes; as the `chroma` user `sudo -n chroma-helper list-backups` works, `sudo -n chroma-update` and `sudo -n ls` are refused ("a password is required"). |
| Every helper command | `check-update` (up-to-date / update-available), `backup` (prints path), `list-backups` (path, bytes, time; newest first), `network-status` (real JSON from `ip -j`), `restart-services` (WARN without systemd), `set-network` (strict validation incl. gateway-in-subnet; WARN + `not-applied` without netplan), `set-network <iface> dhcp`, unknown/extra arguments → exit 2. |
| First-run summary | URL, admin user, config/log/backup locations, update command printed. Marker `/etc/chroma/installed` written. |
| Re-run = update, nothing new | `chroma-update --check` prints `up-to-date <hash>`; `chroma-update` says "already up to date", exit 0. |
| Update with a new commit | `--check` prints `update-available <old> <new>` plus the commit list; `chroma-update` takes a backup, `git merge --ff-only`, re-executes the new install.sh, composer, migrate, PHPUnit against `chroma_install_test`, re-installs deploy files, prints `Update complete: 0.1.1 (…) -> 0.1.2 (…)`. Also run through `sudo -n chroma-helper update` as the chroma user (the Admin screen path): same result, 78 tests green. |
| **Rollback on test failure** | Happened for real: the first snapshot carried another module's failing test; the updater printed the red "UPDATE FAILED … rolling back" box, `git reset --hard` to the old commit, composer, exit 1; `/opt/chroma/VERSION` back to the old version. |
| Dirty working tree | A modified tracked file makes the update refuse with the three commands to see/throw away/stash the changes. Untracked files are ignored. |
| `--force`, `--help`, unknown flag, non-root | `--force` re-runs the steps on an up-to-date install; `--help` exit 0; `--bogus` exit 2; non-root → clear error. |
| Resumable first run | A run that stopped half-way (composer failure) was resumed by running the same command: existing config and DB password kept, steps continued. |
| bin/backup | Dump + prune as the chroma user via config; `--print-path`; `backup.log` written; wrong password → exit 1 with message. Covered by `tests/InstallerTest.php` against the test database too. |
| bin/uninstall | Without `--yes` and no terminal: refuses, nothing changed. `--yes`: removes app, services, helper, sudoers, nginx/fpm config; keeps DB, config, attachments, backups (verified). `--purge --yes`: drops both databases and the DB user, deletes config/attachments/backups/logs and the system user (verified; the shared dev database `chroma` and its user were untouched). |
| shellcheck | `shellcheck -S warning` clean on all five shell files (also asserted by the PHPUnit test when shellcheck is installed). |

## Could NOT be tested here (needs a real Ubuntu Server VM)

- **systemd**: `systemctl enable/start/reload` of mariadb, nginx, php8.3-fpm, and the `chroma-backup.timer` / `chroma-cms-sync.timer` units. The unit files were installed to `/etc/systemd/system` and are syntactically simple; they have not been loaded by systemd.
- **netplan**: writing `/etc/netplan/60-chroma.yaml`, `netplan generate` validation, the 10-second delayed `netplan apply`, and the SSH-drop/new-URL behaviour. The YAML produced was reviewed by eye only.
- **ufw**: `ufw allow 80/tcp` / `22/tcp` when ufw is active.
- **logrotate**: logrotate is not installed in the container; the config was reviewed by eye.
- **Interactive prompts** (reading from `/dev/tty` behind `curl | sudo bash`): the code path exists but the container has no tty; only the unattended path ran.
- **Cloning from GitHub over HTTPS** and composer downloads from packagist/GitHub (the sandbox proxy blocks GitHub zip downloads); a local-path clone was used.
- **A genuinely fresh machine**: MariaDB, PHP CLI and composer were already present before the run; nginx, php8.3-fpm and the PHP extensions were installed by the script.

## Suggested VM test plan (10 minutes)

1. Fresh Ubuntu Server 24.04 VM, one NIC on DHCP, snapshot it.
2. `curl -fsSL …/install.sh | sudo bash`, answer the prompts, choose a static IP. Expect the SSH session to drop after the 10-second warning and the site to answer at the new address.
3. `sudo systemctl list-timers 'chroma-*'` shows both timers; `sudo systemctl start chroma-backup.service` writes a file into `/var/backups/chroma`.
4. Push a trivial commit, `sudo chroma-update --check`, then `sudo chroma-update`.
5. `sudo chroma-helper set-network <iface> dhcp` and back to static from Admin → Network.
6. Revert to the snapshot.
