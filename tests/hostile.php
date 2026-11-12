<?php
/* Hostile input, end to end without a server: log lines, custom rules and settings as someone else wrote them.
 * Anyone who can make a container, a VM or a network client write a log line controls that text; custom-rules.json and
 * config.json sit on the flash drive, which can be shared on the network. Every check says what must stay inert. */
$here = dirname(__DIR__);
$root = "$here/tests/.work/hostile";
exec('rm -rf ' . escapeshellarg($root));
mkdir("$root/usr/local/share/unraid-lookout", 0755, true);
copy("$here/src/usr/local/share/unraid-lookout/rules.json", "$root/usr/local/share/unraid-lookout/rules.json");
foreach (['boot/config/plugins/unraid-lookout', 'var/log', 'var/run/unraid-lookout', 'var/local/emhttp/plugins/unraid-lookout', 'boot/logs'] as $d) mkdir("$root/$d", 0755, true);
putenv("LK_ROOT=$root");
putenv('LK_DRIVEMAP=/nonexistent');
require "$here/src/usr/local/emhttp/plugins/unraid-lookout/include/lookout.php";
$fail = 0;
function t(string $name, bool $ok, string $why = ''): void { global $fail; echo $ok ? "ok   $name\n" : "FAIL $name $why\n"; if (!$ok) $fail = 1; }
/* runs $f; a PHP error or exception is a failed check, not the end of the run */
function safely(string $name, callable $f) {
  try { return $f(); } catch (Throwable $e) { t($name, false, get_class($e) . ': ' . $e->getMessage()); return null; }
}
/* what a terminal or the page would act on: ESC, other C0 controls but tab, DEL, C1 controls, bidi overrides */
function has_controls(string $s): bool {
  return preg_match('/[\x00-\x08\x0a-\x1f\x7f]|\xc2[\x80-\x9f]|\xe2\x80[\xaa-\xae]|\xe2\x81[\xa6-\xa9]/', $s) === 1;
}
$hit = 'kernel: BTRFS warning (device sdb1): devid 2 uuid 12 is missing';
$ansi = "Nov  5 10:00:00 srv \x1b[31m$hit\x1b[0m \x1b]0;owned\x07 \x1b]52;c;cm0gLXJmIC8K\x07 \xe2\x80\xaeevil\xe2\x80\xac \xc2\x9b2J \x08\x08\x7f\x00 end";
[$shipped] = lk_compile(lk_load_rules());

// ---- log lines through the tailer ----
$log = lk_path('/var/log/h.log');
file_put_contents($log, "$ansi\n");
$lines = safely('tailer: control characters', fn() => (new LkTailer($log, false))->lines()) ?? [];
t('tailer: ANSI, OSC, C0, C1 and bidi controls are taken out of a log line', count($lines) === 1 && !has_controls($lines[0]), json_encode($lines));
t('tailer: the text around them stays, and still matches its rule', count($lines) === 1 && strpos($lines[0], 'devid 2 uuid 12 is missing') !== false
  && in_array('btrfs-dev-missing', array_column(lk_match($shipped, $lines[0], 'syslog'), 'id'), true), json_encode($lines));

// a 5 MB line without a newline, then a real error: the error must still be seen
file_put_contents($log, str_repeat('A', 5000000));
$t = new LkTailer($log, false);
$seen = [];
for ($i = 0; $i < 4; $i++) { $seen = array_merge($seen, $t->lines()); if ($i === 1) file_put_contents($log, "\nNov  5 10:00:01 srv $hit\n", FILE_APPEND); }
t('tailer: a 5 MB line without a newline does not stop the tailer', in_array("Nov  5 10:00:01 srv $hit", $seen, true), count($seen) . ' lines');
t('tailer: no line it returns is longer than 8192 bytes', max(array_map('strlen', $seen ?: [''])) <= 8192, (string)max(array_map('strlen', $seen ?: [''])));

// a 1 MB line with a newline: capped before the rules run
file_put_contents($log, str_repeat('kernel: BTRFS ', 75000) . "\n");
$t0 = microtime(true);
$big = (new LkTailer($log, false))->lines();
foreach ($big as $l) lk_match($shipped, $l, 'syslog');
$dt = microtime(true) - $t0;
t('a 1 MB line reaches the rules at most 8192 bytes long', count($big) === 1 && strlen($big[0]) <= 8192, strlen($big[0] ?? ''));
t('a 1 MB line takes under 0.5 s through every rule', $dt < 0.5, sprintf('%.3f s', $dt));

