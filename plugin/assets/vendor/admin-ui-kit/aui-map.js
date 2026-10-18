/*! Admin UI Kit aui-map.js - the Belayline overview map. Load after aui.js, on the Overview screen only.
   Data contract (data-paths on .aui-map): "who,through,pack,outcome,count;..." - one tuple per distinct path,
   ids are the data-node values of the four lanes in lane order. At rest: one quiet bus per lane gap.
   On hover/focus of a node: only the bus segments and stems joining the involved nodes light up (accent, 2px),
   counts show the subset, zero nodes dim. Optional data-labels="Lane|Box|Calls|Totals by box|Every path" (translated) and data-period (default "today"). */
(function (w, d) {
  'use strict';
  var NS = 'http://www.w3.org/2000/svg';
  var $ = function (s, r) { return (r || d).querySelector(s); };
  var $$ = function (s, r) { return [].slice.call((r || d).querySelectorAll(s)); };
  var mk = function (n, a, p) { var e = d.createElementNS(NS, n); for (var k in a) e.setAttribute(k, a[k]); p.appendChild(e); return e; };

  function map(m) {
    var svg = $('.aui-map-svg', m), N = {}, lanes = $$('.aui-lane', m), cur = null;
    $$('[data-node]', m).forEach(function (n) { N[n.getAttribute('data-node')] = n; });
    var T = m.getAttribute('data-paths').split(';').map(function (s) { var p = s.trim().split(','); p[4] = +p[4]; return p; });
    var nm = function (id) { return $('b', N[id]).textContent; };
    var ln = function (i) { return $('.aui-lane-t', lanes[i]).textContent; };
    var sum = function (sub) { var c = {}; sub.forEach(function (t) { for (var i = 0; i < 4; i++) c[t[i]] = (c[t[i]] || 0) + t[4]; }); return c; };
    var total = sum(T);
    function counts(c) {
      for (var k in N) {
        var v = c[k] || 0; $('.aui-num', N[k]).textContent = v;
        N[k].classList.toggle('is-dim', c !== total && !v);
      }
    }
    function geo() {
      var r = m.getBoundingClientRect(), P = {}, k;
      for (k in N) { var b = N[k].getBoundingClientRect(); P[k] = { l: b.left - r.left, w: b.width, t: b.top - r.top, b: b.bottom - r.top }; }
      return { r: r, P: P };
    }
    var laneIds = function (i) { return $$('[data-node]', lanes[i]).map(function (n) { return n.getAttribute('data-node'); }); };
    function gap(P, i) {
      var A = laneIds(i), B = laneIds(i + 1);
      return { A: A, B: B, y0: Math.max.apply(null, A.map(function (k) { return P[k].b; })), y1: Math.min.apply(null, B.map(function (k) { return P[k].t; })) };
    }
    function draw() {
      var g = geo(), P = g.P, i;
      svg.setAttribute('viewBox', '0 0 ' + g.r.width + ' ' + g.r.height); svg.textContent = '';
      var bus = mk('g', { 'class': 'aui-bus' }, svg);
      for (i = 0; i < lanes.length - 1; i++) {
        var G = gap(P, i), ym = (G.y0 + G.y1) / 2, xs = [];
        G.A.forEach(function (k) { var x = P[k].l + P[k].w / 2; xs.push(x); mk('line', { x1: x, y1: P[k].b, x2: x, y2: ym }, bus); mk('circle', { cx: x, cy: ym, r: 2 }, bus); });
        G.B.forEach(function (k) { var x = P[k].l + P[k].w / 2; xs.push(x); mk('line', { x1: x, y1: P[k].t, x2: x, y2: ym }, bus); mk('circle', { cx: x, cy: ym, r: 2 }, bus); });
        mk('line', { x1: Math.min.apply(null, xs), y1: ym, x2: Math.max.apply(null, xs), y2: ym }, bus);
      }
      if (cur) lit(cur, P);
    }
    /* Quiet bus: light only the stems and bus segments that join the nodes in the hovered subset. */
    function lit(id, P) {
      var sub = T.filter(function (t) { return t.indexOf(id) > -1 && t.indexOf(id) < 4; }), c = sum(sub), i;
      var g = mk('g', { 'class': 'aui-lit' }, svg);
      for (i = 0; i < lanes.length - 1; i++) {
        var G = gap(P, i), ym = (G.y0 + G.y1) / 2, xs = [];
        G.A.filter(function (k) { return c[k]; }).forEach(function (k) { var x = P[k].l + P[k].w / 2; xs.push(x); mk('line', { x1: x, y1: P[k].b, x2: x, y2: ym }, g); mk('circle', { cx: x, cy: ym, r: 3 }, g); });
        G.B.filter(function (k) { return c[k]; }).forEach(function (k) { var x = P[k].l + P[k].w / 2; xs.push(x); mk('line', { x1: x, y1: P[k].t, x2: x, y2: ym }, g); mk('circle', { cx: x, cy: ym, r: 3 }, g); });
        if (xs.length) mk('line', { x1: Math.min.apply(null, xs), y1: ym, x2: Math.max.apply(null, xs), y2: ym }, g);
      }
    }
    function light(id) {
      cur = id; m.classList.add('is-active');
      var sub = T.filter(function (t) { return t.indexOf(id) > -1 && t.indexOf(id) < 4; });
      counts(sum(sub));
      for (var k in N) N[k].classList.toggle('is-lit', k === id);
      draw();
    }
    function clear() { cur = null; m.classList.remove('is-active'); $$('.is-lit', m).forEach(function (x) { x.classList.remove('is-lit'); }); counts(total); draw(); }
    Object.keys(N).forEach(function (id) {
      var n = N[id];
      n.addEventListener('mouseenter', function () { light(id); }); n.addEventListener('focus', function () { light(id); });
      n.addEventListener('mouseleave', clear); n.addEventListener('blur', clear);
      n.addEventListener('click', function () { m.dispatchEvent(new CustomEvent('aui:node', { bubbles: true, detail: { id: id, label: nm(id), count: total[id] } })); });
    });
    var tb = d.getElementById(m.getAttribute('data-table')); /* table twin: same numbers */
    if (tb && !tb.children.length) {
      var lb = (m.getAttribute('data-labels') || 'Lane|Box|Calls|Totals by box|Every path').split('|'), pd = m.getAttribute('data-period') || 'today';
      var esc = function (x) { return String(x).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); };
      var h = function (a) { return '<tr>' + a.map(function (x, i) { return '<th scope="col"' + (i === a.length - 1 ? ' class="aui-r"' : '') + '>' + esc(x) + '</th>'; }).join('') + '</tr>'; };
      var row = function (a) { return '<tr>' + a.map(function (x, i) { return '<td' + (i === a.length - 1 ? ' class="aui-r aui-num"' : '') + '>' + esc(x) + '</td>'; }).join('') + '</tr>'; };
      var t1 = Object.keys(N).map(function (k) { return row([ln(lanes.indexOf(N[k].closest('.aui-lane'))), nm(k), total[k]]); }).join('');
      var t2 = T.map(function (t) { return row([nm(t[0]), nm(t[1]), nm(t[2]), nm(t[3]), t[4]]); }).join('');
      tb.innerHTML = '<div class="aui-table-wrap"><table class="aui-table"><caption>' + esc(lb[3] + ', ' + pd) + '</caption><thead>' + h([lb[0], lb[1], lb[2]]) + '</thead><tbody>' + t1 + '</tbody></table></div>' +
        '<div class="aui-table-wrap"><table class="aui-table"><caption>' + esc(lb[4] + ', ' + pd) + '</caption><thead>' + h([ln(0), ln(1), ln(2), ln(3), lb[2]]) + '</thead><tbody>' + t2 + '</tbody></table></div>';
    }
    counts(total);
    if (w.ResizeObserver) new ResizeObserver(draw).observe(m); else w.addEventListener('resize', draw);
    if (d.fonts && d.fonts.ready) d.fonts.ready.then(draw);
    draw();
  }

  function initMaps(root) { $$('.aui-map', root || d).forEach(function (m) { if (!m.__m) { m.__m = 1; map(m); } }); }
  var AUI = w.AUI = w.AUI || {}, init = AUI.init;
  AUI.initMaps = initMaps;
  AUI.init = function (root) { if (init) init(root); initMaps(root); };
})(window, document);
