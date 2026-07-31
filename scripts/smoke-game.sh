#!/bin/sh
set -eu

base_url=${APP_URL:-http://127.0.0.1:8080}
work_dir=$(mktemp -d)
trap 'rm -rf "$work_dir"' EXIT INT TERM

launcher_cookies="$work_dir/launcher.cookies"
game_cookies="$work_dir/game.cookies"
headers="$work_dir/headers"
body="$work_dir/body"
suffix=$(date +%s | tail -c 9)
player="Smoke${suffix}"
email="smoke-${suffix}@example.test"
password="Local-smoke-${suffix}-pass"

request_status() {
    expected=$1
    shift
    actual=$(curl -sS -o "$body" -D "$headers" -w '%{http_code}' "$@")
    if [ "$actual" != "$expected" ]; then
        echo "Expected HTTP $expected, received $actual." >&2
        sed -n '1,80p' "$body" >&2
        exit 1
    fi
}

expect_body() {
    if ! rg -q "$1" "$body"; then
        echo "Response did not contain expected text: $1" >&2
        exit 1
    fi
}

request_status 200 "$base_url/health.php"
expect_body '"status":"ready"'

request_status 200 -c "$launcher_cookies" "$base_url/"
csrf=$(sed -n 's/.*name="csrf" value="\([^"]*\)".*/\1/p' "$body" | head -n 1)
if [ -z "$csrf" ]; then
    echo 'Launcher CSRF token was not rendered.' >&2
    exit 1
fi

request_status 303 -b "$launcher_cookies" -c "$launcher_cookies" \
    --data-urlencode "csrf=$csrf" \
    --data-urlencode "username=$player" \
    --data-urlencode "email=$email" \
    --data-urlencode "password=$password" \
    --data-urlencode 'terms=1' \
    "$base_url/register.php"

activation_path=$(awk 'tolower($1) == "location:" {gsub("\r", "", $2); print $2}' "$headers" | tail -n 1)
case "$activation_path" in
    /game/activate.php?token=*) ;;
    *) echo "Unexpected activation location: $activation_path" >&2; exit 1 ;;
esac

request_status 200 -b "$launcher_cookies" -c "$game_cookies" "$base_url$activation_path"
expect_body 'Select your tribe'

request_status 302 -b "$game_cookies" -c "$game_cookies" --data 'vid=1' \
    "$base_url/game/activate.php?page=vid"
request_status 200 -b "$game_cookies" -c "$game_cookies" \
    "$base_url/game/activate.php?page=sector"
expect_body 'Select your starting position'

activation_completed=0
for sector in sw se nw ne; do
    request_status 200 -b "$game_cookies" -c "$game_cookies" --data "sector=$sector" \
        "$base_url/game/activate.php?page=confirmation"
    expect_body 'PLAY NOW'

    actual=$(curl -sS -o "$body" -D "$headers" -w '%{http_code}' \
        -b "$game_cookies" -c "$game_cookies" --data "sector=$sector" \
        "$base_url/game/activate.php?page=dorf")
    if [ "$actual" = '302' ]; then
        activation_completed=1
        break
    fi
    if [ "$actual" != '200' ] || ! rg -qi 'unable to generate a new village' "$body"; then
        echo "Expected activation redirect or an unavailable sector, received HTTP $actual." >&2
        sed -n '1,80p' "$body" >&2
        exit 1
    fi
done
if [ "$activation_completed" -ne 1 ]; then
    echo 'No starting sector had an available village field.' >&2
    exit 1
fi
request_status 200 -b "$game_cookies" -c "$game_cookies" \
    "$base_url/game/dorf1.php?finished=1"
expect_body "$player"

request_status 200 -c "$launcher_cookies" "$base_url/game/login.php"
request_status 302 -b "$launcher_cookies" -c "$launcher_cookies" \
    --data-urlencode "name=$player" \
    --data-urlencode "password=$password" \
    --data-urlencode 's1=Login' \
    --data-urlencode 'w=1440:900' \
    "$base_url/game/dorf1.php"

for route in dorf1.php dorf2.php karte.php 'build.php?id=1' profile.php 'options.php?s=2' 'options.php?s=3'; do
    request_status 200 -b "$launcher_cookies" "$base_url/game/$route"
    if rg -q 'Fatal error|Uncaught (Error|Exception)|class="outerLoginBox"' "$body"; then
        echo "Authenticated gameplay check failed for $route." >&2
        exit 1
    fi
    if [ "$route" = 'options.php?s=2' ]; then
        expect_body 'name="mpvt_token"'
        if rg -q 'email_abbrechen|a=1&amp;e=2' "$body"; then
            echo 'Account options still expose a state-changing GET cancellation link.' >&2
            exit 1
        fi
    fi
    if [ "$route" = 'options.php?s=3' ]; then
        expect_body 'name="mpvt_token"'
        if rg -q 'options.php\?s=3&amp;e=3&amp;id=' "$body"; then
            echo 'Sitter options still expose a state-changing GET mutation link.' >&2
            exit 1
        fi
    fi
done

echo "Game smoke test passed for $player."
