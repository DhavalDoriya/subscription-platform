#!/usr/bin/env sh
# Restore drill (T9.5): restores a dump into a scratch database and checks the numbers that matter, without touching production.
#   docker/ops/restore-drill.sh /backups/billing-20261101T030000Z.dump
# Needs PGHOST PGUSER PGPASSWORD in the environment and permission to create a database.
set -eu

DUMP="${1:?usage: restore-drill.sh <dump file>}"
SCRATCH="billing_restore_drill"

dropdb --if-exists "$SCRATCH"
createdb "$SCRATCH"
pg_restore --no-owner --dbname="$SCRATCH" "$DUMP"

for table in users providers subscriptions payments commissions webhook_events; do
    printf '%-16s %s\n' "$table" "$(psql -At -d "$SCRATCH" -c "select count(*) from $table")"
done

echo "Compare these counts with production, then: dropdb $SCRATCH"
