<?php
/* EnclosureView: the data of the Enclosures tab, joined from the kit's libraries. Read-only.
 *
 *   $v = enclosure_view(['cabling_file' => '/boot/config/plugins/<name>/enclosures.json']);   // + 'root', 'dir' for tests
 *   $v['units'][0] = unit from DriveEnclosures::units() + 'bays' => [bay row, ...] (front order) + 'rows' => front grid of bay
 *                    rows + 'links' => DriveEnclosures::links()
 *   bay row: ['bay' => 'HDD3', 'kind' => '3.5in' | '', 'device' => 'sdk' | '', 'slot' => 'Disk 3' | '', 'slot_id' => 'disk3', 'model', 'size' (bytes),
 *             'temp' => 36 | null, 'standby' => bool, 'state' => 'active' | 'standby' | ... | 'empty', 'state_text', 'smart' => [...],
 *             'label' => DriveEnclosures label]
 *   $v['candidates'], $v['map'] (kernel name => ['bay', 'unit', 'label'] for the Main page labels)
 * PHP 7.4 and newer. */
require_once __DIR__ . '/DriveMap.php';
require_once __DIR__ . '/Enclosure.php';
require_once __DIR__ . '/DriveState.php';

if (!function_exists('enclosure_view')) {

function enclosure_view(array $opt = []): array {
  $root = (string)($opt['root'] ?? (getenv('DRIVEMAP_ROOT') ?: ''));
  $map = $opt['map'] ?? ($root === '' ? DriveMap::shared() : new DriveMap(['root' => $root, 'cache' => null]));
  $file = (string)($opt['cabling_file'] ?? '');
  $enc = new DriveEnclosures(['root' => $root, 'cabling' => $file !== '' ? enclosure_cabling($file) : []] + (isset($opt['dir']) ? ['dir' => $opt['dir']] : []));
  $drives = $map->drives();
  $byKey = [];
  foreach ($drives as $d) $byKey[$d['key']] = $d;
  $places = $enc->place($drives);
  $state = DriveState::all(['root' => $root]);
  $units = [];
  $short = [];
  foreach ($enc->units($places) as $u) {
    $kinds = $u['kinds'];
    $kindOf = function (string $bay) use ($kinds): string { foreach ($kinds as $pre => $k) if (strpos($bay, (string)$pre) === 0) return (string)$k; return ''; };
    $u['rows'] = [];
    $u['bays'] = [];
    foreach ($u['front'] as $row) {
      $r = [];
      foreach ($row as $c) {
        $d = $c['key'] !== null ? ($byKey[$c['key']] ?? null) : null;
        $s = $d ? ($state[$d['device']] ?? null) : null;
        $b = ['bay' => $c['bay'], 'kind' => $kindOf($c['bay']), 'device' => $d['device'] ?? '', 'slot' => enclosure_slot_label((string)($s['slot'] ?? ($d['slot'] ?? ''))), 'slot_id' => (string)($s['slot'] ?? ($d['slot'] ?? '')),
              'model' => $d['model'] ?? '', 'size' => (int)($d['size'] ?? 0), 'temp' => $s['temp'] ?? null, 'standby' => (bool)($s['standby'] ?? false),
              'state' => $d ? ($s['state'] ?? 'unknown') : 'empty', 'state_text' => $d ? ($s['state_text'] ?? 'Unraid lists no state for this drive') : 'No drive in this bay',
              'smart' => $s['smart'] ?? ['state' => 'unknown', 'text' => $d ? 'unknown' : '', 'title' => ''], 'label' => $d ? ($places[$d['key']]['label'] ?? '') : ''];
        $r[] = $b; $u['bays'][] = $b;
        if ($d) $short[$d['device']] = ['bay' => $c['bay'], 'unit' => $u['name'] . ' ' . $u['unit'], 'label' => $b['label'], 'confirmed' => $u['confirmed']];
      }
      $u['rows'][] = $r;
    }
    $u['unplaced_rows'] = array_values(array_filter(array_map(function ($k) use ($byKey, $places) {
      return isset($byKey[$k]) ? ['device' => $byKey[$k]['device'], 'model' => $byKey[$k]['model'], 'label' => $places[$k]['label'] ?? ''] : null;
    }, $u['unplaced'])));
    $u['links'] = array_map(function ($l) use ($u) {
      $g = $l['controller'] !== null && is_array($u['cabling']) ? array_values((array)($u['groups'][(string)($u['cabling'][$l['controller'] - 1] ?? '')] ?? [])) : [];
      return $l + ['name' => $l['what'] === 'card' ? 'Card to slot' : "Controller {$l['controller']}" . ($g ? " ({$g[0]}-" . end($g) . ')' : '')];
    }, $enc->links($u));
    $units[] = $u;
  }
  return ['units' => $units, 'candidates' => $enc->candidates(), 'map' => $short, 'profiles' => $enc->profiles()];
}

define('ENCLOSURE_ICON', ['active' => 'fa-circle green-text', 'new' => 'fa-square', 'standby' => 'fa-circle grey-text', 'invalid' => 'fa-warning orange-text',
  'disabled' => 'fa-times red-text', 'missing' => 'fa-times red-text', 'unknown' => 'fa-question-circle', 'empty' => '']);

/* The hardware facts of a unit's card, all from sysfs and read-only: per controller its driver, PCIe error counters (AER),
 * whether it offers AHCI enclosure management (CAP bit 6, EMS: LED messages over SGPIO) or port multipliers (CAP bit 17),
 * and its SATA ports that have a link or a drive. $root: a captured tree or ''. */
function enclosure_hw(array $u, array $bays, string $root = ''): array {
  $r = rtrim($root, '/');
  $rd = function ($p) use ($r) { $v = @file_get_contents($r . $p); return $v === false ? '' : trim($v); };
  $aer = function ($a) use ($rd) {
    $o = [];
    foreach (['correctable', 'nonfatal', 'fatal'] as $k) { $t = $rd("/sys/bus/pci/devices/$a/aer_dev_$k"); $o[$k] = preg_match('/^TOTAL_\S+\s+(\d+)/m', $t, $m) ? (int)$m[1] : null; }
    return $o;
  };
  $byDev = [];
  foreach ($bays as $b) if ($b['device'] !== '') $byDev[$b['device']] = $b['bay'];
  $ports = [];
  foreach (glob("$r/sys/class/ata_port/ata*") ?: [] as $ap) {
    $n = substr(basename($ap), 3);
    $real = (string)@realpath("$ap/device");
    if ($real === '') foreach ($u['controllers'] as $c) foreach (glob("$r/sys/devices/pci*/*/*/*/$c/ata$n") ?: [] as $x) $real = $x;   // a captured tree without the link
    foreach ($u['controllers'] as $c) if (strpos($real . '/', "/$c/") !== false) $ports[$c][] = $n;
  }
  $devOfAta = [];
  foreach (glob("$r/sys/block/*") ?: [] as $bl) if (preg_match('#/ata(\d+)/#', (string)@realpath($bl), $m)) $devOfAta[$m[1]] = basename($bl);
  $ctl = [];
  foreach ($u['controllers'] as $i => $c) {
    $caps = '';
    $list = [];
    foreach ($ports[$c] ?? [] as $n) {
      if ($caps === '') $caps = $rd("/sys/class/scsi_host/host$n/ahci_host_caps");
      $spd = $rd("/sys/class/ata_link/link$n/sata_spd");
      $dev = $devOfAta[$n] ?? '';
      if ($dev === '' && ($spd === '' || $spd === '<unknown>')) continue;
      $list[] = ['ata' => "ata$n", 'port_no' => (int)$rd("/sys/class/ata_port/ata$n/port_no"), 'speed' => $spd, 'limit' => $rd("/sys/class/ata_link/link$n/hw_sata_spd_limit"),
                 'device' => $dev, 'bay' => $byDev[$dev] ?? '', 'rev' => $dev !== '' ? $rd("/sys/block/$dev/device/rev") : ''];
    }
    usort($list, function ($a, $b) { return $a['port_no'] <=> $b['port_no']; });
    $capv = ctype_xdigit($caps) ? hexdec($caps) : null;
    $drv = @readlink("$r/sys/bus/pci/devices/$c/driver");
    $ctl[] = ['n' => $i + 1, 'address' => $c, 'driver' => $drv ? basename($drv) : '', 'aer' => $aer($c), 'caps' => $caps,
              'ems' => $capv === null ? null : (bool)($capv & 0x40), 'pmp' => $capv === null ? null : (bool)($capv & 0x20000), 'ports' => $list];
  }
  return ['controllers' => $ctl, 'switch_aer' => $aer($u['switch']), 'ses' => (bool)glob("$r/sys/class/enclosure/*")];
}

/* "36 °C", "standby", "no reading", '' for an empty bay */
function enclosure_temp_text(array $b): string {
  return $b['temp'] !== null ? $b['temp'] . ' °C' : ($b['standby'] ? 'standby' : ($b['device'] !== '' ? 'no reading' : ''));
}

/* the full text of a tray, for its title and for screen readers */
function enclosure_bay_text(array $b): string {
  if ($b['device'] === '') return "{$b['bay']}: empty";
  return "{$b['bay']}: {$b['device']}" . ($b['slot'] !== '' ? ", {$b['slot']}" : '') . ', ' . enclosure_temp_text($b) . ", {$b['state_text']}"
    . ($b['smart']['text'] !== '' ? ", SMART {$b['smart']['text']}" : '');
}

/* the front view of one unit: the profile's rows of bays, each tray with its drive. Updated live by kit/enclosures.js
 * (data-enc-unit, data-enc-bay) from the endpoint's action=view. */
function enclosure_front_html(array $u): string {
  $h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
  $uid = $h("{$u['profile']}-{$u['unit']}");
  $kinds = array_unique(array_filter(array_column($u['bays'], 'kind')));
  $cap = 'Front view, as the bays are labelled on the unit' . (count($kinds) > 1 ? ' (' . implode(', ', array_map(function ($p, $k) { return "$p bays $k"; }, array_keys($u['kinds']), array_values($u['kinds']))) . ')' : '');
  $o = "<figure class=\"enc-front\" aria-labelledby=\"enc-cap-$uid\" data-enc-unit=\"" . $h($u['switch']) . "\">\n<figcaption id=\"enc-cap-$uid\" class=\"enc-cap\">" . $h($cap) . "</figcaption>\n<div class=\"enc-chassis\">\n";
  foreach ($u['rows'] as $row) {
    $o .= '<ul class="enc-row" style="--enc-cols: ' . count($row) . "\">\n";
    foreach ($row as $b) {
      $t = $h(enclosure_bay_text($b));
      $o .= '<li class="enc-bay enc-' . $h($b['state']) . '" tabindex="0" title="' . $t . '" aria-label="' . $t . '" data-enc-bay="' . $h($b['bay']) . '" data-enc-dev="' . $h($b['device']) . "\">"
          . '<span class="enc-bay-name">' . $h($b['bay']) . '</span>';
      if ($b['device'] !== '') $o .= '<span class="enc-bay-dev"><i class="fa ' . (ENCLOSURE_ICON[$b['state']] ?? '') . '" aria-hidden="true"></i> ' . $h($b['device']) . '</span>'
          . '<span class="enc-bay-slot">' . $h($b['slot'] !== '' ? $b['slot'] : '-') . '</span><span class="enc-bay-temp">' . $h(enclosure_temp_text($b)) . '</span>';
      else $o .= '<span class="enc-bay-empty">empty</span>';
      $o .= "</li>\n";
    }
    $o .= "</ul>\n";
  }
  return $o . "</div>\n</figure>\n";
}

/* what the live refresh needs: [switch => [bay => [device, slot, temp, state, icon, text]]] */
function enclosure_live(array $view): array {
  $out = [];
  foreach ($view['units'] as $u) foreach ($u['bays'] as $b)
    $out[$u['switch']][$b['bay']] = ['device' => $b['device'], 'slot' => $b['slot'], 'temp' => enclosure_temp_text($b), 'state' => $b['state'],
      'icon' => ENCLOSURE_ICON[$b['state']] ?? '', 'text' => enclosure_bay_text($b), 'smart' => $b['smart']['state'], 'standby' => $b['standby']];
  return $out;
}

/* The confirmed cabling of every unit: what any plugin on this server stored (/boot/config/plugins/*\/enclosures.json, the
 * same format), the plugin's own file last so that it wins. One confirmation serves every plugin that shows the enclosure. */
function enclosure_cabling(string $own): array {
  $all = [];
  foreach (glob(dirname($own, 2) . '/*/enclosures.json') ?: [] as $f) if ($f !== $own) $all = DriveEnclosures::readCabling($f) + $all;
  return DriveEnclosures::readCabling($own) + $all;
}

/* a slot as Unraid's Main page names it: Parity, Parity 2, Disk 3, Dev 7; a pool slot keeps its name (cache, cache2) */
function enclosure_slot_label(string $slot): string {
  if ($slot === 'parity') return 'Parity';
  if (preg_match('/^parity(\d+)$/', $slot, $m)) return "Parity {$m[1]}";
  if (preg_match('/^(disk|dev)(\d+)$/', $slot, $m)) return ucfirst($m[1]) . " {$m[2]}";
  return $slot;
}

/* 4 TB, 480 GB */
function enclosure_size(int $b): string {
  if ($b <= 0) return '';
  foreach (['TB' => 1e12, 'GB' => 1e9, 'MB' => 1e6] as $u => $f) if ($b >= $f) return rtrim(rtrim(number_format($b / $f, 1, '.', ''), '0'), '.') . " $u";
  return "$b B";
}

/* "8 GT/s x8" */
function enclosure_link_text(?float $speed, ?int $width): string {
  if ($speed === null && $width === null) return 'unknown';
  return trim(($speed !== null ? rtrim(rtrim(number_format($speed, 1, '.', ''), '0'), '.') . ' GT/s' : '') . ($width !== null ? " x$width" : ''));
}

}
