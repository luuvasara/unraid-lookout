<?php
/* Lookout core: paths, config, rules, tailing and the findings file. Used by the daemon (usr/local/bin/lookout)
 * and by include/ajax.php. LK_ROOT prefixes every path, for the tests. */
const LK_PLUGIN = 'unraid-lookout';
const LK_DEFAULTS = [
  'enabled' => true, 'max_findings' => 500, 'dedup_window' => 300,
  'monitor_syslog' => true, 'monitor_docker' => true, 'monitor_libvirt' => true, 'monitor_vm_logs' => true,
  'severity_filter' => 'info', 'notify' => 'critical', 'notify_cooldown' => 21600, 'muted' => [], 'read_previous' => true,
];
const LK_RANK = ['info' => 0, 'warning' => 1, 'critical' => 2];
const LK_FLOOD_SIGNATURES = 5;   // different messages of one rule from one source, inside the dedup window, before they fold into one
const LK_MAX_PREVIOUS_BYTES = 50000000;
const LK_MAX_LINE = 500;
const LK_MAX_READ = 4000000;      // per log per poll: a large log at the first start is read in steps

function lk_path(string $p): string { return (getenv('LK_ROOT') ?: '') . $p; }
function lk_flash(string $f = ''): string { return lk_path('/boot/config/plugins/' . LK_PLUGIN . ($f !== '' ? "/$f" : '')); }
function lk_state(string $f = ''): string { return lk_path('/var/local/emhttp/plugins/' . LK_PLUGIN . ($f !== '' ? "/$f" : '')); }
function lk_run(string $f = ''): string { return lk_path('/var/run/' . LK_PLUGIN . ($f !== '' ? "/$f" : '')); }
function lk_shipped_rules(): string { return lk_path('/usr/local/share/unraid-lookout/rules.json'); }
function lk_pidfile(): string { return lk_path('/var/run/lookout.pid'); }

function lk_log(string $msg): void {
  $line = '[lookout] ' . date('Y-m-d H:i:s') . " $msg\n";
  $f = lk_path('/var/log/lookout.log');
  if (@file_put_contents($f, $line, FILE_APPEND) === false) fwrite(STDERR, $line);
}

function lk_read_json(string $f): ?array {
  if (!is_file($f)) return null;
  $d = json_decode((string)@file_get_contents($f), true);
  return is_array($d) ? $d : null;
}

function lk_write_atomic(string $f, string $data): bool {
  @mkdir(dirname($f), 0755, true);
  $tmp = "$f.tmp." . getmypid();
  if (@file_put_contents($tmp, $data) === false) return false;
  return @rename($tmp, $f);
}

/* ---- config ---- */
function lk_config(): array {
  return lk_clean_config(array_merge(LK_DEFAULTS, lk_read_json(lk_flash('config.json')) ?? []));
}

/* Only known keys, each of its own type and range; anything else falls back to the default. */
function lk_clean_config(array $in): array {
  $out = LK_DEFAULTS;
  foreach (['enabled', 'monitor_syslog', 'monitor_docker', 'monitor_libvirt', 'monitor_vm_logs'] as $k) {
    if (array_key_exists($k, $in)) $out[$k] = filter_var($in[$k], FILTER_VALIDATE_BOOLEAN);
  }
  foreach (['max_findings' => [10, 5000], 'dedup_window' => [0, 86400], 'notify_cooldown' => [300, 604800]] as $k => [$lo, $hi]) {
    if (isset($in[$k]) && is_numeric($in[$k])) $out[$k] = max($lo, min($hi, (int)$in[$k]));
  }
  if (isset($in['severity_filter']) && isset(LK_RANK[$in['severity_filter']])) $out['severity_filter'] = $in['severity_filter'];
  if (isset($in['notify']) && in_array($in['notify'], ['off', 'critical', 'warning'], true)) $out['notify'] = $in['notify'];
  if (array_key_exists('read_previous', $in)) $out['read_previous'] = filter_var($in['read_previous'], FILTER_VALIDATE_BOOLEAN);
  if (isset($in['muted']) && is_array($in['muted'])) {
    $out['muted'] = array_values(array_unique(array_filter($in['muted'], fn($m) => is_string($m) && preg_match('/^[A-Za-z0-9._:-]{1,80}$/', $m) === 1)));
  }
  return $out;
}

