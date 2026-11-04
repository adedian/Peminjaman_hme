/* Rekap (admin): statistik per periode, barang aktif, ranking alat, dan unduh laporan Excel.
   Render dengan DOM + textContent saja (tanpa menyisipkan HTML mentah). Validasi filter tetap dilakukan server. */
(function () {
  'use strict';
  var api = window.hmeApi, ui = window.hme;
  var form = document.getElementById('recap-form');
  if (!api || !ui || !form) { return; }
  var $ = function (id) { return document.getElementById(id); };
  var NS = 'http://www.w3.org/2000/svg';
  var base = (document.querySelector('meta[name="base-path"]') || {}).content || '';
  var out = $('recap-result');
  var busy = false;

  function h(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) { e.className = cls; }
    if (text !== undefined && text !== null) { e.textContent = text; }
    return e;
  }
  function icon(name) {
    var s = document.createElementNS(NS, 'svg');
    s.setAttribute('class', 'ico'); s.setAttribute('aria-hidden', 'true');
    var u = document.createElementNS(NS, 'use'); u.setAttribute('href', '#i-' + name);
    s.appendChild(u);
    return s;
  }
  function fmt(s) {
    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(s || ''));
    return m ? new Date(+m[1], +m[2] - 1, +m[3]).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '';
  }

  function params() {
    var all = $('r-mode').value === 'all';
    var q = new URLSearchParams();
    q.set('month', all ? 'all' : $('r-month').value);
    q.set('year', all ? 'semua' : $('r-year').value.trim());
    return q.toString();
  }
  function syncMode() { $('r-filter').hidden = $('r-mode').value === 'all'; }

  function stat(label, value, accent) {
    var a = h('div', 'stat' + (accent ? ' accent' : ''));
    a.appendChild(h('small', '', label));
    a.appendChild(h('strong', 'num', String(value)));
    return a;
  }
  function section(title, chipText) {
    var s = h('section');
    var head = h('div', 'section-head');
    head.appendChild(h('h2', '', title));
    if (chipText) { head.appendChild(h('span', 'chip info', chipText)); }
    s.appendChild(head);
    var list = h('div', 'list');
    list.style.marginTop = 'var(--s-3)';
    s.appendChild(list);
    return { el: s, list: list };
  }
  function skeleton() {
    out.textContent = '';
    var g = h('div', 'stats');
    for (var i = 0; i < 3; i++) { var k = h('div', 'skeleton'); k.style.height = '76px'; g.appendChild(k); }
    out.appendChild(g);
  }

  function render(s) {
    out.textContent = '';
    var title = h('div', 'section-head');
    title.appendChild(h('h2', '', s.period.label));
    out.appendChild(title);

    if (s.total === 0) {
      var c = h('div', 'card'), e = h('div', 'empty');
      var ic = h('span', 'e-ico'); ic.appendChild(icon('chart')); e.appendChild(ic);
      e.appendChild(h('strong', '', 'Tidak ada data transaksi'));
      e.appendChild(h('p', '', 'Belum ada peminjaman pada periode ini. Coba bulan atau tahun lain.'));
      c.appendChild(e); out.appendChild(c);
      return;
    }

    var stats = h('section', 'stats'); stats.setAttribute('aria-label', 'Ringkasan');
    stats.appendChild(stat('Total transaksi', s.total, true));
    stats.appendChild(stat('Telah selesai', s.returned));
    stats.appendChild(stat('Sedang aktif', s.approved));
    out.appendChild(stats);
    out.appendChild(h('p', 'recap-note', 'Menunggu persetujuan: ' + s.pending + ' · Ditolak: ' + s.rejected));

    if (s.active.length) {
      var act = section('Barang sedang aktif', String(s.active.length));
      s.active.forEach(function (a) {
        var r = h('div', 'row');
        var t = h('span', 'thumb sq'); t.appendChild(icon('box')); r.appendChild(t);
        var m = h('span', 'meta');
        var tt = h('span', 'title', a.item_name); tt.style.display = 'block'; m.appendChild(tt);
        var sub = h('span', 'sub', [a.item_code, a.location, fmt(a.borrowed_at)].filter(Boolean).join(' · ')); sub.style.display = 'block'; m.appendChild(sub);
        r.appendChild(m);
        r.appendChild(h('span', 'recap-who', 'Oleh: ' + a.borrower));
        act.list.appendChild(r);
      });
      out.appendChild(act.el);
    }

    var top = section('Alat paling sering dipinjam');
    var max = s.top_items.length ? s.top_items[0].count : 1;
    s.top_items.forEach(function (it, i) {
      var r = h('div', 'row rank-row');
      r.appendChild(h('span', 'rank-no num', String(i + 1)));
      var m = h('span', 'meta');
      var tt = h('span', 'title', it.name); tt.style.display = 'block'; m.appendChild(tt);
      var bar = h('span', 'rank-bar'); var fill = h('span'); fill.style.width = Math.max(4, Math.round(it.count / max * 100)) + '%';
      bar.appendChild(fill); m.appendChild(bar);
      r.appendChild(m);
      r.appendChild(h('b', 'rank-count num', it.count + 'x dipinjam'));
      top.list.appendChild(r);
    });
    out.appendChild(top.el);
  }

  var shownQuery = null;   // periode yang sedang ditampilkan (bukan isian form yang belum diterapkan)
  function load(silent) {
    if (busy) { return; }
    var qs = silent ? shownQuery : params();
    if (qs === null) { return; }
    busy = true; $('r-show').disabled = true;
    if (!silent) { api.showFieldErrors(form, {}); skeleton(); }
    api.get('/reports/summary?' + qs).then(function (s) {
      shownQuery = qs;
      render(s);
    }, function (err) {
      if (silent) { return; }
      out.textContent = '';
      if (err.status === 422) { api.showFieldErrors(form, err.fields); } else { ui.toast('Rekap gagal dimuat', err.message, 'err'); }
    }).then(function () { busy = false; $('r-show').disabled = false; });
  }

  function download() {
    var b = $('r-export');
    if (b.disabled) { return; }
    b.disabled = true;
    api.showFieldErrors(form, {});
    fetch(base + '/api/v1/reports/export?' + params(), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(function (res) {
      if (res.status === 401) { window.location.href = base + '/login'; return null; }
      if (!res.ok) {
        return res.json().catch(function () { return {}; }).then(function (j) {
          var e = (j && j.error) || {};
          if (res.status === 422 && e.fields) { api.showFieldErrors(form, e.fields); }
          else { ui.toast('Laporan gagal dibuat', e.message || 'Terjadi kesalahan. Silakan coba lagi.', 'err'); }
          return null;
        });
      }
      var cd = res.headers.get('Content-Disposition') || '';
      var m = /filename="([^"]+)"/.exec(cd);
      return res.blob().then(function (blob) {
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url; a.download = m ? m[1] : 'Laporan_Stok_HME.xlsx';
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
        ui.toast('Laporan diunduh', a.download, 'ok');
        return null;
      });
    }, function () {
      ui.toast('Laporan gagal dibuat', 'Tidak dapat terhubung ke server. Periksa koneksi Anda.', 'err');
    }).then(function () { b.disabled = false; });
  }

  $('r-mode').addEventListener('change', function () { syncMode(); load(); });
  form.addEventListener('submit', function (e) { e.preventDefault(); load(); });
  // Realtime: transaksi berubah → angka periode yang sedang tampil dihitung ulang diam-diam
  if (window.hmeLive) { window.hmeLive.on(['request'], function () { load(true); }); }
  $('r-export').addEventListener('click', download);
  syncMode();
  load();
})();
