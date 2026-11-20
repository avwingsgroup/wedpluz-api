#!/usr/bin/env bash
#
# Wedpluz API — provisioning on Ubuntu 22.04 / 24.04 LTS, safe on a SHARED server.
#
# Run as root, from a copy of the repository's deploy/ folder:
#   sudo bash /home/usir/deploy/scripts/provision.sh --domain api.wedpluz.com \
#        --ssh-key-file /home/usir/wedpluz_deploy.pub --email ops@wedpluz.com --ssl
#
# Everything Wedpluz needs is dedicated and namespaced, so other apps on the
# server are not touched:
#   user          usir (existing login user) — you and GitHub Actions SSH in as
#                 usir, and usir owns the app and runs its PHP-FPM pool, queue
#                 workers and cron. (--app-user NAME runs the app as a separate
#                 system user instead; usir then deploys through `sudo -u NAME`.)
#   code          /var/www/apps/wedpluz/api/{releases,shared,current}
#   PHP           pool "wedpluz" in php8.5-fpm, socket /run/php/php8.5-fpm-wedpluz.sock
#                 (PHP settings live in the pool, never in global php.ini files)
#   nginx         dedicated vhost /etc/nginx/sites-available/wedpluz-api
#   Supervisor    /etc/supervisor/conf.d/wedpluz-api.conf, programs wedpluz-api-*
#   cron, logs    /etc/cron.d/wedpluz-api, /etc/logrotate.d/wedpluz-api, /var/log/apps/wedpluz
#   backups       /var/backups/apps/wedpluz
#   sudoers       /etc/sudoers.d/wedpluz-api (passwordless only for reloading php8.5-fpm and wedpluz-api-* programs)
#   database      role + database "wedpluz" in PostgreSQL 19 (installed only if missing)
#   Redis         existing instance reused as-is (installed and secured only if missing)
#
# Options:
#   --domain HOST            Public API host name (required)
#   --ssh-user NAME          Login user for you and CI             (default usir)
#   --ssh-key "KEY"          Public key added to the operator's authorized_keys (CI deploy key)
#   --ssh-key-file FILE      Same, from a file
#   --app-user NAME          User that owns and runs the app       (default: same as --ssh-user)
#   --app-dir DIR            Application root                       (default /var/www/apps/wedpluz/api)
#   --php VERSION            PHP version                            (default 8.5)
#   --postgres VERSION       PostgreSQL version to install if none  (default 19)
#   --email ADDRESS          Let's Encrypt account email (required with --ssl)
#   --ssl                    Issue a certificate now (DNS must already point here)
#   --reverb                 Enable the realtime WebSocket server (Laravel Reverb)
#   --reverb-port PORT       Local port for Reverb                  (default 8086)
#   --no-postgres            Database is external/managed (fill DB_* in shared/.env)
#   --no-redis               Redis is external/managed (fill REDIS_* in shared/.env)
#   --harden                 FRESH servers only: UFW deny-by-default, fail2ban, automatic
#                            security updates, UTC timezone, swap. Never on a server whose
#                            other services you have not checked.
#   -h, --help               Show this help
set -Eeuo pipefail

DOMAIN=""
SSH_USER="usir"
SSH_KEYS=""
APP_USER=""   # defaults to SSH_USER
APP_DIR="/var/www/apps/wedpluz/api"
PHP_VERSION="8.5"
PG_VERSION="19"
EMAIL=""
WITH_SSL=0
WITH_REVERB=0
REVERB_PORT=8086
WITH_POSTGRES=1
WITH_REDIS=1
HARDEN=0

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY_SRC="$(cd "$SCRIPT_DIR/.." && pwd)"
CREDENTIALS_FILE="/root/wedpluz-credentials.txt"
LOG_DIR="/var/log/apps/wedpluz"
BACKUP_DIR="/var/backups/apps/wedpluz"

usage() { sed -n '3,46p' "$0" | sed 's/^# \{0,1\}//'; }

