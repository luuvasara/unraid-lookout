<?php
/* Enclosures: which bay of a disk enclosure a drive sits in, from the drive's PCI path and port.
 *
 * A profile (enclosures/<id>.json next to this file) names the controller chip of the enclosure's card, the PCIe switch
 * the controllers sit behind, the groups of bays each cable feeds and the front layout. Its "calibration" holds two
 * lists measured on a real unit:
 *   port_to_position    for a controller's port 1..n, the position of its bay in the group (a fact of the card);
 *   card_port_to_group  for the card's controllers in PCI address order, the group of bays its cable feeds. This one
 *                       depends on how the cables are plugged, so it is a default (card_port_to_group_default: true)
 *                       until the plugin passes a unit's confirmed cabling: ['cabling' => [switch address => [group, ...]]].
 * A list that is null means unknown: the drive is then shown by its controller (PCI order, counted from 1) and port,
 * never by a guessed bay.
 *
 *   $enc = new DriveEnclosures();                  // or ['root' => captured tree, 'dir' => profile dir, 'cabling' => [...]]
 *   $places = $enc->place(DriveMap::shared()->drives());   // [drive key => place], drives in no known enclosure left out
 *   $place = ['profile' => id, 'name' => 'QNAP TL-D1600S', 'unit' => 1, 'switch' => '0000:01:00.0', 'controller' => 2,
 *             'port' => 3, 'group' => 1|null, 'bay' => 'HDD3'|null, 'confirmed' => bool, 'label' => '...']
 *   $enc->units($places);                          // each enclosure found: profile, unit, front grid with the drive keys
 *   $enc->links($unit);                            // the PCIe links of a unit's card: negotiated against what both ends can do
 *   $enc->candidates();                            // controllers that carry a profile's subsystem id but belong to no unit
 *   DriveEnclosures::readCabling($file); $enc->saveCabling($file, $switch, [group, ...]);   // the confirmed cabling, as JSON
 *
 * A profile may also name the subsystem ids of the card's controllers (match.subsystem, ["1baa:c021"]): a controller
 * then has to carry one of them ("1baa:*": any of that subsystem vendor), so a card from another maker with the same chips
 * is not taken for this enclosure.
 *
 * Reads sysfs only (/sys/bus/pci/devices/<address>/vendor, device, subsystem_*, *_link_*). saveCabling() writes the one
 * file it is given. PHP 7.4 and newer. */

