#!/bin/sh
set -eu

cd "$(dirname "$0")/.."

controller='main_script/include/Controller/OptionCtrl.php'
template='main_script/include/resources/Templates/options/Account.php'

assert_present() {
    file=$1
    pattern=$2
    if ! rg -Fq -- "$pattern" "$file"; then
        echo "Expected account-action contract missing in $file: $pattern" >&2
        exit 1
    fi
}

assert_absent() {
    file=$1
    pattern=$2
    if rg -Fq -- "$pattern" "$file"; then
        echo "Unsafe account GET mutation contract found in $file: $pattern" >&2
        exit 1
    fi
}

assert_present "$controller" 'isset($_POST['
assert_present "$controller" 'cancelEmailChange'
assert_present "$controller" 'cancelDeletion'
assert_present "$controller" 'Session::validateChecker()'
assert_absent "$controller" 'isset($_GET['"'"'email_abbrechen'"'"'])'
assert_absent "$controller" 'isset($_GET['"'"'a'"'"']) && $_GET['"'"'a'"'"'] == 1'

assert_present "$template" 'method="post"'
assert_present "$template" 'Session::getCheckerInput()'
assert_present "$template" 'name="cancelEmailChange"'
assert_present "$template" 'name="cancelDeletion"'
assert_absent "$template" 'email_abbrechen'
assert_absent "$template" '&amp;a=1&amp;e=2'

echo 'Account mutation method checks passed.'
