#!/bin/sh
set -eu

cd "$(dirname "$0")/.."

controller='main_script/include/Controller/OptionCtrl.php'
template='main_script/include/resources/Templates/options/Sitters.php'
controller_slice=$(mktemp)
trap 'rm -f "$controller_slice"' EXIT INT TERM
sed -n '/private function Sitter()/,/private function mergeSitter()/p' "$controller" > "$controller_slice"

assert_present() {
    file=$1
    pattern=$2
    if ! rg -Fq -- "$pattern" "$file"; then
        echo "Expected sitter-action contract missing in $file: $pattern" >&2
        exit 1
    fi
}

assert_absent() {
    file=$1
    pattern=$2
    if rg -Fq -- "$pattern" "$file"; then
        echo "Unsafe sitter GET mutation contract found in $file: $pattern" >&2
        exit 1
    fi
}

assert_present "$controller_slice" 'WebService::isPost()'
assert_present "$controller_slice" 'isset($_POST[Session::getCheckerName()])'
assert_present "$controller_slice" 'Session::validateChecker()'
assert_present "$controller_slice" "isset(\$_POST['removeSitter1'])"
assert_present "$controller_slice" "isset(\$_POST['removeSitter2'])"
assert_absent "$controller_slice" "isset(\$_GET['id'])"
assert_absent "$controller_slice" "\$_GET['type']"

assert_present "$template" 'method="post"'
assert_present "$template" 'Session::getCheckerInput()'
assert_present "$template" 'name="removeSitter1"'
assert_present "$template" 'name="removeSitter2"'
assert_absent "$template" 'options.php?s=3&amp;e=3&amp;id='
assert_absent "$template" 'onclick="window.location.href'

echo 'Sitter mutation method checks passed.'
