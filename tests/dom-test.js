// The Findings tab with hostile findings, in jsdom: every value from the logs and the rules must reach the page as text.
// Needs jsdom (NODE_PATH); tests/run-tests.sh skips it when jsdom is missing.
'use strict';
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const P = path.join(__dirname, '..', 'src/usr/local/emhttp/plugins/unraid-lookout');
let fail = 0;
const t = (name, ok, why) => { console.log((ok ? 'ok   ' : 'FAIL ') + name + (ok ? '' : ' ' + (why || ''))); if (!ok) fail = 1; };

// the page's markup without its header and PHP
const page = fs.readFileSync(path.join(P, 'LookoutFindings.page'), 'utf8').split('\n---\n')[1]
  .replace(/<\?php[\s\S]*?\?>/g, '').replace(/<\?=[\s\S]*?\?>/g, '').replace(/<script[\s\S]*?<\/script>/g, '').replace(/<link[^>]*>/g, '');
const X = '<img src=x onerror="window.__pwned=1">';
const Q = '"\'><script>window.__pwned=2</script>';
const finding = {
  id: 'abc123', rule_id: 'r' + Q, severity: 'critical', timestamp: '2026-11-12T00:00:00+00:00', last_seen: '2026-11-12T00:00:00+00:00',
  name: 'Name ' + X, description: 'Desc ' + Q, recommendation: 'Rec ' + X, category: 'cat' + X, setting_path: 'Settings ' + Q,
  config_file: '/boot/' + X, log_source: 'vm:' + X, log_line: 'kernel: ' + Q + ' \u001b[31m' + X, repeat_count: 3, acknowledged: false, flood: true,
  drives: [{ name: 'ata7' + X, label: 'cache ' + Q }],
};
const dom = new JSDOM('<!doctype html><body>' + page + '</body>', { runScripts: 'outside-only', url: 'http://tower.local/Settings/Lookout' });
const w = dom.window;
w.$ = { post: (url, data) => Promise.resolve(data.action === 'getFindings'
  ? { findings: [finding], muted: [], status: { running: true, rules: 1, rules_bad: ['b' + X] } } : { ok: true, output: 'out ' + X }) };
w.setInterval = () => 0;
w.eval(fs.readFileSync(path.join(P, 'assets/findings.js'), 'utf8'));
setTimeout(() => {
  const d = w.document;
  const row = d.querySelector('#findings-body tr');
  t('the hostile finding is listed', !!row && row.textContent.includes('Name <img'), row && row.textContent);
  [...d.querySelectorAll('#findings-body button')].filter(b => b.textContent === 'Log').forEach(b => b.click());
  t('its log line opens as text', !!d.querySelector('.lk-logrow') && d.querySelector('.lk-logrow').textContent.includes('<script>window.__pwned=2</script>'));
  t('no element came from the data (img, script, iframe, a, svg)', d.querySelectorAll('#lookout-app img, #lookout-app script, #lookout-app iframe, #lookout-app a, #lookout-app svg').length === 0,
    d.getElementById('lookout-app').innerHTML.slice(0, 300));
  t('no attribute holds an event handler', ![...d.querySelectorAll('#lookout-app *')].some(e => [...e.attributes].some(a => /^on/i.test(a.name))));
  t('nothing ran', w.__pwned === undefined, String(w.__pwned));
  const opts = [...d.querySelectorAll('#filter-source option, #filter-category option')].map(o => o.textContent);
  t('filter options carry the text as text', opts.includes('vm:' + X) && opts.includes('cat' + X), JSON.stringify(opts));
  process.exit(fail);
}, 200);
