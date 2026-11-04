/* Log aktivitas (admin): filter, daftar, rincian sebelum/sesudah. Render dengan DOM + textContent saja. */
(function () {
  'use strict';
  var api = window.hmeApi, ui = window.hme;
  var form = document.getElementById('log-form');
  if (!api || !ui || !form) { return; }
  var $ = function (id) { return document.getElementById(id); };
  var NS = 'http://www.w3.org/2000/svg';
  var list = $('log-list');
  var state = { offset: 0, busy: false, query: '' };

  var LABEL = {
    'auth.login': 'Masuk', 'auth.login_failed': 'Gagal masuk', 'auth.logout': 'Keluar',
    'auth.password_changed': 'Ganti password', 'auth.password_change_failed': 'Gagal ganti password',
    'request.submitted': 'Ajukan peminjaman', 'loan.approved': 'Setujui peminjaman', 'loan.rejected': 'Tolak peminjaman',
    'loan.cancelled': 'Batalkan pengajuan', 'loan.returned': 'Kembalikan barang', 'loan.swapped': 'Ganti unit',
    'pqa.submitted': 'Ajukan pasang PQA', 'pqa.approved': 'Setujui pasang PQA', 'pqa.rejected': 'Tolak pasang PQA',
    'pqa.extension_requested': 'Minta tambah waktu PQA', 'pqa.extension_approved': 'Setujui tambah waktu PQA', 'pqa.extension_rejected': 'Tolak tambah waktu PQA',
    'pqa.removal_requested': 'Minta cabut PQA', 'pqa.removal_approved': 'Setujui cabut PQA', 'pqa.removal_rejected': 'Tolak cabut PQA',
    'item.created': 'Tambah barang', 'item.updated': 'Ubah barang', 'item.activated': 'Aktifkan barang', 'item.deactivated': 'Nonaktifkan barang',
    'user.created': 'Tambah pengguna', 'user.updated': 'Ubah pengguna', 'user.activated': 'Aktifkan pengguna', 'user.deactivated': 'Nonaktifkan pengguna',
    'user.password_reset': 'Reset password', 'report.exported': 'Unduh laporan Excel',
    'settings.updated': 'Ubah pengaturan', 'settings.test_email': 'Kirim email uji', 'settings.test_email_failed': 'Email uji gagal'
  };
  var ICON = { auth: 'lock', request: 'inbox', loan: 'inbox', pqa: 'bolt', item: 'box', user: 'users', report: 'chart', settings: 'gear' };

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
  function when(s) {
    var m = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})/.exec(String(s || ''));
    if (!m) { return s || ''; }
    var d = new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +m[6]);
    return d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) + ' ' +
      d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
  }
  function kv(obj) {
    if (!obj) { return '—'; }
    return Object.keys(obj).map(function (k) {
      var v = obj[k];
      return k + ': ' + (v !== null && typeof v === 'object' ? JSON.stringify(v) : String(v));
    }).join('\n');
  }

  function row(a) {
    var d = h('details', 'card log-row');
    var s = h('summary', 'log-sum');
    var ic = h('span', 'thumb sq'); ic.appendChild(icon(ICON[a.action.split('.')[0]] || 'shield')); s.appendChild(ic);
    var m = h('span', 'meta');
    var t = h('span', 'title', (LABEL[a.action] || a.action) + (a.entity_id ? ' · #' + a.entity_id : '')); t.style.display = 'block'; m.appendChild(t);
    var who = a.user ? a.user.name + ' (' + a.user.username + ')' : 'Tanpa akun';
    var sub = h('span', 'sub', who + ' · ' + when(a.created_at)); sub.style.display = 'block'; m.appendChild(sub);
    s.appendChild(m);
    if (/failed$/.test(a.action)) { s.appendChild(h('span', 'chip rejected', 'Gagal')); }
    d.appendChild(s);

    var body = h('div', 'log-detail');
    var grid = h('dl', 'log-dl');
    [['Aksi', a.action], ['Objek', (a.entity_type || '—') + (a.entity_id ? ' #' + a.entity_id : '')], ['IP', a.ip || '—'], ['Perangkat', a.user_agent || '—']]
      .forEach(function (p) { grid.appendChild(h('dt', '', p[0])); grid.appendChild(h('dd', '', p[1])); });
    body.appendChild(grid);
    if (a.before || a.after) {
      var cmp = h('div', 'log-cmp');
      var b1 = h('div'); b1.appendChild(h('small', '', 'Sebelum')); b1.appendChild(h('pre', '', kv(a.before))); cmp.appendChild(b1);
      var b2 = h('div'); b2.appendChild(h('small', '', 'Sesudah')); b2.appendChild(h('pre', '', kv(a.after))); cmp.appendChild(b2);
      body.appendChild(cmp);
    }
    d.appendChild(body);
    return d;
  }

  function query() {
    var q = new URLSearchParams();
    ['q', 'group', 'user_id', 'from', 'to'].forEach(function (k) {
      var el = form.elements[k];
      var v = el ? String(el.value || '').trim() : '';
      if (v) { q.set(k, v); }
    });
    return q.toString();
  }

  function load(reset) {
    if (state.busy) { return; }
    state.busy = true;
    if (reset) { state.offset = 0; state.query = query(); list.textContent = ''; var sk = h('div', 'skeleton'); sk.style.height = '64px'; list.appendChild(sk); }
    api.showFieldErrors(form, {});
    api.get('/logs?limit=50&offset=' + state.offset + (state.query ? '&' + state.query : '')).then(function (d) {
      if (reset) { list.textContent = ''; }
      d.items.forEach(function (a) { list.appendChild(row(a)); });
      if (reset && !d.items.length) { list.appendChild(h('p', 'empty-filter', 'Tidak ada aktivitas yang cocok dengan filter.')); }
      state.offset += d.items.length;
      $('log-more').hidden = !d.has_more;
    }, function (err) {
      if (reset) { list.textContent = ''; }
      if (err.status === 422) { api.showFieldErrors(form, err.fields); } else { ui.toast('Log gagal dimuat', err.message, 'err'); }
    }).then(function () { state.busy = false; });
  }

  form.addEventListener('submit', function (e) { e.preventDefault(); load(true); });
  ['log-group', 'log-user'].forEach(function (id) { $(id).addEventListener('change', function () { load(true); }); });
  $('log-more').addEventListener('click', function () { load(false); });
  load(true);
})();
