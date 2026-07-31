#!/bin/sh
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
BASELINE_FILE="$ROOT_DIR/.upstream-main"
UPSTREAM_URL=${UPSTREAM_URL:-https://github.com/advocaite/TravianT4.6.git}

if ! command -v git >/dev/null 2>&1; then
    echo 'Required tool not found: git.' >&2
    exit 1
fi

if [ ! -r "$BASELINE_FILE" ]; then
    echo "Upstream baseline not found: $BASELINE_FILE" >&2
    exit 1
fi

BASELINE=$(tr -d '\r\n' < "$BASELINE_FILE")
case "$BASELINE" in
    *[!0-9a-f]*|'')
        echo 'The recorded upstream baseline is not a lowercase Git commit hash.' >&2
        exit 1
        ;;
esac
if [ "${#BASELINE}" -ne 40 ]; then
    echo 'The recorded upstream baseline must be a full 40-character commit hash.' >&2
    exit 1
fi

REMOTE_LINE=$(git ls-remote "$UPSTREAM_URL" refs/heads/main)
REMOTE_SHA=${REMOTE_LINE%%[[:space:]]*}

if [ -z "$REMOTE_SHA" ]; then
    echo 'Unable to resolve upstream main.' >&2
    exit 1
fi

if [ "$REMOTE_SHA" = "$BASELINE" ]; then
    echo "Upstream main is synchronized at $BASELINE."
    exit 0
fi

cat >&2 <<EOF
Upstream main has changed.
Recorded: $BASELINE
Current:  $REMOTE_SHA

Review and port the new upstream commits using docs/UPSTREAM.md. Do not merge
the legacy upstream history directly into a sanitized public branch.
EOF
exit 1
