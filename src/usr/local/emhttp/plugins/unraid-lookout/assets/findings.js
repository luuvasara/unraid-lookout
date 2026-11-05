/* Lookout, Findings tab. Every value from the logs reaches the page as text (textContent). */
(function() {
    "use strict";

    var root = document.getElementById('lookout-app');
    if (!root || root.getAttribute('data-started')) return;
    root.setAttribute('data-started', '1');
    var currentFindings = [];
    var daemonIsRunning = false;
    var muted = [];
    var currentFilter = { severity: 'all', category: 'all', source: 'all', showAck: false };

    function el(id) { return document.getElementById(id); }

    // Findings are stored in UTC; the log lines beside them are in local time.
    function localTime(iso) {
        var d = new Date(iso);
        if (isNaN(d)) return String(iso).substring(0, 19);
        var p = function(n) { return (n < 10 ? '0' : '') + n; };
        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
    }

    function makeEl(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (text) e.textContent = text;
        return e;
    }

    var ajaxURL = '/plugins/unraid-lookout/include/ajax.php';

    function postAction(action, extra) {
        var data = {action: action};
        if (extra) { for (var k in extra) data[k] = extra[k]; }
        return Promise.resolve($.post(ajaxURL, data));
    }

    function populateFilterOptions() {
        var cats = {}, sources = {};
        currentFindings.forEach(function(f) { cats[f.category] = 1; sources[f.log_source] = 1; });

        var catSel = el('filter-category');
        while (catSel.options.length > 1) catSel.remove(1);
        Object.keys(cats).sort().forEach(function(c) {
            var o = document.createElement('option'); o.value = c; o.textContent = c; catSel.appendChild(o);
        });

        var srcSel = el('filter-source');
        while (srcSel.options.length > 1) srcSel.remove(1);
        Object.keys(sources).sort().forEach(function(s) {
            var o = document.createElement('option'); o.value = s; o.textContent = s; srcSel.appendChild(o);
        });
    }

    function renderFindings() {
        var tbody = el('findings-body');
        while (tbody.firstChild) tbody.removeChild(tbody.firstChild);

        var filtered = currentFindings.filter(function(f) {
            if (!currentFilter.showAck && f.acknowledged) return false;
            if (currentFilter.severity !== 'all' && f.severity !== currentFilter.severity) return false;
            if (currentFilter.category !== 'all' && f.category !== currentFilter.category) return false;
            if (currentFilter.source !== 'all' && f.log_source !== currentFilter.source) return false;
            return true;
        });
        filtered.sort(function(a, b) { return (b.last_seen || b.timestamp).localeCompare(a.last_seen || a.timestamp); });

        if (filtered.length === 0) {
            var tr = document.createElement('tr');
            var td = document.createElement('td');
            td.setAttribute('colspan', '5');
            td.className = 'lk-empty';
            td.textContent = 'No findings match the current filters.';
            tr.appendChild(td);
            tbody.appendChild(tr);
            updateCounts();
            return;
        }

        filtered.forEach(function(f) {
            var tr = document.createElement('tr');
            tr.className = 'lk-sev-' + f.severity + (f.acknowledged ? ' lk-ack' : '');

            var tdSev = document.createElement('td');
            var badge = makeEl('span', 'lk-sev-badge lk-sev-badge-' + f.severity, f.severity.toUpperCase());
            tdSev.appendChild(badge);
            tr.appendChild(tdSev);

            var tdTime = makeEl('td', 'lk-ts');
            var ts = localTime(f.last_seen || f.timestamp);
            tdTime.appendChild(document.createTextNode(ts));
            if (f.repeat_count > 1) {
                var rpt = makeEl('span', 'lk-repeat', 'x' + f.repeat_count);
                tdTime.appendChild(document.createTextNode(' '));
                tdTime.appendChild(rpt);
            }
            tr.appendChild(tdTime);

            var tdName = document.createElement('td');
            tdName.appendChild(makeEl('strong', null, f.name));
            if (f.description) tdName.appendChild(makeEl('div', 'lk-desc', f.description));
            if (f.recommendation) {
                var rec = makeEl('div', 'lk-rec');
                rec.appendChild(makeEl('span', 'lk-rec-label', 'What to do: '));
                rec.appendChild(document.createTextNode(f.recommendation));
                tdName.appendChild(rec);
            }
            var where = [f.category, f.setting_path, f.config_file].filter(Boolean).join(' · ');
            if (where) tdName.appendChild(makeEl('div', 'lk-setting', where));
            tr.appendChild(tdName);

            tr.appendChild(makeEl('td', null, f.log_source + (f.flood ? ' (flood)' : '')));

            var tdAct = document.createElement('td');
            if (!f.acknowledged) {
                var ackBtn = makeEl('button', 'lk-btn lk-btn-xs', 'Ack');
                ackBtn.addEventListener('click', (function(fid) {
                    return function() { acknowledgeOne(fid); };
                })(f.id));
                tdAct.appendChild(ackBtn);
                tdAct.appendChild(document.createTextNode(' '));
            }
            var logBtn = makeEl('button', 'lk-btn lk-btn-xs', 'Log');
            logBtn.title = 'Show log line';
            logBtn.addEventListener('click', (function(line, row) {
                return function() {
                    var next = row.nextSibling;
                    if (next && next.className === 'lk-logrow') { next.parentNode.removeChild(next); return; }
                    var r = document.createElement('tr');
                    r.className = 'lk-logrow';
                    var c = makeEl('td', null, line);
                    c.setAttribute('colspan', '5');
                    r.appendChild(c);
                    row.parentNode.insertBefore(r, row.nextSibling);
                };
            })(f.log_line, tr));
            tdAct.appendChild(logBtn);
            tdAct.appendChild(document.createTextNode(' '));
            var isMuted = muted.indexOf(f.rule_id) >= 0;
            var muteBtn = makeEl('button', 'lk-btn lk-btn-xs', isMuted ? 'Unmute' : 'Mute');
            muteBtn.title = 'Notifications for this rule. The finding is still listed.';
            muteBtn.addEventListener('click', (function(rule, off) {
                return function() { postAction('mute', off ? { rule: rule, off: 1 } : { rule: rule }).then(refreshFindings); };
            })(f.rule_id, isMuted));
            tdAct.appendChild(muteBtn);
            tr.appendChild(tdAct);

            tbody.appendChild(tr);
        });

        updateCounts();
    }

    function updateCounts() {
        var counts = { critical: 0, warning: 0, info: 0 };
        var unack = 0;
        currentFindings.forEach(function(f) {
            if (!f.acknowledged) { counts[f.severity] = (counts[f.severity] || 0) + 1; unack++; }
        });
        el('count-critical').textContent = counts.critical;
        el('count-warning').textContent = counts.warning;
        el('count-info').textContent = counts.info;
        el('count-total').textContent = unack;
    }

    function applyFilters() {
        currentFilter.severity = el('filter-severity').value;
        currentFilter.category = el('filter-category').value;
        currentFilter.source = el('filter-source').value;
        currentFilter.showAck = el('filter-show-ack').checked;
        renderFindings();
    }

    function refreshFindings() {
        postAction('getFindings').then(function(data) {
            currentFindings = data.findings || [];
            muted = data.muted || [];
            showStatus(data.status);
            populateFilterOptions();
            renderFindings();
        }).catch(function() {});
    }

    function acknowledgeOne(id) {
        postAction('acknowledge', { id: id }).then(function() {
            currentFindings.forEach(function(f) { if (f.id === id) f.acknowledged = true; });
            renderFindings();
        });
    }

    // Event listeners
    el('filter-severity').addEventListener('change', applyFilters);
    el('filter-category').addEventListener('change', applyFilters);
    el('filter-source').addEventListener('change', applyFilters);
    el('filter-show-ack').addEventListener('change', applyFilters);

    el('card-critical').addEventListener('click', function() { el('filter-severity').value = 'critical'; applyFilters(); });
    el('card-warning').addEventListener('click', function() { el('filter-severity').value = 'warning'; applyFilters(); });
    el('card-info').addEventListener('click', function() { el('filter-severity').value = 'info'; applyFilters(); });
    el('card-total').addEventListener('click', function() { el('filter-severity').value = 'all'; applyFilters(); });

    el('btn-ack-all').addEventListener('click', function() {
        if (!confirm('Acknowledge all findings?')) return;
        postAction('acknowledgeAll').then(function() {
            currentFindings.forEach(function(f) { f.acknowledged = true; });
            renderFindings();
        });
    });

    el('btn-clear').addEventListener('click', function() {
        if (!confirm('Clear ALL findings? This cannot be undone.')) return;
        postAction('clearFindings').then(function() { currentFindings = []; renderFindings(); });
    });

    function note(data, fallback) {
        el('lk-note').textContent = (data && (data.output || data.error)) || fallback;
    }

    function showStatus(s) {
        if (!s) return;
        daemonIsRunning = !!s.running;
        var d = el('lk-daemon');
        d.textContent = (s.running ? '\u25CF Running' : '\u25CB Stopped');
        d.className = 'lk-daemon-status ' + (s.running ? 'lk-running' : 'lk-stopped');
        var bad = (s.rules_bad || []).length;
        el('lk-rules').textContent = s.rules + ' rules loaded' + (bad ? ', ' + bad + ' could not be read' : '');
        el('btn-daemon').textContent = (s.running ? 'Stop' : 'Start') + ' Daemon';
    }

    el('btn-daemon').addEventListener('click', function() {
        postAction('daemonControl', { cmd: daemonIsRunning ? 'stop' : 'start' }).then(function(data) {
            note(data, data.ok ? 'Done.' : 'Failed.');
            refreshFindings();
        });
    });

    el('btn-reload').addEventListener('click', function() {
        postAction('daemonControl', { cmd: 'restart' }).then(function(data) {
            note(data, data.ok ? 'Rules reloaded.' : 'Failed.');
            refreshFindings();
        });
    });

    // Init
    refreshFindings();
    setInterval(refreshFindings, 10000);
})();