if (!class_exists('DriveEnclosures')) {

class DriveEnclosures {
  private $root;
  private $dir;
  private $cabling;
  private $profiles = null;
  private $ids = [];
  private $subs = [];

  public function __construct(array $opt = []) {
    $this->root = rtrim((string)($opt['root'] ?? (getenv('DRIVEMAP_ROOT') ?: '')), '/');
    $this->dir = (string)($opt['dir'] ?? (__DIR__ . '/enclosures'));
    $this->cabling = (array)($opt['cabling'] ?? []);
  }

  /* Every profile, keyed by id. A profile that does not parse or lacks a field is left out. */
  public function profiles(): array {
    if ($this->profiles !== null) return $this->profiles;
    $this->profiles = [];
    foreach (glob($this->dir . '/*.json') ?: [] as $f) {
      $p = json_decode((string)@file_get_contents($f), true);
      if (!is_array($p) || empty($p['id']) || empty($p['match']['controller']) || empty($p['groups']) || empty($p['front'])) continue;
      $p['calibration'] = ($p['calibration'] ?? []) + ['card_port_to_group' => null, 'port_to_position' => null, 'card_port_to_group_default' => true];
      $this->profiles[$p['id']] = $p;
    }
    ksort($this->profiles);
    return $this->profiles;
  }

  /* vendor:device of a PCI address, lower case without 0x: "1b21:1164"; '' when unknown */
  public function pciId(string $addr): string {
    if (isset($this->ids[$addr])) return $this->ids[$addr];
    $v = $this->read("/sys/bus/pci/devices/$addr/vendor"); $d = $this->read("/sys/bus/pci/devices/$addr/device");
    return $this->ids[$addr] = ($v !== '' && $d !== '') ? strtolower(preg_replace('/^0x/i', '', $v) . ':' . preg_replace('/^0x/i', '', $d)) : '';
  }

  /* subsystem vendor:device of a PCI address, "1baa:c021"; '' when unknown */
  public function pciSubsystem(string $addr): string {
    if (isset($this->subs[$addr])) return $this->subs[$addr];
    $v = $this->read("/sys/bus/pci/devices/$addr/subsystem_vendor"); $d = $this->read("/sys/bus/pci/devices/$addr/subsystem_device");
    return $this->subs[$addr] = ($v !== '' && $d !== '') ? strtolower(preg_replace('/^0x/i', '', $v) . ':' . preg_replace('/^0x/i', '', $d)) : '';
  }

  /* A cabling the plugin may store for a unit: one group per controller, each group once. */
  public static function validCabling(array $p, $order): bool {
    $n = (int)($p['match']['controllers'] ?? count($p['groups']));
    if (!is_array($order) || count($order) !== $n) return false;
    $order = array_map('strval', array_values($order));
    return count(array_unique($order)) === $n && !array_diff($order, array_map('strval', array_keys($p['groups'])));
  }

  public function place(array $drives): array {
    $out = [];
    foreach ($this->profiles() as $p) {
      $unit = 0;
      foreach ($this->unitsOf($p) as $sw => $ctls) {
        $unit++;
        foreach ($drives as $d) {
          if (empty($d['pci_path']) || isset($out[$d['key']])) continue;
          $i = array_search(end($d['pci_path']), $ctls, true);
          if ($i !== false) $out[$d['key']] = $this->where($p, $unit, $sw, $i + 1, $d['port_no'] ?? null);
        }
      }
    }
    return $out;
  }

  /* The enclosures found: [['profile' => id, 'name' => ..., 'unit' => 1, 'switch' => address, 'cabling' => [group, ...]|null,
   * 'confirmed' => bool, 'controllers' => [addr, ...], 'groups' => profile groups,
   * 'front' => [[['bay' => 'HDD9', 'key' => drive key or null], ...], ...], 'unplaced' => [drive keys with no bay],
   * 'card' => profile card name, 'subsystem' => the controllers' subsystem id, 'kinds' => profile bay_kinds (bay name
   * prefix => kind, "SSD" => "2.5in"), 'notes' => profile host_view (what a host can and cannot see of the enclosure)], ...] */
  public function units(array $places): array {
    $out = [];
    foreach ($this->profiles() as $p) {
      $unit = 0;
      foreach ($this->unitsOf($p) as $sw => $ctls) {
        $unit++;
        $byBay = []; $unplaced = [];
        foreach ($places as $key => $pl) {
          if ($pl['profile'] !== $p['id'] || $pl['unit'] !== $unit) continue;
          if ($pl['bay'] !== null) $byBay[$pl['bay']] = $key; else $unplaced[] = $key;
        }
        $front = [];
        foreach ($p['front'] as $row) $front[] = array_map(function ($b) use ($byBay) { return ['bay' => $b, 'key' => $byBay[$b] ?? null]; }, $row);
        [$cab, $confirmed] = $this->cablingOf($p, $sw);
        $out[] = ['profile' => $p['id'], 'name' => $p['name'], 'card' => (string)($p['card'] ?? ''), 'unit' => $unit, 'switch' => $sw,
                  'subsystem' => $this->pciSubsystem($ctls[0]), 'cabling' => $cab, 'confirmed' => $confirmed,
                  'kinds' => (array)($p['bay_kinds'] ?? []), 'notes' => array_values(array_map('strval', (array)($p['host_view'] ?? []))),
                  'ports_known' => is_array($p['calibration']['port_to_position']), 'controllers' => $ctls, 'groups' => $p['groups'],
                  'front' => $front, 'unplaced' => $unplaced];
      }
    }
    return $out;
  }

  /* The PCIe links of a unit's card, from the slot down to each controller: [['from' => address, 'to' => address,
   * 'what' => 'card' | 'controller', 'controller' => n | null, 'speed' => 8.0 (GT/s) | null, 'width' => 8 | null,
   * 'want_speed' => ..., 'want_width' => ..., 'state' => 'ok' | 'slower' | 'narrower' | 'slower, narrower' | 'unknown'], ...].
   * Expected is the lower of what the two ends can do (max_link_* of each); a link below it trained down (a loose card,
   * a slot that shares lanes, a bad riser). The switch's upstream link is the card's link to the slot. */
  public function links(array $unit): array {
    $sw = (string)$unit['switch'];
    if ($sw === '' || $sw === 'none') return [];
    $out = [];
    $parent = $this->parentOf($sw);
    if ($parent !== null) $out[] = $this->link($parent, $sw, 'card', null);
    foreach (array_values($unit['controllers']) as $i => $c) {
      $down = $this->parentOf($c);
      if ($down !== null) $out[] = $this->link($down, $c, 'controller', $i + 1);
    }
    return $out;
  }

  /* Controllers of a profile's chip that carry its subsystem id but are in no unit of it: a card this kit has no full profile for,
   * or one whose layout differs. [['profile' => id, 'address' => ..., 'id' => '1b21:1164', 'subsystem' => '1baa:c021'], ...] */
  public function candidates(): array {
    $out = [];
    foreach ($this->profiles() as $p) {
      $subs = array_map('strtolower', (array)($p['match']['subsystem'] ?? []));
      $want = strtolower($p['match']['controller']);
      if (!$subs) continue;
      $known = array_merge([], ...array_values($this->unitsOf($p)));
      foreach (@scandir($this->path('/sys/bus/pci/devices')) ?: [] as $addr) {
        if ($addr[0] === '.' || in_array($addr, $known, true) || $this->pciId($addr) !== $want || !self::subsystemIn($this->pciSubsystem($addr), $subs)) continue;
        $out[$addr] = ['profile' => $p['id'], 'address' => $addr, 'id' => $this->pciId($addr), 'subsystem' => $this->pciSubsystem($addr)];
      }
    }
    ksort($out);
    return array_values($out);
  }

  /* The confirmed cabling a plugin keeps: {"<switch address>": [group, ...]}; [] when there is none */
  public static function readCabling(string $file): array {
    $j = json_decode((string)@file_get_contents($file), true);
    return is_array($j) ? $j : [];
  }

  /* Store the cabling of the unit behind $switch, checked against its profile. ['ok' => true] or ['ok' => false, 'error' => text] */
  public function saveCabling(string $file, string $switch, $order): array {
    $unit = null;
    foreach ($this->units([]) as $u) if ($u['switch'] === $switch) $unit = $u;
    if (!$unit) return ['ok' => false, 'error' => 'No such enclosure on this server.'];
    if (!self::validCabling($this->profiles()[$unit['profile']], $order)) return ['ok' => false, 'error' => 'Each controller needs a different group of bays.'];
    $all = self::readCabling($file); $all[$switch] = array_map('intval', array_values($order));
    @mkdir(dirname($file), 0755, true);
    if (@file_put_contents("$file.new", json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false || !@rename("$file.new", $file))
      return ['ok' => false, 'error' => 'The setting could not be saved.'];
    $this->cabling = $all;
    return ['ok' => true];
  }

  /* "1baa:c021" against ["1baa:c021", "1baa:*"]: a "*" device takes any card of that subsystem vendor */
  public static function subsystemIn(string $sub, array $list): bool {
    if ($sub === '') return false;
    foreach ($list as $w) { $w = strtolower((string)$w); if ($w === $sub || (substr($w, -2) === ':*' && strpos($sub, substr($w, 0, -1)) === 0)) return true; }
    return false;
  }

  /* ---- links ---- */

  private function parentOf(string $addr): ?string {
    $real = @realpath($this->path("/sys/bus/pci/devices/$addr"));
    if ($real === false) return null;
    $up = basename(dirname($real));
    return preg_match('/^[0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-9a-f]$/', $up) ? $up : null;
  }

  private function linkOf(string $addr, string $kind): array {
    $s = $this->read("/sys/bus/pci/devices/$addr/{$kind}_link_speed"); $w = $this->read("/sys/bus/pci/devices/$addr/{$kind}_link_width");
    return [preg_match('/^([0-9.]+) GT\/s/', $s, $m) ? (float)$m[1] : null, ctype_digit($w) && (int)$w > 0 ? (int)$w : null];
  }

  private function link(string $from, string $to, string $what, ?int $ctl): array {
    [$speed, $width] = $this->linkOf($to, 'current');
    [$ms1, $mw1] = $this->linkOf($from, 'max'); [$ms2, $mw2] = $this->linkOf($to, 'max');
    $ws = ($ms1 !== null && $ms2 !== null) ? min($ms1, $ms2) : ($ms1 ?? $ms2);
    $ww = ($mw1 !== null && $mw2 !== null) ? min($mw1, $mw2) : ($mw1 ?? $mw2);
    $bad = [];
    if ($speed === null || $width === null || $ws === null || $ww === null) $state = 'unknown';
    else {
      if ($speed < $ws) $bad[] = 'slower';
      if ($width < $ww) $bad[] = 'narrower';
      $state = $bad ? implode(', ', $bad) : 'ok';
    }
    return ['from' => $from, 'to' => $to, 'what' => $what, 'controller' => $ctl, 'speed' => $speed, 'width' => $width,
            'want_speed' => $ws, 'want_width' => $ww, 'state' => $state];
  }

  /* ---- matching ---- */

  /* [group list or null, confirmed] for one unit: the plugin's confirmed cabling, else the profile's default */
  private function cablingOf(array $p, string $sw): array {
    $c = $this->cabling[$sw] ?? null;
    if ($c !== null && self::validCabling($p, $c)) return [array_map('intval', array_values($c)), true];
    $d = $p['calibration']['card_port_to_group'];
    return [is_array($d) ? $d : null, is_array($d) && empty($p['calibration']['card_port_to_group_default'])];
  }

  /* The units of a profile on this server: [switch upstream address => its controllers in PCI address order]. A controller
   * with the right id behind no switch of the profile's id is not this enclosure's card. */
  private function unitsOf(array $p): array {
    $want = strtolower($p['match']['controller']);
    $switch = strtolower((string)($p['match']['switch'] ?? ''));
    $subs = array_map('strtolower', (array)($p['match']['subsystem'] ?? []));
    $units = [];
    foreach (@scandir($this->path('/sys/bus/pci/devices')) ?: [] as $addr) {
      if ($addr[0] === '.' || $this->pciId($addr) !== $want) continue;
      if ($subs && !self::subsystemIn($this->pciSubsystem($addr), $subs)) continue;   // the same chip on another maker's card
      $real = @realpath($this->path("/sys/bus/pci/devices/$addr"));
      if ($real === false) continue;
      preg_match_all('#/([0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-9a-f])(?=/)#', substr($real, strlen($this->root)) . '/', $m);
      $top = null;
      foreach ($m[1] as $a) if ($a !== $addr && $switch !== '' && $this->pciId($a) === $switch) { $top = $a; break; }   // the switch's upstream port
      if ($switch !== '' && $top === null) continue;
      $units[$top ?? 'none'][] = $addr;
    }
    ksort($units);
    $max = (int)($p['match']['controllers'] ?? 0);
    $out = [];
    foreach ($units as $sw => $ctls) {
      sort($ctls);
      if ($max > 0 && count($ctls) > $max) continue;   // more controllers than the card has: not this card
      $out[(string)$sw] = $ctls;
    }
    return $out;
  }

  private function where(array $p, int $unit, string $sw, int $ctl, ?int $port): array {
    [$cab, $confirmed] = $this->cablingOf($p, $sw);
    $ptp = $p['calibration']['port_to_position'];
    $group = is_array($cab) ? ($cab[$ctl - 1] ?? null) : null;
    $pos = (is_array($ptp) && $port !== null) ? ($ptp[$port - 1] ?? null) : null;
    $bay = ($group !== null && $pos !== null) ? ($p['groups'][(string)$group][$pos - 1] ?? null) : null;
    $name = "{$p['name']} $unit";
    if ($bay !== null) $label = "$name, $bay" . ($confirmed ? '' : ' (cabling not confirmed)');
    elseif ($group !== null) { $g = array_values($p['groups'][(string)$group] ?? []); $label = "$name, cable $group" . ($g ? " ({$g[0]}-" . end($g) . ')' : '') . ($port !== null ? ", port $port" : ''); }
    else $label = "$name, controller $ctl" . ($port !== null ? ", port $port" : '');
    return ['profile' => $p['id'], 'name' => $p['name'], 'unit' => $unit, 'switch' => $sw, 'controller' => $ctl, 'port' => $port,
            'group' => $group === null ? null : (int)$group, 'bay' => $bay, 'confirmed' => $confirmed, 'label' => $label];
  }

  private function path(string $p): string { return $this->root . $p; }

  private function read(string $p): string {
    $v = @file_get_contents($this->path($p));
    return $v === false ? '' : trim($v);
  }
}

}