/* ---- rules ---- */
/* The shipped rules, then the user's own custom-rules.json on the flash drive, when their id is new.
 * Rules come only from the package and the flash drive: a pattern runs on every log line as root. */
function lk_load_rules(): array {
  $rules = []; $seen = [];
  foreach (array_merge(lk_read_json(lk_shipped_rules())['rules'] ?? [], lk_read_json(lk_flash('custom-rules.json'))['rules'] ?? []) as $r) {
    if (!is_array($r) || !is_string($r['id'] ?? null) || !is_string($r['pattern'] ?? null) || isset($seen[$r['id']])) continue;
    $seen[$r['id']] = true;
    $rules[] = $r;
  }
  return $rules;
}

function lk_regex(string $pattern): string { return "\x01" . str_replace("\x01", '', $pattern) . "\x01i"; }

/* [compiled rules, ids that do not compile] */
function lk_compile(array $rules): array {
  $ok = []; $bad = [];
  foreach ($rules as $r) {
    $p = lk_regex($r['pattern']);
    $x = !empty($r['exclude_pattern']) ? lk_regex($r['exclude_pattern']) : null;
    if (@preg_match($p, '') === false || ($x !== null && @preg_match($x, '') === false)) { $bad[] = $r['id']; continue; }
    $ok[] = [$r, $p, $x, $r['log_sources'] ?? []];
  }
  return [$ok, $bad];
}

/* A VM's own log counts as the source "libvirt" for rules; the finding still names the VM. */
function lk_match(array $compiled, string $line, string $source): array {
  $src = strncmp($source, 'vm:', 3) === 0 ? 'libvirt' : $source;
  $hits = [];
  foreach ($compiled as [$r, $p, $x, $sources]) {
    if ($sources && !in_array($src, $sources, true)) continue;
    if (preg_match($p, $line) === 1 && ($x === null || preg_match($x, $line) !== 1)) $hits[] = $r;
  }
  return $hits;
}

/* What stays the same when a message repeats: the time stamp, numbers, addresses and ids are taken out. */
function lk_signature(string $line): string {
  $l = preg_replace('/^[A-Z][a-z]{2} +\d+ [\d:]+ \S+ /', '', $line) ?? $line;      // syslog prefix: date, time, host
  $l = preg_replace('/^\d{4}-\d\d-\d\d[T ][\d:.,]+\S* */', '', $l) ?? $l;           // ISO time stamp
  $l = preg_replace('/\b[0-9a-f]{2}(:[0-9a-f]{2}){3,}\b/i', '#', $l) ?? $l;           // MAC and similar
  $l = preg_replace('/\b0x[0-9a-f]+\b|\b[0-9a-f]{8,}\b/i', '#', $l) ?? $l;           // hex values and ids
  $l = preg_replace('/\d+/', '#', $l) ?? $l;                                         // numbers, pids, addresses
  return substr($l, 0, 200);
}

/* ---- tailing ---- */
class LkTailer {
  private $fh = null; private $ino = null; private string $path; private string $rest = '';
  public function __construct(string $path, bool $fromEnd = true) { $this->path = $path; $this->open($fromEnd); }
  private function open(bool $fromEnd): void {
    $st = @stat($this->path);
    $fh = $st ? @fopen($this->path, 'r') : false;
    if (!$fh) { $this->fh = null; $this->ino = null; return; }
    if ($fromEnd) fseek($fh, 0, SEEK_END);
    $this->fh = $fh; $this->ino = $st['ino']; $this->rest = '';
  }
  /* complete new lines; a line still being written waits for its newline */
  public function lines(): array {
    if ($this->fh === null) { $this->open(false); if ($this->fh === null) return []; }
    clearstatcache(true, $this->path);
    $st = @stat($this->path);
    if ($st === false) { fclose($this->fh); $this->fh = null; return []; }
    if ($st['ino'] !== $this->ino) { $out = $this->drain(); fclose($this->fh); $this->open(false); return array_merge($out, $this->fh ? $this->drain() : []); }
    if ($st['size'] < ftell($this->fh)) { fseek($this->fh, 0); $this->rest = ''; }
    return $this->drain();
  }
  public function caughtUp(): bool {
    if ($this->fh === null) return true;
    clearstatcache(true, $this->path);
    return ftell($this->fh) >= (int)@filesize($this->path);
  }
  private function drain(): array {
    $data = $this->rest; $this->rest = '';
    while (!feof($this->fh) && strlen($data) < LK_MAX_READ) { $c = fread($this->fh, 65536); if ($c === '' || $c === false) break; $data .= $c; }
    fseek($this->fh, 0, SEEK_CUR);   // clears PHP's end-of-file state
    $p = strrpos($data, "\n");
    if ($p === false) { $this->rest = $data; return []; }
    $this->rest = substr($data, $p + 1);
    return array_values(array_filter(explode("\n", substr($data, 0, $p)), fn($l) => trim($l) !== ''));
  }
}

