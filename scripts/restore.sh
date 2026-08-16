#!/bin/sh
set -eu

cd "$(dirname "$0")/.."

if [ "${RESTORE_CONFIRM:-}" != yes ]; then
    echo 'Restore replaces both OpenVillage databases.' >&2
    echo 'Re-run with RESTORE_CONFIRM=yes and a verified .sql.gz backup.' >&2
    exit 2
fi

backup_file=${1:-}
if [ -z "$backup_file" ] || [ ! -f "$backup_file" ]; then
    echo 'Usage: RESTORE_CONFIRM=yes ./scripts/restore.sh BACKUP.sql.gz' >&2
    exit 2
fi

case "$backup_file" in
    *.sql.gz) ;;
    *) echo 'Backup must use the .sql.gz extension.' >&2; exit 2 ;;
esac

gzip -t "$backup_file"
temporary=$(mktemp)
services_stopped=0

cleanup() {
    rm -f "$temporary"
    if [ "$services_stopped" -eq 1 ]; then
        docker compose start app worker >/dev/null 2>&1 || true
    fi
}
trap cleanup EXIT INT TERM

gzip -dc "$backup_file" > "$temporary"
if ! rg -q '^-- MariaDB dump|^-- MySQL dump' "$temporary"; then
    echo 'Backup did not contain the expected database-dump header.' >&2
    exit 1
fi

docker compose stop app worker
services_stopped=1
docker compose exec -T database sh -eu -c '
  exec mariadb --protocol=socket --user=root --password="$MARIADB_ROOT_PASSWORD"
' < "$temporary"
docker compose run --rm bootstrap
docker compose up -d app worker
services_stopped=0
rm -f "$temporary"
trap - EXIT INT TERM

echo 'Restore completed. Run ./scripts/verify.sh before accepting traffic.'
