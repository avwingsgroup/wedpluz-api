#!/usr/bin/env bash
#
# Wedpluz API — zero-downtime release deployment.
#
# Runs ON THE SERVER as the app user (deploy-wedpluz), e.g.
#   sudo -u deploy-wedpluz wedpluz-deploy --artifact /tmp/wedpluz-api-<sha>.tar.gz
# Each release gets its own directory;
# the `current` symlink is switched atomically only after the release is
# installed, migrated and cached. If the health check fails after the switch,
# the previous release is restored automatically.
#
#   /var/www/apps/wedpluz/api/
#   ├── current -> releases/20261120101500-3f2a9c1
#   ├── releases/            one directory per release (last N kept)
#   ├── shared/.env          production environment (never in a release)
#   ├── shared/storage/      logs, cache, private files (survives releases)
#   └── deploy.log           history of every deployment
#
# Usage:
#   deploy.sh --artifact /tmp/wedpluz-api-<sha>.tar.gz   # built by CI (recommended)
#   deploy.sh --git <branch|tag|sha> --repo <git-url>    # build on the server
#
# Options:
#   --app-dir DIR        Application root            (default /var/www/apps/wedpluz/api)
#   --keep N             Releases to keep            (default 5)
#   --php-version X.Y    PHP version (php-fpm service and CLI binary)  (default 8.5)
#   --health-url URL     Health endpoint             (default APP_URL/api/v1/health from shared/.env)
#   --seed-reference     Run ReferenceDataSeeder (first deploy; idempotent)
#   --skip-migrations    Do not run database migrations
#   --skip-health        Do not run the post-switch health check (not recommended)
#   -h, --help           Show this help
#
# Exit codes: 0 deployed · 1 failed before switching (nothing changed)
#             2 failed after switching and rolled back
set -Eeuo pipefail
umask 027

APP_DIR="/var/www/apps/wedpluz/api"
KEEP_RELEASES=5
PHP_VERSION="8.5"
PHP_BIN="${PHP_BIN:-}"
ARTIFACT=""
GIT_REF=""
REPO_URL=""
HEALTH_URL=""
SEED_REFERENCE=0
SKIP_MIGRATIONS=0
SKIP_HEALTH=0

usage() { sed -n '3,31p' "$0" | sed 's/^# \{0,1\}//'; }

while [[ $# -gt 0 ]]; do
    case "$1" in
        --artifact) ARTIFACT="$2"; shift 2 ;;
        --git) GIT_REF="$2"; shift 2 ;;
        --repo) REPO_URL="$2"; shift 2 ;;
        --app-dir) APP_DIR="${2%/}"; shift 2 ;;
        --keep) KEEP_RELEASES="$2"; shift 2 ;;
        --php-version) PHP_VERSION="$2"; shift 2 ;;
        --health-url) HEALTH_URL="$2"; shift 2 ;;
        --seed-reference) SEED_REFERENCE=1; shift ;;
        --skip-migrations) SKIP_MIGRATIONS=1; shift ;;
        --skip-health) SKIP_HEALTH=1; shift ;;
        -h|--help) usage; exit 0 ;;
        *) echo "Unknown option: $1" >&2; usage; exit 1 ;;
    esac
done

# The versioned binary (php8.5), never the server-wide `php` default that other apps may rely on.
[[ -n "$PHP_BIN" ]] || PHP_BIN="$(command -v "php${PHP_VERSION}" || command -v php)"

RELEASES_DIR="$APP_DIR/releases"
SHARED_DIR="$APP_DIR/shared"
CURRENT_LINK="$APP_DIR/current"
LOG_FILE="$APP_DIR/deploy.log"
LOCK_FILE="$APP_DIR/.deploy.lock"

STARTED_AT=$(date +%s)
RELEASE_DIR=""
PREVIOUS_RELEASE=""
SWITCHED=0

