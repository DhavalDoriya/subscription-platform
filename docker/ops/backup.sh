#!/usr/bin/env sh
# Nightly PostgreSQL backup (T9.5). Run from cron or the scheduler container:
#   0 3 * * *  /var/www/html/docker/ops/backup.sh
# Needs PGHOST PGUSER PGPASSWORD PGDATABASE in the environment; BACKUP_DIR defaults to /backups, KEEP_DAYS to 14.
# Optional off-site copy: set BACKUP_S3_URI (e.g. s3://bucket/billing) and have the aws cli installed.
set -eu

BACKUP_DIR="${BACKUP_DIR:-/backups}"
KEEP_DAYS="${KEEP_DAYS:-14}"
FILE="$BACKUP_DIR/billing-$(date -u +%Y%m%dT%H%M%SZ).dump"

mkdir -p "$BACKUP_DIR"
pg_dump --format=custom --no-owner --file="$FILE" "$PGDATABASE"
pg_restore --list "$FILE" > /dev/null # a dump that cannot be listed is not a backup

if [ -n "${BACKUP_S3_URI:-}" ]; then
    aws s3 cp "$FILE" "$BACKUP_S3_URI/"
fi

find "$BACKUP_DIR" -name 'billing-*.dump' -mtime "+$KEEP_DAYS" -delete
echo "backup ok: $FILE"
