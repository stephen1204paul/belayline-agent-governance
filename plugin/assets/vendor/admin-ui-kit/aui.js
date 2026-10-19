/*! Admin UI Kit aui.js - vanilla ES2018, no dependencies. AUI.init(root) is idempotent. */
(function (w, d) {
  'use strict';
  var FOC = 'a[href],button:not([disabled]),input:not([disabled]),select,textarea,[tabindex]:not([tabindex="-1"])';
  var $ = function (s, r) { return (r || d).querySelector(s); };
  var $$ = function (s, r) { return [].slice.call((r || d).querySelectorAll(s)); };
  var emit = function (e, n, x) { return e.dispatchEvent(new CustomEvent(n, { bubbles: true, cancelable: true, detail: x || {} })); };
  var tgt = function (e) { return $(e.getAttribute('data-aui-target')); };
  var formOf = function (e) { return e.closest('[data-aui-form]'); };

  function dirty(e) {
    var f = formOf(e);
    if (f && !f.classList.contains('is-dirty')) { f.classList.add('is-dirty'); emit(f, 'aui:dirty', { form: f }); }
  }
  /* A segmented control with data-name keeps a hidden input in sync so it posts with a plain form. */
  function syncSeg(g) {
    var n = g.getAttribute('data-name'), i = $('input[type=hidden][data-aui-seg]', g), p = $('[aria-pressed=true]', g);
    if (!n) return;
    if (!i) { i = d.createElement('input'); i.type = 'hidden'; i.name = n; i.setAttribute('data-aui-seg', ''); g.appendChild(i); i.defaultValue = p ? p.getAttribute('data-value') || '' : ''; }
    i.value = p ? p.getAttribute('data-value') || '' : '';
  }
  function snap(f) { f.__s = $$('[aria-pressed]', f).map(function (b) { return [b, b.getAttribute('aria-pressed')]; }); }
  function discard(f) {
    $$('input,textarea,select', f).forEach(function (i) {
      if (i.type === 'checkbox' || i.type === 'radio') i.checked = i.defaultChecked; else i.value = i.defaultValue;
    });
    (f.__s || []).forEach(function (p) { p[0].setAttribute('aria-pressed', p[1]); });
    $$('.aui-seg[data-name]', f).forEach(syncSeg);
    f.classList.remove('is-dirty'); count(f); emit(f, 'aui:discard', { form: f });
  }
  function count(e) {
    var b = e.closest('[data-aui-counted]'); if (!b) return;
    var n = $$('input[data-ability]:checked', b).length;
    $$('[data-aui-counter]', b).forEach(function (c) { c.textContent = n + ' / ' + b.getAttribute('data-aui-max'); });
  }
  function filter(b) {
    var s = $('[data-aui-filter] [aria-pressed=true]', b), k = s ? s.getAttribute('data-value') : 'all';
    var i = $('[data-aui-search]', b), q = i ? i.value.trim().toLowerCase() : '';
    $$('[data-kind]', b).forEach(function (r) {
      r.hidden = !((k === 'all' || r.getAttribute('data-kind') === k) && (!q || r.textContent.toLowerCase().indexOf(q) > -1));
    });
  }

  var from;
  function open(dl, f) { from = f || d.activeElement; dl.hidden = false; var x = $(FOC, dl); if (x) x.focus(); }
  function close(dl) { dl.hidden = true; emit(dl, 'aui:close'); if (from) from.focus(); }
  function trap(e, dl) {
    if (e.key === 'Escape') { e.preventDefault(); return close(dl); }
    if (e.key !== 'Tab') return;
    var f = $$(FOC, dl), a = f[0], z = f[f.length - 1];
    if (e.shiftKey && d.activeElement === a) { e.preventDefault(); z.focus(); }
    else if (!e.shiftKey && d.activeElement === z) { e.preventDefault(); a.focus(); }
  }
  /* Copy: icon-only buttons keep their icon. Only an optional [data-aui-copy-label] span changes text; the
     result is announced in a polite live region. The word comes from data-copied-label (default "Copied"). */
  function say(t) {
    var r = $('.aui-live', d.body);
    if (!r) { r = d.createElement('div'); r.className = 'aui-live aui-sr'; r.setAttribute('aria-live', 'polite'); r.setAttribute('role', 'status'); d.body.appendChild(r); }
    r.textContent = ''; setTimeout(function () { r.textContent = t; }, 30);
  }
  function copy(b) {
    var s = tgt(b), t = s ? (s.value || s.textContent) : '', l = $('[data-aui-copy-label]', b), o = l && l.textContent,
      msg = b.getAttribute('data-copied-label') || 'Copied';
    var ok = function () {
      if (l) { l.textContent = msg; setTimeout(function () { l.textContent = o; }, 1500); }
      b.classList.add('is-copied'); setTimeout(function () { b.classList.remove('is-copied'); }, 1500);
      say(msg); emit(b, 'aui:copy', { text: t });
    };
    if (w.navigator.clipboard) w.navigator.clipboard.writeText(t).then(ok, ok);
    else { s.select(); try { d.execCommand('copy'); } catch (e) {} ok(); }
  }
  function tab(t) {
    var l = t.closest('[role=tablist]');
    $$('[role=tab]', l).forEach(function (x) {
      var on = x === t, p = d.getElementById(x.getAttribute('aria-controls'));
      x.setAttribute('aria-selected', on); x.tabIndex = on ? 0 : -1; if (p) p.hidden = !on;
    });
    emit(l, 'aui:tab', { tab: t });
  }

  w.AUI = {
    version: '0.2.3',
    init: function (root) {
      root = root || d;
      $$('.aui-seg[data-name]', root).forEach(syncSeg);
      $$('[data-aui-form]', root).forEach(snap);
      $$('[data-aui-counted]', root).forEach(count);
      if (root.__aui) return;
      root.__aui = 1;
      root.addEventListener('click', function (e) {
        var t = e.target, b, f;
        if ((b = t.closest('[aria-expanded][aria-controls]')) && !b.hasAttribute('data-aui-manual')) {
          var o = b.getAttribute('aria-expanded') !== 'true', p = d.getElementById(b.getAttribute('aria-controls'));
          b.setAttribute('aria-expanded', o); if (p) p.hidden = !o; emit(b, 'aui:toggle', { open: o });
        } else if ((b = t.closest('[role=tab]'))) tab(b);
        else if ((b = t.closest('.aui-seg button'))) {
          if (b.disabled) return;
          $$('button', b.parentNode).forEach(function (x) { x.setAttribute('aria-pressed', x === b); });
          syncSeg(b.parentNode);
          if ((f = b.closest('[data-aui-filterbox]'))) filter(f);
          emit(b, 'aui:change', { value: b.getAttribute('data-value') || b.textContent.trim(), control: b.parentNode });
          if (b.parentNode.hasAttribute('data-name')) dirty(b); /* pure filters carry no form value */
        } else if ((b = t.closest('.aui-switch'))) {
          if (b.disabled) return;
          var on = b.getAttribute('aria-pressed') !== 'true', c = b.getAttribute('data-aui-class');
          b.setAttribute('aria-pressed', on);
          $$('.aui-switch-state', b).forEach(function (s) { s.textContent = s.getAttribute(on ? 'data-on' : 'data-off'); });
          if (c) tgt(b).classList.toggle(c, on);
          if (b.hasAttribute('data-aui-views')) b.getAttribute('data-aui-views').split(',').forEach(function (id, i) { d.getElementById(id).hidden = i ? !on : on; });
          emit(b, 'aui:change', { value: on, control: b });
          if (!b.hasAttribute('data-aui-ui')) dirty(b);
        } else if ((b = t.closest('[data-aui-copy]'))) copy(b);
        else if ((b = t.closest('[data-aui-open]'))) open(d.getElementById(b.getAttribute('data-aui-open')), b);
        else if ((b = t.closest('[data-aui-close]'))) close(b.closest('.aui-backdrop'));
        else if (t.classList.contains('aui-backdrop') && !t.classList.contains('aui-backdrop--static')) close(t);
        else if ((b = t.closest('[data-aui-discard]'))) discard(formOf(b));
        else if ((b = t.closest('[data-aui-save]'))) {
          f = formOf(b);
          if (emit(f, 'aui:save', { form: f })) { f.classList.remove('is-dirty'); snap(f); }
        } else if ((b = t.closest('[data-aui-action]'))) emit(b, 'aui:action', { action: b.getAttribute('data-aui-action'), item: b.closest('[data-aui-item]') });
      });
      root.addEventListener('change', function (e) {
        var t = e.target;
        if (t.hasAttribute('data-group')) $$('input[type=checkbox]', d.getElementById(t.getAttribute('data-group'))).forEach(function (c) { c.checked = t.checked; });
        if (formOf(t)) dirty(t);
        count(t);
      });
      root.addEventListener('input', function (e) {
        var t = e.target, b = t.closest('[data-aui-filterbox]');
        if (t.hasAttribute('data-aui-search') && b) filter(b); else if (formOf(t)) dirty(t);
      });
      root.addEventListener('keydown', function (e) {
        var t = e.target, dl = t.closest('.aui-backdrop:not(.aui-backdrop--static)');
        if (dl && !dl.hidden) return trap(e, dl);
        if (t.getAttribute('role') === 'tab' && /^(Arrow(Left|Right)|Home|End)$/.test(e.key)) {
          var a = $$('[role=tab]', t.closest('[role=tablist]')), i = a.indexOf(t), n = a.length;
          i = e.key === 'Home' ? 0 : e.key === 'End' ? n - 1 : (i + (e.key === 'ArrowRight' ? 1 : -1) + n) % n;
          e.preventDefault(); a[i].focus(); tab(a[i]);
        }
      });
    }
  };
})(window, document);
