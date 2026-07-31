#!/bin/sh
set -eu

cd "$(dirname "$0")/.."
verify_started_at=$(date -u '+%Y-%m-%dT%H:%M:%SZ')

docker compose config --quiet
./scripts/check-public-hygiene.sh

checkout_checksum=$(./scripts/source-checksum.sh)
if ! container_checksum=$(docker compose exec -T app /app/scripts/source-checksum.sh); then
    echo 'The running application image does not contain the current verification tooling.' >&2
    echo 'Rebuild it with: docker compose up -d --build --wait' >&2
    exit 1
fi
if [ "$checkout_checksum" != "$container_checksum" ]; then
    echo 'The running application image does not match this checkout.' >&2
    echo 'Rebuild it with: docker compose up -d --build --wait' >&2
    exit 1
fi

docker compose exec -T app sh -lc \
    "find /app/main_script /app/web /app/sections -type f -name '*.php' -exec sh -c 'for file do output=\$(php -l \"\$file\" 2>&1) || { echo \"\$output\"; exit 1; }; done' sh {} +"
echo 'PHP syntax check passed.'

docker compose exec -T database mariadb -N \
    -u"${GAME_DB_USER:-openvillage}" \
    -p"${GAME_DB_PASSWORD:-local-game-password}" \
    "${GAME_DB_NAME:-openvillage_game}" \
    -e "SELECT COUNT(*) FROM openvillage_schema_migrations WHERE version IN ('001_ajax_token_length.sql', '002_adventure_uid_unsigned.sql', '003_scheduled_task_failures.sql');" \
    | rg -q '^3$'

docker compose exec -T database mariadb -N \
    -u"${GAME_DB_USER:-openvillage}" \
    -p"${GAME_DB_PASSWORD:-local-game-password}" \
    "${GLOBAL_DB_NAME:-openvillage_global}" \
    -e "SELECT COUNT(*) FROM openvillage_global_schema_migrations WHERE version IN ('001_notification_delivery_key.sql', '002_mail_delivery_key.sql');" \
    | rg -q '^2$'

base_url=${APP_URL:-http://127.0.0.1:8080}
curl -fsS "$base_url/health.php" | rg -q '"status":"ready"'
./scripts/test-runtime.sh
./scripts/test-complete-round.sh
if [ "${RUN_GAME_SMOKE:-0}" = '1' ]; then
    ./scripts/smoke-game.sh
fi

if docker compose logs --since="$verify_started_at" app worker | rg -q \
    'PHP (Fatal error|Parse error)|Uncaught (Error|Exception)|mysqldump: not found'; then
    echo 'Fatal application or worker error found in recent logs.' >&2
    exit 1
fi

echo 'OpenVillage verification passed.'