# ---------------------------------------------------------------- output
if [[ -t 1 ]]; then BOLD=$'\e[1m'; GREEN=$'\e[32m'; YELLOW=$'\e[33m'; RED=$'\e[31m'; RESET=$'\e[0m'; else BOLD=""; GREEN=""; YELLOW=""; RED=""; RESET=""; fi
log() { local line; line="[$(date -u '+%Y-%m-%d %H:%M:%S')] $*"; echo "$line" >> "$LOG_FILE" 2>/dev/null || true; echo "$line"; }
step() { log "${BOLD}==> $*${RESET}"; }
ok() { log "${GREEN}    ✓ $*${RESET}"; }
warn() { log "${YELLOW}    ! $*${RESET}"; }
fail() { log "${RED}    ✗ $*${RESET}"; }

artisan() { (cd "$RELEASE_DIR" && "$PHP_BIN" artisan "$@" --no-interaction); }

# ---------------------------------------------------------------- failure handling
on_error() {
    local code=$? line=${1:-?}
    trap - ERR
    fail "Deployment failed at line $line (exit $code)."
    if [[ $SWITCHED -eq 1 && -n "$PREVIOUS_RELEASE" ]]; then
        rollback_to "$PREVIOUS_RELEASE"
        log "Release $(basename "$RELEASE_DIR") kept for inspection."
        exit 2
    fi
    if [[ -n "$RELEASE_DIR" && -d "$RELEASE_DIR" && "$(current_target)" != "$RELEASE_DIR" ]]; then
        rm -rf "$RELEASE_DIR"
        warn "Removed incomplete release $(basename "$RELEASE_DIR"); the live site was not changed."
    fi
    exit 1
}
trap 'on_error $LINENO' ERR

current_target() { [[ -L "$CURRENT_LINK" ]] && readlink -f "$CURRENT_LINK" || true; }
release_name() { [[ -n "${1:-}" ]] && basename "$1" || echo none; }

switch_to() {
    # Atomic: create a temporary link, then rename it over `current`.
    ln -sfn "$1" "$APP_DIR/.current.tmp"
    mv -Tf "$APP_DIR/.current.tmp" "$CURRENT_LINK"
}

reload_runtime() {
    # PHP-FPM reload clears OPcache (validate_timestamps=0 in production).
    # The sudoers rule installed by provision.sh allows exactly this command, without a password.
    if command -v sudo >/dev/null 2>&1 && sudo -n /usr/bin/systemctl reload "php${PHP_VERSION}-fpm" 2>/dev/null; then
        ok "PHP-FPM reloaded"
    else
        warn "Could not reload php${PHP_VERSION}-fpm (sudoers rule missing?); OPcache may serve old code until the next reload."
    fi
    # Workers finish their current job, exit, and Supervisor starts them on the new code.
    (cd "$CURRENT_LINK" && "$PHP_BIN" artisan queue:restart --no-interaction >/dev/null) && ok "Queue workers signalled to restart"
    # First deploy (or after a stop): make sure the workers are running at all.
    if command -v sudo >/dev/null 2>&1 && sudo -n /usr/bin/supervisorctl start 'wedpluz-api:*' >/dev/null 2>&1; then
        ok "Queue workers running"
    fi
    if [[ "$(env_value BROADCAST_CONNECTION)" == "reverb" ]]; then
        (cd "$CURRENT_LINK" && "$PHP_BIN" artisan reverb:restart --no-interaction >/dev/null 2>&1) || true
        if command -v sudo >/dev/null 2>&1; then sudo -n /usr/bin/supervisorctl start wedpluz-api-reverb >/dev/null 2>&1 || true; fi
    fi
}

rollback_to() {
    warn "Rolling back to $(basename "$1")"
    switch_to "$1"
    reload_runtime || true
    log "${YELLOW}Rolled back. Note: database migrations are not reverted automatically.${RESET}"
}

env_value() {
    # Read KEY from shared/.env without sourcing it (values may contain shell characters).
    local raw
    raw=$(grep -E "^$1=" "$SHARED_DIR/.env" | tail -n1 | cut -d= -f2- || true)
    raw="${raw%\"}"; raw="${raw#\"}"; raw="${raw%\'}"; raw="${raw#\'}"
    printf '%s' "$raw"
}

