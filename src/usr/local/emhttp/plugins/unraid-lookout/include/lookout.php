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
const LK_MAX_MATCH = 8192;        // bytes of a log line the rules see; syslog's own limit is 8 KB, longer is not a real message
const LK_MAX_HELD = 65536;        // a line still without its newline past this is cut there, the rest of it skipped
const LK_MAX_PATTERN = 1000;      // longest pattern a rule may have; the shipped ones stay under 200
const LK_MAX_JSON = 16000000;     // findings.json at 5000 findings stays well under this
const LK_MAX_CUSTOM_RULES = 1000000;
const LK_SOURCES = ['syslog', 'docker', 'libvirt'];

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

/* A file larger than $max is not read: no file of ours gets near it, so it is not ours. */
function lk_read_json(string $f, int $max = LK_MAX_JSON): ?array {
  if (!is_file($f) || (int)@filesize($f) > $max) return null;
  $d = json_decode((string)@file_get_contents($f), true);
  return is_array($d) ? $d : null;
}

/* Text from a log, a rule or a file name, made safe to store, show and print: escape sequences (colours, terminal titles,
 * clipboard writes), other control characters, C1 controls and bidi overrides are taken out, a tab becomes a space, and
 * the result is valid UTF-8 of at most $max bytes. Anyone who can make a program write a log line controls that text. */
function lk_clean(string $s, int $max = LK_MAX_MATCH): string {
  if (strlen($s) > $max * 2) $s = substr($s, 0, $max * 2);
  $s = preg_replace('/\x1b(?:\[[0-?]*[ -\/]*[@-~]?|\][^\x07\x1b]*(?:\x07|\x1b\\\\)?|[PX^_][^\x1b]*(?:\x1b\\\\)?|[ -\/]*[0-~]?)/', '', $s) ?? '';
  $s = str_replace("\t", ' ', $s);
  $s = preg_replace('/[\x00-\x1f\x7f]|\xc2[\x80-\x9f]|\xe2\x80[\x8e\x8f\xaa-\xae]|\xe2\x81[\xa6-\xa9]/', '', $s) ?? '';
  if (preg_match('//u', $s) !== 1) $s = function_exists('mb_scrub') ? mb_scrub($s, 'UTF-8') : (preg_replace('/[\x80-\xff]/', '?', $s) ?? '');
  return lk_cut($s, $max);
}

/* At most $max bytes, never half a UTF-8 character. */
function lk_cut(string $s, int $max): string {
  if (strlen($s) <= $max) return $s;
  $s = substr($s, 0, $max);
  return preg_match('//u', $s) === 1 ? $s : (preg_replace('/[\xc0-\xff][\x80-\xbf]*$/', '', $s) ?? '');
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
  if (is_string($in['severity_filter'] ?? null) && isset(LK_RANK[$in['severity_filter']])) $out['severity_filter'] = $in['severity_filter'];
  if (is_string($in['notify'] ?? null) && in_array($in['notify'], ['off', 'critical', 'warning'], true)) $out['notify'] = $in['notify'];
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
  $all = array_merge(lk_read_json(lk_shipped_rules())['rules'] ?? [], lk_read_json(lk_flash('custom-rules.json'), LK_MAX_CUSTOM_RULES)['rules'] ?? []);
  foreach (is_array($all) ? $all : [] as $i => $r) {
    $r = lk_check_rule($r, $i);
    if (!isset($r['_invalid'])) { if (isset($seen[$r['id']])) continue; $seen[$r['id']] = true; }
    $rules[] = $r;
  }
  return $rules;
}

/* One rule as the file has it, checked field by field: the rule with its text fields as plain strings, or
 * ['id' => a printable name for it, '_invalid' => why], which lk_compile reports with the rules that do not compile. */