while [[ $# -gt 0 ]]; do
    case "$1" in
        --domain) DOMAIN="$2"; shift 2 ;;
        --ssh-user) SSH_USER="$2"; shift 2 ;;
        --ssh-key) SSH_KEYS+="$2"$'\n'; shift 2 ;;
        --ssh-key-file) SSH_KEYS+="$(cat "$2")"$'\n'; shift 2 ;;
        --app-user) APP_USER="$2"; shift 2 ;;
        --app-dir) APP_DIR="${2%/}"; shift 2 ;;
        --php) PHP_VERSION="$2"; shift 2 ;;
        --postgres) PG_VERSION="$2"; shift 2 ;;
        --email) EMAIL="$2"; shift 2 ;;
        --ssl) WITH_SSL=1; shift ;;
        --reverb) WITH_REVERB=1; shift ;;
        --reverb-port) REVERB_PORT="$2"; shift 2 ;;
        --no-postgres) WITH_POSTGRES=0; shift ;;
        --no-redis) WITH_REDIS=0; shift ;;
        --harden) HARDEN=1; shift ;;
        -h|--help) usage; exit 0 ;;
        *) echo "Unknown option: $1" >&2; usage; exit 1 ;;
    esac
done

BOLD=$'\e[1m'; GREEN=$'\e[32m'; YELLOW=$'\e[33m'; RED=$'\e[31m'; RESET=$'\e[0m'
step() { echo; echo "${BOLD}==> $*${RESET}"; }
ok() { echo "${GREEN}    ✓ $*${RESET}"; }
warn() { echo "${YELLOW}    ! $*${RESET}"; }
die() { echo "${RED}    ✗ $*${RESET}" >&2; exit 1; }
trap 'die "Provisioning failed at line $LINENO. Fix the cause and re-run; completed steps are skipped."' ERR

[[ -n "$APP_USER" ]] || APP_USER="$SSH_USER"
SAME_USER=$([[ "$APP_USER" == "$SSH_USER" ]] && echo 1 || echo 0)
PHP_BIN="/usr/bin/php${PHP_VERSION}"
FPM_SERVICE="php${PHP_VERSION}-fpm"
FPM_SOCKET="/run/php/php${PHP_VERSION}-fpm-wedpluz.sock"
APP_HOME="$(dirname "$APP_DIR")"   # /var/www/apps/wedpluz: the app user's home
export DEBIAN_FRONTEND=noninteractive

render() {
    # render <template> <destination>: replace {{KEY}} placeholders from shell variables.
    local tpl="$1" dest="$2" content key
    content=$(cat "$tpl")
    for key in DOMAIN APP_DIR APP_USER SSH_USER PHP_VERSION PHP_BIN FPM_SERVICE FPM_SOCKET FPM_MAX_CHILDREN FPM_START FPM_MIN_SPARE FPM_MAX_SPARE \
               QUEUE_WORKERS REVERB_AUTOSTART REVERB_PORT LOG_DIR BACKUP_DIR; do
        content="${content//\{\{$key\}\}/${!key:-}}"
    done
    printf '%s\n' "$content" > "$dest"
}

credential() {
    # credential <NAME>: stable random secret, generated once, kept in $CREDENTIALS_FILE (root only).
    local name="$1" value
    touch "$CREDENTIALS_FILE"; chmod 600 "$CREDENTIALS_FILE"
    value=$(grep -E "^$name=" "$CREDENTIALS_FILE" | cut -d= -f2- || true)
    if [[ -z "$value" ]]; then
        value=$(openssl rand -hex 24)
        echo "$name=$value" >> "$CREDENTIALS_FILE"
    fi
    printf '%s' "$value"
}

pkg_available() { apt-cache show "$1" >/dev/null 2>&1; }
pkg_installed() { dpkg -s "$1" >/dev/null 2>&1; }

