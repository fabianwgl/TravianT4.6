#!/bin/sh
set -eu

cd "$(dirname "$0")/.."

if rg -n --hidden \
    --glob '!.git/**' \
    --glob '!scripts/check-public-hygiene.sh' \
    '(/Users/|BEGIN (OPENSSH|RSA|EC) PRIVATE KEY|sftp\.json|statcounter|molon-lave|chamirhossein|travianarab@yahoo)' .; then
    echo 'Sensitive path, key, or legacy personal metadata found.' >&2
    exit 1
fi

if rg -n --hidden \
    --glob '!.git/**' \
    --glob '!scripts/check-public-hygiene.sh' \
    '[[:alnum:]._%+-]+@(gmail|yahoo|hotmail|outlook|icloud)\.[[:alpha:]]{2,}' .; then
    echo 'Consumer email address found.' >&2
    exit 1
fi

tracked_env_files=$(git ls-files | rg '(^|/)\.env$' || true)
if [ -n "$tracked_env_files" ]; then
    echo "Tracked local environment file(s): $tracked_env_files" >&2
    exit 1
fi

tracked_sensitive_files=$(git ls-files | rg -i \
    '(^|/)(\.env|\.npmrc|\.pypirc|credentials|secrets?|id_rsa|id_ed25519)(\.|$|/)|\.(pem|p12|pfx|key)$' \
    | rg -v '(^|/)\.env\.example$' || true)
if [ -n "$tracked_sensitive_files" ]; then
    echo "Tracked sensitive file name(s): $tracked_sensitive_files" >&2
    exit 1
fi

echo 'Public-repository hygiene check passed.'
