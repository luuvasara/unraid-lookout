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
  failedRequests();
}, 200);

// A request that fails, or is answered with something else than the endpoint's JSON (a login page after the session
// ended), must show what failed and why, and must not run the caller's success step.
function failedRequests() {
  const dom2 = new JSDOM('<!doctype html><body>' + page + '</body>', { runScripts: 'outside-only', url: 'http://tower.local/Settings/Lookout' });
  const w2 = dom2.window, d = w2.document;
  let mode = 'ok';
  const plain = { id: 'abc123', rule_id: 'rule-a', severity: 'warning', timestamp: '2026-11-12T00:00:00+00:00', name: 'Example finding', log_source: 'syslog', log_line: 'example line', acknowledged: false };
  w2.$ = { post: (url, data) => {
    if (data.action === 'getFindings') return Promise.resolve({ findings: [plain], muted: [], status: { running: true, rules: 1 } });
    if (mode === 'http500') return Promise.reject({ status: 500 });
    if (mode === 'noanswer') return Promise.reject({ status: 0 });
    if (mode === 'login') return Promise.resolve('<html><body>login</body></html>');
    return Promise.resolve({ ok: true });
  } };
  w2.setInterval = () => 0; w2.confirm = () => true;
  w2.eval(fs.readFileSync(path.join(P, 'assets/findings.js'), 'utf8'));
  const note = () => d.getElementById('lk-note').textContent;
  const rows = () => d.querySelectorAll('#findings-body tr:not(.lk-logrow)').length;
  const ackBtn = () => [...d.querySelectorAll('#findings-body button')].find(b => b.textContent === 'Ack');
  const step = (ms, f) => new Promise(r => setTimeout(() => { f(); r(); }, ms));
  step(50, () => { mode = 'http500'; ackBtn().click(); })
    .then(() => step(50, () => {
      t('failed Ack: the note says what failed and why', note() === 'Acknowledge failed: the server answered HTTP 500.', note());
      t('failed Ack: the finding is not marked acknowledged', !!ackBtn() && d.getElementById('count-total').textContent === '1');
      mode = 'noanswer'; d.getElementById('btn-reload').click();
    }))
    .then(() => step(50, () => {
      t('failed Reload Rules: neither "Rules reloaded." nor a bare "Failed."', note() === 'Reload rules failed: no answer from the server.', note());
      mode = 'login'; d.getElementById('btn-clear').click();
    }))
    .then(() => step(50, () => {
      t('a login page as the answer is a failure: the list is kept', rows() === 1 && /^Clear findings failed: the answer was not the expected one \(the session may have ended/.test(note()), rows() + ' ' + note());
      mode = 'ok'; d.getElementById('btn-daemon').click();
    }))
    .then(() => step(50, () => {
      t('a request that works still shows its result', note() === 'Done.', note());
      ackBtn().click();
    }))
    .then(() => step(50, () => {
      t('Ack that works still marks the finding', !ackBtn() && d.getElementById('count-total').textContent === '0');
      process.exit(fail);
    }));
}
