<?php
/* Runs without a server. LK_ROOT points every path into a scratch tree. */
$here = dirname(__DIR__);
$root = "$here/tests/.work/unit";
exec('rm -rf ' . escapeshellarg($root));
mkdir("$root/usr/local/share/unraid-lookout", 0755, true);
copy("$here/src/usr/local/share/unraid-lookout/rules.json", "$root/usr/local/share/unraid-lookout/rules.json");
foreach (['boot/config/plugins/unraid-lookout', 'var/log/libvirt/qemu', 'var/run/unraid-lookout', 'var/local/emhttp/plugins/unraid-lookout', 'boot/logs'] as $d) mkdir("$root/$d", 0755, true);
putenv("LK_ROOT=$root");
require "$here/src/usr/local/emhttp/plugins/unraid-lookout/include/lookout.php";
$fail = 0;
function t(string $name, bool $ok, string $why = ''): void { global $fail; echo $ok ? "ok   $name\n" : "FAIL $name $why\n"; if (!$ok) $fail = 1; }

// rules
$rules = lk_load_rules();
[$ok, $bad] = lk_compile($rules);
t('rules load: ' . count($rules), count($rules) >= 100);
t('every rule compiles under PCRE', !$bad, implode(',', $bad));
t('rule ids are unique', count(array_unique(array_column($rules, 'id'))) === count($rules));
$need = true; foreach ($rules as $r) foreach (['name', 'description', 'recommendation', 'severity', 'category'] as $k) if (empty($r[$k])) { $need = false; echo "  no $k: {$r['id']}\n"; }
t('every rule has a name, a description, a recommendation, a severity and a category', $need);
$sampled = [];
foreach (file("$here/tests/samples.txt", FILE_IGNORE_NEW_LINES) as $l) {
  if ($l === '' || $l[0] === '#') continue;
  [$id, $src, $line] = explode("\t", $l, 3);
  $ids = array_map(fn($r) => $r['id'], lk_match($ok, $line, $src));
  if ($id === '!') t("no rule on: " . substr($line, 22), !$ids, implode(',', $ids));
  elseif ($id[0] === '-') t("not $id on: " . substr($line, 22), !in_array(substr($id, 1), $ids, true), implode(',', $ids));
  else { t("rule $id", in_array($id, $ids, true), implode(',', $ids) ?: 'nothing matched'); $sampled[$id] = true; }
}
$missing = array_values(array_diff(array_column($rules, 'id'), array_keys($sampled)));
t(sprintf('sample lines for %d of %d rules', count($rules) - count($missing), count($rules)), !$missing, implode(',', $missing));
$sev = array_column($rules, 'severity', 'id');
t('corrected machine checks and memory errors are info, uncorrected ones critical, an unclear one a warning',
  $sev['mce-corrected'] === 'info' && $sev['edac-memory-error'] === 'info' && $sev['mce-hardware-error'] === 'critical' && $sev['edac-uncorrected'] === 'critical' && $sev['mce-logged'] === 'warning');
$t0 = microtime(true);
foreach (['a', 'kernel: ', 'BTRFS ', "\xff", 'docker error '] as $seed) { $l = substr(str_repeat($seed, 1000), 0, 8000); lk_match($ok, $l, 'syslog'); t('no PCRE error on a long hostile line', preg_last_error() === PREG_NO_ERROR, preg_last_error_msg()); }
t('five long lines take under 0.5 s', microtime(true) - $t0 < 0.5, (string)(microtime(true) - $t0));
$vm = lk_compile([['id' => 'v', 'pattern' => 'boom', 'log_sources' => ['libvirt']]])[0];
t('a VM log counts as libvirt for rules', count(lk_match($vm, 'boom', 'vm:win11')) === 1);
t('a syslog line does not match a libvirt rule', count(lk_match($vm, 'boom', 'syslog')) === 0);
$ex = lk_compile([['id' => 'x', 'pattern' => 'err', 'exclude_pattern' => 'harmless']])[0];
t('exclude_pattern drops a line', !lk_match($ex, 'harmless err', 'syslog') && lk_match($ex, 'real err', 'syslog'));
t('a rule with a bad pattern is reported, not fatal', lk_compile([['id' => 'bad', 'pattern' => '(']])[1] === ['bad']);
file_put_contents(lk_flash('custom-rules.json'), json_encode(['rules' => [['id' => 'mine', 'pattern' => 'x'], ['id' => $rules[0]['id'], 'pattern' => 'y']]]));
$c = lk_load_rules();
t('custom rules add, a shipped id wins', count($c) === count($rules) + 1 && $c[0]['pattern'] === $rules[0]['pattern']);
unlink(lk_flash('custom-rules.json'));

// signature
$a = lk_signature('Nov  5 10:01:02 srv kernel: ata3.00: failed command at 0x1f00 sector 12345 from 10.0.0.1');
$b = lk_signature('Nov 15 22:11:09 srv kernel: ata4.00: failed command at 0x2a00 sector 99 from 10.0.0.77');
t('signature ignores time, host, numbers, addresses', $a === $b, "$a | $b");
t('signature keeps the message', lk_signature('kernel: out of memory') !== lk_signature('kernel: I/O error'));

