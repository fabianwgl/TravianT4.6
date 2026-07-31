#!/bin/sh
set -eu

cd "$(dirname "$0")/.."
umask 077

backup_dir=${BACKUP_DIR:-backups}
mkdir -p "$backup_dir"
timestamp=$(date -u +%Y%m%dT%H%M%SZ)
target="$backup_dir/openvillage-$timestamp.sql.gz"
temporary=$(mktemp "$backup_dir/.openvillage-backup.XXXXXX")
trap 'rm -f "$temporary" "$temporary.gz"' EXIT INT TERM

docker compose exec -T database sh -eu -c '
  exec mariadb-dump \
    --protocol=socket \
    --user=root \
    --password="$MARIADB_ROOT_PASSWORD" \
    --single-transaction \
    --routines \
    --events \
    --triggers \
    --databases "$MARIADB_DATABASE" "$GAME_DB_NAME"
' > "$temporary"

if ! rg -q '^-- MariaDB dump|^-- MySQL dump' "$temporary"; then
    echo 'Database dump did not contain the expected header.' >&2
    exit 1
fi

gzip --best "$temporary"
gzip -t "$temporary.gz"
mv "$temporary.gz" "$target"
trap - EXIT INT TERM

echo "Backup created: $target"
