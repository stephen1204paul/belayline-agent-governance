/* Belayline admin glue: starts the shared UI kit, sends map clicks to Activity, and keeps the
   "12 abilities - 8 run freely" summaries in step with the tier controls. No network, no globals. */
(function (w, d) {
  'use strict';
  var MAP = { '': 'none', read: 'free', write: 'approval', irreversible: 'always', blocked: 'blocked' };

  function summary(span) {
    var t, group = span.closest('.aui-group'), c = { free: 0, approval: 0, always: 0, blocked: 0, none: 0 }, n = 0, parts, k;
    try { t = JSON.parse(span.getAttribute('data-belayline-meta')); } catch (e) { return; }
    [].forEach.call(group.querySelectorAll('.aui-seg[data-name]'), function (g) {
      var p = g.querySelector('[aria-pressed=true]'), v = p ? p.getAttribute('data-value') || '' : '';
      c[MAP[v] || 'none']++; n++;
    });
    parts = [(n === 1 ? t.one : t.many).replace('%d', n)];
    ['free', 'approval', 'always', 'blocked', 'none'].forEach(function (key) {
      k = c[key];
      if (k > 0 || key === 'free' || key === 'approval' || key === 'always') parts.push(t[key].replace('%d', k));
    });
    span.textContent = parts.join(' · ');
  }

  function start() {
    if (w.AUI) w.AUI.init(d);

    var map = d.getElementById('belayline-map');
    if (map) {
      map.addEventListener('aui:node', function (e) {
        var node = map.querySelector('[data-node="' + e.detail.id + '"]');
        var key = node && node.getAttribute('data-filter-key');
        if (!key) return;
        var url = new URL(map.getAttribute('data-activity-url'), w.location.href);
        url.searchParams.set(key, node.getAttribute('data-filter-value'));
        w.location.href = url.toString();
      });
    }

    d.addEventListener('aui:change', function (e) {
      var group = e.target.closest && e.target.closest('.aui-group'), span = group && group.querySelector('[data-belayline-meta]');
      if (span) summary(span);
    });
  }

  if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', start); else start();
})(window, document);
