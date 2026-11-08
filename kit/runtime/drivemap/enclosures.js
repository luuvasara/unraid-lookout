/* Enclosures tab: Locate (one drive at a time, cancellable) and the cabling form. Answers come from kit/enclosures.php
 * (POST; Unraid's pages define csrf_token and the prologue checks it) and are drawn with textContent only. */
(function () {
  'use strict';
  document.querySelectorAll('.enc[data-endpoint]').forEach(function (root) {
    if (root.dataset.encReady) return;
    root.dataset.encReady = '1';
    var url = root.dataset.endpoint;
    function post(data) {
      var b = new URLSearchParams();
      Object.keys(data).forEach(function (k) { [].concat(data[k]).forEach(function (v) { b.append(k, v); }); });
      if (typeof csrf_token !== 'undefined') b.append('csrf_token', csrf_token);
      return fetch(url, { method: 'POST', body: b, credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }
    function say(el, text, bad) { if (!el) return; el.textContent = text; el.classList.toggle('enc-bad', !!bad); }
    function ask(text, yes) {
      if (typeof swal === 'function') swal({ title: 'Spin up?', text: text, type: 'warning', showCancelButton: true, confirmButtonText: 'Spin up and locate', cancelButtonText: 'Cancel' }, function (ok) { if (ok) yes(); });
      else if (window.confirm(text)) yes();
    }
    var out = root.querySelectorAll('[data-enc="locout"]');
    function sayAll(t, bad) { out.forEach(function (o) { say(o, t, bad); }); }
    var timer = null;
    function locState(st) {
      root.querySelectorAll('.enc-loc').forEach(function (b) {
        var dev = b.closest('tr').dataset.encDev;
        if (st.running && st.device === dev) { b.value = 'Stop'; b.disabled = false; b.dataset.stop = '1'; }
        else { b.value = 'Locate'; b.disabled = !!st.running; delete b.dataset.stop; }
      });
      root.querySelectorAll('.enc-bay').forEach(function (t) { t.classList.toggle('enc-locating', !!st.running && t.dataset.encDev === st.device); });
      if (st.running) {
        sayAll('Reading ' + st.device + ' (' + st.left + ' s left): its activity light flashes now. Only reads, nothing is written.');
        clearTimeout(timer); timer = setTimeout(poll, 2000);
      } else if (timer) { timer = null; sayAll(''); }
    }
    function poll() { post({ action: 'locate_status' }).then(locState).catch(function () {}); }
    function locate(dev, spinup) {
      sayAll('Starting...');
      post({ action: 'locate', dev: dev, spinup: spinup ? 'yes' : '' }).then(function (j) {
        if (j.confirm) { sayAll(''); ask(j.error + ' Go on?', function () { locate(dev, true); }); return; }
        if (!j.ok) { sayAll(j.error || 'Locate failed.', true); return; }
        poll();
      }).catch(function (e) { sayAll('No answer from the server: ' + e.message, true); });
    }
    root.querySelectorAll('.enc-loc').forEach(function (b) {
      b.addEventListener('click', function () {
        if (b.dataset.stop) { b.disabled = true; post({ action: 'locate_stop' }).then(function () { locState({ running: false }); }); return; }
        locate(b.closest('tr').dataset.encDev, false);
      });
    });
    root.querySelectorAll('[data-enc="cabling"]').forEach(function (f) {
      f.addEventListener('submit', function (e) {
        e.preventDefault();
        var o = f.querySelector('[data-enc="cabout"]');
        var order = [].map.call(f.querySelectorAll('select'), function (s) { return s.value; });
        if (new Set(order).size !== order.length) { say(o, 'Each controller needs a different group of bays.', true); return; }
        say(o, 'Saving...');
        post({ action: 'cabling', switch: f.dataset.switch, 'order[]': order }).then(function (j) {
          if (!j.ok) { say(o, j.error || 'Not saved.', true); return; }
          say(o, 'Saved. Reloading...'); window.location.reload();
        }).catch(function (err) { say(o, 'No answer from the server: ' + err.message, true); });
      });
    });
    if (root.querySelector('.enc-loc')) poll();

    /* the live front view: every 10 s while the page is visible, the trays are redrawn from action=view */
    var STATES = ['active', 'new', 'standby', 'invalid', 'disabled', 'missing', 'unknown', 'empty'];
    function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e; }
    function tray(li, b) {
      var state = STATES.indexOf(b.state) >= 0 ? b.state : 'unknown';
      STATES.forEach(function (s) { li.classList.remove('enc-' + s); });
      li.classList.add('enc-' + state);
      li.title = b.text; li.setAttribute('aria-label', b.text); li.dataset.encDev = b.device;
      var name = li.querySelector('.enc-bay-name'), keep = name ? name.textContent : li.dataset.encBay;
      while (li.firstChild) li.removeChild(li.firstChild);
      li.appendChild(el('span', 'enc-bay-name', keep));
      if (!b.device) { li.appendChild(el('span', 'enc-bay-empty', 'empty')); return; }
      var dev = el('span', 'enc-bay-dev'), i = el('i', 'fa ' + String(b.icon || '').replace(/[^a-z0-9 -]/g, ''));
      i.setAttribute('aria-hidden', 'true'); dev.appendChild(i); dev.appendChild(document.createTextNode(' ' + b.device));
      li.appendChild(dev); li.appendChild(el('span', 'enc-bay-slot', b.slot || '-')); li.appendChild(el('span', 'enc-bay-temp', b.temp));
    }
    var fronts = root.querySelectorAll('.enc-front[data-enc-unit]');
    function live() {
      if (document.hidden || !fronts.length) return;
      post({ action: 'view' }).then(function (j) {
        if (!j || !j.ok || !j.units) return;
        fronts.forEach(function (f) {
          var u = j.units[f.dataset.encUnit]; if (!u) return;
          f.querySelectorAll('.enc-bay[data-enc-bay]').forEach(function (li) { var b = u[li.dataset.encBay]; if (b) tray(li, b); });
        });
        root.dispatchEvent(new CustomEvent('enc:view', { detail: j }));
      }).catch(function () {});
    }
    if (fronts.length) setInterval(live, 10000);
  });
}());