// ---- the finding as stored, and as the shell prints it ----
[$d] = lk_merge_hits(['findings' => []], [[['id' => 'r', 'severity' => 'critical', 'name' => 'n'], $ansi, "vm:\x1b[2Jwin", 1000, 1]], 300, 500);
$f = $d['findings'][0];
t('finding: the stored log line has no control characters', !has_controls($f['log_line']), json_encode($f['log_line']));
t('finding: the stored source has no control characters', !has_controls($f['log_source']), json_encode($f['log_source']));
t('finding: the stored log line is valid UTF-8 and at most 500 bytes', preg_match('//u', $f['log_line']) === 1 && strlen($f['log_line']) <= 500);
// findings.json written by an older version still holds raw text: `lookout findings` must not pass it to the terminal
file_put_contents(lk_findings_file(), json_encode(['findings' => [[
  'id' => 'abc', 'severity' => 'critical', 'timestamp' => '2026-11-12T00:00:00+00:00', 'name' => "n\x1b]0;title\x07", 'log_source' => "s\x1b[2J",
  'repeat_count' => 1, 'log_line' => $ansi, 'recommendation' => "r\x1b[8m", 'setting_path' => "p\x1b[1A"]]]));
$cli = shell_exec('LK_ROOT=' . escapeshellarg($root) . ' LK_PLUGIN_DIR=' . escapeshellarg("$here/src/usr/local/emhttp/plugins/unraid-lookout") . ' php -q ' . escapeshellarg("$here/src/usr/local/bin/lookout") . ' findings');
t('lookout findings prints no escape sequences to the terminal', is_string($cli) && strpos($cli, 'missing') !== false && strpos($cli, "\x1b") === false && !preg_match('/\xe2\x80\xae|\xc2\x9b/', $cli), json_encode($cli));
unlink(lk_findings_file());

// ---- previous boot's log: a giant line and escapes ----
$prev = lk_path('/boot/logs/syslog-previous');
file_put_contents($prev, str_repeat('B', 3000000) . "\n$ansi\nNov  4 03:00:01 srv $hit\n");
$n = safely('previous log', fn() => lk_read_previous($shipped, lk_clean_config([])));
$pf = lk_read_json(lk_findings_file())['findings'] ?? [];
t('previous log: read past a 3 MB line', (int)$n >= 2, (string)$n);
t('previous log: no control characters in what it stores', $pf && !array_filter($pf, fn($x) => has_controls($x['log_line'])), json_encode(array_column($pf, 'log_line')));
@unlink(lk_findings_file()); @unlink(lk_run('previous-read'));

// ---- custom rules as someone else wrote them ----
$evilRules = [
  ['id' => 'type-exclude', 'pattern' => 'x', 'exclude_pattern' => ['a']],
  ['id' => 'type-sources', 'pattern' => 'x', 'log_sources' => 'syslog'],
  ['id' => 'type-severity', 'pattern' => 'x', 'severity' => ['critical']],
  ['id' => 'type-name', 'pattern' => 'x', 'name' => ['n'], 'recommendation' => 7],
  ['id' => 42, 'pattern' => 'x'],
  ['id' => 'type-pattern', 'pattern' => ['x']],
  ['id' => "bad\nid [lookout] forged", 'pattern' => 'x'],
  // PCRE gives up on these on a long run of one character (backtrack limit): refused at load
  ['id' => 'redos-nested', 'pattern' => '(a+)+$'],
  ['id' => 'redos-exclude', 'pattern' => 'kernel', 'exclude_pattern' => '(a|aa)+$'],
  // textbook ReDoS shapes that PCRE2's auto-possessification and JIT already run fast: allowed, but timed below
  ['id' => 'redos-pair', 'pattern' => '(x+x+)+y'],
  ['id' => 'redos-digits', 'pattern' => '^(\d+)+x'],
  ['id' => 'redos-alt', 'pattern' => '(\w|\w)*!'],
  ['id' => 'redos-dot', 'pattern' => '(.*a){12}'],
  ['id' => 'too-long', 'pattern' => str_repeat('a', 5000)],
  ['id' => 'fine-custom', 'pattern' => 'custom thing happened', 'severity' => 'warning'],
];
file_put_contents(lk_flash('custom-rules.json'), json_encode(['rules' => $evilRules]));
$loaded = safely('custom rules: load', fn() => lk_load_rules()) ?? [];
[$ok, $bad] = safely('custom rules: compile', fn() => lk_compile($loaded)) ?? [[], []];
$okIds = array_map(fn($c) => $c[0]['id'], $ok);
foreach (['type-exclude', 'type-sources', 'type-severity', 'type-name', 'type-pattern', 'redos-nested', 'redos-exclude', 'too-long'] as $id)
  t("custom rule $id is refused and reported", !in_array($id, $okIds, true) && in_array($id, $bad, true), 'ok=' . (int)in_array($id, $okIds, true) . ' bad=' . json_encode($bad));
