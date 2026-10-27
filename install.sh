#!/usr/bin/env bash
# =============================================================================
# Chroma installer AND updater (SPEC.md section 7).
#
#   First run (fresh Ubuntu Server 24.04+):
#     curl -fsSL https://raw.githubusercontent.com/mattcomputers-ctrl/labmanager/main/install.sh | sudo bash
#
#   Update (re-run = update):
#     sudo chroma-update             # wrapper installed to /usr/local/sbin
#     sudo bash /opt/chroma/install.sh
#
# Flags:   --check            report whether an update is available, change nothing
#          --force            update even when nothing new was fetched
#          --branch <name>    install/update from this git branch (default: main)
#          --unattended       never prompt; take values from environment variables
#          --help             this text
#
# Environment variables (all optional; they replace the prompts):
#   CHROMA_ADMIN_USER, CHROMA_ADMIN_PASSWORD (min 10 chars)
#   CHROMA_NET_SKIP=1 (keep DHCP) or CHROMA_NET_IFACE, CHROMA_NET_IP (a.b.c.d/nn),
#   CHROMA_NET_GATEWAY, CHROMA_NET_DNS (comma separated)
#   CHROMA_BRANCH, CHROMA_SOURCE (git URL, or a local path for testing: the clone is
#     made by root, so the path only has to be readable by root)
#   CHROMA_SKIP_SQLSRV=1 (do not try to install the Mar-Kov CMS driver)
#   CHROMA_DB_NAME, CHROMA_DB_USER, CHROMA_TEST_DB_NAME, CHROMA_MYSQL_ROOT_PASSWORD
#   CHROMA_APP_DIR, CHROMA_CONFIG_DIR (for testing on a shared machine)
#
# Ownership model: /opt/chroma (code, vendor, install.sh, deploy files) is owned by
# ROOT and is read-only for the web-app user. git and composer run as root; PHP
# (migrate, create-admin, the test-suite, backups) runs as the chroma user. The
# chroma user can only write to /var/lib/chroma, /var/log/chroma, /var/backups/chroma
# and /etc/chroma/config.env. So a bug in the web app cannot change the scripts that
# root later executes through chroma-helper (see docs/decisions/F.md #19).
#
# Everything that touches systemd, netplan or ufw goes through a small function
# that prints a WARN and carries on when that tool is not available (containers).
# =============================================================================
set -Eeuo pipefail

APP_DIR="${CHROMA_APP_DIR:-/opt/chroma}"
CONFIG_DIR="${CHROMA_CONFIG_DIR:-/etc/chroma}"
CONFIG_FILE="$CONFIG_DIR/config.env"
INSTALLED_MARKER="$CONFIG_DIR/installed"   # written at the end of a successful first run
APP_USER="${CHROMA_APP_USER:-chroma}"
APP_HOME="${CHROMA_APP_HOME:-/var/lib/chroma}"
FILES_DIR="${CHROMA_FILES_DIR:-$APP_HOME/files}"
LOG_DIR="${CHROMA_LOG_DIR:-/var/log/chroma}"
BACKUP_DIR="${CHROMA_BACKUP_DIR:-/var/backups/chroma}"
SBIN_DIR="${CHROMA_SBIN_DIR:-/usr/local/sbin}"
COMPOSER_DIR="${CHROMA_COMPOSER_HOME:-/var/cache/chroma/composer}"   # root's composer home/cache
APP_TMP="$APP_HOME/tmp"       # TMPDIR for everything run as the app user (the test-suite writes here)
REPO_URL="${CHROMA_SOURCE:-https://github.com/mattcomputers-ctrl/labmanager.git}"
BRANCH="${CHROMA_BRANCH:-main}"
DB_NAME="${CHROMA_DB_NAME:-chroma}"
DB_USER="${CHROMA_DB_USER:-chroma}"
TEST_DB_NAME="${CHROMA_TEST_DB_NAME:-${DB_NAME}_test}"
PHP_VER="8.3"
INSTALL_LOG="$LOG_DIR/install.log"

MODE_CHECK=0
MODE_FORCE=0
UNATTENDED=0
CONTINUE_FROM=""      # internal: --continue-update <old-commit> (set by the pull step)
BRANCH_FLAG=""
INTERACTIVE=0

ADMIN_USER=""
ADMIN_PASSWORD=""
ADMIN_PASSWORD_GENERATED=0
NET_SKIP=1
NET_IFACE=""
NET_IP=""
NET_GATEWAY=""
NET_DNS=""
CMS_DRIVER_STATUS="not installed"
TEST_LOG=""          # set by run_tests: the complete PHPUnit output of this update

# ----------------------------------------------------------------------------- output
c_bold=$'\033[1m'; c_red=$'\033[31m'; c_yel=$'\033[33m'; c_grn=$'\033[32m'; c_off=$'\033[0m'
if [ ! -t 1 ]; then c_bold=""; c_red=""; c_yel=""; c_grn=""; c_off=""; fi
log()  { printf '%s[chroma]%s %s\n' "$c_bold" "$c_off" "$*"; }
ok()   { printf '%s[chroma] OK%s   %s\n' "$c_grn" "$c_off" "$*"; }
warn() { printf '%s[chroma] WARN%s %s\n' "$c_yel" "$c_off" "$*" >&2; }
die()  { printf '%s[chroma] ERROR%s %s\n' "$c_red" "$c_off" "$1" >&2; exit "${2:-1}"; }   # die "<message>" [exit-code]
step() { printf '\n%s==> %s%s\n' "$c_bold" "$*" "$c_off"; }

usage() {
    if [ -r "${BASH_SOURCE[0]:-}" ]; then
        sed -n '2,/^set -Eeuo/p' "${BASH_SOURCE[0]}" | sed '$d' | grep -v '^# =====' | sed 's/^# \{0,1\}//'
    else
        echo "Chroma installer and updater. Flags: --check --force --branch <name> --unattended --help"
    fi
}

ERROR_SHOWN=0
on_error() {
    local line=$1
    [ "$ERROR_SHOWN" -eq 0 ] || return 0
    ERROR_SHOWN=1
    printf '%s[chroma] ERROR%s the installer stopped at line %s. The log is %s\n' "$c_red" "$c_off" "$line" "$INSTALL_LOG" >&2
    printf '%s[chroma]%s Fix the problem above and run the same command again; every step is safe to repeat.\n' "$c_bold" "$c_off" >&2
}

# ----------------------------------------------------------------------------- environment probes
have_systemd() { [ -d /run/systemd/system ] && command -v systemctl >/dev/null 2>&1; }
have_netplan() { command -v netplan >/dev/null 2>&1; }