// merge
$r = ['id' => 'r1', 'severity' => 'warning', 'name' => 'n'];
$d = ['findings' => []];
[$d, $n] = lk_merge_hits($d, [[$r, 'Nov  5 10:01:02 srv kernel: sector 1', 'syslog', 1000, 1]], 300, 500);
t('a new message is a new finding', count($d['findings']) === 1 && count($n) === 1);
[$d, $n] = lk_merge_hits($d, [[$r, 'Nov  5 10:01:09 srv kernel: sector 2', 'syslog', 1010, 2]], 300, 500);
t('the same message counts on the finding', count($d['findings']) === 1 && $d['findings'][0]['repeat_count'] === 3 && !$n);
[$d, $n] = lk_merge_hits($d, [[$r, 'Nov  5 10:01:02 srv kernel: sector 1', 'syslog', 2000, 1]], 300, 500);
t('after the window it is new again', count($d['findings']) === 2 && count($n) === 1);
$d = ['findings' => []];
$hits = []; foreach (range(1, 40) as $i) $hits[] = [$r, 'x ' . str_repeat(chr(96 + $i % 26 + 1), $i), 'syslog', 1000, 1];
[$d, $n] = lk_merge_hits($d, $hits, 300, 500);
t('a flood of different messages folds', count($d['findings']) === LK_FLOOD_SIGNATURES && !empty(end($d['findings'])['flood']) && array_sum(array_column($d['findings'], 'repeat_count')) === 40);
[$d] = lk_merge_hits(['findings' => []], array_map(fn($i) => [['id' => "r$i"], "l$i", 's', 1000, 1], range(1, 30)), 300, 10);
t('findings are capped', count($d['findings']) === 10);

// notifications
$cfg = lk_clean_config([]);
$crit = ['id' => 'c', 'severity' => 'critical']; $warn = ['id' => 'w', 'severity' => 'warning']; $sent = [];
t('critical notifies by default', lk_notify_due($crit, $cfg, $sent, 100000));
t('not again inside the cooldown', !lk_notify_due($crit, $cfg, $sent, 100000 + 21599));
t('again after the cooldown', lk_notify_due($crit, $cfg, $sent, 100000 + 21601));
t('warning does not at the default level', !lk_notify_due($warn, $cfg, $sent, 100000));
$sent = []; t('warning does at the warning level', lk_notify_due($warn, lk_clean_config(['notify' => 'warning']), $sent, 100000));
$sent = []; t('off is off', !lk_notify_due($crit, lk_clean_config(['notify' => 'off']), $sent, 100000));
t('a muted rule is silent', !lk_notify_due($crit, lk_clean_config(['muted' => ['c']]), $sent, 100000));

// config
$c = lk_clean_config(['max_findings' => 99999999, 'evil' => 'x', 'notify' => '`id`', 'severity_filter' => 'nope', 'muted' => ['ok-1', '../x', 7], 'rules_url_main' => 'http://x']);
t('config keeps known keys in range', $c['max_findings'] === 5000 && !isset($c['evil']) && $c['notify'] === 'critical' && $c['severity_filter'] === 'info' && $c['muted'] === ['ok-1'] && !isset($c['rules_url_main']));

// previous boot
[$compiled] = lk_compile($rules);
$prev = lk_path('/boot/logs/syslog-previous');
file_put_contents($prev, "Nov  4 03:00:00 srv kernel: BTRFS warning (device sdb1): devid 2 uuid 12 is missing\nNov  4 03:00:01 srv kernel: BTRFS warning (device sdb1): devid 3 uuid 13 is missing\nNov  4 03:00:02 srv sshd[1]: ok\n");
touch($prev, 1730000000);
$cfg = lk_clean_config(['read_previous' => false]);
t('previous log: off is off', lk_read_previous($compiled, $cfg) === 0);
$cfg = lk_clean_config([]);
t('previous log is read', lk_read_previous($compiled, $cfg) >= 2);
$f = lk_read_json(lk_findings_file())['findings'] ?? [];
$one = array_values(array_filter($f, fn($x) => $x['rule_id'] === 'btrfs-dev-missing'));
t('previous log: one finding with the count and its source', count($one) === 1 && $one[0]['repeat_count'] === 2 && $one[0]['log_source'] === 'syslog (previous boot)');
t('previous log: only once per boot', lk_read_previous($compiled, $cfg) === 0);

// tailer
$log = lk_path('/var/log/t.log');
file_put_contents($log, "old\n");
$t = new LkTailer($log, true); file_put_contents($log, "new\n", FILE_APPEND);
t('tailer from the end sees only new lines', $t->lines() === ['new']);
$t2 = new LkTailer($log, false);
t('tailer from the start sees everything', $t2->lines() === ['old', 'new']);

file_put_contents($log, "after\n"); clearstatcache();
$got = $t->lines(); t('tailer reads a rotated log', in_array('after', $got, true), json_encode($got));
file_put_contents($log, str_repeat(str_repeat('x', 999) . "\n", 9000));
$t3 = new LkTailer($log, false); $first = count($t3->lines()); $total = $first; foreach (range(1, 5) as $_) $total += count($t3->lines());
t('a large log is read in steps, nothing lost', $first < 9000 && $total === 9000, "$first $total");
exit($fail);
