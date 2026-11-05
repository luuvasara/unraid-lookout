#!/bin/bash
# shellcheck disable=SC2015,SC2016
# The built package's pages with Unraid's real stylesheets, in headless Chrome: all themes, and the 6.12.15 stylesheets.
# Findings hold hostile text: nothing from a log may become markup. OUT=<dir> keeps the screenshots.
set -uo pipefail
HERE=$(cd "$(dirname "$0")/.." && pwd); NAME=unraid-lookout
OUT=${OUT:-$HERE/tests/.work/shots}; mkdir -p "$OUT"
# shellcheck disable=SC1091
. "$HERE/kit/render/render-lib.sh"
PLUGIN_ROOT=$HERE "$HERE/kit/render/fetch-webgui.sh"; PLUGIN_ROOT=$HERE "$HERE/kit/render/fetch-webgui.sh" 6.12
W=$HERE/tests/.work/render; rm -rf "$W"; mkdir -p "$W"
fail=0; ok() { echo "ok   $*"; }; bad() { echo "FAIL $*"; fail=1; }
FX=$W/fx; mkdir -p "$FX/var/local/emhttp/plugins/$NAME" "$FX/boot/config/plugins/$NAME" "$FX/usr/local/share/$NAME"
cp "$HERE/src/usr/local/share/$NAME/rules.json" "$FX/usr/local/share/$NAME/"
php -r '
$evil = "<img src=x onerror=\"document.title=\x27PWNED\x27\"><script>document.title=\x27PWNED\x27</script>";
$now = gmdate("c");
$f = [["id"=>"aaaaaaaaaaaa","timestamp"=>$now,"last_seen"=>$now,"rule_id"=>"btrfs-dev-missing","category"=>"filesystem","severity"=>"critical","name"=>"BTRFS device missing","description"=>"A device of the BTRFS pool is missing.","recommendation"=>"Check the cabling of the pool device.","setting_path"=>"Main > Pool","config_file"=>"","log_source"=>"syslog","log_line"=>"Nov  5 10:00:00 srv kernel: BTRFS warning: devid 2 uuid 12 is missing $evil","repeat_count"=>3,"acknowledged"=>false,"dedup_key"=>"k1"],
 ["id"=>"bbbbbbbbbbbb","timestamp"=>$now,"last_seen"=>$now,"rule_id"=>"usb-disconnect","category"=>"hardware","severity"=>"warning","name"=>"USB device disconnected $evil","description"=>$evil,"recommendation"=>$evil,"setting_path"=>$evil,"config_file"=>$evil,"log_source"=>"vm:$evil","log_line"=>"usb 1-2: USB disconnect","repeat_count"=>1,"acknowledged"=>false,"dedup_key"=>"k2","flood"=>true],
 ["id"=>"cccccccccccc","timestamp"=>$now,"last_seen"=>$now,"rule_id"=>"mcelog-dimm-prefill","category"=>"hardware","severity"=>"info","name"=>"mcelog DIMM database","description"=>"Harmless on most boards.","recommendation"=>"Nothing to do.","setting_path"=>"","config_file"=>"","log_source"=>"syslog (previous boot)","log_line"=>"mcelog: failed to prefill DIMM database","repeat_count"=>1,"acknowledged"=>true,"dedup_key"=>"k3"]];
file_put_contents($argv[1], json_encode(["findings"=>$f]));' "$FX/var/local/emhttp/plugins/$NAME/findings.json"
export LK_ROOT=$FX LK_BIN=/bin/true LK_RC=/bin/true
TABS="Findings=LookoutFindings,Settings=LookoutSettings,About=LookoutAbout"
for spec in black white gray azure black:6.12 white:6.12; do
  theme=${spec%%:*}; gui=; [ "$spec" != "$theme" ] && gui=6.12
  web=$W/web-$theme${gui:+-$gui}; stage_web "$web" "$gui" || exit 1
  port=$(free_port); start_harness "$web" "$port" "$theme" "$TABS" "$gui"
  tag=$theme${gui:+-6.12}
  for tab in 1 2 3; do
    shot "http://127.0.0.1:$port/?tab=$tab" "$OUT/kit-unraid-lookout-$tag-$tab.png"
    [ -s "$OUT/kit-unraid-lookout-$tag-$tab.png" ] || bad "no screenshot $tag tab $tab"
  done
  dom "http://127.0.0.1:$port/?tab=1" "$W/dom-$tag.html"
  grep -q 'id="lk-tbody"\|lk-table' "$W/dom-$tag.html" || bad "$tag: no table"
  [ "$(grep -c 'lk-sev-critical' "$W/dom-$tag.html")" -ge 1 ] && ok "$tag: findings rendered" || bad "$tag: findings not rendered"
  grep -qi '<title>PWNED\|<img src="x"\|<img src=x' "$W/dom-$tag.html" && bad "$tag: log text became markup" || ok "$tag: log text stays text"
  grep -q 'More Unraid plugins' "$W/dom-$tag.html" && ok "$tag: About hint" || bad "$tag: About hint"
  grep -q "$(sed -n 's/^ *<!ENTITY version *"\([^"]*\)">.*/\1/p' "$HERE/$NAME.plg")" "$W/dom-$tag.html" && ok "$tag: About version" || bad "$tag: About version"
  grep -q 'Lookout is by the Lookout project' "$W/dom-$tag.html" && grep -q 'General Public License' "$W/dom-$tag.html" && ok "$tag: About names project, licence and source" || bad "$tag: About attribution"
  grep -q 'ex\. 21600' "$W/dom-$tag.html" && [ "$(grep -c 'class="lk-hint">[^<]*ex\. ' "$W/dom-$tag.html")" -ge 10 ] && ok "$tag: every setting has an example and a reason" || bad "$tag: setting hints"
  if grep -E "PHP (Warning|Fatal|Parse)" "$web.log" | head -5; then bad "$tag: PHP errors"; fi
done
render_cleanup
[ $fail = 0 ] && echo "ALL PASSED" || echo FAILED
exit $fail
