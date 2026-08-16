#!/bin/sh
set -eu

case "${GAME_MAP_SIZE:-25}" in
  *[!0-9]*|'') echo "GAME_MAP_SIZE must be numeric" >&2; exit 1 ;;
esac

case "${GAME_SPEED:-10}" in
  *[!0-9]*|'') echo "GAME_SPEED must be numeric" >&2; exit 1 ;;
esac

game_db="${GAME_DB_NAME:-openvillage_game}"
global_db="${MARIADB_DATABASE:-openvillage_global}"
app_user="${MARIADB_USER:-openvillage}"

for identifier in "$game_db" "$global_db" "$app_user"; do
  case "$identifier" in
    *[!A-Za-z0-9_]*|'') echo "Database names and users may contain only letters, numbers, and underscores" >&2; exit 1 ;;
  esac
done

run_root() {
  mariadb --protocol=socket -uroot -p"${MARIADB_ROOT_PASSWORD}" "$@"
}

run_root <<SQL
CREATE DATABASE IF NOT EXISTS \`${global_db}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS \`${game_db}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON \`${global_db}\`.* TO '${app_user}'@'%';
GRANT ALL PRIVILEGES ON \`${game_db}\`.* TO '${app_user}'@'%';
FLUSH PRIVILEGES;
SQL

run_root "$global_db" < /workspace/main.sql
run_root "$game_db" < /workspace/main_script/include/schema/T4.4.sql

run_root "$game_db" <<SQL
INSERT INTO config (
  startTime, map_size, worldUniqueId, installed, loginInfoTitle, loginInfoHTML, message
) VALUES (
  UNIX_TIMESTAMP(), ${GAME_MAP_SIZE}, 1, 0, '', '', ''
);
SQL

run_root "$global_db" <<SQL
UPDATE gameServers
SET speed=${GAME_SPEED}, startTime=UNIX_TIMESTAMP()
WHERE id=1;
SQL