# ------------------------------------------------------------------ checks
step "Checking the server"
[[ $EUID -eq 0 ]] || die "Run as root (sudo bash $0 ...)."
[[ -n "$DOMAIN" ]] || die "--domain is required (e.g. --domain api.wedpluz.com)."
[[ $WITH_SSL -eq 0 || -n "$EMAIL" ]] || die "--ssl needs --email for Let's Encrypt."
[[ -f "$DEPLOY_SRC/config/nginx.conf.template" ]] || die "Run from a copy of the repository's deploy/ folder (templates not found in $DEPLOY_SRC/config)."
. /etc/os-release
[[ "$ID" == "ubuntu" && ( "$VERSION_ID" == "22.04" || "$VERSION_ID" == "24.04" ) ]] || warn "Tested on Ubuntu 22.04/24.04; this is $PRETTY_NAME."
TOTAL_RAM_MB=$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo)
CPUS=$(nproc)
ok "$PRETTY_NAME · ${CPUS} vCPU · ${TOTAL_RAM_MB} MB RAM · domain $DOMAIN"
[[ $HARDEN -eq 1 ]] || ok "Shared-server mode: no firewall, timezone, swap or global PHP/nginx/Redis changes (use --harden on a fresh server)"

apt-get update -qq
apt-get -y -qq install ca-certificates curl gnupg lsb-release software-properties-common unzip git acl logrotate cron rsync jq openssl

# ------------------------------------------------------------------ optional hardening (fresh servers)
if [[ $HARDEN -eq 1 ]]; then
    step "Hardening (fresh server)"
    apt-get -y -qq -o Dpkg::Options::="--force-confold" upgrade
    apt-get -y -qq install ufw fail2ban unattended-upgrades
    timedatectl set-timezone UTC
    printf 'APT::Periodic::Update-Package-Lists "1";\nAPT::Periodic::Unattended-Upgrade "1";\nAPT::Periodic::AutocleanInterval "7";\n' > /etc/apt/apt.conf.d/20auto-upgrades
    if ! swapon --show | grep -q .; then
        fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile >/dev/null && swapon /swapfile
        grep -q '^/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
    fi
    ufw default deny incoming >/dev/null && ufw default allow outgoing >/dev/null
    ufw allow OpenSSH >/dev/null && ufw allow 80/tcp >/dev/null && ufw allow 443/tcp >/dev/null
    ufw --force enable >/dev/null
    printf '[sshd]\nenabled = true\nmaxretry = 5\nbantime = 1h\n' > /etc/fail2ban/jail.d/wedpluz.local
    systemctl enable --now fail2ban >/dev/null 2>&1 && systemctl restart fail2ban
    ok "UFW (SSH/HTTP/HTTPS), fail2ban, security updates, UTC, swap"
elif command -v ufw >/dev/null 2>&1 && ufw status | grep -q "Status: active"; then
    ufw allow 80/tcp >/dev/null && ufw allow 443/tcp >/dev/null
    ok "UFW is active: ensured HTTP/HTTPS are allowed (no other rules changed)"
fi

# ------------------------------------------------------------------ PHP
step "PHP ${PHP_VERSION}"
if ! pkg_available "php${PHP_VERSION}-fpm" && ! grep -rqs "ondrej/php" /etc/apt/sources.list.d/; then
    add-apt-repository -y ppa:ondrej/php >/dev/null && apt-get update -qq
fi
PHP_PKGS=()
for ext in fpm cli pgsql mbstring xml curl zip gd intl bcmath redis readline igbinary opcache; do
    # OPcache is built into PHP 8.5+, so its package may not exist; install what the repository offers.
    pkg_available "php${PHP_VERSION}-$ext" && PHP_PKGS+=("php${PHP_VERSION}-$ext")
done
apt-get -y -qq install "${PHP_PKGS[@]}"
for mod in pdo_pgsql redis intl gd bcmath zip mbstring; do
    "$PHP_BIN" -m | grep -qi "^$mod$" || die "PHP extension '$mod' is missing for PHP ${PHP_VERSION}."
done
"$PHP_BIN" -r 'exit(function_exists("opcache_get_status") ? 0 : 1);' || warn "OPcache not loaded for the CLI (normal); FPM status is checked below."
ok "$("$PHP_BIN" -r 'echo PHP_VERSION;') with ${#PHP_PKGS[@]} packages (system default 'php' left unchanged)"

