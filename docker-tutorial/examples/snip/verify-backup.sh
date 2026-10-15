#!/bin/sh
# Restores the newest backup into a throwaway PostgreSQL container and checks it (chapter 18).
# Run it from cron after backup.sh; it exits non-zero if the backup can't be restored.
#   ./verify-backup.sh
set -eu
cd "$(dirname "$0")"
file=$(ls -1t backups/snip-*.dump | head -1)
docker run -d --name snip-restore-test -e POSTGRES_PASSWORD=test postgres:18-alpine >/dev/null
trap 'docker rm -f snip-restore-test >/dev/null' EXIT
until docker exec snip-restore-test pg_isready -h 127.0.0.1 -U postgres >/dev/null 2>&1; do sleep 1; done
docker exec snip-restore-test createdb -U postgres snip
docker exec -i snip-restore-test pg_restore -U postgres -d snip --no-owner < "$file"
echo "$file: $(docker exec snip-restore-test psql -U postgres -d snip -tAc \
  "SELECT count(*) || ' links, newest ' || coalesce(max(created_at)::text, 'none') FROM links")"
