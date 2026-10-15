#!/bin/sh
# A consistent PostgreSQL backup in ./backups, keeping the newest 14 (chapter 18).
#   ./backup.sh
# Copy the files off this server too: a backup on the same disk isn't a backup.
# Restore, replacing the current data (stop the api and worker first):
#   docker compose stop api worker
#   docker compose exec -T db pg_restore -U snip -d snip --clean --if-exists < backups/<file>.dump
#   docker compose start api worker
set -eu
cd "$(dirname "$0")"
mkdir -p backups
file="backups/snip-$(date -u +%Y%m%dT%H%M%SZ).dump"
# pg_dump reads a consistent snapshot while the app keeps running. Writing to a temporary
# name first means a failed dump never looks like a good backup.
docker compose exec -T db pg_dump -U snip -d snip --format=custom > "$file.partial"
mv "$file.partial" "$file"
echo "wrote $file ($(du -h "$file" | cut -f1))"
ls -1t backups/snip-*.dump | tail -n +15 | xargs -r rm --
