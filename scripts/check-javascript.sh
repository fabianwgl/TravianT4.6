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

legacy_clients='main_script/copyable/public/crypt-1554471962.js main_script/copyable/public/crypt-lowres-1554471962.js'
if rg -n 'eval\(responsePayload|setTimeout\("Travian\.TimersAndCounters|motorCosmetologyRioted' $legacy_clients; then
    echo 'Legacy client contains JavaScript execution blocked by the runtime CSP.' >&2
    exit 1
fi

echo 'JavaScript syntax check passed.'