function lk_check_rule($r, int $i): array {
  if (!is_array($r)) return ['id' => "#$i", '_invalid' => 'not an object'];
  $id = $r['id'] ?? null;
  if (!is_string($id) || preg_match('/^[A-Za-z0-9._:-]{1,80}$/', $id) !== 1) return ['id' => "#$i", '_invalid' => 'id must be 1 to 80 of A-Z a-z 0-9 . _ : -'];
  foreach (['pattern', 'exclude_pattern'] as $k) {
    if ($k === 'pattern' || isset($r[$k])) {
      if (!is_string($r[$k] ?? null) || ($k === 'pattern' && $r[$k] === '') || strlen($r[$k]) > LK_MAX_PATTERN) return ['id' => $id, '_invalid' => "$k must be text of 1 to " . LK_MAX_PATTERN . ' characters'];
    }
  }
  if (isset($r['log_sources']) && (!is_array($r['log_sources']) || array_filter($r['log_sources'], fn($s) => !is_string($s) || !in_array($s, LK_SOURCES, true)))) {
    return ['id' => $id, '_invalid' => 'log_sources must be a list of ' . implode(', ', LK_SOURCES)];
  }
  if (isset($r['severity']) && !(is_string($r['severity']) && isset(LK_RANK[$r['severity']]))) return ['id' => $id, '_invalid' => 'severity must be info, warning or critical'];
  foreach (['name' => 120, 'category' => 40, 'description' => 2000, 'recommendation' => 2000, 'setting_path' => 200, 'config_file' => 200] as $k => $max) {
    if (!isset($r[$k])) continue;
    if (!is_string($r[$k])) return ['id' => $id, '_invalid' => "$k must be text"];
    $r[$k] = lk_clean($r[$k], $max);
  }
  return $r;
}

function lk_regex(string $pattern): string { return "\x01" . str_replace("\x01", '', $pattern) . "\x01i"; }

/* A pattern runs on every log line. One that PCRE gives up on, or that takes long, on these lines would do the same on a
 * real one, every time: the long runs of one kind of character are what makes nested repeats backtrack. */
function lk_regex_too_slow(string $re): bool {
  static $probes = null;
  if ($probes === null) {
    $probes = [];
    foreach (['a', '1', ' ', 'aA1 ', 'a.b/c-', "\xc3\xa4"] as $c) { $run = substr(str_repeat($c, 4096), 0, 4096); $probes[] = $run; $probes[] = "$run!"; $probes[] = "x $run\x01"; }
  }
  $t = microtime(true);
  foreach ($probes as $s) {
    if (@preg_match($re, $s) === false) return true;
    if (microtime(true) - $t > 0.05) return true;
  }
  return false;
}

/* [compiled rules, ids that are refused: invalid, not compiling, or too slow] */
function lk_compile(array $rules): array {
  $ok = []; $bad = [];
  foreach ($rules as $r) {
    if (isset($r['_invalid'])) { $bad[] = $r['id']; continue; }
    $p = lk_regex($r['pattern']);
    $x = ($r['exclude_pattern'] ?? '') !== '' ? lk_regex($r['exclude_pattern']) : null;
    if (@preg_match($p, '') === false || ($x !== null && @preg_match($x, '') === false)) { $bad[] = $r['id']; continue; }
    if (lk_regex_too_slow($p) || ($x !== null && lk_regex_too_slow($x))) { $bad[] = $r['id']; continue; }
    $ok[] = [$r, $p, $x, $r['log_sources'] ?? []];
  }
  return [$ok, $bad];
}

/* A VM's own log counts as the source "libvirt" for rules; the finding still names the VM. A pattern that PCRE gives up
 * on (backtrack or stack limit) three times is not run again by this process: it would cost that on every line. */
