/* Permohonan / peminjaman — daftar, detail, dan aksi (staff & admin).
   Seluruh tampilan dibangun dengan DOM + textContent (tanpa innerHTML) sehingga data pengguna tidak dapat menyuntikkan HTML.
   Tombol yang ditampilkan mengikuti izin dari server (`can`), tetapi SERVER tetap memeriksa ulang setiap aksi. */
(function () {
  'use strict';
  var api = window.hmeApi, ui = window.hme;
  var cfg = document.getElementById('req-config');
  if (!api || !ui || !cfg) { return; }
  var ADMIN = cfg.getAttribute('data-role') === 'admin';
  var NS = 'http://www.w3.org/2000/svg';
  var $ = function (id) { return document.getElementById(id); };

  var STATUS = {
    pending:   { label: 'Menunggu',  cls: 'pending' },
    approved:  { label: 'Dipinjam',  cls: 'active' },
    returned:  { label: 'Selesai',   cls: 'done' },
    rejected:  { label: 'Ditolak',   cls: 'rejected' },
    cancelled: { label: 'Dibatalkan', cls: 'cancelled' }
  };

  // ---------------------------------------------------------------- pembantu DOM -----------
  function h(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) { e.className = cls; }
    if (text !== undefined && text !== null) { e.textContent = text; }
    return e;
  }
  function icon(name, cls) {
    var s = document.createElementNS(NS, 'svg');
    s.setAttribute('class', 'ico' + (cls ? ' ' + cls : ''));
    s.setAttribute('aria-hidden', 'true');
    var u = document.createElementNS(NS, 'use');
    u.setAttribute('href', '#i-' + name);
    s.appendChild(u);
    return s;
  }
  function btn(cls, label, iconName) {
    var b = h('button', 'btn ' + cls);
    b.type = 'button';
    if (iconName) { b.appendChild(icon(iconName)); }
    b.appendChild(document.createTextNode(label));
    return b;
  }
  function chip(status) {
    var s = STATUS[status] || STATUS.pending;
    return h('span', 'chip ' + s.cls, s.label);
  }
  function parse(s) {
    var m = /^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2}))?/.exec(String(s || ''));
    return m ? new Date(+m[1], +m[2] - 1, +m[3], +(m[4] || 0), +(m[5] || 0)) : null;
  }
  function fmtDate(s) {
    var d = parse(s);
    return d ? d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';
  }
  function fmtDateTime(s) {
    var d = parse(s);
    return d ? d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) + ', ' +
      d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }).replace(':', '.') : '—';
  }
  function thumb(item, cls) {
    var t = h('span', 'thumb ' + (cls || ''));
    if (item.thumb_url) {
      var img = document.createElement('img');
      img.src = item.thumb_url;
      img.alt = '';
      img.loading = 'lazy';
      t.appendChild(img);
    } else {
      t.appendChild(icon('box'));
    }
    return t;
  }
  function itemsLabel(r) {
    var first = r.loans[0] ? r.loans[0].item.name : '—';
    return r.loans.length > 1 ? first + ' +' + (r.loans.length - 1) : first;
  }
  function errToast(err, title) { ui.toast(title || 'Tidak dapat diproses', err.message, 'err'); }

  // ---------------------------------------------------------------- kartu ----------------
  function renderCard(r) {
    var card = h('article', 'card loan-card');
    card.tabIndex = 0;
    card.setAttribute('role', 'button');
    card.setAttribute('aria-label', 'Buka detail ' + r.code);

    var top = h('div', 'loan-top');
    var meta = h('div', 'meta');
    var sub = h('div', 'sub with-stack');
    var stack = h('span', 'items-stack');
    r.loans.slice(0, 3).forEach(function (l) { stack.appendChild(thumb(l.item)); });

    if (ADMIN) {
      top.appendChild(h('span', 'avatar', r.user.initials));
      meta.appendChild(h('div', 'id', r.user.name));
    } else {
      meta.appendChild(h('div', 'id num', r.code));
    }
    sub.appendChild(stack);
    sub.appendChild(h('span', '', r.loans.length + ' barang · ' + fmtDate(r.created_at)));
    meta.appendChild(sub);
    top.appendChild(meta);
    top.appendChild(chip(r.status));
    card.appendChild(top);

    var facts = h('div', 'facts');
    [['Barang', itemsLabel(r)], ['Tujuan', r.location]].forEach(function (p) {
      var d = h('div');
      d.appendChild(h('small', '', p[0]));
      d.appendChild(h('strong', '', p[1]));
      facts.appendChild(d);
    });
    card.appendChild(facts);

    // Aksi cepat langsung di kartu
    if (ADMIN && r.can.approve) {
      var row = h('div', 'btn-row');
      var no = btn('danger', 'Tolak'), yes = btn('primary', 'Setujui');
      no.addEventListener('click', function (e) { e.stopPropagation(); ctx = 'card'; confirmReject(r); });
      yes.addEventListener('click', function (e) { e.stopPropagation(); ctx = 'card'; doApprove(r); });
      row.appendChild(no); row.appendChild(yes);
      card.appendChild(row);
    } else if (!ADMIN && r.can.return) {
      var ret = btn('primary block', 'Konfirmasi Pengembalian', 'check');
      ret.addEventListener('click', function (e) { e.stopPropagation(); ctx = 'card'; confirmReturn(r); });
      card.appendChild(ret);
    }

    var open = function () { openDetail(r); };
    card.addEventListener('click', open);
    card.addEventListener('keydown', function (e) { if ((e.key === 'Enter' || e.key === ' ') && e.target === card) { e.preventDefault(); open(); } });
    return card;
  }

  // ---------------------------------------------------------------- detail ---------------
  var current = null;
  var ctx = 'card';   // dari mana aksi dipicu: 'card' (tombol cepat di kartu) atau 'detail' (sheet detail)

  function stepper(status) {
    var wrap = h('div');
    var s = h('div', 'stepper on-dark');
    var states = { pending: ['current', 'todo', 'todo'], approved: ['done', 'current', 'todo'], returned: ['done', 'done', 'done'] }[status];
    if (!states) { return null; }
    states.forEach(function (st, i) {
      var n = h('span', 'step' + (st === 'todo' ? '' : ' ' + st));
      if (st === 'done') { n.appendChild(icon('check')); }
      if (st === 'current') { n.appendChild(icon(i === 0 ? 'clock' : 'box')); }
      s.appendChild(n);
      if (i < 2) { s.appendChild(h('span', 'link-dots' + (states[i + 1] !== 'todo' ? ' done' : ''))); }
    });
    wrap.appendChild(s);
    var labels = h('div', 'step-labels on-dark');
    ['Diajukan', 'Dipinjam', 'Dikembalikan'].forEach(function (t, i) { labels.appendChild(h('span', states[i] === 'current' ? 'on' : '', t)); });
    wrap.appendChild(labels);
    return wrap;
  }

  function kv(pairs) {
    var g = h('div', 'kv');
    pairs.forEach(function (p) {
      if (p[1] === null || p[1] === undefined || p[1] === '') { return; }
      var d = h('div', p[2] ? 'full' : '');
      d.appendChild(h('small', '', p[0]));
      d.appendChild(h('strong', p[3] || '', p[1]));
      g.appendChild(d);
    });
    return g;
  }

  function miniBtn(label, cls, fn) {
    var b = h('button', 'mini-btn ' + (cls || ''), label);
    b.type = 'button';
    b.addEventListener('click', fn);
    return b;
  }

  function itemLine(r, l) {
    var line = h('div', 'item-line');
    line.appendChild(thumb(l.item, 'sq'));
    var m = h('div', 'il-meta');
    m.appendChild(h('b', '', l.item.name));
    m.appendChild(h('span', 'num', l.item.code));
    line.appendChild(m);
    var acts = h('div', 'acts');
    if (r.loans.length > 1 || !(ADMIN && l.can.approve)) { acts.appendChild(chip(l.status)); }
    if (l.can.approve && r.loans.length > 1) {
      var ok = h('button', 'round-btn ok sm'); ok.type = 'button'; ok.setAttribute('aria-label', 'Setujui ' + l.item.name); ok.appendChild(icon('check'));
      ok.addEventListener('click', function () { run(function () { return api.post('/loans/' + l.id + '/approve'); }, 'Barang disetujui'); });
      var no = h('button', 'round-btn no sm'); no.type = 'button'; no.setAttribute('aria-label', 'Tolak ' + l.item.name); no.appendChild(icon('x'));
      no.addEventListener('click', function () { askThen('Tolak ' + l.item.name + '?', 'Barang ini tidak akan dipinjamkan dan kembali tersedia.', 'Ya, tolak', function () { return api.post('/loans/' + l.id + '/reject'); }, 'Barang ditolak'); });
      acts.appendChild(ok); acts.appendChild(no);
    }
    if (l.can.swap) { acts.appendChild(miniBtn('Ganti', '', function () { openSwap(r, l); })); }
    if (l.can.return && r.counts.approved > 1) {
      acts.appendChild(miniBtn('Kembalikan', 'accent', function () {
        askThen('Kembalikan ' + l.item.name + '?', 'Pastikan barang ini sudah dikembalikan secara fisik ke gudang HME.', 'Ya, sudah kembali', function () { return api.post('/loans/' + l.id + '/return'); }, 'Pengembalian dikonfirmasi');
      }));
    }
    line.appendChild(acts);
    return line;
  }

  function openDetail(r, live) {
    current = r;
    ctx = 'detail';
    var body = $('rq-body');
    body.textContent = '';

    var head = h('div', 'head');
    var left = h('div');
    left.appendChild(h('small', '', ADMIN ? 'Pengajuan · ' + r.user.name : 'ID Pinjam'));
    var big = h('div', 'big num', r.code);
    big.id = 'rq-title';
    left.appendChild(big);
    head.appendChild(left);
    var c = chip(r.status);
    c.className = 'chip on-dark';
    head.appendChild(c);
    body.appendChild(head);

    var st = stepper(r.status);
    if (st) { body.appendChild(st); }
    body.appendChild(h('hr'));
    body.appendChild(kv([
      ['Peminjam', ADMIN ? r.user.name + ' (@' + r.user.username + ')' : null, true],
      ['Dibawa', fmtDateTime(r.borrowed_at)],
      ['Diajukan', fmtDateTime(r.created_at)],
      ['Lokasi tujuan', r.location, true],
      ['Tim', r.team, true],
      ['Keterangan', r.notes, true]
    ]));
    body.appendChild(h('hr'));
    var items = h('div', 'items-col');
    r.loans.forEach(function (l) { items.appendChild(itemLine(r, l)); });
    body.appendChild(items);

    // Aksi untuk seluruh pengajuan
    var multi = r.counts.pending > 1;
    if (ADMIN && r.can.approve) {
      var row = h('div', 'btn-row');
      var no = btn('danger', multi ? 'Tolak semua' : 'Tolak'), yes = btn('primary', multi ? 'Setujui semua' : 'Setujui');
      no.addEventListener('click', function () { confirmReject(r); });
      yes.addEventListener('click', function () { doApprove(r); });
      row.appendChild(no); row.appendChild(yes);
      body.appendChild(row);
    }
    if (!ADMIN && r.can.return) {
      var ret = btn('primary block', 'Konfirmasi Pengembalian Gudang', 'check');
      ret.addEventListener('click', function () { confirmReturn(r); });
      body.appendChild(ret);
    }
    if (!ADMIN && r.can.cancel) {
      var cn = btn('danger block', 'Batalkan pengajuan');
      cn.addEventListener('click', function () { confirmCancel(r); });
      body.appendChild(cn);
    }
    var close = btn('dark-btn block', 'Tutup');
    close.setAttribute('data-close-sheet', '');
    close.style.justifyContent = 'center';
    body.appendChild(close);

    if (!live) { ui.openSheet('sheet-request'); }   // pembaruan realtime: isi diganti tanpa membuka ulang / memindah fokus
  }

  // ---------------------------------------------------------------- aksi ----------------
  var busy = false;

  function setBusy(on) {
    busy = on;
    document.querySelectorAll('#rq-body button, #sheet-confirm button, #sheet-swap button').forEach(function (b) { b.disabled = on; });
  }

  /** Jalankan aksi API; sukses → toast + render ulang detail + segarkan daftar. */
  function run(factory, okTitle) {
    if (busy) { return Promise.resolve(); }
    setBusy(true);
    return factory().then(function (req) {
      setBusy(false);
      ui.toast(okTitle, '', 'ok');
      refreshAll();
      if (ctx === 'detail') { openDetail(req); } else { ui.closeSheets(true); }   // aksi cepat dari kartu: cukup toast
    }).catch(function (err) {
      setBusy(false);
      refreshAll();
      if (ctx === 'detail' && current) { api.get('/requests/' + current.id).then(openDetail, function () { ui.closeSheets(true); }); }
      else { ui.closeSheets(true); }
      errToast(err);
    });
  }

  function ask(title, body, okLabel, fn) {
    $('sc-title').textContent = title;
    $('sc-body').textContent = body;
    var ok = $('sc-ok');
    ok.textContent = okLabel;
    ok.disabled = false;
    ok.onclick = function () { fn(); };
    ui.openSheet('sheet-confirm');
  }
  function askThen(title, body, label, factory, okTitle) {
    ask(title, body, label, function () { run(factory, okTitle); });
  }

  function doApprove(r) {
    run(function () { return api.post('/requests/' + r.id + '/approve'); }, 'Pengajuan disetujui');
  }
  function confirmReject(r) {
    askThen('Tolak pengajuan ' + (ADMIN ? r.user.name : r.code) + '?', 'Barang tidak akan dipinjamkan dan kembali tersedia. Tindakan ini tidak dapat dibatalkan.', 'Ya, tolak',
      function () { return api.post('/requests/' + r.id + '/reject'); }, 'Pengajuan ditolak');
  }
  function confirmCancel(r) {
    askThen('Batalkan pengajuan?', 'Barang yang masih menunggu persetujuan akan dilepas dan dapat dipinjam orang lain.', 'Ya, batalkan',
      function () { return api.post('/requests/' + r.id + '/cancel'); }, 'Pengajuan dibatalkan');
  }
  function confirmReturn(r) {
    askThen('Konfirmasi pengembalian?', 'Pastikan barang sudah dikembalikan secara fisik ke gudang HME. Status barang akan kembali Tersedia.', 'Ya, sudah kembali',
      function () { return api.post('/requests/' + r.id + '/return'); }, 'Pengembalian dikonfirmasi');
  }

  function openSwap(r, l) {
    $('sw-title').textContent = 'Ganti ' + l.item.name;
    $('sw-sub').textContent = r.code;
    var sel = $('sw-select'), hint = $('sw-hint'), ok = $('sw-ok');
    sel.textContent = '';
    hint.textContent = 'Memuat daftar barang…';
    ok.disabled = true;
    ui.openSheet('sheet-swap');
    api.get('/items/available').then(function (items) {
      sel.textContent = '';
      items.forEach(function (it) {
        var o = document.createElement('option');
        o.value = String(it.id);
        o.textContent = it.name + ' (' + it.code + ')';
        sel.appendChild(o);
      });
      hint.textContent = items.length ? '' : 'Tidak ada unit lain yang tersedia saat ini.';
      ok.disabled = !items.length;
    }).catch(function (e) { hint.textContent = e.message; });
    ok.onclick = function () {
      ok.disabled = true;
      api.post('/loans/' + l.id + '/swap', { item_id: parseInt(sel.value, 10) }).then(function (req) {
        ui.toast('Unit diganti', '', 'ok');
        refreshAll();
        openDetail(req);
      }).catch(function (err) { ok.disabled = false; hint.textContent = err.message; errToast(err); });
    };
  }

  // ---------------------------------------------------------------- daftar ---------------
  var listEl = $('req-list');
  var feeds = [].slice.call(document.querySelectorAll('[data-feed]'));
  var state = { status: null, q: '', offset: 0, total: 0, loaded: 0 };
  var LIMIT = 20;

  function skeleton(n) {
    var frag = document.createDocumentFragment();
    for (var i = 0; i < n; i++) { frag.appendChild(h('div', 'card skeleton', '')).style.height = '132px'; }
    return frag;
  }

  function query(status, q, limit, offset) {
    var p = [];
    if (status) { p.push('status=' + encodeURIComponent(status)); }
    if (q) { p.push('q=' + encodeURIComponent(q)); }
    p.push('limit=' + limit, 'offset=' + offset);
    return '/requests?' + p.join('&');
  }

  function loadList(reset) {
    if (!listEl) { return; }
    if (reset) { state.offset = 0; state.loaded = 0; listEl.textContent = ''; listEl.appendChild(skeleton(3)); $('req-empty').hidden = true; $('req-more').hidden = true; }
    var token = ++loadList.t;
    return api.get(query(state.status, state.q, LIMIT, state.offset)).then(function (d) {
      if (token !== loadList.t) { return; }
      if (reset) { listEl.textContent = ''; }
      d.requests.forEach(function (r) { listEl.appendChild(renderCard(r)); });
      state.total = d.total;
      state.loaded += d.requests.length;
      state.offset = state.loaded;
      $('req-more').hidden = state.loaded >= state.total;
      var empty = state.total === 0;
      $('req-empty').hidden = !empty;
      if (empty) {
        $('req-empty-title').textContent = state.q ? 'Tidak ada yang cocok' : (state.status === 'pending' && ADMIN ? 'Tidak ada permohonan baru' : 'Belum ada data');
        $('req-empty-sub').textContent = state.q ? 'Coba kata kunci lain.' : (ADMIN ? 'Pengajuan dari staff akan muncul di sini.' : 'Pengajuan peminjaman Anda akan muncul di sini.');
      }
    }).catch(function (err) {
      if (token !== loadList.t) { return; }
      listEl.textContent = '';
      errToast(err, 'Gagal memuat data');
    });
  }
  loadList.t = 0;

  function loadFeeds() {
    feeds.forEach(function (box) {
      box.textContent = '';
      box.appendChild(skeleton(1));
      api.get(query(box.getAttribute('data-feed'), '', parseInt(box.getAttribute('data-limit'), 10) || 5, 0)).then(function (d) {
        box.textContent = '';
        if (!d.requests.length) {
          var msg = box.getAttribute('data-empty');
          if (msg) {
            var e = h('div', 'card');
            var em = h('div', 'empty');
            em.appendChild(h('p', '', msg));
            e.appendChild(em);
            box.appendChild(e);
          } else {
            box.parentElement.hidden = true;
          }
          return;
        }
        d.requests.forEach(function (r) { box.appendChild(renderCard(r)); });
      }).catch(function (err) { box.textContent = ''; errToast(err, 'Gagal memuat data'); });
    });
  }

  function refreshAll() {
    if (listEl) {
      // muat ulang halaman yang sedang tampil tanpa mengosongkan layar
      var keep = state.loaded || LIMIT;
      api.get(query(state.status, state.q, Math.max(keep, LIMIT), 0)).then(function (d) {
        listEl.textContent = '';
        d.requests.forEach(function (r) { listEl.appendChild(renderCard(r)); });
        state.total = d.total; state.loaded = d.requests.length; state.offset = state.loaded;
        $('req-more').hidden = state.loaded >= state.total;
        $('req-empty').hidden = d.total !== 0;
      }, function () { /* biarkan tampilan lama */ });
    }
    if (feeds.length) { loadFeeds(); }
    var stats = document.querySelectorAll('[data-stat]');
    if (stats.length) {
      api.get('/loans/summary').then(function (s) {
        stats.forEach(function (el) { var v = s[el.getAttribute('data-stat')]; if (v !== undefined) { el.textContent = String(v); } });
      }, function () { /* biarkan angka lama */ });
    }
  }

  // ---------------------------------------------------------------- inisialisasi ---------
  if (listEl) {
    var filter = $('req-filter');
    var allowed = [].map.call(filter.querySelectorAll('button'), function (b) { return b.getAttribute('data-status'); });
    var initial = cfg.getAttribute('data-initial');
    state.status = allowed.indexOf(initial) !== -1 && initial !== '' ? initial : (ADMIN ? 'pending' : '');
    var mark = function () {
      filter.querySelectorAll('button').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-status') === state.status ? 'true' : 'false'); });
    };
    mark();
    filter.addEventListener('click', function (e) {
      var b = e.target.closest('button[data-status]');
      if (!b) { return; }
      state.status = b.getAttribute('data-status');
      mark();
      try { history.replaceState(null, '', state.status ? '?status=' + state.status : location.pathname); } catch (err) { /* abaikan */ }
      loadList(true);
    });
    var timer = null;
    $('req-q').addEventListener('input', function (e) {
      clearTimeout(timer);
      var v = e.target.value.trim();
      timer = setTimeout(function () { state.q = v; loadList(true); }, 300);
    });
    $('req-more').addEventListener('click', function () { loadList(false); });
    loadList(true);
  }
  if (feeds.length) { loadFeeds(); }

  // Tautan dari notifikasi: /requests?open=<id> membuka detail pengajuan itu
  var openId = /^[0-9]{1,10}$/.test(new URLSearchParams(location.search).get('open') || '') ? new URLSearchParams(location.search).get('open') : null;
  if (openId) {
    api.get('/requests/' + openId).then(openDetail, function (err) { ui.toast('Pengajuan tidak dapat dibuka', err.message, 'err'); });
  }

  // Realtime: perubahan pengajuan (milik sendiri untuk staff, semua untuk admin) → daftar, feed, dan statistik dimuat ulang.
  // Detail yang sedang terbuka ikut diperbarui, kecuali saat pengguna sedang menjalankan aksi.
  if (window.hmeLive) {
    window.hmeLive.on(['request'], function (ch, reset) {
      refreshAll();
      if (current && !busy && window.hmeLive.isOpen('sheet-request') && (reset || (ch.request || []).indexOf(current.id) !== -1)) {
        api.get('/requests/' + current.id).then(function (r) { if (!busy && window.hmeLive.isOpen('sheet-request')) { openDetail(r, true); } }, function () { /* biarkan */ });
      }
    });
  }

  window.hmeRequests = { openDetail: openDetail, refresh: refreshAll };
})();