health_check() {
    local url="$1" attempt body code host scheme port resolve=()
    # Hit this server directly (bypassing DNS/CDN): resolve the public host to 127.0.0.1.
    scheme="${url%%://*}"
    host="${url#*://}"; host="${host%%/*}"; host="${host%%:*}"
    port=$([[ "$scheme" == "https" ]] && echo 443 || echo 80)
    [[ "$scheme" == http* ]] && resolve=(--resolve "$host:$port:127.0.0.1")
    for attempt in 1 2 3 4 5 6; do
        body=$(curl -sS --insecure --max-time 10 "${resolve[@]}" -w $'\n%{http_code}' "$url" 2>/dev/null || true)
        code="${body##*$'\n'}"; body="${body%$'\n'*}"
        if [[ "$code" == "000" && "$scheme" == "https" ]]; then
            # No TLS listener yet (before Certbot): check over plain HTTP instead.
            body=$(curl -sS --max-time 10 --resolve "$host:80:127.0.0.1" -w $'\n%{http_code}' "http://${url#https://}" 2>/dev/null || true)
            code="${body##*$'\n'}"; body="${body%$'\n'*}"
        fi
        if [[ "$code" == "200" || ( "$scheme" == "file" && -n "$body" ) ]]; then
            ok "Health check passed (attempt $attempt): $(echo "$body" | tr -d '\n' | cut -c1-160)"
            return 0
        fi
        warn "Health check attempt $attempt returned ${code:-no response}; retrying..."
        sleep 3
    done
    fail "Health check failed: $url"
    return 1
}

# ---------------------------------------------------------------- 0. preflight
mkdir -p "$RELEASES_DIR" "$SHARED_DIR"
exec 9>"$LOCK_FILE"
if command -v flock >/dev/null 2>&1; then
    flock -n 9 || { echo "Another deployment is running (lock: $LOCK_FILE)." >&2; exit 1; }
fi

step "Preflight"
[[ -n "$ARTIFACT" || -n "$GIT_REF" ]] || { fail "Pass --artifact FILE or --git REF --repo URL."; exit 1; }
[[ -z "$ARTIFACT" || -f "$ARTIFACT" ]] || { fail "Artifact not found: $ARTIFACT"; exit 1; }
[[ -z "$GIT_REF" || -n "$REPO_URL" ]] || { fail "--git requires --repo."; exit 1; }
[[ -f "$SHARED_DIR/.env" ]] || { fail "Missing $SHARED_DIR/.env (run provision.sh, then fill in the values)."; exit 1; }
[[ -n "$(env_value APP_KEY)" ]] || { fail "APP_KEY is empty in shared/.env."; exit 1; }
[[ "$(env_value APP_ENV)" == "production" || "$(env_value APP_ENV)" == "staging" ]] || warn "APP_ENV is '$(env_value APP_ENV)' (expected production or staging)."
[[ "$(env_value APP_DEBUG)" != "true" ]] || warn "APP_DEBUG=true on a server exposes stack traces. Set it to false."
FREE_MB=$(df -Pm "$APP_DIR" | awk 'NR==2 {print $4}')
[[ "${FREE_MB:-0}" -gt 500 ]] || { fail "Less than 500 MB free on $(df -P "$APP_DIR" | awk 'NR==2 {print $6}')."; exit 1; }
"$PHP_BIN" -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' || { fail "PHP 8.3+ required ($("$PHP_BIN" -r 'echo PHP_VERSION;'))."; exit 1; }
PREVIOUS_RELEASE=$(current_target)
ok "Environment $(env_value APP_ENV), PHP $("$PHP_BIN" -r 'echo PHP_VERSION;'), ${FREE_MB} MB free, live release: $(release_name "$PREVIOUS_RELEASE")"

# ---------------------------------------------------------------- 1. unpack the release
step "Installing release"
TIMESTAMP=$(date -u +%Y%m%d%H%M%S)
if [[ -n "$ARTIFACT" ]]; then
    RELEASE_DIR="$RELEASES_DIR/$TIMESTAMP"
    mkdir -p "$RELEASE_DIR"
    tar -xzf "$ARTIFACT" -C "$RELEASE_DIR"
else
    RELEASE_DIR="$RELEASES_DIR/$TIMESTAMP"
    git clone --quiet --depth 1 --branch "$GIT_REF" "$REPO_URL" "$RELEASE_DIR" 2>/dev/null \
        || { git clone --quiet "$REPO_URL" "$RELEASE_DIR" && git -C "$RELEASE_DIR" checkout --quiet "$GIT_REF"; }
    git -C "$RELEASE_DIR" rev-parse --short HEAD > "$RELEASE_DIR/REVISION"
    rm -rf "$RELEASE_DIR/.git"
    command -v composer >/dev/null 2>&1 || { fail "composer is required for --git deployments."; false; }
    (cd "$RELEASE_DIR" && composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader --quiet)
