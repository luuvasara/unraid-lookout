<?php
/* Enclosures endpoint (the Enclosures tab and the Main page bay labels). POST only, so Unraid's prologue
 * (local_prepend.php) checks csrf_token before this runs.
 *   action=view                          {"ok":true,"units":{switch:{bay:{device,slot,temp,state,icon,text}}}}       read-only, the live front view
 *   action=bays                          {"ok":true,"bays":{"sdk":{"bay":"HDD1","unit":"QNAP TL-D1600S 1",...}}}   read-only
 *   action=locate_status                 {"ok":true,"running":bool,"device":...,"left":s}                           read-only
 *   action=locate&dev=sdk[&spinup=yes]   read the drive for 20 s so its light flashes (read-only on the drive)
 *   action=locate_stop
 *   action=cabling&switch=...&order[]=   store the confirmed cabling of a unit in /boot/config/plugins/<plugin>/enclosures.json
 * The plugin is the directory above this one. */
require_once __DIR__ . '/EnclosureView.php';
require_once __DIR__ . '/Locate.php';

function enclosures_cabling_file(): string {
  $root = rtrim((string)(getenv('DRIVEMAP_ROOT') ?: ''), '/');
  return $root . '/boot/config/plugins/' . basename(dirname(__DIR__)) . '/enclosures.json';
}

function enclosures_answer(string $action, array $in): array {
  $root = (string)(getenv('DRIVEMAP_ROOT') ?: '');
  $loc = new DriveLocate(['root' => $root] + ($root !== '' ? ['state' => rtrim($root, '/') . '/var/run/drivemap/locate.json'] : []));
  switch ($action) {
    case 'view':
      return ['ok' => true, 'units' => (object)enclosure_live(enclosure_view(['cabling_file' => enclosures_cabling_file()]))];
    case 'bays':
      return ['ok' => true, 'bays' => (object)enclosure_view(['cabling_file' => enclosures_cabling_file()])['map']];
    case 'locate_status':
      return ['ok' => true] + $loc->status();
    case 'locate':
      $dev = (string)($in['dev'] ?? '');
      $st = DriveState::all(['root' => $root]);
      return $loc->start($dev, (bool)($st[$dev]['standby'] ?? false), ($in['spinup'] ?? '') === 'yes');
    case 'locate_stop':
      return $loc->stop();
    case 'cabling':
      $order = $in['order'] ?? null;
      if (!is_array($order)) return ['ok' => false, 'error' => 'No cabling given.'];
      $order = array_map(function ($v) { return (string)$v; }, array_values($order));
      foreach ($order as $v) if (!preg_match('/^[0-9]{1,2}$/', $v)) return ['ok' => false, 'error' => 'Each controller needs a different group of bays.'];
      $sw = (string)($in['switch'] ?? '');
      if (!preg_match('/^[0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-7]$/', $sw)) return ['ok' => false, 'error' => 'No such enclosure on this server.'];
      return (new DriveEnclosures(['root' => $root]))->saveCabling(enclosures_cabling_file(), $sw, $order);
  }
  return ['ok' => false, 'error' => 'Unknown action'];
}

if (PHP_SAPI !== 'cli') {
  header('Content-Type: application/json');
  header('Cache-Control: no-store');
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); header('Allow: POST'); echo '{"ok":false,"error":"POST only"}'; exit; }
  try {
    echo json_encode(enclosures_answer((string)($_POST['action'] ?? ''), $_POST), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
  } catch (Throwable $e) {
    http_response_code(500);
    echo '{"ok":false,"error":"The enclosure view failed."}';
  }
}
