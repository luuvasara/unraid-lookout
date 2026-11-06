#!/bin/bash
# The README screenshots: single panels, black theme, from the render harness with made-up findings.
# Needs a built package, the webgui stylesheets (kit/render/fetch-webgui.sh), php, headless Chrome and playwright.
# Env: PLAYWRIGHT_MODULE (path of the playwright node module), CHROME (default google-chrome), OUT (default docs/screenshots).
set -euo pipefail
HERE=$(cd "$(dirname "$0")/.." && pwd); NAME=unraid-lookout
: "${PLAYWRIGHT_MODULE:?set PLAYWRIGHT_MODULE to the playwright node module path}"
OUT=${OUT:-$HERE/docs/screenshots}; mkdir -p "$OUT"
# shellcheck disable=SC1091
. "$HERE/kit/render/render-lib.sh"
PLUGIN_ROOT=$HERE "$HERE/kit/render/fetch-webgui.sh"
W=$HERE/tests/.work/readme; rm -rf "$W"; mkdir -p "$W"
FX=$W/fx; mkdir -p "$FX/var/local/emhttp/plugins/$NAME" "$FX/boot/config/plugins/$NAME" "$FX/usr/local/share/$NAME"
cp "$HERE/src/usr/local/share/$NAME/rules.json" "$FX/usr/local/share/$NAME/"
# findings: the rule's own text, with the sample line from tests/samples.txt
php -r '
$rules = []; foreach (json_decode(file_get_contents($argv[1]), true)["rules"] as $r) $rules[$r["id"]] = $r;
$lines = []; foreach (file($argv[2], FILE_IGNORE_NEW_LINES) as $l) { $p = explode("\t", $l, 3); if (count($p) == 3) $lines[$p[0]] = $p[2]; }
$list = [["oom-fork-inotify",2,"syslog",0],["nginx-auth-request-504",1,"syslog",9],["array-failed-device-misleading",1,"syslog",25],["docker-layer-not-exist",1,"docker",40],["mcelog-dimm-prefill",1,"syslog (previous boot)",70]];
$f = []; foreach ($list as $i => [$id,$n,$src,$ago]) { $r = $rules[$id]; $t = gmdate("c", time() - $ago * 60);
  $f[] = ["id"=>sprintf("%012d",$i+1),"timestamp"=>$t,"last_seen"=>$t,"rule_id"=>$id,"category"=>$r["category"],"severity"=>$r["severity"],"name"=>$r["name"],"description"=>$r["description"],"recommendation"=>$r["recommendation"],"setting_path"=>$r["setting_path"] ?? "","config_file"=>$r["config_file"] ?? "","log_source"=>$src,"log_line"=>str_replace(" srv ", " demo ", $lines[$id]),"repeat_count"=>$n,"acknowledged"=>false,"dedup_key"=>"k$i"]; }
file_put_contents($argv[3], json_encode(["findings"=>$f]));' "$HERE/src/usr/local/share/$NAME/rules.json" "$HERE/tests/samples.txt" "$FX/var/local/emhttp/plugins/$NAME/findings.json"
export LK_ROOT=$FX LK_BIN=/bin/true LK_RC=/bin/true
web=$W/web; stage_web "$web" || exit 1
port=$(free_port); start_harness "$web" "$port" black "Findings=LookoutFindings,Settings=LookoutSettings" ""
BASE="http://127.0.0.1:$port" OUT=$OUT PLAYWRIGHT_MODULE=$PLAYWRIGHT_MODULE CHROME=${CHROME:-google-chrome} node "$HERE/tests/readme-shots.mjs"
echo "shots in $OUT"
