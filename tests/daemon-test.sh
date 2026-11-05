#!/bin/bash
# shellcheck disable=SC2015,SC2016
# The real daemon script against a scratch tree, with a stand-in for Unraid's notify.
set -uo pipefail
HERE=$(cd "$(dirname "$0")/.." && pwd)
W=$HERE/tests/.work/daemon; rm -rf "$W"
mkdir -p "$W/usr/local/share/unraid-lookout" "$W/boot/config/plugins/unraid-lookout" "$W/var/log/libvirt/qemu" "$W/var/run/unraid-lookout" "$W/var/local/emhttp/plugins/unraid-lookout" "$W/boot/logs"
cp "$HERE/src/usr/local/share/unraid-lookout/rules.json" "$W/usr/local/share/unraid-lookout/"
cat >"$W/notify" <<'N'
#!/bin/bash
printf '%q ' "$@" >>"$NOTIFY_LOG"; echo >>"$NOTIFY_LOG"
N
chmod 755 "$W/notify"
export LK_ROOT=$W LK_PLUGIN_DIR=$HERE/src/usr/local/emhttp/plugins/unraid-lookout LK_POLL_US=50000 LK_NOTIFY=$W/notify NOTIFY_LOG=$W/notify.log
BIN="php -q $HERE/src/usr/local/bin/lookout"
fail=0; ok() { echo "ok   $*"; }; bad() { echo "FAIL $*"; fail=1; }
hit='Nov  5 10:00:00 srv kernel: BTRFS warning (device sdb1): devid 2 uuid 12 is missing'
echo "$hit" >"$W/var/log/syslog"; : >"$W/var/log/docker.log"
$BIN start >"$W/out.log" 2>&1 & DPID=$!
for _ in $(seq 1 40); do $BIN status >/dev/null 2>&1 && break; sleep 0.1; done
$BIN status >/dev/null 2>&1 && ok "daemon runs" || bad "daemon did not start: $(cat "$W/out.log")"
evil='Nov  5 10:00:05 srv kernel: BTRFS warning (device <img src=x onerror=alert(1)>): devid 2 uuid `id` is missing'
for _ in 1 2 3 4 5 6 7 8 9 10; do [ -f "$W/var/local/emhttp/plugins/unraid-lookout/findings.json" ] && break; sleep 0.1; done
[ -f "$W/var/local/emhttp/plugins/unraid-lookout/findings.json" ] && ok "what was already in the log is listed at once" || bad "backlog not listed"
[ ! -s "$W/notify.log" ] && ok "what was already in the log sends no notification" || bad "notified on the backlog"
echo "$evil" >>"$W/var/log/syslog"
sleep 0.5
[ -n "$($BIN reload)" ] && sleep 0.3
$BIN stop >/dev/null; wait "$DPID"
n=$($BIN findings json | php -r '$d=json_decode(stream_get_contents(STDIN),true); $c=0; foreach($d["findings"] as $f) if($f["rule_id"]==="btrfs-dev-missing") $c+=$f["repeat_count"]; echo $c;')
[ "$n" = 2 ] && ok "lines from before the start and after it count: $n" || bad "count $n"
[ "$(wc -l <"$W/notify.log")" = 1 ] && ok "one notification, for the new line only" || bad "notifications: $(cat "$W/notify.log" 2>&1)"
grep -q 'BTRFS' "$W/notify.log" 2>/dev/null; grep -qF 'onerror' "$W/notify.log" && bad "log text in the notification" || ok "the notification carries no log text"
grep -qF 'alert' "$W/notify.log" && ok "critical goes out as alert" || bad "importance: $(cat "$W/notify.log")"
before=$($BIN findings json | md5sum)
# second start of this boot: from the end, and the cooldown holds
: >"$W/notify.log"
$BIN start >"$W/out.log" 2>&1 & DPID=$!
for _ in $(seq 1 40); do $BIN status >/dev/null 2>&1 && break; sleep 0.1; done
[ "$($BIN findings json | md5sum)" = "$before" ] && ok "a restart keeps the findings" || bad "findings changed by a restart"
$BIN clear >/dev/null
sleep 0.3
$BIN stop >/dev/null; wait "$DPID"
c=$($BIN findings json | php -r 'echo count(json_decode(stream_get_contents(STDIN),true)["findings"]);')
[ "$c" = 0 ] && ok "a restart does not read the log again, a clear stays cleared" || bad "findings after clear: $c"
[ ! -s "$W/notify.log" ] && ok "no notification from a restart" || bad "notified again"
# the page's save keeps what the Mute button stored
A=$HERE/src/usr/local/emhttp/plugins/unraid-lookout/include/ajax.php
req() { LK_BIN=/bin/true php -r '$_SERVER["REQUEST_METHOD"]="POST"; parse_str($argv[1], $_POST); include $argv[2];' "$1" "$A" >/dev/null; }
req 'action=mute&rule=rule-a'
req 'action=saveConfig&config={"notify":"warning"}'
grep -q '"rule-a"' "$W/boot/config/plugins/unraid-lookout/config.json" && ok "saving the settings keeps muted rules" || bad "mute lost: $(cat "$W/boot/config/plugins/unraid-lookout/config.json")"
grep -q '"notify": "warning"' "$W/boot/config/plugins/unraid-lookout/config.json" && ok "the save itself worked" || bad "save lost"
exit $fail