# svc <systemctl args...>: run systemctl, or WARN when systemd is not running (containers).
# SVC_SKIPPED remembers that something was skipped so the step summaries can say so.
SVC_SKIPPED=0
svc() {
    if have_systemd; then
        systemctl "$@"
    else
        SVC_SKIPPED=1
        warn "systemd is not running on this machine; skipped: systemctl $*"
    fi
}

# Proxy / CA variables are passed through to child processes so composer, git and curl
# work behind a corporate proxy. Everything else of root's environment is dropped.
proxy_envs() {
    local v
    for v in http_proxy https_proxy HTTP_PROXY HTTPS_PROXY no_proxy NO_PROXY SSL_CERT_FILE CURL_CA_BUNDLE GIT_SSL_CAINFO COMPOSER_CAFILE COMPOSER_AUTH; do
        if [ -n "${!v:-}" ]; then printf '%s=%s\n' "$v" "${!v}"; fi
    done
}

# Run a command as the app user with a clean, known environment (PHP: migrate, create-admin,
# the test-suite, backups). TMPDIR points at a directory only the app user writes to, so
# files left in /tmp by a root-run test-suite can never break a later update.
run_as_app() {
    local -a envs=("HOME=$APP_HOME" "TMPDIR=$APP_TMP" "CHROMA_CONFIG=$CONFIG_FILE" "PATH=/usr/local/bin:/usr/bin:/bin" "LANG=C.UTF-8")
    mapfile -t -O "${#envs[@]}" envs < <(proxy_envs)
    runuser -u "$APP_USER" -- env -i "${envs[@]}" "$@"
}

# Run git/composer as ROOT with a clean environment: the checkout in $APP_DIR is root-owned
# and read-only for the app user (see the ownership model at the top of this file).
run_clean_root() {
    local -a envs=("HOME=/root" "COMPOSER_HOME=$COMPOSER_DIR" "COMPOSER_ALLOW_SUPERUSER=1" "COMPOSER_NO_INTERACTION=1" "PATH=/usr/local/bin:/usr/bin:/bin" "LANG=C.UTF-8")
    mapfile -t -O "${#envs[@]}" envs < <(proxy_envs)
    env -i "${envs[@]}" "$@"
}

git_app() { run_clean_root git -C "$APP_DIR" "$@"; }

# The scripts root executes (install.sh, bin/*, deploy/*) must not be writable by the app
# user; otherwise a bug in the web app would become root through `chroma-helper update`.
# Prints the offending paths and returns 1 when something is wrong.
app_writable_paths() {
    local p
    [ -e "$APP_DIR" ] && find "$APP_DIR" -maxdepth 0 \( ! -user root -o -perm /022 \) -print 2>/dev/null
    for p in "$APP_DIR/install.sh" "$APP_DIR/bin" "$APP_DIR/deploy" "$APP_DIR/.git/hooks" "$APP_DIR/.git/config"; do
        [ -e "$p" ] || continue
        find "$p" ! -type l \( ! -user root -o -perm /022 \) -print 2>/dev/null
    done
}
assert_tree_root_owned() {
    local bad; bad=$(app_writable_paths | head -n 5)
    [ -z "$bad" ] || die "These files in $APP_DIR are writable by someone other than root, so they will not be run or installed as root:
$bad
       Fix with:  sudo chown -R root:root $APP_DIR && sudo chmod -R go-w $APP_DIR   (then run the update again)"
}
make_tree_root_owned() {
    chown -R root:root "$APP_DIR"
    chmod -R go-w "$APP_DIR"
}

mysql_root() {
    if [ -n "${CHROMA_MYSQL_ROOT_PASSWORD:-}" ]; then
        MYSQL_PWD="$CHROMA_MYSQL_ROOT_PASSWORD" mysql -uroot "$@"
    else
        mysql -uroot "$@"
    fi
}

random_token() { openssl rand -base64 96 | tr -dc 'A-Za-z0-9' | cut -c1-"${1:-32}"; }

