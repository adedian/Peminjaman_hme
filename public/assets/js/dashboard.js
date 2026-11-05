/* Dashboard beranda (R7). Data dari GET /api/v1/dashboard, dirender dengan DOM + SVG (tanpa library, tanpa innerHTML),
   diperbarui otomatis lewat hmeLive saat ada perubahan pinjaman / barang / PQA. */
(function () {
  'use strict';
  var api = window.hmeApi, ui = window.hme;
  var root = document.getElementById('dash');
  if (!api || !ui || !root) { return; }
  var NS = 'http://www.w3.org/2000/svg';
  var ADMIN = root.getAttribute('data-role') === 'admin';
  var base = root.getAttribute('data-base') || '';

  // ---------------------------------------------------------------- pembantu --------------
  function h(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) { e.className = cls; }
    if (text !== undefined && text !== null) { e.textContent = text; }
    return e;
  }
  function s(tag, attrs, text) {
    var e = document.createElementNS(NS, tag);
    Object.keys(attrs || {}).forEach(function (k) { e.setAttribute(k, attrs[k]); });
    if (text !== undefined) { e.textContent = text; }
    return e;
  }
  function icon(name) {
    var e = s('svg', { 'class': 'ico', 'aria-hidden': 'true' });
    e.appendChild(s('use', { href: '#i-' + name }));
    return e;
  }
  function parse(v) {
    var m = /^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2}))?/.exec(String(v || ''));
    return m ? new Date(+m[1], +m[2] - 1, +m[3], +(m[4] || 0), +(m[5] || 0)) : null;
  }
  function fmtDT(v) {
    var d = parse(v);
    return d ? d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short' }) + ', ' + d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }).replace(':', '.') : '—';
  }
  function ago(v) {
    var d = parse(v);
    if (!d) { return ''; }
    var sec = Math.max(0, Math.round((Date.now() - d.getTime()) / 1000));
    if (sec < 60) { return 'baru saja'; }
    if (sec < 3600) { return Math.floor(sec / 60) + ' mnt lalu'; }
    if (sec < 86400) { return Math.floor(sec / 3600) + ' jam lalu'; }
    return Math.floor(sec / 86400) + ' hari lalu';
  }
  function remainText(sec, lateWord) {
    var late = sec < 0, a = Math.abs(sec), d = Math.floor(a / 86400), hr = Math.floor((a % 86400) / 3600), mn = Math.floor((a % 3600) / 60);
    var t = d > 0 ? d + ' hari ' + hr + ' jam' : (hr > 0 ? hr + ' jam ' + mn + ' mnt' : Math.max(mn, 1) + ' mnt');
    return (late ? lateWord : 'Sisa') + ' ' + t;
  }
  function countdown(sec, lateWord) {
    var e = h('span', 'countdown' + (sec < 0 ? ' late' : ''));
    e.setAttribute('data-rem', String(sec));
    e.setAttribute('data-t0', String(Math.floor(performance.now() / 1000)));
    e.setAttribute('data-late', lateWord);
    e.textContent = remainText(sec, lateWord);
    return e;
  }
  setInterval(function () {
    var now = Math.floor(performance.now() / 1000);
    root.querySelectorAll('.countdown[data-rem]').forEach(function (e) {
      var sec = parseInt(e.getAttribute('data-rem'), 10) - (now - parseInt(e.getAttribute('data-t0'), 10));
      e.textContent = remainText(sec, e.getAttribute('data-late'));
      e.classList.toggle('late', sec < 0);
    });
  }, 30000);

  // ---------------------------------------------------------------- kartu dasar -----------
  function card(title, sub, cls) {
    var c = h('section', 'dcard' + (cls ? ' ' + cls : ''));
    var head = h('header', 'dcard-head');
    head.appendChild(h('h2', '', title));
    if (sub) { head.appendChild(h('span', 'dcard-sub', sub)); }
    c.appendChild(head);
    return c;
  }
  function empty(text) { return h('p', 'dempty', text); }

  function kpi(label, value, sub, opts) {
    opts = opts || {};
    var a = h(opts.href ? 'a' : 'div', 'kpi' + (opts.tone ? ' tone-' + opts.tone : ''));
    if (opts.href) { a.href = base + opts.href; }
    var top = h('div', 'kpi-top');
    top.appendChild(h('span', 'kpi-label', label));
    if (opts.icon) { var ic = h('span', 'kpi-ico'); ic.appendChild(icon(opts.icon)); top.appendChild(ic); }
    a.appendChild(top);
    a.appendChild(h('strong', 'kpi-value num', String(value)));
    a.appendChild(h('span', 'kpi-sub', sub));
    return a;
  }

  // ---------------------------------------------------------------- grafik ----------------
  /** Tren 14 hari: dua garis (pengajuan, dikembalikan) dengan area lembut dan sumbu minimal. */
  function trendChart(t) {
    var W = 560, H = 190, L = 28, R = 8, T = 10, B = 24;
    var days = t.days, n = days.length;
    var max = Math.max(3, Math.max.apply(null, days.map(function (d) { return Math.max(d.submitted, d.returned); })));
    var nice = max <= 5 ? max : Math.ceil(max / 5) * 5;
    var x = function (i) { return L + (i * (W - L - R)) / (n - 1); };
    var y = function (v) { return T + (1 - v / nice) * (H - T - B); };
    var svg = s('svg', { viewBox: '0 0 ' + W + ' ' + H, 'class': 'chart', role: 'img', 'aria-label': 'Grafik pengajuan dan pengembalian 14 hari terakhir. Pengajuan ' + t.total_submitted + ', dikembalikan ' + t.total_returned + '.', preserveAspectRatio: 'xMidYMid meet' });
    [0, 0.5, 1].forEach(function (f) {
      var v = Math.round(nice * f), yy = y(v);
      svg.appendChild(s('line', { x1: L, x2: W - R, y1: yy, y2: yy, 'class': 'grid' }));
      svg.appendChild(s('text', { x: L - 6, y: yy + 3.5, 'class': 'axis', 'text-anchor': 'end' }, String(v)));
    });
    days.forEach(function (d, i) {
      if (i % 3 === 0 || i === n - 1) {
        var p = parse(d.date + ' 00:00');
        svg.appendChild(s('text', { x: x(i), y: H - 6, 'class': 'axis', 'text-anchor': i === 0 ? 'start' : (i === n - 1 ? 'end' : 'middle') }, p.getDate() + ' ' + p.toLocaleDateString('id-ID', { month: 'short' })));
      }
    });
    function line(key, cls) {
      var pts = days.map(function (d, i) { return [x(i), y(d[key])]; });
      var dpath = pts.map(function (p, i) { return (i ? 'L' : 'M') + p[0].toFixed(1) + ' ' + p[1].toFixed(1); }).join(' ');
      if (cls === 's-a') {
        svg.appendChild(s('path', { d: dpath + ' L' + x(n - 1).toFixed(1) + ' ' + y(0).toFixed(1) + ' L' + x(0).toFixed(1) + ' ' + y(0).toFixed(1) + ' Z', 'class': 'area ' + cls }));
      }
      svg.appendChild(s('path', { d: dpath, 'class': 'ln ' + cls }));
      var last = pts[pts.length - 1];
      svg.appendChild(s('circle', { cx: last[0], cy: last[1], r: 3.5, 'class': 'dot ' + cls }));
    }
    line('submitted', 's-a');
    line('returned', 's-b');
    return svg;
  }

  /** Donut komposisi barang. */
  function donut(parts, total) {
    var S = 150, r = 54, cx = S / 2, cy = S / 2, C = 2 * Math.PI * r;
    var svg = s('svg', { viewBox: '0 0 ' + S + ' ' + S, 'class': 'donut', role: 'img', 'aria-label': 'Komposisi barang: ' + parts.map(function (p) { return p.label + ' ' + p.value; }).join(', ') });
    svg.appendChild(s('circle', { cx: cx, cy: cy, r: r, 'class': 'ring-bg' }));
    var off = 0;
    parts.forEach(function (p, i) {
      if (p.value <= 0 || total <= 0) { return; }
      var len = (p.value / total) * C;
      var seg = s('circle', { cx: cx, cy: cy, r: r, 'class': 'ring seg-' + i, 'stroke-dasharray': Math.max(0, len - 2).toFixed(2) + ' ' + (C - Math.max(0, len - 2)).toFixed(2), 'stroke-dashoffset': (-off).toFixed(2), transform: 'rotate(-90 ' + cx + ' ' + cy + ')' });
      svg.appendChild(seg);
      off += len;
    });
    svg.appendChild(s('text', { x: cx, y: cy + 2, 'class': 'donut-n', 'text-anchor': 'middle' }, String(total)));
    svg.appendChild(s('text', { x: cx, y: cy + 18, 'class': 'donut-l', 'text-anchor': 'middle' }, 'barang aktif'));
    return svg;
  }

  function bars(list) {
    var box = h('div', 'hbars');
    var max = Math.max.apply(null, list.map(function (r) { return r.count; }).concat([1]));
    list.forEach(function (r) {
      var row = h('div', 'hbar');
      var top = h('div', 'hbar-top');
      top.appendChild(h('span', 'hbar-name', r.name));
      top.appendChild(h('strong', 'num', String(r.count) + '×'));
      row.appendChild(top);
      var track = h('div', 'hbar-track'); var fill = h('span'); fill.style.width = Math.max(4, Math.round((r.count / max) * 100)) + '%';
      track.appendChild(fill); row.appendChild(track);
      box.appendChild(row);
    });
    return box;
  }

  // ---------------------------------------------------------------- bagian ----------------
  var ACTIONS = {
    'request.submitted': 'mengajukan peminjaman', 'loan.approved': 'menyetujui peminjaman', 'loan.rejected': 'menolak peminjaman',
    'loan.returned': 'mengonfirmasi pengembalian', 'loan.cancelled': 'membatalkan pengajuan', 'loan.swapped': 'mengganti unit barang',
    'pqa.submitted': 'mengajukan pemasangan PQA', 'pqa.approved': 'mengonfirmasi pemasangan PQA', 'pqa.rejected': 'menolak pemasangan PQA',
    'pqa.extension_requested': 'meminta perpanjangan PQA', 'pqa.extension_approved': 'menyetujui perpanjangan PQA', 'pqa.extension_rejected': 'menolak perpanjangan PQA',
    'pqa.removal_requested': 'meminta pencabutan PQA', 'pqa.removal_approved': 'mengonfirmasi pencabutan PQA', 'pqa.removal_rejected': 'menolak pencabutan PQA'
  };

  function attention(d) {
    var k = d.kpi, items = [];
    if (k.loans_overdue > 0) { items.push(['danger', k.loans_overdue + ' peminjaman melewati batas pengembalian', '/requests?status=overdue']); }
    if (k.pqa_overdue > 0) { items.push(['danger', k.pqa_overdue + ' PQA melewati jadwal pencabutan', '/pqa']); }
    if (ADMIN && k.loans_pending > 0) { items.push(['warn', k.loans_pending + ' permohonan menunggu persetujuan', '/requests?status=pending']); }
    if (ADMIN && k.pqa_pending > 0) { items.push(['warn', k.pqa_pending + ' pengajuan pemasangan PQA menunggu', '/pqa']); }
    if (k.loans_due_soon > 0) { items.push(['info', k.loans_due_soon + ' peminjaman jatuh tempo dalam waktu dekat', '/requests?status=approved']); }
    if (k.pqa_due_soon > 0) { items.push(['info', k.pqa_due_soon + ' PQA dijadwalkan dicabut dalam waktu dekat', '/pqa']); }
    if (!items.length) { return null; }
    var box = h('section', 'attn'); box.setAttribute('aria-label', 'Perlu perhatian');
    items.forEach(function (it) {
      var a = h('a', 'attn-row tone-' + it[0]); a.href = base + it[2];
      a.appendChild(h('span', 'attn-dot'));
      a.appendChild(h('span', 'attn-text', it[1]));
      a.appendChild(icon('arrow'));
      box.appendChild(a);
    });
    return box;
  }

  /** Ajakan satu kali untuk mengaktifkan notifikasi HP (hanya bila didukung, belum diizinkan, dan belum ditutup pengguna). */
  function pushBanner() {
    try {
      var ok = ('serviceWorker' in navigator) && ('PushManager' in window) && ('Notification' in window) && window.isSecureContext;
      if (!ok || Notification.permission !== 'default' || localStorage.getItem('hme.push.dismiss') === '1') { return null; }
    } catch (e) { return null; }
    var box = h('section', 'attn push-banner'); box.setAttribute('aria-label', 'Aktifkan notifikasi');
    var a = h('a', 'attn-row tone-info'); a.href = base + '/profile#push-card';
    a.appendChild(h('span', 'attn-dot'));
    a.appendChild(h('span', 'attn-text', 'Aktifkan notifikasi agar pengingat muncul di layar HP Anda'));
    a.appendChild(icon('arrow'));
    box.appendChild(a);
    var x = h('button', 'mini-btn', 'Nanti'); x.type = 'button'; x.style.justifySelf = 'end';
    x.addEventListener('click', function () { try { localStorage.setItem('hme.push.dismiss', '1'); } catch (e) { /* abaikan */ } box.remove(); });
    box.appendChild(x);
    return box;
  }

  function render(d) {
    var k = d.kpi;
    root.textContent = '';
    root.setAttribute('aria-busy', 'false');

    var pb = pushBanner();
    if (pb) { root.appendChild(pb); }
    var at = attention(d);
    if (at) { root.appendChild(at); }

    var grid = h('div', 'kpis');
    grid.appendChild(kpi('Barang tersedia', k.items_available, 'dari ' + k.items_total + ' barang aktif', { icon: 'box', href: ADMIN ? '/items' : '/requests/new' }));
    grid.appendChild(kpi(ADMIN ? 'Sedang dipinjam' : 'Pinjaman saya', k.loans_active, k.returned_today + ' dikembalikan hari ini', { icon: 'clock', href: '/requests?status=approved' }));
    grid.appendChild(kpi('Terlambat', k.loans_overdue, k.loans_due_soon + ' segera jatuh tempo', { icon: 'bell', tone: k.loans_overdue > 0 ? 'danger' : '', href: '/requests?status=overdue' }));
    grid.appendChild(kpi('PQA terpasang', k.pqa_active, k.pqa_overdue > 0 ? k.pqa_overdue + ' melewati jadwal' : (k.pqa_due_soon + ' segera dicabut'), { icon: 'bolt', tone: k.pqa_overdue > 0 ? 'danger' : '', href: '/pqa' }));
    root.appendChild(grid);

    var row1 = h('div', 'dgrid g-trend');
    var tr = card('Aktivitas 14 hari', d.trend.total_submitted + ' pengajuan · ' + d.trend.total_returned + ' dikembalikan');
    var legend = h('div', 'legend');
    [['s-a', 'Pengajuan'], ['s-b', 'Dikembalikan']].forEach(function (l) { var i = h('span', 'lg'); i.appendChild(h('i', 'sw ' + l[0])); i.appendChild(document.createTextNode(l[1])); legend.appendChild(i); });
    tr.appendChild(legend);
    tr.appendChild(trendChart(d.trend));
    row1.appendChild(tr);

    var comp = card('Komposisi barang', null);
    var total = d.composition.reduce(function (a, p) { return a + p.value; }, 0);
    var wrap = h('div', 'comp');
    wrap.appendChild(donut(d.composition, total));
    var lg = h('ul', 'comp-list');
    d.composition.forEach(function (p, i) {
      var li = h('li'); li.appendChild(h('i', 'sw seg-' + i)); li.appendChild(h('span', '', p.label)); li.appendChild(h('strong', 'num', String(p.value))); lg.appendChild(li);
    });
    wrap.appendChild(lg);
    comp.appendChild(wrap);
    row1.appendChild(comp);
    root.appendChild(row1);

    var row2 = h('div', 'dgrid g-two');
    var dl = card('Jatuh tempo terdekat', 'peminjaman aktif');
    if (!d.deadlines.length) { dl.appendChild(empty(ADMIN ? 'Tidak ada peminjaman aktif dengan batas pengembalian.' : 'Anda tidak sedang meminjam barang.')); }
    else {
      var ul = h('ul', 'dlist');
      d.deadlines.forEach(function (x) {
        var li = h('li'); var a = h('a', 'dl-row' + (x.overdue ? ' late' : '')); a.href = base + '/requests?status=approved';
        var m = h('span', 'dl-main'); m.appendChild(h('strong', '', x.items)); m.appendChild(h('small', '', (x.borrower ? x.borrower + ' · ' : '') + 'batas ' + fmtDT(x.due_at)));
        a.appendChild(m); a.appendChild(countdown(x.remaining_seconds, 'Terlambat')); li.appendChild(a); ul.appendChild(li);
      });
      dl.appendChild(ul);
    }
    row2.appendChild(dl);

    var pq = card('PQA terpasang', 'jadwal pencabutan');
    if (!d.pqa_active.length) { pq.appendChild(empty('Tidak ada PQA yang sedang terpasang.')); }
    else {
      var pl = h('ul', 'dlist');
      d.pqa_active.forEach(function (x) {
        var li = h('li'); var a = h('a', 'dl-row' + (x.overdue ? ' late' : '')); a.href = base + '/pqa?open=' + x.id;
        var m = h('span', 'dl-main'); m.appendChild(h('strong', '', x.unit)); m.appendChild(h('small', '', x.site + ' · hari ke-' + (x.elapsed_days + 1) + ' dari ' + x.target_days));
        var bar = h('span', 'mini-bar'); var f = h('i'); f.style.width = Math.min(100, Math.round(((x.elapsed_days + 1) / Math.max(1, x.target_days)) * 100)) + '%'; bar.appendChild(f); m.appendChild(bar);
        a.appendChild(m);
        if (x.remaining_seconds !== null) { a.appendChild(countdown(x.remaining_seconds, 'Lewat')); }
        li.appendChild(a); pl.appendChild(li);
      });
      pq.appendChild(pl);
    }
    row2.appendChild(pq);
    root.appendChild(row2);

    var row3 = h('div', 'dgrid g-two');
    var tp = card('Barang paling sering dipinjam', '90 hari terakhir');
    tp.appendChild(d.top_items.length ? bars(d.top_items) : empty('Belum ada data peminjaman.'));
    row3.appendChild(tp);

    var ac = card(ADMIN ? 'Aktivitas terbaru' : 'Aktivitas saya', null);
    if (!d.activity.length) { ac.appendChild(empty('Belum ada aktivitas.')); }
    else {
      var tl = h('ol', 'timeline');
      d.activity.forEach(function (x) {
        var li = h('li');
        li.appendChild(h('span', 'tl-dot'));
        var b = h('span', 'tl-body');
        b.appendChild(h('span', 'tl-text', (ADMIN ? x.actor + ' ' : 'Anda ') + (ACTIONS[x.action] || x.action)));
        b.appendChild(h('small', '', ago(x.at)));
        li.appendChild(b); tl.appendChild(li);
      });
      ac.appendChild(tl);
    }
    row3.appendChild(ac);
    root.appendChild(row3);
  }

  function load(live) {
    api.get('/dashboard').then(render, function (err) {
      if (!live) { root.textContent = ''; root.appendChild(empty('Dashboard gagal dimuat. ' + err.message)); }
    });
  }
  load(false);
  if (window.hmeLive) { window.hmeLive.on(['request', 'item', 'pqa'], function () { load(true); }); }
  // jatuh tempo bisa terlewati tanpa ada perubahan data → segarkan berkala
  setInterval(function () { if (!document.hidden) { load(true); } }, 120000);
})();
