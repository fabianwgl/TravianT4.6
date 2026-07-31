#!/bin/sh
set -eu

cd "$(dirname "$0")/.."
docker compose exec -T app php /app/tests/formula-golden-regression.php
