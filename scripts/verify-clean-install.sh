#!/bin/sh
set -eu

cd "$(dirname "$0")/.."

clean_project=${CLEAN_PROJECT_NAME:-openvillage-clean-$$}
clean_port=${CLEAN_APP_PORT:-$((20000 + ($$ % 20000)))}
clean_files="compose.yaml:compose.clean.yaml"

cleanup() {
    COMPOSE_PROJECT_NAME="$clean_project" \
    COMPOSE_FILE="$clean_files" \
    APP_PORT="$clean_port" \
        docker compose down -v --remove-orphans >/dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM

COMPOSE_PROJECT_NAME="$clean_project" \
COMPOSE_FILE="$clean_files" \
APP_PORT="$clean_port" \
    docker compose up -d --build --wait

COMPOSE_PROJECT_NAME="$clean_project" \
COMPOSE_FILE="$clean_files" \
APP_PORT="$clean_port" \
APP_URL="http://127.0.0.1:$clean_port" \
RUN_GAME_SMOKE=1 \
    ./scripts/verify.sh

echo 'Disposable clean-install verification passed.'