t('a custom rule with a number or a newline as its id is refused', !array_filter($okIds, fn($i) => !is_string($i) || strpos($i, "\n") !== false) && !array_filter($bad, fn($i) => strpos((string)$i, "\n") !== false), json_encode($bad));
t('a good custom rule next to them still loads', in_array('fine-custom', $okIds, true));
t('the shipped rules still load beside them, and only the fast custom ones', count($ok) === count($shipped) + 5, count($ok) . ' vs ' . (count($shipped) + 5));
// every hostile line the rules may meet, against everything that was let in: 8000 bytes, the most a line gets to be
$t0 = microtime(true); $lines = 0;
foreach (['a', '1', ' ', 'x', 'w', 'kernel '] as $c) foreach (['', '!', 'z'] as $end) {
  $l = substr(str_repeat($c, 8000), 0, 8000 - strlen($end)) . $end;
  safely('custom rules: match', fn() => lk_match($ok, $l, 'syslog')); $lines++;
}
$dt = microtime(true) - $t0;
t(sprintf('%d hostile 8000-byte lines through every accepted rule take under 0.5 s', $lines), $dt < 0.5, sprintf('%.3f s', $dt));
$m2 = safely('custom rules: match', fn() => lk_match($ok, "custom thing happened " . str_repeat(' ', 8000) . 'z', 'docker'));
// a pattern PCRE gives up on, past the load check (a line shape the probes missed): it stops after three tries
$slow = [[['id' => 'slow-one'], lk_regex('(a+)+$'), null, []]];
for ($i = 0; $i < 6; $i++) safely('slow rule', fn() => lk_match($slow, str_repeat('a', 8000) . '!', 'syslog'));
$llog = (string)@file_get_contents(lk_path('/var/log/lookout.log'));
t('a rule that PCRE gives up on three times is stopped, and the log says so', substr_count($llog, 'rule slow-one stopped') === 1, $llog);
t('the good custom rule matches', is_array($m2) && in_array('fine-custom', array_column($m2, 'id'), true));
$merged = safely('custom rules: merge', fn() => lk_merge_hits(['findings' => []], [[['id' => 'w', 'severity' => ['x'], 'name' => ['y'], 'category' => 5], 'l', 's', 1000, 1]], 300, 500));
t('a finding from a rule with wrong types gets plain strings', is_array($merged) && is_string($merged[0]['findings'][0]['name']) && $merged[0]['findings'][0]['severity'] === 'info' && is_string($merged[0]['findings'][0]['category']));
// a custom-rules.json far larger than any real one is not read
file_put_contents(lk_flash('custom-rules.json'), json_encode(['rules' => [['id' => 'huge-file', 'pattern' => 'x', 'name' => str_repeat('n', 3000000)]]]));
t('a custom-rules.json over 1 MB is not read', !in_array('huge-file', array_column(lk_load_rules(), 'id'), true));
unlink(lk_flash('custom-rules.json'));

