#!/usr/bin/env bash
#
# Wedpluz API — PostgreSQL backup (installed as /usr/local/bin/wedpluz-backup;
# runs nightly from /etc/cron.d/wedpluz-api as the app user, usir by default).
#
# Writes a compressed custom-format dump (restorable with pg_restore) and keeps
# the last N days. Reads the connection from shared/.env. Optionally copies the
# dump off the server with BACKUP_S3_URI (needs the aws CLI) — strongly
# recommended: a backup on the same disk does not survive losing the server.
#
# Usage: wedpluz-backup [--app-dir DIR] [--dir /var/backups/apps/wedpluz] [--keep-days 14]
#
# Restore (into an empty database):
#   pg_restore --clean --if-exists --no-owner -d "$DB_DATABASE" wedpluz-YYYYmmdd-HHMMSS.dump
set -Eeuo pipefail
umask 077

APP_DIR="/var/www/apps/wedpluz/api"
BACKUP_DIR="/var/backups/apps/wedpluz"
KEEP_DAYS=14

while [[ $# -gt 0 ]]; do
    case "$1" in
        --app-dir) APP_DIR="${2%/}"; shift 2 ;;
        --dir) BACKUP_DIR="${2%/}"; shift 2 ;;
        --keep-days) KEEP_DAYS="$2"; shift 2 ;;
        -h|--help) sed -n '3,15p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Unknown option: $1" >&2; exit 1 ;;
    esac
done

ENV_FILE="$APP_DIR/shared/.env"
env_value() {
    local raw
    raw=$(grep -E "^$1=" "$ENV_FILE" | tail -n1 | cut -d= -f2- || true)
    raw="${raw%\"}"; raw="${raw#\"}"; raw="${raw%\'}"; raw="${raw#\'}"
    printf '%s' "$raw"
}

DB_HOST=$(env_value DB_HOST); DB_PORT=$(env_value DB_PORT); DB_DATABASE=$(env_value DB_DATABASE)
DB_USERNAME=$(env_value DB_USERNAME); S3_URI=$(env_value BACKUP_S3_URI)
export PGPASSWORD; PGPASSWORD=$(env_value DB_PASSWORD)

mkdir -p "$BACKUP_DIR"
FILE="$BACKUP_DIR/wedpluz-$(date -u +%Y%m%d-%H%M%S).dump"

pg_dump --host="${DB_HOST:-127.0.0.1}" --port="${DB_PORT:-5432}" --username="$DB_USERNAME" \
    --format=custom --compress=9 --no-owner --file="$FILE.partial" "$DB_DATABASE"
mv "$FILE.partial" "$FILE"
pg_restore --list "$FILE" >/dev/null   # proves the dump is readable
echo "[$(date -u '+%F %T')] backup $FILE ($(du -h "$FILE" | cut -f1))"

if [[ -n "$S3_URI" ]]; then
    aws s3 cp --only-show-errors "$FILE" "${S3_URI%/}/$(basename "$FILE")"
    echo "[$(date -u '+%F %T')] copied to ${S3_URI%/}/"
fi

find "$BACKUP_DIR" -name 'wedpluz-*.dump' -type f -mtime +"$KEEP_DAYS" -delete