if ! command -v composer >/dev/null 2>&1; then
    step "Composer"
    EXPECTED=$(curl -fsSL https://composer.github.io/installer.sig)
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
    [[ "$("$PHP_BIN" -r "echo hash_file('sha384', '/tmp/composer-setup.php');")" == "$EXPECTED" ]] || die "Composer installer checksum mismatch."
    "$PHP_BIN" /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer && rm -f /tmp/composer-setup.php
    ok "Composer installed"
fi

# ------------------------------------------------------------------ PostgreSQL
DB_DATABASE="wedpluz"; DB_USERNAME="wedpluz"; DB_PASSWORD=""; DB_PORT=5432
PG_CLUSTER=""; PG_PORT=""; PG_STATUS=""
find_cluster() {
    # Picks the best real cluster from pg_lsclusters (ver name port status ...):
    # online PG_VERSION > any online > PG_VERSION down > any down. Empty when none exist.
    # (`systemctl is-active postgresql` is useless here: that umbrella unit reports
    # "active" even when no server is running.)
    PG_CLUSTER=""; PG_PORT=""; PG_STATUS=""
    command -v pg_lsclusters >/dev/null 2>&1 || return 0
    local ver name port status rank best=9
    while read -r ver name port status _; do
        [[ -n "$ver" && "$port" =~ ^[0-9]+$ ]] || continue
        if [[ "$status" == online* ]]; then rank=2; else rank=4; fi
        if [[ "$ver" == "$PG_VERSION" ]]; then rank=$((rank - 1)); fi
        if (( rank < best )); then
            best=$rank; PG_CLUSTER="$ver/$name"; PG_PORT="$port"; PG_STATUS="$status"
        fi
    done < <(pg_lsclusters --no-header 2>/dev/null || true)
}
pg_sql() {
    # psql as the postgres superuser on the chosen cluster; stops at the first SQL error.
    (cd /tmp && sudo -u postgres psql -X -q -p "$PG_PORT" -v ON_ERROR_STOP=1 "$@")
}

if [[ $WITH_POSTGRES -eq 1 ]]; then
    step "PostgreSQL"
    find_cluster
    if [[ -z "$PG_CLUSTER" ]]; then
        # No local cluster. If something else already answers on 5432 (Docker, a manual
        # install), don't install a second server next to it.
        if ss -Hltn 'sport = :5432' 2>/dev/null | grep -q .; then
            die "Port 5432 is in use, but no local PostgreSQL cluster exists (Docker or another install?). Re-run with --no-postgres and set DB_HOST/DB_PORT/DB_* in $APP_DIR/shared/.env to that server."
        fi
        if [[ ! -f /etc/apt/sources.list.d/pgdg.list ]]; then
            install -d /usr/share/postgresql-common/pgdg
            curl -fsSL -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc https://www.postgresql.org/media/keys/ACCC4CF8.asc
            echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt $(lsb_release -cs)-pgdg main" \
                > /etc/apt/sources.list.d/pgdg.list
            apt-get update -qq
        fi
        apt-get -y -qq install "postgresql-${PG_VERSION}" "postgresql-client-${PG_VERSION}"
        find_cluster
        if [[ -z "$PG_CLUSTER" ]]; then
            # The package is installed but its cluster was removed earlier: create a fresh one.
            pg_createcluster "$PG_VERSION" main --start >/dev/null
            find_cluster
        fi
        [[ -n "$PG_CLUSTER" ]] || die "PostgreSQL ${PG_VERSION} installed, but no cluster was found. Check: pg_lsclusters"
        ok "Installed PostgreSQL ${PG_VERSION} (cluster $PG_CLUSTER, port $PG_PORT)"
    fi
    if [[ "$PG_STATUS" != online* ]]; then
        warn "Cluster $PG_CLUSTER is $PG_STATUS; starting it."
        pg_ctlcluster "${PG_CLUSTER%/*}" "${PG_CLUSTER#*/}" start \
            || die "Could not start PostgreSQL $PG_CLUSTER. Check: pg_lsclusters; journalctl -u postgresql@${PG_CLUSTER/\//-} -n 50"
        systemctl enable "postgresql@${PG_CLUSTER/\//-}" >/dev/null 2>&1 || true
        find_cluster
        [[ "$PG_STATUS" == online* ]] || die "PostgreSQL $PG_CLUSTER is still not online. Check: pg_lsclusters"
    fi
    pg_sql -c 'SELECT 1' >/dev/null \
        || die "Cannot connect to PostgreSQL $PG_CLUSTER on port $PG_PORT as postgres. Check: pg_lsclusters"
    ok "Using PostgreSQL cluster $PG_CLUSTER on port $PG_PORT"

    DB_PORT="$PG_PORT"
    DB_PASSWORD=$(credential DB_PASSWORD)
    # Identifiers and the password are quoted by format(%I/%L); \gexec runs each generated statement.
    pg_sql -v role="$DB_USERNAME" -v pw="$DB_PASSWORD" -v db="$DB_DATABASE" <<'SQL'
SELECT format('CREATE ROLE %I LOGIN', :'role') WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = :'role')
\gexec
SELECT format('ALTER ROLE %I LOGIN PASSWORD %L', :'role', :'pw')
\gexec
SELECT format('CREATE DATABASE %I OWNER %I ENCODING %L TEMPLATE template0', :'db', :'role', 'UTF8')
 WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = :'db')
