#!/bin/sh
set -eu

cd "$(dirname "$0")/.."

# Pin the analyzer image so local and CI checks use the same toolchain.
phpstan_image='ghcr.io/phpstan/phpstan@sha256:e8587192fb4c241f3b2791afd35b4c8fce425e6e70fad25cd379294231394d5e'
docker run --rm --workdir /app -v "$PWD:/app:ro" "$phpstan_image" \
    analyse -c /app/phpstan.neon --no-progress --error-format=table /app/main_script/include

echo 'PHP static analysis passed.'