fi
REVISION=$(cat "$RELEASE_DIR/REVISION" 2>/dev/null || echo unknown)
NEW_NAME="$TIMESTAMP-${REVISION:0:7}"
mv "$RELEASE_DIR" "$RELEASES_DIR/$NEW_NAME"
RELEASE_DIR="$RELEASES_DIR/$NEW_NAME"
[[ -f "$RELEASE_DIR/artisan" && -d "$RELEASE_DIR/vendor" ]] || { fail "Release is missing artisan or vendor/."; false; }
ok "Release $NEW_NAME (revision $REVISION)"

# ---------------------------------------------------------------- 2. shared files
step "Linking shared environment and storage"
for d in app/public app/private framework/cache/data framework/sessions framework/views logs; do
    mkdir -p "$SHARED_DIR/storage/$d"
done
rm -rf "$RELEASE_DIR/storage" "$RELEASE_DIR/.env"
ln -s "$SHARED_DIR/storage" "$RELEASE_DIR/storage"
ln -s "$SHARED_DIR/.env" "$RELEASE_DIR/.env"
mkdir -p "$RELEASE_DIR/bootstrap/cache"
rm -f "$RELEASE_DIR"/bootstrap/cache/{config,routes-v7,events}.php
ln -sfn "$SHARED_DIR/storage/app/public" "$RELEASE_DIR/public/storage"
ok "shared/.env and shared/storage linked"

# ---------------------------------------------------------------- 3. database
if [[ $SKIP_MIGRATIONS -eq 0 ]]; then
    step "Running database migrations"
    artisan migrate --force --isolated
    ok "Migrations complete"
else
    warn "Migrations skipped (--skip-migrations)"
fi
if [[ $SEED_REFERENCE -eq 1 ]]; then
    step "Seeding reference data"
    artisan db:seed --class='Database\Seeders\ReferenceDataSeeder' --force
    ok "Reference data seeded"
fi

# ---------------------------------------------------------------- 4. caches
step "Building caches"
artisan config:cache >/dev/null
artisan route:cache >/dev/null
artisan view:cache >/dev/null
artisan event:cache >/dev/null
# The config cache contains every secret from .env: readable by the app user only (shared server).
chmod 600 "$RELEASE_DIR/bootstrap/cache/config.php"
ok "config, routes, views and events cached (config cache mode 600)"

# ---------------------------------------------------------------- 5. go live
step "Switching to the new release"
switch_to "$RELEASE_DIR"
SWITCHED=1
ok "current -> $NEW_NAME"
reload_runtime

# ---------------------------------------------------------------- 6. verify
if [[ $SKIP_HEALTH -eq 0 ]]; then
    step "Verifying"
    [[ -n "$HEALTH_URL" ]] || HEALTH_URL="$(env_value APP_URL | sed 's:/*$::')/api/v1/health"
    health_check "$HEALTH_URL"
else
    warn "Health check skipped (--skip-health)"
fi

# ---------------------------------------------------------------- 7. clean up
step "Cleaning up old releases (keeping $KEEP_RELEASES)"
mapfile -t OLD < <(ls -1dt "$RELEASES_DIR"/*/ 2>/dev/null | sed 's:/*$::' | tail -n +"$((KEEP_RELEASES + 1))")
for dir in "${OLD[@]}"; do
    # Compare release names (unique), not paths: symlinked roots can resolve to different path forms.
    name=$(basename "$dir")
    [[ "$name" == "$(release_name "$(current_target)")" || "$name" == "$(release_name "$PREVIOUS_RELEASE")" ]] && continue
    rm -rf "$dir" && ok "Removed $(basename "$dir")"
done

trap - ERR
log "${GREEN}${BOLD}Deployed $NEW_NAME in $(( $(date +%s) - STARTED_AT ))s.${RESET} Previous: $(release_name "$PREVIOUS_RELEASE")"
