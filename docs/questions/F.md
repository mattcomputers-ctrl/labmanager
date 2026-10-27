# Questions for Matt — module F (installer / server)

1. **Backup copies off the server.** The nightly backup lands in `/var/backups/chroma/` on the Chroma server only. Do you want the installer to also copy it to a network share (SMB path + credentials) or is a weekly manual `scp` / download from Admin → System acceptable? *Default: manual.*
2. **Backup time.** Nightly at 02:17 (server local time) — fine, or should it run after the last shift instead?
3. **Static IP during install.** The installer offers to set a fixed address. Should IT reserve one (DHCP reservation) instead, so the "keep DHCP" default is the right answer? *Default: offer both, default to keeping DHCP.*
4. **Branch for production.** Updates follow `main`. If you want a slower "release" branch for the lab server, say so and we set `CHROMA_BRANCH=release` on the server (or `sudo chroma-update --branch release`). *Default: main.*
5. **HTTPS.** v1 serves plain HTTP on port 80 on the LAN. Do you want a self-signed certificate on port 443 as well (browsers will warn once), or wait for a proper internal CA? *Default: HTTP only.*
