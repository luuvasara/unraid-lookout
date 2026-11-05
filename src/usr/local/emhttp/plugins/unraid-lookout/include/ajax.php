<?php
/* Page requests. Unraid's own POST check (csrf_token) runs before this file, on POST only, so GET is refused here. */
header('Content-Type: application/json');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo '{"ok":false,"error":"POST only"}'; exit; }
require_once __DIR__ . '/lookout.php';
$bin = getenv('LK_BIN') ?: lk_path('/usr/local/bin/lookout');
$rc = getenv('LK_RC') ?: lk_path('/etc/rc.d/rc.lookout');

function lk_exec(array $cmd): array {
  $p = @proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
  if (!is_resource($p)) return [false, ''];
  $out = stream_get_contents($pipes[1]);
  fclose($pipes[1]);
  return [proc_close($p) === 0, (string)$out];
}

$action = (string)($_POST['action'] ?? '');
switch ($action) {
  case 'getFindings':
    echo json_encode(['findings' => lk_read_json(lk_findings_file())['findings'] ?? [], 'status' => lk_status(), 'muted' => lk_config()['muted']], JSON_INVALID_UTF8_SUBSTITUTE);
    break;
  case 'acknowledge':
    $id = preg_replace('/[^a-f0-9]/', '', (string)($_POST['id'] ?? ''));
    lk_with_findings(function ($d) use ($id) { foreach ($d['findings'] as &$f) { if ($f['id'] === $id) $f['acknowledged'] = true; } unset($f); return $d; });
    echo '{"ok":true}';
    break;
  case 'acknowledgeAll':
    lk_with_findings(function ($d) { foreach ($d['findings'] as &$f) { $f['acknowledged'] = true; } unset($f); return $d; });
    echo '{"ok":true}';
    break;
  case 'clearFindings':
    lk_with_findings(function ($d) { $d['findings'] = []; return $d; });
    echo '{"ok":true}';
    break;
  case 'saveConfig':
    $in = json_decode((string)($_POST['config'] ?? ''), true);
    if (!is_array($in)) { echo '{"ok":false,"error":"Invalid settings"}'; break; }
    if (!lk_write_atomic(lk_flash('config.json'), json_encode(lk_clean_config(['muted' => lk_config()['muted']] + $in), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
      echo '{"ok":false,"error":"Cannot write the settings file"}';
      break;
    }
    [, $out] = lk_exec([$bin, 'reload']);
    echo json_encode(['ok' => true, 'output' => $out]);
    break;
  case 'mute':
    $id = (string)($_POST['rule'] ?? '');
    if (preg_match('/^[A-Za-z0-9._:-]{1,80}$/', $id) !== 1) { echo '{"ok":false,"error":"Invalid rule"}'; break; }
    $c = lk_config();
    $c['muted'] = !empty($_POST['off']) ? array_values(array_diff($c['muted'], [$id])) : array_values(array_unique(array_merge($c['muted'], [$id])));
    lk_write_atomic(lk_flash('config.json'), json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    lk_exec([$bin, 'reload']);
    echo json_encode(['ok' => true, 'muted' => $c['muted']]);
    break;
  case 'daemonControl':
    $cmd = (string)($_POST['cmd'] ?? '');
    if (!in_array($cmd, ['start', 'stop', 'restart'], true)) { echo '{"ok":false,"error":"Invalid command"}'; break; }
    [$ok, $out] = lk_exec([$rc, $cmd]);
    echo json_encode(['ok' => $ok, 'output' => $out]);
    break;
  default:
    echo '{"ok":false,"error":"Unknown action"}';
}
