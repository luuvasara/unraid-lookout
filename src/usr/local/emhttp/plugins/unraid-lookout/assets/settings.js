/* Lookout, Settings tab. */
(function () {
  'use strict';
  var panel = document.getElementById('settings-panel');
  if (!panel || panel.getAttribute('data-started')) return;
  panel.setAttribute('data-started', '1');
  function el(id) { return document.getElementById(id); }
  el('btn-save-config').addEventListener('click', function () {
    var cfg = {
      monitor_syslog: el('cfg-monitor_syslog').checked,
      monitor_docker: el('cfg-monitor_docker').checked,
      monitor_libvirt: el('cfg-monitor_libvirt').checked,
      monitor_vm_logs: el('cfg-monitor_vm_logs').checked,
      severity_filter: el('cfg-severity_filter').value,
      max_findings: parseInt(el('cfg-max_findings').value, 10),
      dedup_window: parseInt(el('cfg-dedup_window').value, 10),
      notify: el('cfg-notify').value,
      notify_cooldown: parseInt(el('cfg-notify_cooldown').value, 10),
      read_previous: el('cfg-read_previous').checked,
      enabled: true
    };
    $.post('/plugins/unraid-lookout/include/ajax.php', { action: 'saveConfig', config: JSON.stringify(cfg) }, function (data) {
      // an answer that is not the endpoint's JSON (a login page after the session ended) is not a save
      if (!data || typeof data !== 'object') { el('lk-settings-note').textContent = 'Error: the settings were not saved: the answer was not the expected one (the session may have ended: reload the page).'; return; }
      el('lk-settings-note').textContent = data.ok ? 'Settings saved.' : 'Error: ' + (data.error || 'unknown');
    }).fail(function (xhr) {
      el('lk-settings-note').textContent = 'Error: the settings were not saved: ' + (xhr.status ? 'the server answered HTTP ' + xhr.status : 'no answer from the server') + '.';
    });
  });
})();
