#!/bin/sh
set -eu

base_url=${APP_URL:-http://127.0.0.1:8080}
work_dir=$(mktemp -d)

launcher_cookies="$work_dir/launcher.cookies"
game_cookies="$work_dir/game.cookies"
headers="$work_dir/headers"
body="$work_dir/body"
sitter_owner_id=''
sitter_id=''
suffix=$(date +%s | tail -c 9)
player="Smoke${suffix}"
email="smoke-${suffix}@example.test"
password="Local-smoke-${suffix}-pass"
sitter_player="Sit${suffix}"
sitter_password="Local-sitter-${suffix}-pass"

cleanup() {
    status=$?
    if [ -n "$sitter_owner_id" ] && [ -n "$sitter_id" ]; then
        if ! docker compose exec -T app php /app/tests/account-export-http-fixture.php \
            remove-sitter "$sitter_owner_id" "$sitter_id" "$player" "$sitter_player" >/dev/null; then
            echo 'Could not remove the disposable sitter fixture.' >&2
            status=1
        fi
    fi
    rm -rf "$work_dir"
    trap - EXIT INT TERM
    exit "$status"
}
trap cleanup EXIT INT TERM

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

expect_header() {
    header_name=$1
    expected=$2
    if ! rg -qi "^${header_name}:[[:space:]]*${expected}" "$headers"; then
        echo "Response headers did not contain ${header_name}: ${expected}." >&2
        sed -n '1,80p' "$headers" >&2
        exit 1
    fi
}

expect_account_export_json() {
    export_file=$1
    expected_name=$2
    if ! docker compose exec -T app php -r '
        $payload = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
        $expectedName = $argv[1];
        $identityFound = (($payload["metadata"]["player_id"] ?? 0) > 2
            && ($payload["profile"]["name"] ?? null) === $expectedName);
        $credentialKey = null;
        $walk = function ($value) use (&$walk, &$credentialKey): void {
            if (!is_array($value)) {
                return;
            }
            foreach ($value as $key => $child) {
                $keyName = strtolower((string)$key);
                if (preg_match("/(^|_)(password|passwd|passphrase|secret|token|credential|session|recovery|api[_-]?key)(_|$)/", $keyName)) {
                    $credentialKey = (string)$key;
                    return;
                }
                $walk($child);
            }
        };
        $walk($payload);
        if (!$identityFound) {
            fwrite(STDERR, "Export did not contain the disposable player identity.\n");
            exit(1);
        }
        if ($credentialKey !== null) {
            fwrite(STDERR, "Export contained a credential field: {$credentialKey}.\n");
            exit(1);
        }
    ' "$expected_name" < "$export_file"; then
        echo 'Account export was not valid JSON with the expected identity and safe fields.' >&2
        exit 1
    fi
    if rg -Fq "$password" "$export_file"; then
        echo 'Account export contained the submitted password value.' >&2
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

request_status 200 -b "$launcher_cookies" "$base_url/game/options.php?s=2"
if ! rg -q 'id="accountExport"' "$body"; then
    echo 'Account export form was not rendered for the disposable player.' >&2
    exit 1
fi
export_checker=$(awk '/<form id="accountExport"/ {inside=1} inside && /name="mpvt_token"/ {print; exit}' "$body" \
    | sed -n 's/.*value="\([^"]*\)".*/\1/p')
if [ -z "$export_checker" ]; then
    echo 'Account export checker token was not rendered.' >&2
    exit 1
fi

request_status 401 --cookie '' \
    --data-urlencode "mpvt_token=$export_checker" \
    --data-urlencode "export_password=$password" \
    "$base_url/game/account-export.php"

request_status 405 -b "$launcher_cookies" \
    "$base_url/game/account-export.php?mpvt_token=$export_checker"
expect_header 'Allow' 'POST'

request_status 403 -b "$launcher_cookies" \
    --data-urlencode "export_password=$password" \
    "$base_url/game/account-export.php?mpvt_token=$export_checker"

request_status 403 -b "$launcher_cookies" \
    --data-urlencode "export_password=$password" \
    "$base_url/game/account-export.php"

request_status 403 -b "$launcher_cookies" \
    --data-urlencode "mpvt_token[]=$export_checker" \
    --data-urlencode "export_password=$password" \
    "$base_url/game/account-export.php"

request_status 400 -b "$launcher_cookies" \
    --data-urlencode "mpvt_token=$export_checker" \
    --data-urlencode "export_password[]=$password" \
    "$base_url/game/account-export.php"

request_status 400 -b "$launcher_cookies" \
    --data-urlencode "mpvt_token=$export_checker" \
    "$base_url/game/account-export.php"

request_status 403 -b "$launcher_cookies" \
    --data-urlencode "mpvt_token=$export_checker" \
    --data-urlencode "export_password=${password}-wrong" \
    "$base_url/game/account-export.php"

request_status 200 -b "$launcher_cookies" \
    --data-urlencode "mpvt_token=$export_checker" \
    --data-urlencode 'uid=1' \
    --data-urlencode "export_password=$password" \
    "$base_url/game/account-export.php"
expect_header 'Content-Type' 'application/json;[[:space:]]*charset=utf-8'
expect_header 'Content-Disposition' 'attachment;[[:space:]]*filename="openvillage-account\.json"'
expect_header 'Cache-Control' 'private.*no-store'
expect_header 'X-Content-Type-Options' 'nosniff'
export_body="$work_dir/account-export.json"
cp "$body" "$export_body"
expect_account_export_json "$export_body" "$player"

request_status 429 -b "$launcher_cookies" \
    --data-urlencode "mpvt_token=$export_checker" \
    --data-urlencode "export_password=$password" \
    "$base_url/game/account-export.php"
expect_header 'Retry-After' '60'

fresh_cookies="$work_dir/fresh-game.cookies"
request_status 200 -c "$fresh_cookies" "$base_url/game/login.php"
request_status 302 -b "$fresh_cookies" -c "$fresh_cookies" \
    --data-urlencode "name=$player" \
    --data-urlencode "password=$password" \
    --data-urlencode 's1=Login' \
    --data-urlencode 'w=1440:900' \
    "$base_url/game/dorf1.php"
request_status 200 -b "$fresh_cookies" "$base_url/game/options.php?s=2"
fresh_checker=$(awk '/<form id="accountExport"/ {inside=1} inside && /name="mpvt_token"/ {print; exit}' "$body" \
    | sed -n 's/.*value="\([^"]*\)".*/\1/p')
if [ -z "$fresh_checker" ]; then
    echo 'Fresh login did not receive an account export checker token.' >&2
    exit 1
fi
for attempt in 1 2; do
    request_status 403 -b "$fresh_cookies" \
        --data-urlencode "mpvt_token=$fresh_checker" \
        --data-urlencode "export_password=${password}-wrong-$attempt" \
        "$base_url/game/account-export.php"
done
request_status 429 -b "$fresh_cookies" \
    --data-urlencode "mpvt_token=$fresh_checker" \
    --data-urlencode "export_password=${password}-wrong-final" \
    "$base_url/game/account-export.php"
expect_header 'Retry-After' '900'

sitter_fixture=$(docker compose exec -T app php /app/tests/account-export-http-fixture.php \
    create-sitter "$player" "$sitter_player" "$sitter_password")
sitter_owner_id=$(printf '%s\n' "$sitter_fixture" | awk '{print $1}')
sitter_id=$(printf '%s\n' "$sitter_fixture" | awk '{print $2}')
if ! printf '%s %s\n' "$sitter_owner_id" "$sitter_id" | rg -q '^[1-9][0-9]* [1-9][0-9]*$'; then
    echo 'Disposable sitter fixture did not return valid player IDs.' >&2
    exit 1
fi
sitter_cookies="$work_dir/sitter-game.cookies"
request_status 200 -c "$sitter_cookies" "$base_url/game/login.php"
request_status 302 -b "$sitter_cookies" -c "$sitter_cookies" \
    --data-urlencode "name=$player" \
    --data-urlencode "password=$sitter_password" \
    --data-urlencode 's1=Login' \
    --data-urlencode 'w=1440:900' \
    "$base_url/game/dorf1.php"
request_status 200 -b "$sitter_cookies" "$base_url/game/options.php?s=3"
sitter_checker=$(sed -n 's/.*name="mpvt_token" value="\([^"]*\)".*/\1/p' "$body" | head -n 1)
if [ -z "$sitter_checker" ]; then
    echo 'Sitter login did not render a checker token.' >&2
    exit 1
fi
request_status 403 -b "$sitter_cookies" \
    --data-urlencode "mpvt_token=$sitter_checker" \
    --data-urlencode "export_password=$password" \
    "$base_url/game/account-export.php"

echo "Game smoke test passed for $player."
