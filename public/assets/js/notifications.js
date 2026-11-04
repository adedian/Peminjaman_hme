/* Notifikasi in-app: daftar, filter belum dibaca, tandai dibaca (satu/semua), buka tautan.
   Render dengan DOM + textContent saja. */
(function () {
  'use strict';
  var api = window.hmeApi, ui = window.hme;
  var list = document.getElementById('nt-list');
  if (!api || !ui || !list) { return; }
  var $ = function (id) { return document.getElementById(id); };
  var NS = 'http://www.w3.org/2000/svg';
  var state = { unread: false, offset: 0, busy: false };

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
  function ago(s) {
    var m = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})/.exec(String(s || ''));
    if (!m) { return ''; }
    var d = new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]);
    var min = Math.round((Date.now() - d.getTime()) / 60000);
    if (min < 1) { return 'baru saja'; }
    if (min < 60) { return min + ' menit lalu'; }
    if (min < 1440) { return Math.floor(min / 60) + ' jam lalu'; }
    return d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) + ' ' +
      d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
  }
  function setBell(n) {
    var bell = $('nav-bell');
    if (!bell) { return; }
    var c = bell.querySelector('[data-unread]');
    if (n > 0) {
      if (!c) { c = h('span', 'bell-count num'); c.setAttribute('data-unread', ''); bell.appendChild(c); }
      c.textContent = n > 99 ? '99+' : String(n);
      bell.setAttribute('aria-label', 'Notifikasi (' + n + ' belum dibaca)');
    } else {
      if (c) { c.remove(); }
      bell.setAttribute('aria-label', 'Notifikasi');
    }
  }

  function row(n) {
    var r = h('a', 'row nt-row' + (n.read ? '' : ' unread'));
    r.href = n.link || '#';
    var ic = h('span', 'thumb sq'); ic.appendChild(icon(n.type.indexOf('pqa.') === 0 ? 'bolt' : 'inbox')); r.appendChild(ic);
    var m = h('span', 'meta');
    var t = h('span', 'title', n.title); t.style.display = 'block'; m.appendChild(t);
    if (n.body) { var b = h('span', 'nt-body', n.body); m.appendChild(b); }
    m.appendChild(h('span', 'sub nt-time', ago(n.created_at)));
    r.appendChild(m);
    if (!n.read) { var dot = h('span', 'nt-dot'); dot.setAttribute('aria-label', 'Belum dibaca'); r.appendChild(dot); }
    r.addEventListener('click', function (e) {
      if (n.read) { if (!n.link) { e.preventDefault(); } return; }
      e.preventDefault();
      api.post('/notifications/' + n.id + '/read').then(function (res) {
        setBell(res.unread);
        if (n.link) { window.location.href = n.link; } else { n.read = true; r.classList.remove('unread'); if (dot) { dot.remove(); } }
      }, function (err) { ui.toast('Tidak dapat diproses', err.message, 'err'); });
    });
    return r;
  }

  function empty() {
    var c = h('div', 'card'), e = h('div', 'empty');
    var ic = h('span', 'e-ico'); ic.appendChild(icon('bell')); e.appendChild(ic);
    e.appendChild(h('strong', '', state.unread ? 'Semua sudah dibaca' : 'Belum ada notifikasi'));
    e.appendChild(h('p', '', 'Pemberitahuan tentang peminjaman dan PQA akan muncul di sini.'));
    c.appendChild(e);
    return c;
  }

  function load(reset) {
    if (state.busy) { return; }
    state.busy = true;
    if (reset) { state.offset = 0; }
    api.get('/notifications?limit=20&offset=' + state.offset + (state.unread ? '&unread=1' : '')).then(function (d) {
      if (reset) { list.textContent = ''; }
      d.items.forEach(function (n) { list.appendChild(row(n)); });
      if (reset && !d.items.length) { list.appendChild(empty()); }
      state.offset += d.items.length;
      $('nt-more').hidden = !d.has_more;
      $('nt-read-all').disabled = d.unread === 0;
      setBell(d.unread);
    }, function (err) {
      ui.toast('Notifikasi gagal dimuat', err.message, 'err');
    }).then(function () { state.busy = false; });
  }

  $('nt-filter').addEventListener('click', function (e) {
    var b = e.target.closest('button');
    if (!b) { return; }
    state.unread = b.getAttribute('data-unread') === '1';
    this.querySelectorAll('button').forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
    load(true);
  });
  $('nt-more').addEventListener('click', function () { load(false); });
  $('nt-read-all').addEventListener('click', function () {
    var b = this; b.disabled = true;
    api.post('/notifications/read-all').then(function () {
      setBell(0); ui.toast('Semua notifikasi ditandai dibaca', '', 'ok'); load(true);
    }, function (err) { b.disabled = false; ui.toast('Tidak dapat diproses', err.message, 'err'); });
  });
  // Realtime: notifikasi baru → halaman pertama dimuat ulang (bila pengguna belum menggulir ke halaman berikutnya)
  if (window.hmeLive) { window.hmeLive.on(['notification'], function () { if (state.offset <= 20) { load(true); } }); }
  load(true);
})();