\gexec
SELECT format('REVOKE CONNECT ON DATABASE %I FROM PUBLIC', :'db')
\gexec
SQL
    # Only the owner may connect: other apps' roles cannot reach the Wedpluz database.
    ok "Dedicated role and database '$DB_DATABASE' (CONNECT revoked from PUBLIC)"
else
    warn "Skipping PostgreSQL: set DB_* in shared/.env to the managed database."
fi

# ------------------------------------------------------------------ Redis
REDIS_PASSWORD=""
if [[ $WITH_REDIS -eq 1 ]]; then
    step "Redis"
    if pkg_installed redis-server; then
        if grep -qE '^\s*requirepass\s+\S+' /etc/redis/redis.conf 2>/dev/null; then
            warn "Existing Redis requires a password: put it in REDIS_PASSWORD in shared/.env (configuration left untouched)."
        else
            ok "Reusing the existing Redis (configuration untouched; Wedpluz keys are prefixed 'wedpluz-')"
        fi
    else
        apt-get -y -qq install redis-server
        REDIS_PASSWORD=$(credential REDIS_PASSWORD)
        REDIS_CONF=/etc/redis/redis.conf
        sed -i -E 's/^#?\s*bind .*/bind 127.0.0.1 -::1/; s/^#?\s*supervised .*/supervised systemd/; s/^#?\s*appendonly .*/appendonly yes/' "$REDIS_CONF"
        if grep -qE '^#?\s*requirepass ' "$REDIS_CONF"; then
            sed -i -E "s/^#?\s*requirepass .*/requirepass $REDIS_PASSWORD/" "$REDIS_CONF"
        else
            echo "requirepass $REDIS_PASSWORD" >> "$REDIS_CONF"
        fi
        systemctl enable redis-server >/dev/null 2>&1 && systemctl restart redis-server
        ok "Installed Redis on localhost with a password and AOF persistence"
    fi
else
    warn "Skipping Redis: set REDIS_* in shared/.env to the managed Redis."
fi

# ------------------------------------------------------------------ nginx, Supervisor, Certbot
step "nginx, Supervisor and Certbot"
apt-get -y -qq install nginx supervisor certbot python3-certbot-nginx
systemctl enable --now nginx supervisor >/dev/null 2>&1
ok "Present (existing configuration left as is)"

# ------------------------------------------------------------------ users and layout
step "Users: $SSH_USER$([[ $SAME_USER -eq 1 ]] && echo " (login and app)" || echo " (login) and $APP_USER (app)")"
if ! id "$SSH_USER" >/dev/null 2>&1; then
    adduser --disabled-password --gecos "Wedpluz operator" "$SSH_USER" >/dev/null
    warn "Created login user $SSH_USER (it did not exist)."
fi
if [[ $SAME_USER -eq 0 ]] && ! id "$APP_USER" >/dev/null 2>&1; then
    install -d -m 755 "$(dirname "$APP_HOME")"
    adduser --system --group --home "$APP_HOME" --shell /usr/sbin/nologin "$APP_USER" >/dev/null
