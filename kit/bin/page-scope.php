<?php
/* page-scope.php FILE... - fail when a page sets one of the variables of Unraid's page layout.
 * Unraid evaluates every tab of a page inside its own layout (DefaultPageLayout/MainContentTabbed.php), in the same
 * scope: a page that sets one of the layout's variables breaks the page. ex. "foreach (... as $i)" turns the layout's
 * tab counter into an array, PHP stops with "Cannot increment array" and that tab and every tab after it stay blank.
 * Reading a layout variable (ex. $docroot, $task) is fine; setting one is not. Pages use prefixed names instead.
 * Prints "FILE: $name on line N, ..." for each file that sets one; exit 1 if any does. */
const LAYOUT = ['i', 'page', 'pages', 'path', 'tabId', 'tabbed', 'evalContent', 'evalFile', 'skipIndexIncrement',
  'defaultIcon', 'docroot', 'task', 'display', 'myPage', 'useTabCookie', 'var', 'title', 'pg', 'pgs', 'nchan',
  'nchan_run', 'script', 'start', 'stop', 'running', 'icon', 'name', 'row', 'output', 'content', 'opt', 'panelTitle',
  'rawTitle', 'isDeviceInfoSettingsTitle', 'matches'];

function page_scope_hits(string $src): array {
  $t = array_values(array_filter(token_get_all($src), function ($x) {
    return !is_array($x) || !in_array($x[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
  }));
  $is = function ($k, $id) use ($t) { return isset($t[$k]) && is_array($t[$k]) && $t[$k][0] === $id; };
  $hits = [];
  foreach ($t as $k => $x) {
    if (!is_array($x) || $x[0] !== T_VARIABLE || !in_array(substr($x[1], 1), LAYOUT, true)) continue;
    $loop = $is($k - 1, T_AS) || ($is($k - 1, T_DOUBLE_ARROW) && $is($k - 2, T_VARIABLE) && $is($k - 3, T_AS));
    $next = $t[$k + 1] ?? null;
    $set = $next === '=' || (is_array($next) && in_array($next[0], [T_PLUS_EQUAL, T_MINUS_EQUAL, T_CONCAT_EQUAL,
      T_MUL_EQUAL, T_COALESCE_EQUAL, T_INC, T_DEC], true));
    if ($loop || $set) $hits[] = "{$x[1]} on line {$x[2]}";
  }
  return $hits;
}

$fail = 0;
foreach (array_slice($argv, 1) as $f) {
  $s = (string)file_get_contents($f);
  $p = strpos($s, "\n---\n");
  if ($p !== false) $s = str_repeat("\n", substr_count(substr($s, 0, $p + 5), "\n")) . substr($s, $p + 5);   // line numbers as in the file
  if ($h = page_scope_hits($s)) { echo "$f: ", implode(', ', $h), "\n"; $fail = 1; }
}
exit($fail);
