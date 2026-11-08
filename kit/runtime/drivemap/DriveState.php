<?php
/* Drive state: what Unraid already knows about each drive, read from its own state files. No drive is opened, so no
 * drive is woken: the temperature is the one Unraid last read, SMART is Unraid's last report.
 *
 *   $st = DriveState::all();                       // or ['root' => captured tree]
 *   $st['sdk'] = ['slot' => 'disk3', 'temp' => 36 | null, 'standby' => bool, 'color' => 'green-on', 'state' => 'active',
 *                 'state_text' => 'Normal operation, device is active', 'smart' => ['state' => 'healthy', 'text' => ..., 'title' => ...]]
 *
 * Sources: /var/local/emhttp/disks.ini (array and pool slots: color, temp, spundown), devs.ini (unassigned: temp, spundown),
 * /var/local/emhttp/smart/<slot name> (smartctl -A as Unraid last saved it), /var/local/emhttp/monitor.ini (errors acknowledged
 * on the Dashboard). The colours and their meaning are Unraid's (webGui nchan/device_list); unassigned drives are blue there.
 * SMART attributes: Unraid's preselected notification codes (webGui include/Preselect.php: 5, 187, 197, 198, 199).
 * PHP 7.4 and newer. */

if (!class_exists('DriveState')) {

class DriveState {
  const SMART_CODES = ['5', '187', '197', '198', '199'];
  const STATES = [
    'green-on' => ['active', 'Normal operation, device is active'],
    'green-blink' => ['standby', 'Device is in standby mode (spun-down)'],
    'blue-on' => ['new', 'New device'],
    'blue-blink' => ['standby', 'New device, in standby mode (spun-down)'],
    'yellow-on' => ['invalid', 'Device contents emulated or invalid'],
    'yellow-blink' => ['invalid', 'Device contents emulated or invalid, in standby mode (spun-down)'],
    'red-on' => ['disabled', 'Device is disabled'],
    'red-blink' => ['disabled', 'Device is disabled'],
    'red-off' => ['missing', 'Device is missing (disabled)'],
  ];

  /* [kernel name => state] for every drive Unraid lists */
  public static function all(array $opt = []): array {
    $root = rtrim((string)($opt['root'] ?? (getenv('DRIVEMAP_ROOT') ?: '')), '/');
    $e = "$root/var/local/emhttp";
    $mon = @parse_ini_file("$e/monitor.ini", true, INI_SCANNER_RAW) ?: [];
    $out = [];
    foreach (['disks.ini' => false, 'devs.ini' => true] as $f => $unassigned) {
      foreach (@parse_ini_file("$e/$f", true, INI_SCANNER_RAW) ?: [] as $slot => $s) {
        $dev = (string)($s['device'] ?? '');
        if (!is_array($s) || $dev === '' || isset($out[$dev])) continue;
        $name = (string)($s['name'] ?? $slot);
        $standby = (string)($s['spundown'] ?? '0') === '1';
        $color = $unassigned ? ($standby ? 'blue-blink' : 'blue-on') : (string)($s['color'] ?? '');
        if (strpos($color, 'blink') !== false) $standby = true;
        [$state, $text] = self::STATES[$color] ?? ['unknown', 'Unraid reports no state'];
        if ($unassigned) [$state, $text] = $standby ? ['standby', 'Unassigned, in standby mode (spun-down)'] : ['active', 'Unassigned, active'];
        $t = (string)($s['temp'] ?? '*');
        $out[$dev] = ['slot' => $name, 'temp' => is_numeric($t) ? (int)$t : null, 'standby' => $standby, 'color' => $color,
                      'state' => $state, 'state_text' => $text, 'smart' => self::smart("$e/smart/$name", $mon, $name, $standby)];
      }
    }
    return $out;
  }

  /* Unraid's last SMART report for a slot: failed (an attribute failing now), error (one of the watched counters above 0),
   * healthy, or not known. A drive in standby keeps its last report. */
  public static function smart(string $file, array $mon, string $name, bool $standby): array {
    $s = @file_get_contents($file);
    if ($s === false || trim($s) === '') return ['state' => 'unknown', 'text' => 'unknown', 'title' => $standby ? 'In standby, no SMART report yet (not woken to read one)' : 'No SMART report yet'];
    if (preg_match('/^SMART overall-health.*: *([A-Z]+)/m', $s, $m) && $m[1] === 'FAILED') return ['state' => 'failed', 'text' => 'failed', 'title' => 'SMART health check failed'];
    $bad = []; $failing = false;
    foreach (preg_split('/\n/', $s) as $l) {
      $c = preg_split('/\s+/', trim($l));
      if (count($c) < 10 || !ctype_digit($c[0])) continue;
      if (strpos($c[8], 'FAILING_NOW') !== false) { $failing = true; $bad[] = str_replace('_', ' ', $c[1]) . ' failing now'; }
      elseif (in_array($c[0], self::SMART_CODES, true) && ctype_digit($c[9]) && (int)$c[9] > 0) $bad[] = str_replace('_', ' ', $c[1]) . ' ' . $c[9];
    }
    if ($failing) return ['state' => 'failed', 'text' => 'failed', 'title' => implode('; ', array_unique($bad))];
    if ($bad && !empty($mon['smart'][str_replace('~', '', $name) . '.ack'])) return ['state' => 'healthy', 'text' => 'healthy', 'title' => 'Errors acknowledged on the Dashboard: ' . implode('; ', array_unique($bad))];
    return $bad ? ['state' => 'error', 'text' => 'error', 'title' => implode('; ', array_unique($bad))] : ['state' => 'healthy', 'text' => 'healthy', 'title' => 'No errors reported'];
  }
}

}
