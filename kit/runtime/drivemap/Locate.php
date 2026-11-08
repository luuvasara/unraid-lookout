<?php
/* Locate: read a drive for some seconds so its activity light flashes, to find the drive in its bay. Read-only: dd from the
 * drive into /dev/null with O_DIRECT, so every read reaches the drive and not the page cache. Nothing is written anywhere
 * but the state file. One drive at a time on the whole server, for every plugin built with this kit (one state file).
 *
 *   $loc = new DriveLocate();                      // or ['root' => captured tree, 'state' => file, 'run' => callable for tests]
 *   $loc->start('sdk', $standby, $confirmed);      // ['ok' => true, 'device' => 'sdk', 'seconds' => 20] | ['ok' => false, 'error' => ..., 'confirm' => true?]
 *   $loc->status();                                // ['running' => true, 'device' => 'sdk', 'left' => 12] | ['running' => false]
 *   $loc->stop();
 *
 * A drive in standby is only read when the caller says the user agreed ($confirmed): the read spins it up. PHP 7.4 and newer. */

if (!class_exists('DriveLocate')) {

class DriveLocate {
  const SECONDS = 20;
  const NAME = '/^(sd[a-z]{1,3}|hd[a-z]{1,3}|vd[a-z]{1,3}|nvme\d{1,3}n\d{1,3})$/';
  private $root;
  private $state;
  private $run;

  public function __construct(array $opt = []) {
    $this->root = rtrim((string)($opt['root'] ?? (getenv('DRIVEMAP_ROOT') ?: '')), '/');
    $this->state = (string)($opt['state'] ?? '/var/run/drivemap/locate.json');
    $this->run = $opt['run'] ?? function (string $node, int $secs): int {
      return (int)exec('setsid timeout ' . $secs . ' dd if=' . escapeshellarg($node) . ' of=/dev/null bs=1M iflag=direct status=none </dev/null >/dev/null 2>&1 & echo $!');
    };
  }

  public function status(): array {
    $s = json_decode((string)@file_get_contents($this->state), true);
    if (!is_array($s) || empty($s['pid']) || !preg_match(self::NAME, (string)($s['device'] ?? ''))) return ['running' => false];
    $cmd = (string)@file_get_contents("/proc/{$s['pid']}/cmdline");
    $left = self::SECONDS - (time() - (int)($s['started'] ?? 0));
    if (strpos($cmd, 'timeout') === false || strpos($cmd, "/{$s['device']}") === false || $left < -5) { @unlink($this->state); return ['running' => false, 'last' => $s['device']]; }
    return ['running' => true, 'device' => $s['device'], 'left' => max(0, $left)];
  }

  public function start(string $dev, bool $standby, bool $confirmed): array {
    if (!preg_match(self::NAME, $dev) || !is_dir($this->root . "/sys/block/$dev")) return ['ok' => false, 'error' => 'No such drive.'];
    $st = $this->status();
    if ($st['running']) return ['ok' => false, 'error' => "Another drive is being located ({$st['device']}). Stop it first, or wait {$st['left']} s."];
    if ($standby && !$confirmed) return ['ok' => false, 'confirm' => true, 'error' => "$dev is in standby. Locating it spins it up."];
    $node = $this->root . "/dev/$dev";
    if (!file_exists($node) || ($this->root === '' && filetype($node) !== 'block')) return ['ok' => false, 'error' => "$dev has no device node to read."];
    @mkdir(dirname($this->state), 0755, true);
    $pid = ($this->run)($node, self::SECONDS);
    if ($pid <= 0) return ['ok' => false, 'error' => 'The read could not be started.'];
    file_put_contents($this->state, json_encode(['device' => $dev, 'pid' => $pid, 'started' => time()]));
    return ['ok' => true, 'device' => $dev, 'seconds' => self::SECONDS];
  }

  public function stop(): array {
    $st = $this->status();
    if (!$st['running']) return ['ok' => true, 'running' => false];
    $s = json_decode((string)@file_get_contents($this->state), true);
    if (function_exists('posix_kill')) @posix_kill((int)$s['pid'], 15);   // timeout passes the signal on to dd
    else @exec('kill ' . (int)$s['pid']);
    for ($i = 0; $i < 20 && $this->status()['running']; $i++) usleep(100000);
    @unlink($this->state);
    return ['ok' => true, 'running' => false];
  }
}

}