function lk_match(array $compiled, string $line, string $source): array {
  static $errors = [];
  $src = strncmp($source, 'vm:', 3) === 0 ? 'libvirt' : $source;
  $hits = [];
  foreach ($compiled as [$r, $p, $x, $sources]) {
    if ($sources && !in_array($src, $sources, true)) continue;
    if (($errors[$p] ?? 0) >= 3 || ($x !== null && ($errors[$x] ?? 0) >= 3)) continue;
    $m = preg_match($p, $line);
    if ($m === false) { if (($errors[$p] = ($errors[$p] ?? 0) + 1) === 3) lk_log("rule {$r['id']} stopped: " . preg_last_error_msg()); continue; }
    if ($m !== 1) continue;
    $e = $x === null ? 0 : preg_match($x, $line);
    if ($e === false) { if (($errors[$x] = ($errors[$x] ?? 0) + 1) === 3) lk_log("rule {$r['id']} stopped: " . preg_last_error_msg()); continue; }
    if ($e !== 1) $hits[] = $r;
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
  private $fh = null; private $ino = null; private string $path; private string $rest = ''; private bool $skip = false;
  public function __construct(string $path, bool $fromEnd = true) { $this->path = $path; $this->open($fromEnd); }
  private function open(bool $fromEnd): void {
    $st = @stat($this->path);
    $fh = $st ? @fopen($this->path, 'r') : false;
    if (!$fh) { $this->fh = null; $this->ino = null; return; }
    if ($fromEnd) fseek($fh, 0, SEEK_END);
    $this->fh = $fh; $this->ino = $st['ino']; $this->rest = ''; $this->skip = false;
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
  /* Lines come out cleaned (lk_clean) and at most LK_MAX_MATCH bytes. A line that grows past LK_MAX_HELD without its
   * newline is given as far as it got and the rest of it is skipped: held whole, it would fill the read budget of every
   * poll and the tailer would never reach the lines after it. */
  private function drain(): array {
    $data = $this->rest; $this->rest = '';
    while (!feof($this->fh) && strlen($data) < LK_MAX_READ) { $c = fread($this->fh, 65536); if ($c === '' || $c === false) break; $data .= $c; }
    fseek($this->fh, 0, SEEK_CUR);   // clears PHP's end-of-file state
    if ($this->skip) {
      $p = strpos($data, "\n");
      if ($p === false) return [];
      $data = substr($data, $p + 1); $this->skip = false;
    }
    $p = strrpos($data, "\n");
    $body = $p === false ? '' : substr($data, 0, $p);
    $tail = $p === false ? $data : substr($data, $p + 1);
    $out = [];
    foreach ($body === '' ? [] : explode("\n", $body) as $l) { $l = lk_clean($l); if (trim($l) !== '') $out[] = $l; }
    if (strlen($tail) > LK_MAX_HELD) {
      $l = lk_clean($tail);
      if (trim($l) !== '') $out[] = $l;
      $this->skip = true;
    } else $this->rest = $tail;
    return $out;
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

/* The drives a log line names, as the kit's drive map knows them: a line that says only ata7, sdk1 or devid 2 gets the
 * slot, model and serial beside it. [['name' => as written, 'label' => 'cache (sdk, model, serial X)'], ...]. Read when a
 * finding is made, so it names the drive that had the name then. Empty when the package has no drive map. */
function lk_drives(string $line): array {
  static $ready = null, $read = 0;
  if ($ready === null) {
    $lib = getenv('LK_DRIVEMAP') ?: __DIR__ . '/../kit/DriveMap.php';
    $ready = is_file($lib) && (require_once $lib) && class_exists('DriveMap');
  }
  if (!$ready) return [];
  $map = DriveMap::shared();
  if (time() - $read > 60) { $map->refresh(); $read = time(); }   // a daemon runs for weeks; drives come and go
  $out = [];
  foreach ($map->scan($line) as $h) $out[] = ['name' => $h['name'], 'label' => DriveMap::label($h['drive'])];
  return $out;
}

/* $hits: [[rule, line, source, epoch, count], ...]. The same message inside the dedup window counts on its finding. So
 * does a flood: past LK_FLOOD_SIGNATURES different messages of one rule from one source in the window, they fold into
 * the newest finding of that rule. Returns [data, the rules that got a new finding]. */
function lk_merge_hits(array $d, array $hits, int $window, int $max): array {
  $new = [];
  // what goes into findings.json is plain text whatever the rule or the line held: the page, the shell and the
  // notification all read it from there
  $text = fn($v, string $default, int $max) => is_string($v) ? lk_clean($v, $max) : $default;
  foreach ($hits as [$r, $line, $source, $when, $count]) {
    $source = lk_clean((string)$source, 120);
    $line = lk_clean((string)$line);
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
      'category' => $text($r['category'] ?? null, 'unknown', 40),
      'severity' => is_string($r['severity'] ?? null) && isset(LK_RANK[$r['severity']]) ? $r['severity'] : 'info',
      'name' => $text($r['name'] ?? null, 'Unknown', 120), 'description' => $text($r['description'] ?? null, '', 2000),
      'recommendation' => $text($r['recommendation'] ?? null, '', 2000),
      'setting_path' => $text($r['setting_path'] ?? null, '', 200), 'config_file' => $text($r['config_file'] ?? null, '', 200),
      'log_source' => $source, 'log_line' => lk_cut($line, LK_MAX_LINE), 'repeat_count' => $count,
      'acknowledged' => false, 'dedup_key' => $key, 'drives' => lk_drives($line),
    ];
    $new[$r['id']] = $r;
  }
  if (count($d['findings']) > $max) $d['findings'] = array_slice($d['findings'], -$max);
  return [$d, array_values($new)];
}

/* ---- notifications: one per rule per cooldown, through Unraid's own notify script ---- */
function lk_notify_due(array $rule, array $config, array &$sent, int $now): bool {
  if ($config['notify'] === 'off' || in_array($rule['id'], $config['muted'], true)) return false;
  $rank = is_string($rule['severity'] ?? null) ? (LK_RANK[$rule['severity']] ?? 0) : 0;
  if ($rank < LK_RANK[$config['notify']]) return false;
  if ($now - ($sent[$rule['id']] ?? 0) < $config['notify_cooldown']) return false;
  $sent[$rule['id']] = $now;
  return true;
}

/* The notification carries the rule's own text, never the log line: that can hold what anyone typed at a login prompt.
 * It goes as an argument list, no shell. Unraid's notify writes subject and description into an ini file, one key per
 * line (so no line breaks), shows them in its own page and mails them (so no < or >: a rule's "<poolName>" becomes
 * "‹poolName›"), and short. */
function lk_notify_text($s, int $max): string {
  $s = lk_clean(preg_replace('/[\r\n]+/', ' ', is_string($s) ? $s : '') ?? '', $max * 2);
  $s = str_replace(['<', '>'], ["\u{2039}", "\u{203A}"], $s);
  return lk_cut(trim(preg_replace('/ {2,}/', ' ', $s) ?? ''), $max);
}

function lk_notify(array $rule): bool {
  $bin = getenv('LK_NOTIFY') ?: '/usr/local/emhttp/webGui/scripts/notify';
  $importance = ($rule['severity'] ?? '') === 'critical' ? 'alert' : 'warning';
  $p = @proc_open([$bin, '-e', 'Lookout', '-s', lk_notify_text('Lookout: ' . (is_string($rule['name'] ?? null) ? $rule['name'] : 'finding'), 120),
                   '-d', lk_notify_text($rule['recommendation'] ?? '', 500), '-i', $importance, '-l', '/Settings/Lookout'],
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

/* The next line without its newline, at most LK_MAX_HELD bytes of it; the rest of a longer line is skipped. null at the end. */
function lk_fgets($fh): ?string {
  $l = fgets($fh, LK_MAX_HELD);
  if ($l === false) return null;
  if (substr($l, -1) === "\n") return substr($l, 0, -1);
  while (($more = fgets($fh, LK_MAX_HELD)) !== false && substr($more, -1) !== "\n");
  return $l;
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
  if ($start > 0) { fseek($fh, $start); lk_fgets($fh); }
  $when = (int)filemtime($f); $source = 'syslog (previous boot)'; $hits = []; $n = 0; $min = LK_RANK[$config['severity_filter']];
  while (($line = lk_fgets($fh)) !== null) {
    $line = lk_clean($line);
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