/* ---- findings: one file, changed only under its lock, so the page and the daemon cannot undo each other ---- */
function lk_findings_file(): string { return lk_state('findings.json'); }

function lk_with_findings(callable $change): array {
  @mkdir(lk_state(), 0755, true);
  $lock = fopen(lk_state('findings.lock'), 'c');
  if ($lock) flock($lock, LOCK_EX);
  $d = lk_read_json(lk_findings_file()) ?? ['findings' => []];
  if (!isset($d['findings']) || !is_array($d['findings'])) $d['findings'] = [];
  $d = $change($d);
  $d['generated'] = gmdate('c');
  $d['total'] = count($d['findings']);
  lk_write_atomic(lk_findings_file(), json_encode($d, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
  if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
  return $d;
}

/* $hits: [[rule, line, source, epoch, count], ...]. The same message inside the dedup window counts on its finding. So
 * does a flood: past LK_FLOOD_SIGNATURES different messages of one rule from one source in the window, they fold into
 * the newest finding of that rule. Returns [data, the rules that got a new finding]. */
function lk_merge_hits(array $d, array $hits, int $window, int $max): array {
  $new = [];
  foreach ($hits as [$r, $line, $source, $when, $count]) {
    $key = md5("{$r['id']}:$source:" . lk_signature($line));
    $iso = gmdate('c', $when);
    $sameRule = []; $same = null;
    foreach ($d['findings'] as $i => $f) {
      if (($f['rule_id'] ?? '') !== $r['id'] || ($f['log_source'] ?? '') !== $source) continue;
      if ($when - (int)strtotime($f['last_seen'] ?? '1970-01-01') >= $window) continue;
      $sameRule[] = $i;
      if (($f['dedup_key'] ?? '') === $key) $same = $i;
    }
    $target = $same ?? (count($sameRule) >= LK_FLOOD_SIGNATURES ? end($sameRule) : null);
    if ($target !== null) {
      $d['findings'][$target]['repeat_count'] = ($d['findings'][$target]['repeat_count'] ?? 1) + $count;
      $d['findings'][$target]['last_seen'] = $iso;
      if ($same === null) $d['findings'][$target]['flood'] = true;
      continue;
    }
    $d['findings'][] = [
      'id' => substr(md5("{$r['id']}:$line:$when:" . mt_rand()), 0, 12),
      'timestamp' => $iso, 'last_seen' => $iso, 'rule_id' => $r['id'],
      'category' => $r['category'] ?? 'unknown', 'severity' => isset(LK_RANK[$r['severity'] ?? '']) ? $r['severity'] : 'info',
      'name' => $r['name'] ?? 'Unknown', 'description' => $r['description'] ?? '', 'recommendation' => $r['recommendation'] ?? '',
      'setting_path' => $r['setting_path'] ?? '', 'config_file' => $r['config_file'] ?? '',
      'log_source' => $source, 'log_line' => substr($line, 0, LK_MAX_LINE), 'repeat_count' => $count,
      'acknowledged' => false, 'dedup_key' => $key,
    ];
    $new[$r['id']] = $r;
  }
  if (count($d['findings']) > $max) $d['findings'] = array_slice($d['findings'], -$max);
  return [$d, array_values($new)];
}

/* ---- notifications: one per rule per cooldown, through Unraid's own notify script ---- */
function lk_notify_due(array $rule, array $config, array &$sent, int $now): bool {
  if ($config['notify'] === 'off' || in_array($rule['id'], $config['muted'], true)) return false;
  $rank = LK_RANK[$rule['severity'] ?? 'info'] ?? 0;
  if ($rank < LK_RANK[$config['notify']]) return false;
  if ($now - ($sent[$rule['id']] ?? 0) < $config['notify_cooldown']) return false;
  $sent[$rule['id']] = $now;
  return true;
}

/* The notification carries the rule's own text, never the log line: that can hold what anyone typed at a login prompt. */
function lk_notify(array $rule): bool {
  $bin = getenv('LK_NOTIFY') ?: '/usr/local/emhttp/webGui/scripts/notify';
  $importance = ($rule['severity'] ?? '') === 'critical' ? 'alert' : 'warning';
  $p = @proc_open([$bin, '-e', 'Lookout', '-s', 'Lookout: ' . ($rule['name'] ?? 'finding'),
                   '-d', (string)($rule['recommendation'] ?? ''), '-i', $importance, '-l', '/Settings/Lookout'],
                  [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
  return is_resource($p) && proc_close($p) === 0;
}

function lk_flush(array $hits, array $config, bool $notify = true): void {
  $new = [];
  lk_with_findings(function ($d) use ($hits, $config, &$new) {
    [$d, $new] = lk_merge_hits($d, $hits, $config['dedup_window'], $config['max_findings']);
    return $d;
  });
  if (!$new || !$notify) return;
  $f = lk_run('notified.json');
  $sent = lk_read_json($f) ?? [];
  $changed = false;
  foreach ($new as $r) {
    if (lk_notify_due($r, $config, $sent, time())) { lk_notify($r); $changed = true; }
  }
  if ($changed) lk_write_atomic($f, json_encode($sent));
}

/* One read of the log saved at the last shutdown, once per boot: what went wrong before this start.
 * Findings from it name the source "syslog (previous boot)". */
function lk_read_previous(array $compiled, array $config): int {
  $f = lk_path('/boot/logs/syslog-previous');
  $done = lk_run('previous-read');
  if (!$config['read_previous'] || is_file($done) || !is_file($f)) return 0;
  @touch($done);
  $fh = @fopen($f, 'r');
  if (!$fh) return 0;
  $start = max(0, (int)filesize($f) - LK_MAX_PREVIOUS_BYTES);
  if ($start > 0) { fseek($fh, $start); fgets($fh); }
  $when = (int)filemtime($f); $source = 'syslog (previous boot)'; $hits = []; $n = 0; $min = LK_RANK[$config['severity_filter']];
  while (($line = fgets($fh)) !== false) {
    $line = rtrim($line, "\n");
    if (trim($line) === '') continue;
    foreach (lk_match($compiled, $line, 'syslog') as $r) {
      if ((LK_RANK[$r['severity'] ?? 'info'] ?? 0) < $min) continue;
      $k = "{$r['id']}:" . lk_signature($line);
      if (isset($hits[$k])) $hits[$k][4]++;
      else $hits[$k] = [$r, $line, $source, $when, 1];
      $n++;
    }
  }
  fclose($fh);
  if ($hits) lk_with_findings(function ($d) use ($hits, $config) { return lk_merge_hits($d, array_values($hits), $config['dedup_window'], $config['max_findings'])[0]; });
  return $n;
}

/* ---- daemon state ---- */
function lk_daemon_pid(): int {
  $pid = (int)@file_get_contents(lk_pidfile());
  return ($pid > 0 && file_exists("/proc/$pid") && strpos((string)@file_get_contents("/proc/$pid/cmdline"), 'lookout') !== false) ? $pid : 0;
}

function lk_status(): array {
  $s = lk_read_json(lk_state('state.json')) ?? [];
  $s['running'] = lk_daemon_pid() > 0;
  if (!$s['running'] || !isset($s['rules'])) {
    [$ok, $bad] = lk_compile(lk_load_rules());
    $s['rules'] = count($ok); $s['rules_bad'] = $bad;
  }
  return $s;
}