fi
usermod -aG www-data "$APP_USER"   # lets nginx-readable directories use group www-data
SSH_HOME=$(getent passwd "$SSH_USER" | cut -d: -f6)   # /home/usir
install -d -m 700 -o "$SSH_USER" -g "$SSH_USER" "$SSH_HOME/.ssh"
AUTH_KEYS="$SSH_HOME/.ssh/authorized_keys"
touch "$AUTH_KEYS"
while IFS= read -r key; do
    [[ -z "$key" ]] && continue
    grep -qxF "$key" "$AUTH_KEYS" || echo "$key" >> "$AUTH_KEYS"
done <<< "$SSH_KEYS"
chown "$SSH_USER:$SSH_USER" "$AUTH_KEYS" && chmod 600 "$AUTH_KEYS"
if [[ $SAME_USER -eq 1 ]]; then
    ok "$SSH_USER logs in (keys in $AUTH_KEYS) and runs the app"
else
    usermod -aG "$APP_USER" "$SSH_USER"   # read access to worker logs
    ok "$APP_USER: system user, no login shell · $SSH_USER: SSH keys in $AUTH_KEYS"
fi

step "Directory layout $APP_DIR"
# Code (releases) is readable by group www-data so nginx can serve public/; secrets and private
# files are owner-only, so other apps' PHP pools (often running as www-data) cannot read them.
install -d -m 2750 -o "$APP_USER" -g www-data "$APP_HOME" "$APP_DIR" "$APP_DIR/releases" "$APP_DIR/shared"
install -d -m 0711 -o "$APP_USER" -g "$APP_USER" "$APP_DIR/shared/storage" "$APP_DIR/shared/storage/app"
install -d -m 2750 -o "$APP_USER" -g www-data "$APP_DIR/shared/storage/app/public"
for d in app/private framework framework/cache framework/cache/data framework/sessions framework/views logs; do
    install -d -m 0700 -o "$APP_USER" -g "$APP_USER" "$APP_DIR/shared/storage/$d"
done
install -d -m 2750 -o "$APP_USER" -g "$APP_USER" "$LOG_DIR"
install -d -m 0700 -o "$APP_USER" -g "$APP_USER" "$BACKUP_DIR"
ok "releases/ shared/ (storage private, public uploads readable by nginx); logs $LOG_DIR; backups $BACKUP_DIR"

step "shared/.env"
ENV_FILE="$APP_DIR/shared/.env"
if [[ ! -f "$ENV_FILE" ]]; then
    APP_KEY="base64:$(openssl rand -base64 32)"
    HEALTH_TOKEN=$(credential HEALTH_CHECK_TOKEN)
    REVERB_APP_ID=$(( RANDOM * 100 + RANDOM % 100 ))
    REVERB_APP_KEY=$(credential REVERB_APP_KEY | cut -c1-20)
    REVERB_APP_SECRET=$(credential REVERB_APP_SECRET)
    BROADCAST_CONNECTION=$([[ $WITH_REVERB -eq 1 ]] && echo reverb || echo null)
    APP_URL="https://$DOMAIN"
    content=$(cat "$DEPLOY_SRC/env/production.env.example")
    for key in APP_KEY APP_URL DOMAIN DB_DATABASE DB_USERNAME DB_PASSWORD DB_PORT REDIS_PASSWORD HEALTH_TOKEN BROADCAST_CONNECTION \
               REVERB_APP_ID REVERB_APP_KEY REVERB_APP_SECRET REVERB_PORT; do
        content="${content//\{\{$key\}\}/${!key:-}}"
    done
    printf '%s\n' "$content" > "$ENV_FILE"
    ok "Created with a new APP_KEY and generated secrets. Fill in the provider keys before going live."
else
    ok "Kept the existing file (secrets are never overwritten)"
fi
chown "$APP_USER:$APP_USER" "$ENV_FILE" && chmod 600 "$ENV_FILE"   # app user only

