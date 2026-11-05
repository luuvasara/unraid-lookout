#!/bin/bash
# Everything that runs without a server. tests/render-test.sh renders the pages in Chrome.
set -uo pipefail
HERE=$(cd "$(dirname "$0")/.." && pwd); fail=0
mkdir -p "$HERE/tests/.work"
"$HERE/kit/bin/lint.sh" || fail=1
php "$HERE/tests/unit.php" || fail=1
bash "$HERE/tests/daemon-test.sh" || fail=1
out=$(LK_ROOT=$HERE/src LK_PLUGIN_DIR=$HERE/src/usr/local/emhttp/plugins/unraid-lookout php -q "$HERE/src/usr/local/bin/lookout" check-rules 2>&1)
if grep -Eq '^[0-9]+ rules compile, 0 do not$' <<<"$out"; then echo "ok   check-rules: $out"; else echo "FAIL check-rules: $out"; fail=1; fi
I=$HERE/src/usr/local/emhttp/plugins/unraid-lookout/images/lookout.png
if [ -s "$I" ] && grep -q 'icon="lookout.png"' "$HERE/unraid-lookout.plg" && grep -q '^Icon="lookout.png"' "$HERE/src/usr/local/emhttp/plugins/unraid-lookout/Lookout.page" && [ -s "$HERE/icon.png" ] && grep -q '/main/icon.png<' "$HERE/plugins/unraid-lookout.xml"; then echo "ok   icon wired into the .plg, the page and the template"; else echo "FAIL icon wiring"; fail=1; fi
L=$(sed -n 's/^LOGFILE=//p' "$HERE/src/etc/rc.d/rc.lookout")
if [ -n "$L" ] && grep -q "^rm -f $L " "$HERE/unraid-lookout.plg"; then echo "ok   remove deletes the daemon's log, $L"; else echo "FAIL remove leaves $L behind"; fail=1; fi
[ $fail = 0 ] && echo "ALL PASSED" || echo "FAILED"
exit $fail
