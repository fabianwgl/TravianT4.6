#!/bin/sh
set -eu

cd "$(dirname "$0")/.."

command -v node >/dev/null 2>&1 || {
    echo 'Node.js is required for JavaScript syntax verification.' >&2
    exit 1
}

find main_script sections web -type f -name '*.js' -exec sh -c '
    for file do
        node --check "$file" >/dev/null || exit 1
    done
' sh {} +

echo 'JavaScript syntax check passed.'