# ------------------------------------------------------------------ PHP-FPM pool (dedicated)
step "PHP-FPM pool 'wedpluz' in $FPM_SERVICE"
FPM_MAX_CHILDREN=$(( (TOTAL_RAM_MB - 1024) / 80 / 2 ))   # shared server: claim at most about half the RAM
(( FPM_MAX_CHILDREN < 4 )) && FPM_MAX_CHILDREN=4
(( FPM_MAX_CHILDREN > 40 )) && FPM_MAX_CHILDREN=40
FPM_START=$(( FPM_MAX_CHILDREN / 4 > 2 ? FPM_MAX_CHILDREN / 4 : 2 ))
FPM_MIN_SPARE=$FPM_START
FPM_MAX_SPARE=$(( FPM_START * 2 ))
render "$DEPLOY_SRC/config/php-fpm-pool.conf.template" "/etc/php/${PHP_VERSION}/fpm/pool.d/wedpluz.conf"
"php-fpm${PHP_VERSION}" -t >/dev/null 2>&1 || die "php-fpm configuration test failed (php-fpm${PHP_VERSION} -t)."
systemctl enable "$FPM_SERVICE" >/dev/null 2>&1 && systemctl reload "$FPM_SERVICE" 2>/dev/null || systemctl restart "$FPM_SERVICE"
ok "Pool runs as $APP_USER, up to $FPM_MAX_CHILDREN workers, socket $FPM_SOCKET (other pools untouched)"

