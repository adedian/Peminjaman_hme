/* Laporan migrasi (admin): riwayat run, hasil rekonsiliasi, rincian per baris, unduhan XLSX. DOM + textContent saja. */
(function () {
  'use strict';
  var api = window.hmeApi, ui = window.hme;
  var runsEl = document.getElementById('mg-runs');
  if (!api || !ui || !runsEl) { return; }
  var $ = function (id) { return document.getElementById(id); };
  var base = (document.querySelector('meta[name="base-path"]') || {}).content || '';
  var CAT = { ok: ['Berhasil', 'done'], failed: ['Gagal', 'rejected'], duplicate: ['Duplikat', 'cancelled'], needs_review: ['Perlu diperiksa', 'pending'] };
  var state = { run: null, cat: '', q: '', offset: 0, busy: false };

  function h(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) { e.className = cls; }
    if (text !== undefined && text !== null) { e.textContent = text; }
    return e;
  }
  function when(s) {
    var m = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})/.exec(String(s || ''));
    return m ? new Date(+m[1], +m[2] - 1, +m[3]).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) + ' ' + m[4] + '.' + m[5] : '';
  }

  function runCard(r) {
    var c = h('article', 'card ar-card');
    var top = h('div', 'ar-top');
    var t = h('div', 'ar-title');
    t.appendChild(h('strong', '', 'Run #' + r.id + ' · ' + (r.mode === 'dry' ? 'Uji coba (dry-run)' : 'Migrasi')));
    t.appendChild(h('small', '', when(r.started_at) + ' · ' + r.source_file + ' · SHA-256 ' + r.source_sha256.slice(0, 12) + '…'));
    top.appendChild(t);
    top.appendChild(h('span', 'chip ' + (r.status === 'success' ? 'done' : r.status === 'failed' ? 'rejected' : 'pending'),
      r.status === 'success' ? 'Selesai' : r.status === 'failed' ? 'Gagal' : 'Berjalan'));
    c.appendChild(top);
    if (r.counts) {
      var chips = h('div', 'mg-chips');
      Object.keys(CAT).forEach(function (k) { chips.appendChild(h('span', 'chip ' + CAT[k][1], CAT[k][0] + ' ' + (r.counts[k] || 0))); });
      c.appendChild(chips);
    }
    if (r.error) { c.appendChild(h('p', 'pqa-note warn', r.error)); }
    if (r.reconciliation) {
      var rec = r.reconciliation;
      var d = h('details', 'mg-rec');
      var s = h('summary', rec.ok ? 'mg-ok' : 'mg-bad', rec.ok ? 'Rekonsiliasi: semua cocok (' + rec.checks.length + ' pemeriksaan)' : 'Rekonsiliasi: ' + rec.failed + ' pemeriksaan gagal');
      d.appendChild(s);
      var ul = h('ul', 'mg-checks');
      rec.checks.forEach(function (k) {
        var li = h('li', k.ok ? '' : 'bad');
        li.appendChild(h('span', 'chip ' + (k.ok ? 'done' : 'rejected'), k.ok ? 'Cocok' : 'Beda'));
        var m = h('span');
        m.appendChild(h('strong', '', k.group + ' — ' + k.label));
        m.appendChild(h('small', '', 'Sumber: ' + String(k.expected) + ' · Sistem: ' + String(k.actual)));
        (k.details || []).forEach(function (x) { m.appendChild(h('small', 'bad', '• ' + x)); });
        li.appendChild(m);
        ul.appendChild(li);
      });
      d.appendChild(ul);
      c.appendChild(d);
    }
    var b = h('button', 'btn ghost', 'Lihat rincian per baris');
    b.type = 'button';
    b.addEventListener('click', function () { select(r); });
    c.appendChild(b);
    return c;
  }

  function issueRow(i) {
    var r = h('div', 'row mg-issue');
    r.appendChild(h('span', 'chip ' + CAT[i.category][1], CAT[i.category][0]));
    var m = h('span', 'meta');
    var t = h('span', 'title', i.message); t.style.display = 'block'; t.style.whiteSpace = 'normal'; m.appendChild(t);
    var sub = h('span', 'sub', i.sheet + (i.row ? ' · baris ' + i.row : '')); sub.style.display = 'block'; m.appendChild(sub);
    r.appendChild(m);
    return r;
  }

  function loadIssues(reset) {
    if (!state.run || state.busy) { return; }
    state.busy = true;
    if (reset) { state.offset = 0; }
    var q = new URLSearchParams({ limit: '50', offset: String(state.offset) });
    if (state.cat) { q.set('filter', state.cat); }
    if (state.q) { q.set('q', state.q); }
    api.get('/migration/runs/' + state.run.id + '/issues?' + q.toString()).then(function (d) {
      if (reset) { $('mg-issues').textContent = ''; }
      d.items.forEach(function (i) { $('mg-issues').appendChild(issueRow(i)); });
      state.offset += d.items.length;
      $('mg-more').hidden = state.offset >= d.total;
      if (reset && !d.items.length) { $('mg-issues').appendChild(h('p', 'empty-filter', 'Tidak ada baris pada kategori ini.')); }
    }, function (err) { ui.toast('Rincian gagal dimuat', err.message, 'err'); }).then(function () { state.busy = false; });
  }

  function select(r) {
    state.run = r;
    $('mg-detail').hidden = false;
    $('h-issues').textContent = 'Rincian per baris — Run #' + r.id;
    loadIssues(true);
    $('mg-detail').scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  api.get('/migration/runs').then(function (runs) {
    runsEl.textContent = '';
    if (!runs.length) {
      var c = h('div', 'card'), e = h('div', 'empty');
      e.appendChild(h('strong', '', 'Belum ada migrasi'));
      e.appendChild(h('p', '', 'Jalankan php tools/migrate-legacy.php --source=… --dry-run di server.'));
      c.appendChild(e); runsEl.appendChild(c);
      return;
    }
    runs.forEach(function (r) { runsEl.appendChild(runCard(r)); });
    var firstReal = runs.filter(function (r) { return r.mode === 'real'; })[0];
    if (firstReal) { state.run = firstReal; $('mg-detail').hidden = false; $('h-issues').textContent = 'Rincian per baris — Run #' + firstReal.id; state.cat = ''; loadIssues(true); }
  }, function (err) { ui.toast('Laporan gagal dimuat', err.message, 'err'); });

  $('mg-filter').addEventListener('click', function (e) {
    var b = e.target.closest('button[data-cat]');
    if (!b) { return; }
    state.cat = b.getAttribute('data-cat');
    this.querySelectorAll('button').forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
    loadIssues(true);
  });
  var timer = null;
  $('mg-q').addEventListener('input', function (e) {
    clearTimeout(timer);
    var v = e.target.value.trim();
    timer = setTimeout(function () { state.q = v; loadIssues(true); }, 300);
  });
  $('mg-more').addEventListener('click', function () { loadIssues(false); });
  $('mg-export').addEventListener('click', function () {
    if (!state.run) { return; }
    var b = this; b.disabled = true;
    fetch(base + '/api/v1/migration/runs/' + state.run.id + '/export', { credentials: 'same-origin' }).then(function (res) {
      if (!res.ok) { throw new Error('HTTP ' + res.status); }
      var m = /filename="([^"]+)"/.exec(res.headers.get('Content-Disposition') || '');
      return res.blob().then(function (blob) {
        var url = URL.createObjectURL(blob), a = document.createElement('a');
        a.href = url; a.download = m ? m[1] : 'Laporan_Migrasi.xlsx';
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
      });
    }).catch(function () { ui.toast('Unduhan gagal', 'Coba lagi.', 'err'); }).then(function () { b.disabled = false; });
  });
})();
