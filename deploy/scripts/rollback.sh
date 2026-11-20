#!/usr/bin/env bash
#
# Wedpluz API — roll back to a previous release (runs on the server as the app user):
#   wedpluz-rollback [--list | --to <release>]     (as the app user, usir by default)
#
# Usage:
#   rollback.sh                 # switch to the release before the current one
#   rollback.sh --list          # list releases, newest first
#   rollback.sh --to <release>  # switch to a named release (directory name under releases/)
#
# Options: --app-dir DIR (default /var/www/apps/wedpluz/api) · --php-version X.Y (default 8.5)
#
# Database migrations are NOT reverted. Migrations are written to be backward
# compatible for one release, so the previous code runs on the newer schema.
# To undo a migration as well, run `php artisan migrate:rollback --step=N`
# from the newer release BEFORE switching back.
set -Eeuo pipefail

APP_DIR="/var/www/apps/wedpluz/api"
PHP_VERSION="8.5"
PHP_BIN="${PHP_BIN:-}"
TARGET=""
LIST=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --to) TARGET="$2"; shift 2 ;;
        --list) LIST=1; shift ;;
        --app-dir) APP_DIR="${2%/}"; shift 2 ;;
        --php-version) PHP_VERSION="$2"; shift 2 ;;
        -h|--help) sed -n '3,16p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Unknown option: $1" >&2; exit 1 ;;
    esac
done

[[ -n "$PHP_BIN" ]] || PHP_BIN="$(command -v "php${PHP_VERSION}" || command -v php)"
RELEASES_DIR="$APP_DIR/releases"
CURRENT_LINK="$APP_DIR/current"
CURRENT=$(readlink -f "$CURRENT_LINK" 2>/dev/null || true)
CURRENT_NAME=$(basename "${CURRENT:-none}")   # release names are unique; compare names, not path forms
mapfile -t RELEASES < <(ls -1dt "$RELEASES_DIR"/*/ 2>/dev/null | sed 's:/*$::')

if [[ $LIST -eq 1 ]]; then
    for r in "${RELEASES[@]}"; do
        marker="  "; [[ "$(basename "$r")" == "$CURRENT_NAME" ]] && marker="* "
        echo "$marker$(basename "$r")   revision $(cat "$r/REVISION" 2>/dev/null || echo '?')"
    done
    exit 0
fi

if [[ -n "$TARGET" ]]; then
    DEST="$RELEASES_DIR/$TARGET"
else
    DEST=""
    for i in "${!RELEASES[@]}"; do
        if [[ "$(basename "${RELEASES[$i]}")" == "$CURRENT_NAME" ]]; then
            DEST="${RELEASES[$((i + 1))]:-}"
            break
        fi
    done
fi
[[ -n "$DEST" && -d "$DEST" && -f "$DEST/artisan" ]] || { echo "No release to roll back to (use --list)." >&2; exit 1; }
[[ "$(basename "$DEST")" != "$CURRENT_NAME" ]] || { echo "$(basename "$DEST") is already live." >&2; exit 1; }

echo "==> Rolling back: $CURRENT_NAME -> $(basename "$DEST")"
ln -sfn "$DEST" "$APP_DIR/.current.tmp"
mv -Tf "$APP_DIR/.current.tmp" "$CURRENT_LINK"
sudo -n /usr/bin/systemctl reload "php${PHP_VERSION}-fpm" 2>/dev/null && echo "    ✓ PHP-FPM reloaded" || echo "    ! Reload php${PHP_VERSION}-fpm manually to clear OPcache."
(cd "$CURRENT_LINK" && "$PHP_BIN" artisan queue:restart --no-interaction >/dev/null) && echo "    ✓ Queue workers restarting on $(basename "$DEST")"
echo "[$(date -u '+%Y-%m-%d %H:%M:%S')] ROLLBACK $CURRENT_NAME -> $(basename "$DEST")" >> "$APP_DIR/deploy.log"
echo "Done. Check: curl -s \$APP_URL/api/v1/health"