# ------------------------------------------------------------------ nginx vhost (dedicated)
step "nginx virtual host for $DOMAIN"
SITE="/etc/nginx/sites-available/wedpluz-api"
if grep -rlsE "server_name\s+([^;]*\s)?${DOMAIN//./\\.}(\s|;)" /etc/nginx/sites-enabled/ | grep -vq "wedpluz-api$"; then
    die "Another nginx site already serves $DOMAIN: $(grep -rlsE "server_name\s+([^;]*\s)?${DOMAIN//./\\.}(\s|;)" /etc/nginx/sites-enabled/ | grep -v wedpluz-api)"
fi
if [[ -f "$SITE" ]] && grep -q "managed by Certbot" "$SITE"; then
    ok "Kept the existing vhost (it already has Certbot's TLS configuration)"
else
    render "$DEPLOY_SRC/config/nginx.conf.template" "$SITE"
    if [[ $WITH_REVERB -eq 0 ]]; then
        sed -i '/{{REVERB_BLOCK_START}}/,/{{REVERB_BLOCK_END}}/d' "$SITE"
    else
        sed -i '/{{REVERB_BLOCK_START}}/d; /{{REVERB_BLOCK_END}}/d' "$SITE"
    fi
    ln -sfn "$SITE" /etc/nginx/sites-enabled/wedpluz-api
fi
if [[ ! -e "$APP_DIR/current" ]]; then
    # Placeholder until the first deploy, so nginx and Certbot work immediately.
    install -d -m 2750 -o "$APP_USER" -g www-data "$APP_DIR/releases/000-placeholder" "$APP_DIR/releases/000-placeholder/public"
    echo '<!doctype html><title>Wedpluz API</title><p>Provisioned. Waiting for the first deployment.</p>' > "$APP_DIR/releases/000-placeholder/public/index.html"
    chown "$APP_USER:www-data" "$APP_DIR/releases/000-placeholder/public/index.html" && chmod 640 "$APP_DIR/releases/000-placeholder/public/index.html"
    ln -sfn "$APP_DIR/releases/000-placeholder" "$APP_DIR/current" && chown -h "$APP_USER:www-data" "$APP_DIR/current"
fi
nginx -t >/dev/null 2>&1 || die "nginx configuration test failed (run: nginx -t). Nothing was reloaded."
systemctl reload nginx
ok "Dedicated vhost /etc/nginx/sites-available/wedpluz-api (default and other sites untouched)"

# ------------------------------------------------------------------ workers, cron, logs, backups, sudo
step "Workers, scheduler, log rotation, backups and permissions"
QUEUE_WORKERS=$(( CPUS >= 4 ? 3 : (CPUS >= 2 ? 2 : 1) ))
REVERB_AUTOSTART=$([[ $WITH_REVERB -eq 1 ]] && echo true || echo false)
render "$DEPLOY_SRC/config/supervisor.conf.template" /etc/supervisor/conf.d/wedpluz-api.conf
render "$DEPLOY_SRC/config/cron.template" /etc/cron.d/wedpluz-api && chmod 644 /etc/cron.d/wedpluz-api
render "$DEPLOY_SRC/config/logrotate.template" /etc/logrotate.d/wedpluz-api
install -m 755 "$DEPLOY_SRC/scripts/backup.sh" /usr/local/bin/wedpluz-backup
install -m 755 "$DEPLOY_SRC/scripts/deploy.sh" /usr/local/bin/wedpluz-deploy
install -m 755 "$DEPLOY_SRC/scripts/rollback.sh" /usr/local/bin/wedpluz-rollback
render "$DEPLOY_SRC/config/sudoers.template" /tmp/wedpluz-api-sudoers
if [[ $SAME_USER -eq 1 ]]; then
    sed -i '/{{OPERATOR_BLOCK_START}}/,/{{OPERATOR_BLOCK_END}}/d' /tmp/wedpluz-api-sudoers
else
    sed -i '/{{OPERATOR_BLOCK_START}}/d; /{{OPERATOR_BLOCK_END}}/d' /tmp/wedpluz-api-sudoers
fi
visudo -cqf /tmp/wedpluz-api-sudoers || die "Generated sudoers rule is invalid; nothing was installed."
install -m 440 /tmp/wedpluz-api-sudoers /etc/sudoers.d/wedpluz-api && rm -f /tmp/wedpluz-api-sudoers
supervisorctl reread >/dev/null && supervisorctl update >/dev/null
if [[ ! -f "$APP_DIR/current/artisan" ]]; then
    supervisorctl stop 'wedpluz-api:*' >/dev/null 2>&1 || true
    supervisorctl stop wedpluz-api-reverb >/dev/null 2>&1 || true
fi
ok "Supervisor wedpluz-api:* ($QUEUE_WORKERS fast + 1 long worker$([[ $WITH_REVERB -eq 1 ]] && echo ", wedpluz-api-reverb on :$REVERB_PORT")); /etc/cron.d/wedpluz-api; /etc/sudoers.d/wedpluz-api"

# ------------------------------------------------------------------ TLS
if [[ $WITH_SSL -eq 1 ]]; then
    step "Let's Encrypt certificate for $DOMAIN"
    certbot --nginx -d "$DOMAIN" --cert-name wedpluz-api --non-interactive --agree-tos -m "$EMAIL" --redirect --no-eff-email
    systemctl reload nginx
    ok "HTTPS enabled; renews automatically"
fi

# ------------------------------------------------------------------ summary
trap - ERR
cat <<EOF

${GREEN}${BOLD}Wedpluz API provisioned.${RESET}

  App directory   $APP_DIR   (owner $APP_USER)
  Environment     $ENV_FILE   (mode 600, $APP_USER only)
  Secrets         $CREDENTIALS_FILE   (root only)
  PHP-FPM         pool wedpluz in $FPM_SERVICE · $FPM_SOCKET
  Deploy          as $SSH_USER: $([[ $SAME_USER -eq 1 ]] || echo "sudo -u $APP_USER ")wedpluz-deploy --artifact <file>   (or GitHub Actions)
  Logs            $APP_DIR/shared/storage/logs · $LOG_DIR · /var/log/nginx/wedpluz-api.*
  Backups         $BACKUP_DIR (nightly 01:30 UTC)

${BOLD}Next steps${RESET}
  1. Edit the environment:  $([[ $SAME_USER -eq 1 ]] || echo "sudo -u $APP_USER ")nano $ENV_FILE   (provider keys, web origins, CORS)
  2. $([[ $WITH_SSL -eq 1 ]] && echo "HTTPS is on." || echo "Point DNS for $DOMAIN here, then: sudo certbot --nginx -d $DOMAIN --cert-name wedpluz-api --redirect -m <email> --agree-tos")
  3. GitHub Actions secrets (docs/deployment-vps.md, step 4): VPS_USER=$SSH_USER$([[ $SAME_USER -eq 1 ]] || echo ", variable VPS_APP_USER=$APP_USER").
EOF