// ---- settings with wrong types (the page's save, and config.json on the flash drive) ----
foreach ([
  ['severity_filter' => ['critical']], ['notify' => ['off']], ['notify' => true], ['max_findings' => ['9']], ['max_findings' => '1e99'],
  ['dedup_window' => -5], ['notify_cooldown' => 'NaN'], ['muted' => 'rule-a'], ['muted' => [['x'], null, str_repeat('a', 500)]],
  ['enabled' => ['x']], ['monitor_syslog' => 'yes please'], ['read_previous' => null], ['severity_filter' => null],
] as $in) {
  $c = safely('config ' . json_encode($in), fn() => lk_clean_config($in));
  $good = is_array($c) && isset(LK_RANK[$c['severity_filter']]) && in_array($c['notify'], ['off', 'critical', 'warning'], true)
    && is_int($c['max_findings']) && $c['max_findings'] >= 10 && $c['max_findings'] <= 5000 && is_int($c['dedup_window']) && $c['dedup_window'] >= 0
    && is_int($c['notify_cooldown']) && $c['notify_cooldown'] >= 300 && is_array($c['muted']) && !array_filter($c['muted'], fn($m) => !is_string($m) || strlen($m) > 80)
    && is_bool($c['enabled']) && is_bool($c['monitor_syslog']) && is_bool($c['read_previous']);
  t('settings ' . json_encode($in) . ' fall back to a valid value', $good, json_encode($c));
}
file_put_contents(lk_flash('config.json'), '{"severity_filter":{"a":1},"max_findings":[1]}');
$c = safely('config.json with wrong types', fn() => lk_config());
t('a config.json with wrong types gives the defaults', is_array($c) && $c['severity_filter'] === 'info' && $c['max_findings'] === 500);
// the Settings page as it is drawn from that file
$page = explode("\n---\n", file_get_contents("$here/src/usr/local/emhttp/plugins/unraid-lookout/LookoutSettings.page"), 2)[1];
file_put_contents(lk_flash('config.json'), json_encode(['severity_filter' => '"><script>alert(1)</script>', 'notify' => "'><img src=x onerror=alert(1)>", 'max_findings' => '9"><b>']));
$docroot = "$here/src/usr/local/emhttp";
// eval runs our own page file (the part after its header), as Unraid does: the test is the page itself, not foreign code
ob_start(); safely('settings page', function () use ($page, $docroot) { eval('?>' . $page); }); $html = (string)ob_get_clean();
t('the Settings page from a hostile config.json has no injected markup', $html !== '' && stripos($html, '<script>alert') === false && stripos($html, 'onerror') === false && stripos($html, '<b>') === false);
unlink(lk_flash('config.json'));

// ---- the notification: no log text, no shell, nothing that breaks Unraid's notification file ----
$nlog = "$root/notify.argv";
file_put_contents("$root/notify", "#!/bin/bash\nfor a in \"\$@\"; do printf '%s\\0' \"\$a\"; done >>" . escapeshellarg($nlog) . "\n");
chmod("$root/notify", 0755);
putenv("LK_NOTIFY=$root/notify");
$pwn = "$root/pwned";
$rule = ['id' => 'evil', 'severity' => 'critical',
  'name' => "Disk \$(touch $pwn) `touch $pwn` ; touch $pwn \"' <script>alert(1)</script>\nimportance=\"normal\"",
  'recommendation' => "Do \x1b[31mthis\x1b[0m\nlink=\"http://evil\"\r\n<img src=x onerror=alert(1)> " . str_repeat('x', 3000)];
safely('notify', fn() => lk_notify($rule));
$args = array_values(array_filter(explode("\0", (string)@file_get_contents($nlog)), fn($a) => $a !== ''));
t('notify ran with an argument list', count($args) >= 8, json_encode($args));
t('notify: nothing from the text ran as a command', !file_exists($pwn));
t('notify: no newline or control character in any argument (Unraid writes them into an ini file, one key per line)', $args && !array_filter($args, 'has_controls'), substr(json_encode($args), 0, 400));
t('notify: no < or > in the subject or the description (Unraid shows them in its own page)', $args && !array_filter($args, fn($a) => strpbrk($a, '<>') !== false), substr(json_encode($args), 0, 400));
$si = array_search('-s', $args, true); $di = array_search('-d', $args, true);
t('notify: subject at most 120 and description at most 500 bytes', $si !== false && $di !== false && strlen($args[$si + 1]) <= 120 && strlen($args[$di + 1]) <= 500);
exit($fail);
