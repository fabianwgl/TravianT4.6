#!/bin/sh
set -eu

cd "$(dirname "$0")/.."

controller='main_script/include/Controller/RallyPoint/RallyPointCntrl.php'
renderer='main_script/include/Controller/RallyPoint/RallyPointHTML.php'

assert_present() {
    file=$1
    pattern=$2
    if ! rg -Fq -- "$pattern" "$file"; then
        echo "Expected game-action contract missing in $file: $pattern" >&2
        exit 1
    fi
}

assert_absent() {
    file=$1
    pattern=$2
    if rg -Fq -- "$pattern" "$file"; then
        echo "Unsafe GET mutation contract found in $file: $pattern" >&2
        exit 1
    fi
}

assert_present "$controller" 'WebService::isPost()'
assert_present "$controller" '$_POST['
assert_present "$controller" 'Session::validateChecker()'
assert_absent "$controller" '$_GET['"'"'kill'"'"']'
assert_absent "$controller" '$_GET['"'"'free'"'"']'

assert_present "$renderer" 'method="post" action="build.php?gid=16&amp;tt=1" class="inlineForm"'
assert_present "$renderer" 'name="kill"'
assert_present "$renderer" 'name="free"'
assert_present "$renderer" 'name="a" value="4"'
assert_present "$renderer" 'Session::getCheckerInput()'
assert_absent "$renderer" 'kill='
assert_absent "$renderer" 'free='
assert_absent "$renderer" 'a=4&amp;'

echo 'Game mutation method checks passed.'