# Read a config.env value (same rules as src/Core/Config.php: KEY=VALUE, optional quotes).
cfg_get() {
    local key=$1 file=${2:-$CONFIG_FILE} val
    val=$(grep -E "^(export )?${key}=" "$file" 2>/dev/null | tail -n1 | cut -d= -f2- || true)
    val=${val#\"}; val=${val%\"}; val=${val#\'}; val=${val%\'}
    printf '%s' "$val"
}

app_version() {
    local v="" c=""
    [ -f "$APP_DIR/VERSION" ] && v=$(tr -d '[:space:]' <"$APP_DIR/VERSION")
    c=$(git_app rev-parse --short HEAD 2>/dev/null || true)
    printf '%s (%s)' "${v:-?}" "${c:-?}"
}

# ----------------------------------------------------------------------------- validation
valid_ipv4() {
    local ip=$1 o
    [[ $ip =~ ^([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})$ ]] || return 1
    for o in "${BASH_REMATCH[@]:1:4}"; do [ "$o" -le 255 ] || return 1; done
}
valid_cidr() {
    [[ $1 =~ ^([0-9.]+)/([0-9]{1,2})$ ]] || return 1
    local ip=${BASH_REMATCH[1]} prefix=${BASH_REMATCH[2]}   # saved: valid_ipv4 overwrites BASH_REMATCH
    valid_ipv4 "$ip" && [ "$prefix" -ge 1 ] && [ "$prefix" -le 32 ]
}
valid_dns_list() {
    local list=$1 d n=0
    [ -n "$list" ] || return 1
    IFS=',' read -r -a parts <<<"$list"
    for d in "${parts[@]}"; do
        d=${d// /}
        [ -n "$d" ] || continue
        valid_ipv4 "$d" || return 1
        n=$((n + 1))
    done
    [ "$n" -ge 1 ] && [ "$n" -le 3 ]
}
valid_branch() { [[ $1 =~ ^[A-Za-z0-9][A-Za-z0-9._/-]{0,100}$ ]] && [[ $1 != *..* ]]; }
valid_username() { [[ $1 =~ ^[A-Za-z0-9][A-Za-z0-9._-]{1,49}$ ]]; }
ip_to_int() { local a b c d; IFS=. read -r a b c d <<<"$1"; echo $(( (a << 24) + (b << 16) + (c << 8) + d )); }
same_subnet() { # same_subnet <ip> <prefix> <other-ip>
    local mask=$(( 0xFFFFFFFF << (32 - $2) & 0xFFFFFFFF ))
    [ $(( $(ip_to_int "$1") & mask )) -eq $(( $(ip_to_int "$3") & mask )) ]
}
# The same rules chroma-helper set-network applies, checked at prompt time so a wrong
# answer is caught before anything is installed (not at the very last step).
# Prints the reason and returns 1 when the combination cannot work.
static_network_problem() { # static_network_problem <ip/cidr> <gateway> <dns-list>
    local cidr=$1 gw=$2 dns=$3 ip prefix
    valid_cidr "$cidr" || { echo "The IP address must look like 10.10.5.20/24 (got '$cidr')."; return 1; }
    valid_ipv4 "$gw" || { echo "The gateway must be an IPv4 address such as 10.10.5.1 (got '$gw')."; return 1; }
    valid_dns_list "$dns" || { echo "Give one to three DNS servers (IPv4) separated by commas, e.g. 10.10.5.2,1.1.1.1 (got '$dns')."; return 1; }
    ip=${cidr%%/*}; prefix=${cidr##*/}
    case "$ip" in 0.*|127.*|255.*) echo "$ip is not a usable server address."; return 1 ;; esac
    [ "$prefix" -le 30 ] || { echo "A /$prefix network has no room for a gateway; use /24 or similar."; return 1; }
    [ "$ip" != "$gw" ] || { echo "The gateway cannot be the same as the server's own address."; return 1; }
    same_subnet "$ip" "$prefix" "$gw" || { echo "Gateway $gw is not inside the network $cidr; check the address or the /$prefix size."; return 1; }
    return 0
}

# ----------------------------------------------------------------------------- prompts
# Prompts read from /dev/tty so they work with `curl ... | sudo bash`.
ask() { # ask "<question>" "<default>" -> prints answer
    local q=$1 def=${2:-} ans
    if [ -n "$def" ]; then printf '%s [%s]: ' "$q" "$def" >/dev/tty; else printf '%s: ' "$q" >/dev/tty; fi
    IFS= read -r ans </dev/tty || ans=""
    printf '%s' "${ans:-$def}"
}
ask_secret() {
    local q=$1 ans
    printf '%s: ' "$q" >/dev/tty
    IFS= read -r -s ans </dev/tty || ans=""
    printf '\n' >/dev/tty
    printf '%s' "$ans"
}

detect_interactive() {
    INTERACTIVE=0
    if [ "$UNATTENDED" -eq 0 ] && [ -r /dev/tty ] && [ -w /dev/tty ] && (: </dev/tty) 2>/dev/null; then
        INTERACTIVE=1
    fi
}

collect_admin_settings() {
    ADMIN_USER="${CHROMA_ADMIN_USER:-}"
    ADMIN_PASSWORD="${CHROMA_ADMIN_PASSWORD:-}"
    if [ "$INTERACTIVE" -eq 1 ]; then
        local def_user="${ADMIN_USER:-admin}"
        while :; do
            ADMIN_USER=$(ask "Administrator username" "$def_user")
            valid_username "$ADMIN_USER" && break
            echo "  Use 2-50 letters, digits, dots, dashes or underscores." >/dev/tty
            def_user="admin"   # never offer the rejected answer as the default
        done
        while [ -z "$ADMIN_PASSWORD" ]; do
            local p1 p2
            p1=$(ask_secret "Administrator password (at least 10 characters, typed hidden)")
            if [ "${#p1}" -lt 10 ]; then echo "  Too short: use at least 10 characters." >/dev/tty; continue; fi
            p2=$(ask_secret "Type the password again")
            if [ "$p1" != "$p2" ]; then echo "  The two passwords do not match; try again." >/dev/tty; continue; fi
            ADMIN_PASSWORD=$p1
        done
    else
        ADMIN_USER=${ADMIN_USER:-admin}
        valid_username "$ADMIN_USER" || die "CHROMA_ADMIN_USER must be 2-50 letters, digits, dots, dashes or underscores."
        if [ -z "$ADMIN_PASSWORD" ]; then
            ADMIN_PASSWORD=$(random_token 16)
            ADMIN_PASSWORD_GENERATED=1
            warn "CHROMA_ADMIN_PASSWORD is not set; a random password was generated and is printed in the summary."
        fi
    fi
    [ "${#ADMIN_PASSWORD}" -ge 10 ] || die "The administrator password must be at least 10 characters (CHROMA_ADMIN_PASSWORD)."
}

list_interfaces() {
    local d
    for d in /sys/class/net/*; do
        d=$(basename "$d")
        case "$d" in lo|veth*|docker*|br-*|virbr*|ifb*|tun*|tap*|wg*) continue ;; esac
        [ "$(cat "/sys/class/net/$d/type" 2>/dev/null)" = "1" ] || continue
        printf '%s\n' "$d"
    done
}

current_ip_of() { ip -4 -o addr show dev "$1" 2>/dev/null | awk '{print $4}' | head -n1; }
current_gateway() { ip -4 route show default 2>/dev/null | awk '{print $3}' | head -n1; }
current_dns() {
    local d=""
    if command -v resolvectl >/dev/null 2>&1; then
        d=$(resolvectl dns 2>/dev/null | awk -F': ' '/Link/ && $2 != "" {print $2; exit}' | tr ' ' ',')
    fi
    [ -n "$d" ] || d=$(awk '/^nameserver/ {print $2}' /etc/resolv.conf 2>/dev/null | grep -v '^127\.' | head -n3 | paste -sd, -)
    printf '%s' "$d"
}

collect_network_settings() {
    NET_SKIP=1
    if [ "${CHROMA_NET_SKIP:-0}" = "1" ]; then
        log "Network: keeping the current (DHCP) configuration (CHROMA_NET_SKIP=1)."
        return
    fi
    if [ -n "${CHROMA_NET_IP:-}" ]; then
        NET_IFACE="${CHROMA_NET_IFACE:-$(list_interfaces | head -n1)}"
        NET_IP="$CHROMA_NET_IP"; NET_GATEWAY="${CHROMA_NET_GATEWAY:-}"; NET_DNS="${CHROMA_NET_DNS// /}"
        local problem
        problem=$(static_network_problem "$NET_IP" "$NET_GATEWAY" "$NET_DNS") || die "CHROMA_NET_IP / CHROMA_NET_GATEWAY / CHROMA_NET_DNS: $problem"
        [ -n "$NET_IFACE" ] || die "CHROMA_NET_IFACE is not set and no network interface was found."
        [ -d "/sys/class/net/$NET_IFACE" ] || die "CHROMA_NET_IFACE=$NET_IFACE: there is no such network interface on this server (see: ip link)."
        NET_SKIP=0
        return
    fi
    if [ "$INTERACTIVE" -eq 0 ]; then
        log "Network: no CHROMA_NET_* variables set; keeping DHCP."
        return
    fi
    echo >/dev/tty
    echo "Network interfaces on this server:" >/dev/tty
    local i
    while read -r i; do
        printf '  %-10s %s\n' "$i" "$(current_ip_of "$i")" >/dev/tty
    done < <(list_interfaces)
    local choice
    choice=$(ask "Give this server a fixed IP address now? (y = yes, n = keep DHCP / skip)" "n")
    case "$choice" in y|Y|yes|YES) ;; *) log "Network: keeping DHCP."; return ;; esac
    local first; first=$(list_interfaces | head -n1)
    while :; do
        NET_IFACE=$(ask "Interface" "$first")
        [ -d "/sys/class/net/$NET_IFACE" ] && break
        echo "  There is no interface called $NET_IFACE." >/dev/tty
    done
    local cur; cur=$(current_ip_of "$NET_IFACE")
    while :; do
        NET_IP=$(ask "IP address with network size, e.g. 10.10.5.20/24" "$cur")
        valid_cidr "$NET_IP" || { echo "  That is not a valid address. Use the form a.b.c.d/nn, for example 10.10.5.20/24." >/dev/tty; continue; }
        case "${NET_IP%%/*}" in 0.*|127.*|255.*) echo "  ${NET_IP%%/*} is not a usable server address." >/dev/tty; continue ;; esac
        [ "${NET_IP##*/}" -le 30 ] || { echo "  A /${NET_IP##*/} network has no room for a gateway; use /24 or similar." >/dev/tty; continue; }
        break
    done
    while :; do
        NET_GATEWAY=$(ask "Gateway (the router's address)" "$(current_gateway)")
        valid_ipv4 "$NET_GATEWAY" || { echo "  That is not a valid IPv4 address." >/dev/tty; continue; }
        # Same checks as chroma-helper set-network, so a wrong gateway is caught here and not after the install.
        local problem
        if problem=$(static_network_problem "$NET_IP" "$NET_GATEWAY" "1.1.1.1"); then break; fi
        echo "  $problem" >/dev/tty
    done
    while :; do
        NET_DNS=$(ask "DNS servers, comma separated" "$(current_dns)")
        NET_DNS=${NET_DNS// /}
        valid_dns_list "$NET_DNS" && break
        echo "  Give one to three IPv4 addresses separated by commas, e.g. 10.10.5.2,1.1.1.1" >/dev/tty
    done
    NET_SKIP=0
}

# ----------------------------------------------------------------------------- first-run steps
check_os() {
    step "Checking the operating system"
    [ -r /etc/os-release ] || die "Cannot read /etc/os-release. Chroma needs Ubuntu Server 24.04 LTS or a newer LTS release."
    # shellcheck disable=SC1091
    . /etc/os-release
    [ "${ID:-}" = "ubuntu" ] || die "This is ${PRETTY_NAME:-not Ubuntu}. Chroma installs only on Ubuntu Server 24.04 LTS or a newer LTS release."
    local major minor
    major=${VERSION_ID%%.*}; minor=${VERSION_ID#*.}
    if [ "${major:-0}" -lt 24 ]; then
        die "This is Ubuntu ${VERSION_ID}. Chroma needs Ubuntu 24.04 LTS or newer. Install a fresh Ubuntu Server 24.04 LTS and run this again."
    fi
    if [ "$minor" != "04" ] || [ $((major % 2)) -ne 0 ]; then
        warn "Ubuntu ${VERSION_ID} is not an LTS release. Chroma is tested on 24.04 LTS; continuing anyway."
    fi
    ok "Ubuntu ${VERSION_ID} (${VERSION_CODENAME:-})"
    [ "$(uname -m)" = "x86_64" ] || warn "Architecture $(uname -m): the Microsoft ODBC driver may not be available here."
}

install_packages() {
    step "Installing packages with apt (git, nginx, MariaDB, PHP ${PHP_VER}, composer)"
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -q
    apt-get install -y -q --no-install-recommends \
        ca-certificates curl gnupg git unzip rsync \
        nginx mariadb-server mariadb-client \
        "php${PHP_VER}-fpm" "php${PHP_VER}-cli" "php${PHP_VER}-mysql" "php${PHP_VER}-mbstring" "php${PHP_VER}-xml" \
        "php${PHP_VER}-curl" "php${PHP_VER}-zip" "php${PHP_VER}-intl" "php${PHP_VER}-bcmath" "php${PHP_VER}-gd" \
        composer
    ok "Packages installed"
}

install_sqlsrv_driver() {
    step "Mar-Kov CMS driver (Microsoft ODBC Driver 18 + pdo_sqlsrv) - optional"
    if php -m 2>/dev/null | grep -qi '^pdo_sqlsrv$'; then
        CMS_DRIVER_STATUS="installed"; ok "pdo_sqlsrv is already installed"; return
    fi
    if [ "${CHROMA_SKIP_SQLSRV:-0}" = "1" ]; then
        CMS_DRIVER_STATUS="not installed (skipped with CHROMA_SKIP_SQLSRV=1)"; warn "$CMS_DRIVER_STATUS"; return
    fi
    local dlog="$LOG_DIR/sqlsrv-install.log"
    log "Trying to install the driver; this is not required and the details go to $dlog"
    if (
        set -e
        export DEBIAN_FRONTEND=noninteractive ACCEPT_EULA=Y
        . /etc/os-release
        curl -fsSL https://packages.microsoft.com/keys/microsoft.asc | gpg --dearmor --yes -o /usr/share/keyrings/microsoft-prod.gpg
        echo "deb [arch=amd64 signed-by=/usr/share/keyrings/microsoft-prod.gpg] https://packages.microsoft.com/ubuntu/${VERSION_ID}/prod ${VERSION_CODENAME} main" \
            >/etc/apt/sources.list.d/mssql-release.list
        apt-get update -q
        apt-get install -y -q msodbcsql18 unixodbc-dev "php${PHP_VER}-dev" php-pear build-essential
        pecl channel-update pecl.php.net || true
        # pecl's own downloader does not cope with every proxy; fetch the archives with curl instead.
        tmpd=$(mktemp -d)
        curl -fsSL https://pecl.php.net/get/sqlsrv -o "$tmpd/sqlsrv.tgz"
        curl -fsSL https://pecl.php.net/get/pdo_sqlsrv -o "$tmpd/pdo_sqlsrv.tgz"
        printf '\n' | pecl install -f "$tmpd/sqlsrv.tgz"
        printf '\n' | pecl install -f "$tmpd/pdo_sqlsrv.tgz"
        rm -rf "$tmpd"
        ext_dir=$(php -r 'echo ini_get("extension_dir");')
        [ -f "$ext_dir/sqlsrv.so" ] && [ -f "$ext_dir/pdo_sqlsrv.so" ]
        printf 'extension=sqlsrv.so\n' >"/etc/php/${PHP_VER}/mods-available/sqlsrv.ini"
        printf 'extension=pdo_sqlsrv.so\n' >"/etc/php/${PHP_VER}/mods-available/pdo_sqlsrv.ini"
        phpenmod -v "$PHP_VER" sqlsrv pdo_sqlsrv
        php -m | grep -qi '^pdo_sqlsrv$'
    ) >"$dlog" 2>&1; then
        CMS_DRIVER_STATUS="installed"; ok "pdo_sqlsrv installed"
    else
        CMS_DRIVER_STATUS="not installed (install failed; see $dlog)"
        # Leave no half-enabled extension behind (it would make every php call print a warning).
        if ! php -m 2>/dev/null | grep -qi '^pdo_sqlsrv$'; then
            phpdismod -v "$PHP_VER" sqlsrv pdo_sqlsrv >/dev/null 2>&1 || true
            rm -f "/etc/php/${PHP_VER}/mods-available/sqlsrv.ini" "/etc/php/${PHP_VER}/mods-available/pdo_sqlsrv.ini"
        fi
        rm -f /etc/apt/sources.list.d/mssql-release.list
        warn "The CMS driver could not be installed. Chroma works without it; the CMS screen in Admin will say 'driver not installed'. Details: $dlog"
    fi
}

create_system_user() {
    step "Creating the ${APP_USER} system user and directories"
    if ! id -u "$APP_USER" >/dev/null 2>&1; then
        adduser --system --group --home "$APP_HOME" --shell /usr/sbin/nologin --gecos "Chroma web app" "$APP_USER" >/dev/null
        ok "User $APP_USER created"
    else
        ok "User $APP_USER already exists"
    fi
    install -d -o "$APP_USER" -g "$APP_USER" -m 0750 "$APP_HOME" "$FILES_DIR" "$BACKUP_DIR"
    install -d -o "$APP_USER" -g "$APP_USER" -m 0700 "$APP_TMP"
    install -d -o "$APP_USER" -g "$APP_USER" -m 0755 "$LOG_DIR" "$LOG_DIR/jobs"
    install -d -o root -g root -m 0755 "$CONFIG_DIR" "$COMPOSER_DIR"
    rm -rf "$APP_HOME/.composer"   # composer now runs as root (older installs kept its cache here)
}

fetch_source() {
    step "Getting the Chroma code into $APP_DIR (branch $BRANCH)"
    if [ -d "$APP_DIR/.git" ]; then
        make_tree_root_owned
        git_app fetch -q origin
        git_app checkout -q "$BRANCH" 2>/dev/null || git_app checkout -q -b "$BRANCH" "origin/$BRANCH"
        git_app pull -q --ff-only origin "$BRANCH"
        ok "Existing checkout updated"
    else
        install -d -o root -g root -m 0755 "$APP_DIR"
        [ -z "$(ls -A "$APP_DIR")" ] || die "$APP_DIR exists and is not empty but is not a git checkout. Move it away and run again."
        local -a gitopts=()
        # A local path (testing) may be owned by another user; git would refuse it as "dubious ownership".
        if [ -d "$REPO_URL" ]; then gitopts+=(-c "safe.directory=*"); fi
        run_clean_root git "${gitopts[@]}" clone -q --branch "$BRANCH" "$REPO_URL" "$APP_DIR"
        make_tree_root_owned
        ok "Cloned $REPO_URL"
    fi
    [ -f "$APP_DIR/bin/migrate" ] || die "$APP_DIR does not look like Chroma (bin/migrate is missing)."
    log "Version: $(app_version)"
}

ensure_mariadb_running() {
    if mysqladmin --silent ping >/dev/null 2>&1; then
        ok "MariaDB is running"; return
    fi
    if have_systemd; then
        systemctl enable -q --now mariadb
        local i
        for i in 1 2 3 4 5 6 7 8 9 10; do mysqladmin --silent ping >/dev/null 2>&1 && break; sleep 1; done
    fi
    mysqladmin --silent ping >/dev/null 2>&1 || die "MariaDB is not running and could not be started. Start it (systemctl start mariadb) and run the installer again."
    ok "MariaDB started"
}

DB_PASS=""
setup_database() {
    step "Creating the databases ($DB_NAME, $TEST_DB_NAME) and the database user $DB_USER"
    ensure_mariadb_running
    mysql_root -e 'SELECT 1' >/dev/null 2>&1 || die "Cannot connect to MariaDB as root. If root has a password, set CHROMA_MYSQL_ROOT_PASSWORD and run again."
    # A resumed first run keeps the password already written to config.env.
    DB_PASS=$(cfg_get DB_PASS)
    [ -n "$DB_PASS" ] || DB_PASS=$(random_token 28)
    mysql_root <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS \`$TEST_DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
GRANT ALL PRIVILEGES ON \`$TEST_DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
    ok "Databases and user ready"
}

write_config() {
    step "Writing $CONFIG_FILE"
    if [ -f "$CONFIG_FILE" ]; then
        chown "$APP_USER:$APP_USER" "$CONFIG_FILE"; chmod 600 "$CONFIG_FILE"
        ok "Config already exists; contents left untouched"; return
    fi
    local url
    url="http://$(hostname -I 2>/dev/null | awk '{print $1}')"
    [ "$NET_SKIP" -eq 0 ] && url="http://${NET_IP%%/*}"
    [ "$url" = "http://" ] && url="http://localhost"
    umask 077
    cat >"$CONFIG_FILE" <<EOF
# Chroma configuration - written by install.sh on $(date '+%Y-%m-%d %H:%M'). The updater never overwrites this file.
# Only root and the $APP_USER user can read it. Keep a copy of DB_PASS with your backups.
APP_ENV=production
APP_DEBUG=0
APP_KEY=$(openssl rand -hex 32)
APP_URL=$url
APP_SECURE_COOKIES=0
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=$DB_NAME
DB_USER=$DB_USER
DB_PASS=$DB_PASS
# MariaDB runs on this server, so connect over its socket (faster than TCP; the legacy import runs about twice as fast).
DB_SOCKET=/run/mysqld/mysqld.sock
# Test database used by the update check (PHPUnit). Created by the installer.
TEST_DB_NAME=$TEST_DB_NAME
FILES_PATH=$FILES_DIR
LOG_PATH=$LOG_DIR
# Mar-Kov CMS (read-only). Managed from Admin -> CMS connection.
CMS_ENABLED=0
CMS_HOST=
CMS_PORT=1433
CMS_DATABASE=CMS
CMS_USER=
CMS_PASS=
EOF
    umask 022
    chown "$APP_USER:$APP_USER" "$CONFIG_FILE"
    chmod 600 "$CONFIG_FILE"
    ok "Config written (mode 600, owner $APP_USER)"
}

install_composer_deps() {
    step "Installing PHP libraries with composer (into the root-owned $APP_DIR/vendor)"
    # Dev dependencies (PHPUnit) are installed on purpose: the updater runs the
    # test-suite before switching versions. See docs/decisions/F.md.
    run_clean_root composer --working-dir="$APP_DIR" install --no-interaction --no-progress --prefer-dist --optimize-autoloader \
        || die "composer install failed. Check the server's internet connection (it downloads from packagist.org) and the output above."
    [ -f "$APP_DIR/vendor/autoload.php" ] || die "composer install did not produce vendor/autoload.php."
    ok "Libraries installed"
}

run_migrations() {
    step "Creating/updating the database tables (bin/migrate)"
    run_as_app php "$APP_DIR/bin/migrate"
    ok "Database is up to date"
}

record_driver_status() {
    mysql_root "$DB_NAME" <<SQL
INSERT INTO chroma_settings (\`key\`, \`value\`, updated_at) VALUES ('cms_driver_status', '$CMS_DRIVER_STATUS', UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE \`value\` = VALUES(\`value\`), updated_at = UTC_TIMESTAMP();
SQL
}

create_admin() {
    step "Creating the administrator account '$ADMIN_USER'"
    # The password goes in on stdin so it never shows in the process list.
    printf '%s\n' "$ADMIN_PASSWORD" | run_as_app php "$APP_DIR/bin/create-admin" "$ADMIN_USER"
    ok "Administrator ready (must change password at first sign-in)"
}

install_web_config() {
    step "Configuring nginx and PHP-FPM"
    install -o root -g root -m 0644 "$APP_DIR/deploy/php-fpm/chroma.conf" "/etc/php/${PHP_VER}/fpm/pool.d/chroma.conf"
    install -o root -g root -m 0644 "$APP_DIR/deploy/nginx/chroma.conf" /etc/nginx/sites-available/chroma
    ln -sfn /etc/nginx/sites-available/chroma /etc/nginx/sites-enabled/chroma
    rm -f /etc/nginx/sites-enabled/default
    touch "$LOG_DIR/php-errors.log" "$LOG_DIR/app.log"
    chown "$APP_USER:$APP_USER" "$LOG_DIR/php-errors.log" "$LOG_DIR/app.log"
    "php-fpm${PHP_VER}" -t >/dev/null 2>&1 || "php-fpm${PHP_VER}" -t || die "The PHP-FPM configuration is invalid (see above)."
    nginx -t >/dev/null 2>&1 || nginx -t || die "The nginx configuration is invalid (see above)."
    ok "nginx site and PHP-FPM pool installed"
}

set_permissions() {
    step "Setting file permissions"
    # The code is root-owned and read-only for the app user; the app user owns only its data dirs.
    make_tree_root_owned
    chmod 755 "$APP_DIR" "$APP_DIR/public"
    chmod +x "$APP_DIR"/bin/* 2>/dev/null || true
    chown -R "$APP_USER:$APP_USER" "$APP_HOME" "$BACKUP_DIR"
    install -d -o "$APP_USER" -g "$APP_USER" -m 0700 "$APP_TMP"
    install -d -o "$APP_USER" -g "$APP_USER" -m 0755 "$LOG_DIR" "$LOG_DIR/jobs"
    chown "$APP_USER:$APP_USER" "$CONFIG_FILE"; chmod 600 "$CONFIG_FILE"
    chmod 750 "$FILES_DIR" "$BACKUP_DIR"
    ok "Permissions set (root owns $APP_DIR read-only; $APP_USER owns $FILES_DIR, $LOG_DIR, $BACKUP_DIR)"
}

install_helper_scripts() {
    step "Installing the privileged helper, the updater and the uninstaller to $SBIN_DIR"
    assert_tree_root_owned   # never copy app-user-writable files into a root-executed location
    install -d -m 0755 "$SBIN_DIR"
    install -o root -g root -m 0755 "$APP_DIR/bin/chroma-helper" "$SBIN_DIR/chroma-helper"
    install -o root -g root -m 0755 "$APP_DIR/bin/chroma-update" "$SBIN_DIR/chroma-update"
    install -o root -g root -m 0755 "$APP_DIR/bin/uninstall" "$SBIN_DIR/chroma-uninstall"
    bash -n "$SBIN_DIR/chroma-helper" && bash -n "$SBIN_DIR/chroma-update" && bash -n "$SBIN_DIR/chroma-uninstall"
    ok "chroma-helper, chroma-update and chroma-uninstall installed"
}

install_sudoers() {
    step "Allowing only the $APP_USER user to run $SBIN_DIR/chroma-helper without a password"
    local tmp; tmp=$(mktemp)
    sed -e "s#/usr/local/sbin/chroma-helper#$SBIN_DIR/chroma-helper#g" -e "s#^chroma #$APP_USER #" -e "s#^Defaults:chroma#Defaults:$APP_USER#" \
        "$APP_DIR/deploy/sudoers/chroma" >"$tmp"
    visudo -cf "$tmp" >/dev/null || { rm -f "$tmp"; die "The sudoers drop-in did not validate; nothing was installed."; }
    install -o root -g root -m 0440 "$tmp" /etc/sudoers.d/chroma
    rm -f "$tmp"
    ok "sudoers drop-in installed (/etc/sudoers.d/chroma)"
}

install_units() {
    step "Installing the nightly backup timer, the CMS sync timer and log rotation"
    local u
    for u in chroma-backup.service chroma-backup.timer chroma-cms-sync.service chroma-cms-sync.timer; do
        install -o root -g root -m 0644 "$APP_DIR/deploy/systemd/$u" "/etc/systemd/system/$u"
    done
    install -o root -g root -m 0644 "$APP_DIR/deploy/logrotate/chroma" /etc/logrotate.d/chroma
    SVC_SKIPPED=0
    svc daemon-reload
    svc enable -q --now chroma-backup.timer chroma-cms-sync.timer
    if [ "$SVC_SKIPPED" -eq 0 ]; then ok "Timers enabled and logrotate installed"; else ok "Unit files and logrotate installed (timers NOT enabled: no systemd here)"; fi
}

start_services() {
    step "Enabling and starting services"
    SVC_SKIPPED=0
    svc enable -q mariadb nginx "php${PHP_VER}-fpm"
    svc restart "php${PHP_VER}-fpm"
    svc reload-or-restart nginx
    if [ "$SVC_SKIPPED" -eq 0 ]; then ok "Services started"; else warn "Services were NOT started (no systemd here); start nginx and php${PHP_VER}-fpm by hand if you need them."; fi
}

reload_services() {
    step "Reloading PHP-FPM and nginx"
    if have_systemd; then
        systemctl reload-or-restart "php${PHP_VER}-fpm"
        systemctl reload-or-restart nginx
    else
        warn "systemd is not running here; PHP-FPM and nginx were not reloaded."
    fi
}

configure_firewall() {
    step "Firewall"
    if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q '^Status: active'; then
        ufw allow 80/tcp >/dev/null
        ufw allow 22/tcp >/dev/null
        ok "UFW is active: allowed ports 80 (web) and 22 (SSH)"
    else
        ok "UFW is not active; nothing to do"
    fi
}

apply_network() {
    [ "$NET_SKIP" -eq 0 ] || return 0
    step "Network: applying the fixed IP address LAST"
    if ! have_netplan; then
        warn "netplan is not installed on this machine; the network settings were NOT changed."
        return 0
    fi
    echo
    echo "${c_yel}${c_bold}The network settings change in 10 seconds.${c_off}"
    echo "If you are connected over SSH to the old address, this session will drop."
    echo "Then open:  ${c_bold}http://${NET_IP%%/*}/${c_off}"
    echo
    # Not fatal: Chroma is already installed and reachable at the current address. The values were
    # validated at prompt time; the only thing that can still fail here is netplan itself.
    if ! "$SBIN_DIR/chroma-helper" set-network "$NET_IFACE" "$NET_IP" "$NET_GATEWAY" "$NET_DNS"; then
        warn "The network settings were NOT applied (see the message above). Chroma is installed and still answers at its current address."
        warn "To try again:  sudo chroma-helper set-network $NET_IFACE $NET_IP $NET_GATEWAY $NET_DNS   (or use Admin -> Network)"
        return 1
    fi
}

print_summary() {
    local url; url=$(cfg_get APP_URL)
    [ "$NET_SKIP" -eq 0 ] && url="http://${NET_IP%%/*}"
    cat <<EOF

${c_grn}${c_bold}=============================================================
 Chroma is installed. Version $(app_version)
=============================================================${c_off}
 Open Chroma:        ${c_bold}${url}/${c_off}
 Administrator:      ${c_bold}${ADMIN_USER}${c_off}  (you will be asked to choose a new password at first sign-in)
EOF
    if [ "$ADMIN_PASSWORD_GENERATED" -eq 1 ]; then
        # The password goes to the screen only (fd 3 = the terminal/stdout before the log tee),
        # never into install.log, which other administrators of the server can read.
        echo " Generated password: (shown on the screen only; it is not written to the log)"
        echo " Generated password: ${c_bold}${ADMIN_PASSWORD}${c_off}  <- write this down now; it is not stored anywhere else" >&3
    fi
    cat <<EOF
 Config file:        $CONFIG_FILE (database password, app key; keep a copy with your backups)
 Application code:   $APP_DIR (root-owned, read-only for the web app; updated only by chroma-update)
 Logs:               $LOG_DIR (app.log, php-errors.log, nginx-*.log, install.log, backup.log)
 Attachments:        $FILES_DIR
 Backups:            $BACKUP_DIR (nightly, kept 30 days; run one now with: sudo -u $APP_USER $APP_DIR/bin/backup)
 CMS driver:         $CMS_DRIVER_STATUS
 Update later with:  ${c_bold}sudo chroma-update${c_off}   (or: sudo chroma-update --check)
 Remove with:        sudo chroma-uninstall   (keeps the database unless --purge)
EOF
    if ! have_systemd; then
        warn "systemd is not running on this machine, so services and timers were not started (fine for testing in a container)."
    fi
    echo
}

run_install() {
    log "Running a FIRST-TIME INSTALL. Log: $INSTALL_LOG"
    check_os
    detect_interactive
    collect_admin_settings
    collect_network_settings
    install_packages
    install_sqlsrv_driver
    create_system_user
    fetch_source
    setup_database
    write_config
    install_composer_deps
    run_migrations
    record_driver_status
    create_admin
    install_web_config
    set_permissions
    install_helper_scripts
    install_sudoers
    install_units
    start_services
    configure_firewall
    printf 'installed=%s\nversion=%s\n' "$(date '+%Y-%m-%d %H:%M')" "$(app_version)" >"$INSTALLED_MARKER"
    print_summary
    apply_network || true   # a netplan refusal is reported, but the install itself is complete
}

# ----------------------------------------------------------------------------- update path
take_backup() {
    step "Taking a backup before updating"
    local path
    path=$(run_as_app "$APP_DIR/bin/backup" --print-path) || die "The backup failed, so the update was not started. Fix the backup first (see $LOG_DIR/backup.log)."
    ok "Backup written: $path"
}

run_tests() {
    step "Running the test-suite against $(cfg_get TEST_DB_NAME) before switching over"
    if [ ! -x "$APP_DIR/vendor/bin/phpunit" ]; then
        warn "vendor/bin/phpunit is missing (dev dependencies not installed); tests were skipped."
        return 0
    fi
    # The complete output goes to its own file (the screen shows only the last lines):
    # when a test fails, the lines that say why are usually printed early, not at the end.
    TEST_LOG="$LOG_DIR/phpunit-$(date '+%Y-%m-%d_%H%M%S').log"
    : >"$TEST_LOG"; chmod 0640 "$TEST_LOG"
    # The only place inside the root-owned tree the test-suite writes: the importer test builds its
    # sample spreadsheets in tests/fixtures/legacy (see docs/requests/F.md). Nothing root runs lives there.
    if [ -d "$APP_DIR/tests/fixtures" ]; then
        install -d -o "$APP_USER" -g "$APP_USER" -m 0750 "$APP_DIR/tests/fixtures/legacy"
        chown -R "$APP_USER:$APP_USER" "$APP_DIR/tests/fixtures/legacy"
    fi
    local rc=0
    (cd "$APP_DIR" && run_as_app php vendor/bin/phpunit --no-coverage --colors=never --do-not-cache-result) >"$TEST_LOG" 2>&1 || rc=$?
    tail -n 40 "$TEST_LOG"
    log "Complete test output: $TEST_LOG"
    return "$rc"
}

rollback() {
    local old=$1
    echo
    echo "${c_red}${c_bold}=============================================================
 UPDATE FAILED: the tests did not pass on the new version.
 Rolling back to the previous version (${old:0:7}) now.
=============================================================${c_off}"
    git_app reset -q --hard "$old"
    run_clean_root composer --working-dir="$APP_DIR" install --no-interaction --no-progress --prefer-dist --optimize-autoloader >/dev/null 2>&1 || warn "composer install on the old version reported a problem."
    make_tree_root_owned
    reload_services
    echo "${c_red}${c_bold}Chroma is back on version $(app_version). Nothing was lost; the database backup taken before the update is in $BACKUP_DIR.${c_off}"
    echo "New database migrations (if any) stay applied; they are additive and the old version ignores them."
    echo "${c_bold}Send this file to the developer: ${TEST_LOG:-$INSTALL_LOG}${c_off} (the complete test output; the cause is often near the top, not in the summary)."
    echo "Run 'sudo chroma-update' again once the problem is fixed, or right away if the developer says the failure was a one-off."
    exit 1
}

update_post_pull() {
    # Runs on the NEW code after the pull (re-executed so new steps in install.sh take effect).
    local old=$1
    make_tree_root_owned
    install_composer_deps
    make_tree_root_owned           # composer may have created files with a wider umask
    run_migrations
    if ! run_tests; then rollback "$old"; fi
    ok "Tests passed"
    assert_tree_root_owned
    install_web_config
    set_permissions
    install_helper_scripts
    install_sudoers
    install_units
    reload_services
    local oldv; oldv=$(git_app show "$old:VERSION" 2>/dev/null | tr -d '[:space:]' || true)
    printf 'installed=%s\nversion=%s\n' "$(cat "$INSTALLED_MARKER" 2>/dev/null | sed -n 's/^installed=//p')" "$(app_version)" >"$INSTALLED_MARKER"
    echo
    echo "${c_grn}${c_bold}Update complete: ${oldv:-?} (${old:0:7}) -> $(app_version)${c_off}"
    echo "Open Chroma at $(cfg_get APP_URL)/ to check it."
}

run_update() {
    log "Found $CONFIG_FILE: running in UPDATE mode. Log: $INSTALL_LOG"
    id -u "$APP_USER" >/dev/null 2>&1 || die "The $APP_USER user is missing; this does not look like a complete install."
    # Installs made by an older install.sh left $APP_DIR owned by the app user; take it back.
    make_tree_root_owned
    local current; current=$(git_app rev-parse --abbrev-ref HEAD 2>/dev/null || echo "")
    if [ -z "$BRANCH_FLAG" ] && [ -z "${CHROMA_BRANCH:-}" ] && [ -n "$current" ] && [ "$current" != "HEAD" ]; then
        BRANCH=$current
    fi
    valid_branch "$BRANCH" || die "'$BRANCH' is not a valid branch name."
    REPO_URL=$(git_app remote get-url origin 2>/dev/null || printf '%s' "$REPO_URL")
    step "Checking $REPO_URL for a newer version of branch $BRANCH"
    git_app fetch -q origin "$BRANCH" || die "Could not fetch from the git remote. Check the server's network connection."
    git_app rev-parse -q --verify "origin/$BRANCH" >/dev/null 2>&1 || die "The remote has no branch called '$BRANCH'."
    local old new oldv
    old=$(git_app rev-parse HEAD); new=$(git_app rev-parse "origin/$BRANCH"); oldv=$(app_version)
    if [ "$current" != "$BRANCH" ]; then
        log "Switching from branch '$current' to '$BRANCH'"
    fi
    if [ "$MODE_CHECK" -eq 1 ]; then
        if [ "$old" = "$new" ] && [ "$current" = "$BRANCH" ]; then
            echo "up-to-date ${old:0:7}  (version $oldv is the newest on branch $BRANCH)"
        else
            echo "update-available ${old:0:7} ${new:0:7}  (run: sudo chroma-update)"
            git_app log --oneline --no-decorate "${old}..${new}" 2>/dev/null | head -n 20 | sed 's/^/   /' || true
        fi
        exit 0
    fi
    if [ "$old" = "$new" ] && [ "$current" = "$BRANCH" ] && [ "$MODE_FORCE" -eq 0 ]; then
        ok "Chroma is already up to date: version $oldv. Use --force to re-run the update steps anyway."
        exit 0
    fi
    if [ -n "$(git_app status --porcelain --untracked-files=no)" ]; then
        die "The working tree in $APP_DIR has local changes, so a safe fast-forward update is not possible.
       See them with:   sudo git -C $APP_DIR status
       Throw them away: sudo git -C $APP_DIR checkout -- .     (then run the update again)
       Or keep them:    sudo git -C $APP_DIR stash"
    fi
    take_backup
    step "Pulling the new version (fast-forward only)"
    if [ "$current" != "$BRANCH" ]; then
        git_app checkout -q "$BRANCH" 2>/dev/null || git_app checkout -q -b "$BRANCH" "origin/$BRANCH"
    fi
    git_app merge -q --ff-only "origin/$BRANCH" || die "git could not fast-forward $APP_DIR. Run: sudo git -C $APP_DIR status"
    make_tree_root_owned
    ok "Now at $(app_version)"
    # Re-run the NEW install.sh so steps added in the new version are executed. It is root-owned
    # (just checked out by root, verified again here) so the app user cannot have changed it.
    assert_tree_root_owned
    local -a pass=(--continue-update "$old")
    [ "$UNATTENDED" -eq 1 ] && pass+=(--unattended)
    [ -n "$BRANCH_FLAG" ] && pass+=(--branch "$BRANCH")
    exec bash "$APP_DIR/install.sh" "${pass[@]}"
}

# ----------------------------------------------------------------------------- main
parse_args() {
    while [ $# -gt 0 ]; do
        case "$1" in
            --check) MODE_CHECK=1 ;;
            --force) MODE_FORCE=1 ;;
            --unattended) UNATTENDED=1 ;;
            --branch) [ -n "${2:-}" ] || die "--branch needs a branch name" 2; BRANCH_FLAG=$2; BRANCH=$2; shift ;;
            --branch=*) BRANCH_FLAG=${1#*=}; BRANCH=$BRANCH_FLAG ;;
            --continue-update) [ -n "${2:-}" ] || die "--continue-update needs a commit" 2; CONTINUE_FROM=$2; shift ;;
            --help|-h) usage; exit 0 ;;
            *) printf 'Unknown option: %s\n\n' "$1" >&2; usage >&2; exit 2 ;;
        esac
        shift
    done
    valid_branch "$BRANCH" || die "'$BRANCH' is not a valid branch name." 2
}

main() {
    parse_args "$@"
    [ "$(id -u)" -eq 0 ] || die "Run this as root:  sudo bash install.sh   (or: curl -fsSL ... | sudo bash)"
    command -v runuser >/dev/null 2>&1 || die "runuser (util-linux) is missing."
    mkdir -p "$LOG_DIR"
    trap 'on_error $LINENO' ERR
    exec 3>&1                             # fd 3 = the screen, for the one line that must stay out of the log
    exec > >(tee -a "$INSTALL_LOG") 2>&1
    printf '\n----- %s  %s -----\n' "$(date '+%Y-%m-%d %H:%M:%S')" "install.sh $*" >>"$INSTALL_LOG"
    if [ -n "$CONTINUE_FROM" ]; then
        update_post_pull "$CONTINUE_FROM"
    elif [ -f "$CONFIG_FILE" ] && [ -f "$INSTALLED_MARKER" ] && [ -d "$APP_DIR/.git" ]; then
        run_update
    else
        [ "$MODE_CHECK" -eq 0 ] || die "Chroma is not installed yet (no $INSTALLED_MARKER), so there is nothing to check."
        if [ -f "$CONFIG_FILE" ]; then
            warn "Found $CONFIG_FILE but the first install never finished; resuming it (existing settings are kept)."
        fi
        run_install
    fi
}

main "$@"
