#!/bin/sh
set -eu

cd "$(dirname "$0")/.."

find main_script web sections tests \
    -path 'main_script/copyable/include/runtime' -prune -o \
    -type f -exec cksum {} + \
    | LC_ALL=C sort \
    | cksum \
    | awk '{print $1 ":" $2}'
