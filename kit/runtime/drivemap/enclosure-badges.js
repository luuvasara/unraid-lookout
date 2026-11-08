/* Bay labels on Unraid's Main page ("HDD3" after a drive's name). Visual only: asks kit/enclosures.php (POST, read-only) which
 * bay each drive is in, then adds a label to the first cell of every table row whose cell ends in "(sdX)", the way Unraid
 * describes a device (nchan/device_list device_desc(): "<id> - <size> (sdX)"). The tables are redrawn every few seconds, so a
 * MutationObserver puts the labels back before the next paint. If the request fails or nothing matches, nothing is added.
 * When two plugins carry this script, the first one to load does the work. */
(function (window, document) {
  'use strict';
  if (window.encBadges || !window.MutationObserver || !window.fetch) return;
  window.encBadges = true;
  var me = document.currentScript, url = me && me.dataset.endpoint, map = null;
  if (!url) return;
  var RE = /\(([a-z]{2,4}[a-z0-9]{0,12})\)\s*$/;
  function apply() {
    if (!map) return;
    try {
      var cells = document.querySelectorAll('table tbody td');
      for (var i = 0; i < cells.length; i++) {
        var td = cells[i], tr = td.parentNode;
        if (!tr || tr.querySelector('.enc-bay-badge') || td.querySelector('select, input')) continue;
        var m = RE.exec(td.textContent || '');
        if (!m || !Object.prototype.hasOwnProperty.call(map, m[1])) continue;
        var b = map[m[1]];
        if (!b || typeof b.bay !== 'string') continue;
        var s = document.createElement('span');
        s.className = 'enc-bay-badge';
        s.textContent = b.bay;
        s.title = String(b.label || (b.unit + ', ' + b.bay));
        var host = tr.cells && tr.cells[0] && tr.cells[0] !== td ? tr.cells[0] : td;
        host.appendChild(s);
      }
    } catch (e) { /* the Main page changed shape: add nothing */ }
  }
  function own(m) {
    var n = m.addedNodes;
    if (!n || !n.length || m.removedNodes.length) return false;
    for (var i = 0; i < n.length; i++) if (!(n[i].classList && n[i].classList.contains('enc-bay-badge'))) return false;
    return true;
  }
  function load() {
    if (document.hidden) return;
    var body = new URLSearchParams({ action: 'bays' });
    if (typeof window.csrf_token !== 'undefined') body.append('csrf_token', window.csrf_token);
    fetch(url, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) {
      if (r && r.ok === true && r.bays && typeof r.bays === 'object') { map = r.bays; apply(); }
    }).catch(function () {});
  }
  function go() {
    load();
    setInterval(load, 60000);
    new MutationObserver(function (ms) { for (var i = 0; i < ms.length; i++) if (!own(ms[i])) { apply(); return; } })
      .observe(document.body, { childList: true, subtree: true });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', go); else go();
}(window, document));
