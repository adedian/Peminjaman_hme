/* Tracker PQA — daftar (menunggu / aktif / riwayat), pengajuan pemasangan, tambah waktu, pencabutan, dan keputusan admin.
   Render dengan DOM + textContent (tanpa innerHTML). Tombol mengikuti `can` dari server, tetapi server memeriksa ulang setiap aksi. */
(function () {
  'use strict';
  var api = window.hmeApi, ui = window.hme;
  var cfg = document.getElementById('pqa-config');
  if (!api || !ui || !cfg) { return; }
  var ADMIN = cfg.getAttribute('data-role') === 'admin';
  var NS = 'http://www.w3.org/2000/svg';
  var $ = function (id) { return document.getElementById(id); };

  var STATUS = {
    'Pending':   { label: 'Menunggu',            cls: 'pending' },
    'Terpasang': { label: 'Terpasang',           cls: 'info' },
    'Req Cabut': { label: 'Menunggu pencabutan', cls: 'pending' },
    'Dicabut':   { label: 'Selesai',             cls: 'done' },
    'Ditolak':   { label: 'Ditolak',             cls: 'rejected' }
  };

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
  function btn(cls, label, ic) {
    var b = h('button', 'btn ' + cls); b.type = 'button';
    if (ic) { b.appendChild(icon(ic)); }
    b.appendChild(document.createTextNode(label));
    return b;
  }
  function chip(status, overdue) {
    if (overdue) { return h('span', 'chip rejected', 'Lewat jadwal cabut'); }
    var s = STATUS[status] || STATUS.Pending; return h('span', 'chip ' + s.cls, s.label);
  }
  function parse(s) {
    var m = /^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2}))?/.exec(String(s || ''));
    return m ? new Date(+m[1], +m[2] - 1, +m[3], +(m[4] || 0), +(m[5] || 0)) : null;
  }
  function fmt(s) {
    var d = parse(s);
    return d ? d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';
  }
  function fmtDT(s) {
    var d = parse(s);
    return d ? d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) + ', ' +
      d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }).replace(':', '.') : '—';
  }
  // Sisa waktu dikirim server (detik) lalu dihitung maju dengan jam monotonik browser (tidak bergantung jam perangkat)
  function remainText(sec) {
    var late = sec < 0, a = Math.abs(sec), d = Math.floor(a / 86400), hr = Math.floor((a % 86400) / 3600), mn = Math.floor((a % 3600) / 60);
    var t = d > 0 ? d + ' hari ' + hr + ' jam' : (hr > 0 ? hr + ' jam ' + mn + ' mnt' : Math.max(mn, 1) + ' mnt');
    return (late ? 'Lewat jadwal ' : 'Sisa ') + t;
  }
  function countdown(sec) {
    var s = h('span', 'countdown' + (sec < 0 ? ' late' : ''));
    s.setAttribute('data-rem', String(sec));
    s.setAttribute('data-t0', String(Math.floor(performance.now() / 1000)));
    s.textContent = remainText(sec);
    return s;
  }
  setInterval(function () {
    var now = Math.floor(performance.now() / 1000);
    document.querySelectorAll('.countdown[data-rem]').forEach(function (e) {
      var sec = parseInt(e.getAttribute('data-rem'), 10) - (now - parseInt(e.getAttribute('data-t0'), 10));
      e.textContent = remainText(sec);
      e.classList.toggle('late', sec < 0);
    });
  }, 30000);
  function destText(p) {
    if (p.removal_destination === 'site') { return 'Pindah ke ' + p.destination_site + (p.move_target_days ? ' (target ' + p.move_target_days + ' hari)' : ''); }
    if (p.removal_destination === 'office') { return 'Kembali ke kantor'; }
    return null;
  }
  var EVENT = { installed: 'Dipasang', moved_out: 'Dicabut & dipindah', moved_in: 'Terpasang di site tujuan', removed_office: 'Kembali ke kantor', removed: 'Dicabut' };
  function thumb(item) {
    var t = h('span', 'thumb sq');
    if (item.thumb_url) { var i = document.createElement('img'); i.src = item.thumb_url; i.alt = ''; i.loading = 'lazy'; t.appendChild(i); }
    else { t.appendChild(icon('bolt')); }
    return t;
  }
  function fact(label, value) {
    var d = h('div'); d.appendChild(h('small', '', label)); d.appendChild(h('strong', '', value)); return d;
  }
  function errToast(err, t) { ui.toast(t || 'Tidak dapat diproses', err.message, 'err'); }

  // ---------------------------------------------------------------- kartu -----------------
  var cardCtx = 'card';

  function progress(p) {
    var box = h('div', 'pqa-progress' + (p.over_target ? ' over' : ''));
    var top = h('div', 'pp-top');
    top.appendChild(h('span', '', 'Aktual / target'));
    top.appendChild(h('strong', 'num', p.actual_days + ' / ' + p.target_days + ' hari'));
    box.appendChild(top);
    var bar = h('div', 'pp-bar'); var fill = h('span');
    fill.style.width = Math.max(4, Math.min(100, Math.round((p.actual_days / Math.max(1, p.target_days)) * 100))) + '%';
    bar.appendChild(fill); box.appendChild(bar);
    if (p.over_target) { box.appendChild(h('small', 'pp-warn', 'Melewati target ' + (p.actual_days - p.target_days) + ' hari')); }
    return box;
  }

  function actions(p) {
    var row = h('div', 'btn-row'), n = 0;
    function add(b, fn) { b.addEventListener('click', function (e) { e.stopPropagation(); cardCtx = 'card'; fn(); }); row.appendChild(b); n++; }
    if (p.can.approve) {
      add(btn('danger', 'Tolak'), function () { confirmDo(p, 'Tolak pemasangan ' + p.item.name + '?', 'Pengajuan ditolak dan unit kembali tersedia.', 'Ya, tolak', '/pqa/' + p.id + '/reject', 'Pemasangan ditolak'); });
      add(btn('primary', 'Setujui'), function () { run(p, '/pqa/' + p.id + '/approve', 'Pemasangan disetujui'); });
    }
    if (p.can.approve_removal) {
      add(btn('danger', 'Tolak cabut'), function () { confirmDo(p, 'Tolak pencabutan?', 'Unit tetap berstatus terpasang.', 'Ya, tolak', '/pqa/' + p.id + '/removal/reject', 'Pencabutan ditolak'); });
      add(btn('primary', 'Setujui cabut'), function () { confirmDo(p, 'Setujui pencabutan ' + p.item.name + '?', 'Durasi akhir dihitung dan unit kembali tersedia.', 'Ya, setujui', '/pqa/' + p.id + '/removal/approve', 'Pencabutan disetujui'); });
    }
    if (p.can.approve_extension) {
      add(btn('danger', 'Tolak +' + p.extension_pending_days + ' hari'), function () { run(p, '/pqa/' + p.id + '/extension/reject', 'Tambah waktu ditolak'); });
      add(btn('success', 'Setujui +' + p.extension_pending_days + ' hari'), function () { run(p, '/pqa/' + p.id + '/extension/approve', 'Waktu ditambahkan'); });
    }
    if (p.can.extend) { add(btn('ghost', 'Tambah waktu', 'plus'), function () { openExt(p); }); }
    if (p.can.remove) { add(btn('danger', 'Request cabut'), function () { openRm(p); }); }
    return n ? row : null;
  }

  function renderCard(p) {
    var card = h('article', 'card loan-card');
    card.tabIndex = 0; card.setAttribute('role', 'button'); card.setAttribute('aria-label', 'Buka detail ' + p.item.name);
    var top = h('div', 'loan-top');
    top.appendChild(thumb(p.item));
    var meta = h('div', 'meta');
    meta.appendChild(h('div', 'id', p.item.name));
    meta.appendChild(h('div', 'sub num', p.item.code + ' · ' + p.location));
    top.appendChild(meta);
    top.appendChild(chip(p.status, p.overdue));
    card.appendChild(top);

    if (p.due_at && (p.status === 'Terpasang' || p.status === 'Req Cabut')) {
      var sch = h('div', 'facts due-row');
      var sd = h('div'); sd.appendChild(h('small', '', 'Jadwal cabut'));
      var ss = h('strong', '', fmtDT(p.due_at));
      if (p.remaining_seconds !== null) { ss.appendChild(document.createTextNode(' · ')); ss.appendChild(countdown(p.remaining_seconds)); }
      sd.appendChild(ss); sch.appendChild(sd);
      card.appendChild(sch);
    }
    if (p.actual_days !== null && (p.status === 'Terpasang' || p.status === 'Req Cabut')) { card.appendChild(progress(p)); }
    if (p.status === 'Dicabut') {
      var done = h('div', 'facts');
      done.appendChild(fact('Durasi aktual', p.actual_days + ' hari (target ' + p.target_days + ')'));
      done.appendChild(fact('Dicabut', fmt(p.removed_at)));
      card.appendChild(done);
    }
    var f = h('div', 'facts');
    f.appendChild(fact('PPIC', p.ppic));
    f.appendChild(fact(p.status === 'Pending' ? 'Diajukan' : (p.status === 'Ditolak' ? 'Diajukan' : 'Dipasang'), fmt(p.installed_at || p.submitted_at)));
    card.appendChild(f);

    if (p.extension_pending_days > 0 && !ADMIN) {
      card.appendChild(h('div', 'pqa-note', 'Menunggu persetujuan tambah waktu +' + p.extension_pending_days + ' hari'));
    }
    if (p.status === 'Req Cabut' && !ADMIN) { card.appendChild(h('div', 'pqa-note', 'Menunggu persetujuan pencabutan · Tim: ' + (p.removal_team || '—') + (destText(p) ? ' · ' + destText(p) : ''))); }
    if (p.status === 'Pending' && p.previous_installation_id) { card.appendChild(h('div', 'pqa-note warn', 'Pindahan dari pemasangan sebelumnya — menunggu konfirmasi pemasangan di site tujuan')); }
    if (p.status === 'Pending' && !ADMIN) { card.appendChild(h('div', 'pqa-note', 'Menunggu persetujuan admin')); }
    if (ADMIN && p.status === 'Req Cabut') { card.appendChild(h('div', 'pqa-note warn', 'Permintaan pencabutan · Tim pencabut: ' + (p.removal_team || '—') + (destText(p) ? ' · ' + destText(p) : ''))); }

    var act = actions(p);
    if (act) { card.appendChild(act); }
    var open = function () { cardCtx = 'detail'; openDetail(p.id); };
    card.addEventListener('click', open);
    card.addEventListener('keydown', function (e) { if ((e.key === 'Enter' || e.key === ' ') && e.target === card) { e.preventDefault(); open(); } });
    return card;
  }

  // ---------------------------------------------------------------- detail ----------------
  var detailId = null;
  function openDetail(id, live) {
    detailId = String(id);
    api.get('/pqa/' + id).then(function (p) {
      if (live && !window.hmeLive.isOpen('sheet-pqa')) { return; }
      var body = $('pq-body'); body.textContent = '';
      var head = h('div', 'head'); var left = h('div');
      left.appendChild(h('small', '', p.item.code));
      var big = h('div', 'big', p.item.name); big.id = 'pq-title'; left.appendChild(big);
      head.appendChild(left);
      var c = chip(p.status, p.overdue); c.className = 'chip on-dark'; head.appendChild(c);
      body.appendChild(head);
      var g = h('div', 'kv');
      [['Lokasi', p.location, 1], ['PPIC', p.ppic], ['Tim pemasang', p.install_team], ['Diajukan', fmtDT(p.submitted_at)], ['Rencana pasang', p.planned_install_at ? fmtDT(p.planned_install_at) : null],
       ['Dipasang', p.installed_at ? fmtDT(p.installed_at) : null], ['Jadwal cabut', p.due_at ? fmtDT(p.due_at) : null],
       ['Target', p.target_days + ' hari'], ['Aktual', p.actual_days === null ? '—' : p.actual_days + ' hari'], ['Dicabut', p.removed_at ? fmtDT(p.removed_at) : null],
       ['Tim pencabut', p.removal_team, 1], ['Tujuan setelah dicabut', destText(p), 1], ['Catatan', p.notes, 1], ['Diajukan oleh', p.requested_by ? p.requested_by.name : null, 1]].forEach(function (x) {
        if (x[1] === null || x[1] === undefined || x[1] === '' || x[1] === '—' && x[0] === 'Dipasang') { return; }
        var d = h('div', x[2] ? 'full' : ''); d.appendChild(h('small', '', x[0])); d.appendChild(h('strong', '', x[1])); g.appendChild(d);
      });
      body.appendChild(g);
      if (p.remaining_seconds !== null && p.due_at) {
        var cl = h('p', 'countdown-line'); cl.appendChild(countdown(p.remaining_seconds)); body.appendChild(cl);
      }
      if (p.history && p.history.length) {
        body.appendChild(h('hr'));
        body.appendChild(h('small', 'sheet-note', 'Riwayat permintaan'));
        var tl = h('div', 'items-col');
        p.history.forEach(function (x) {
          var line = h('div', 'item-line');
          var m = h('div', 'il-meta');
          m.appendChild(h('b', '', x.type === 'extend' ? 'Tambah waktu +' + x.days + ' hari' : 'Pencabutan · ' + (x.team || '—')));
          m.appendChild(h('span', '', (x.requested_by || '—') + ' · ' + fmt(x.requested_at)));
          if (x.type === 'extend' && x.status === 'approved' && x.old_due_at && x.new_due_at) {
            m.appendChild(h('span', '', 'Jadwal cabut: ' + fmtDT(x.old_due_at) + ' → ' + fmtDT(x.new_due_at)));
          }
          line.appendChild(m);
          var st = { pending: 'Menunggu', approved: 'Disetujui', rejected: 'Ditolak' }[x.status] || x.status;
          line.appendChild(h('span', 'chip ' + ({ pending: 'pending', approved: 'done', rejected: 'rejected' }[x.status] || ''), st));
          tl.appendChild(line);
        });
        body.appendChild(tl);
      }
      if (p.locations && p.locations.length) {
        body.appendChild(h('hr'));
        body.appendChild(h('small', 'sheet-note', 'Riwayat lokasi unit'));
        var lt = h('div', 'items-col');
        p.locations.forEach(function (x) {
          var line = h('div', 'item-line');
          var m = h('div', 'il-meta');
          m.appendChild(h('b', '', (EVENT[x.event] || x.event) + ' · ' + x.location + (x.to_location ? ' → ' + x.to_location : '')));
          m.appendChild(h('span', '', fmtDT(x.occurred_at) + (x.confirmed_by ? ' · ' + x.confirmed_by : '') + (x.note ? ' · ' + x.note : '')));
          line.appendChild(m); lt.appendChild(line);
        });
        body.appendChild(lt);
      }
      var close = btn('dark-btn block', 'Tutup'); close.setAttribute('data-close-sheet', ''); close.style.justifyContent = 'center';
      body.appendChild(close);
      if (!live) { ui.openSheet('sheet-pqa'); }   // pembaruan realtime: isi diganti tanpa membuka ulang / memindah fokus
    }).catch(function (e) { if (!live) { errToast(e, 'Gagal memuat detail'); } });
  }

  // ---------------------------------------------------------------- aksi -----------------
  var busy = false;
  function run(p, path, okTitle, body) {
    if (busy) { return; }
    busy = true;
    api.post(path, body === undefined ? {} : body).then(function () {
      busy = false; ui.toast(okTitle, '', 'ok'); ui.closeSheets(true); reload();
    }).catch(function (err) {
      busy = false; ui.closeSheets(true); reload(); errToast(err);
    });
  }
  function confirmDo(p, title, text, label, path, okTitle) {
    $('sc-title').textContent = title; $('sc-body').textContent = text;
    var ok = $('sc-ok'); ok.textContent = label; ok.disabled = false;
    ok.onclick = function () { ok.disabled = true; run(p, path, okTitle); };
    ui.openSheet('sheet-confirm');
  }

  // Formulir: pasang baru
  var newBtn = $('btn-new-pqa');
  if (newBtn) {
    newBtn.addEventListener('click', function () {
      var form = $('pqa-form'); form.reset(); api.showFieldErrors(form, {});
      $('pn-ppic').value = cfg.getAttribute('data-name') || '';
      (function () {
        var pad = function (n) { return (n < 10 ? '0' : '') + n; }, n = new Date();
        $('pn-when').value = n.getFullYear() + '-' + pad(n.getMonth() + 1) + '-' + pad(n.getDate()) + 'T' + pad(n.getHours()) + ':' + pad(n.getMinutes());
      })();
      var sel = $('pn-unit'); sel.textContent = ''; $('pn-submit').disabled = true;
      var hint = $('pn-unit-hint'); hint.textContent = 'Memuat unit…'; hint.classList.remove('err');
      ui.openSheet('sheet-pqa-new');
      loadUnits(false);
    });
    // Daftar unit di formulir; saat realtime, pilihan pengguna dipertahankan bila unitnya masih tersedia
    var loadUnits = function (live) {
      var sel = $('pn-unit'), hint = $('pn-unit-hint');
      var keep = sel.value, keepName = sel.selectedIndex >= 0 && sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].textContent : '';
      api.get('/pqa/units').then(function (units) {
        sel.textContent = '';
        var still = false;
        units.forEach(function (u) {
          var o = document.createElement('option'); o.value = String(u.id); o.textContent = u.name + ' (' + u.code + ')'; sel.appendChild(o);
          if (o.value === keep) { still = true; }
        });
        if (still) { sel.value = keep; }
        hint.classList.remove('err');
        hint.textContent = units.length ? '' : 'Tidak ada unit PQA yang tersedia saat ini.';
        if (live && keep && !still) {
          hint.textContent = keepName + ' baru saja tidak tersedia (dipakai orang lain). Pilih unit lain.';
          hint.classList.add('err');
        }
        $('pn-submit').disabled = !units.length;
      }).catch(function (e) { if (!live) { hint.textContent = e.message; hint.classList.add('err'); } });
    };
    window.hmePqaUnits = loadUnits;
    $('pqa-form').addEventListener('submit', function (e) {
      e.preventDefault();
      var form = e.target, local = {};
      var body = { item_id: parseInt($('pn-unit').value, 10) || 0, ppic: $('pn-ppic').value.trim(), location: $('pn-loc').value.trim(),
                   install_team: $('pn-team').value.trim(), target_days: parseInt($('pn-days').value, 10) || 0,
                   planned_install_at: $('pn-when').value, notes: $('pn-notes').value.trim() };
      if (!body.planned_install_at) { local.planned_install_at = 'Tanggal & jam pemasangan wajib diisi.'; }
      if (!body.item_id) { local.item_id = 'Pilih unit PQA.'; }
      if (body.ppic.length < 2) { local.ppic = 'PPIC wajib diisi.'; }
      if (body.location.length < 2) { local.location = 'Lokasi pemasangan wajib diisi.'; }
      if (body.install_team.length < 2) { local.install_team = 'Tim pemasang wajib diisi.'; }
      if (body.target_days < 1) { local.target_days = 'Isi target lebih dari 0 hari.'; }
      api.showFieldErrors(form, local);
      if (Object.keys(local).length) { return; }
      var b = $('pn-submit'); b.disabled = true;
      api.post('/pqa', body).then(function () {
        ui.closeSheets(true); ui.toast('Pengajuan terkirim', 'Menunggu persetujuan admin', 'ok'); reload();
      }).catch(function (err) {
        b.disabled = false;
        if (err.fields && Object.keys(err.fields).length) { api.showFieldErrors(form, err.fields); } else { errToast(err); }
      });
    });
  }

  // Formulir: tambah waktu
  var extFor = null;
  function openExt(p) {
    extFor = p; var form = $('pqa-ext-form'); form.reset(); api.showFieldErrors(form, {});
    $('pe-sub').textContent = p.item.name + ' · target ' + p.target_days + ' hari';
    ui.openSheet('sheet-pqa-ext');
  }
  $('pqa-ext-form').addEventListener('submit', function (e) {
    e.preventDefault();
    var form = e.target, d = parseInt($('pe-days').value, 10) || 0;
    if (d < 1) { api.showFieldErrors(form, { days: 'Masukkan jumlah hari yang valid.' }); return; }
    var b = $('pe-submit'); b.disabled = true;
    api.post('/pqa/' + extFor.id + '/extend', { days: d }).then(function () {
      b.disabled = false; ui.closeSheets(true); ui.toast('Permintaan terkirim', '+' + d + ' hari menunggu persetujuan', 'ok'); reload();
    }).catch(function (err) {
      b.disabled = false;
      if (err.fields && Object.keys(err.fields).length) { api.showFieldErrors(form, err.fields); } else { ui.closeSheets(true); reload(); errToast(err); }
    });
  });

  // Formulir: request cabut
  var rmFor = null;
  function openRm(p) {
    rmFor = p; var form = $('pqa-rm-form'); form.reset(); api.showFieldErrors(form, {});
    $('pr-sub').textContent = p.item.name + ' · ' + p.location;
    $('pr-site-wrap').hidden = true;
    ui.openSheet('sheet-pqa-rm');
  }
  $('pqa-rm-form').addEventListener('submit', function (e) {
    e.preventDefault();
    var form = e.target, t = $('pr-team').value.trim();
    if (t.length < 2) { api.showFieldErrors(form, { team: 'Tim pencabut wajib diisi.' }); return; }
    var b = $('pr-submit'); b.disabled = true;
    var dest = form.querySelector('input[name="destination"]:checked').value, payload = { team: t, destination: dest };
    if (dest === 'site') {
      payload.destination_site = $('pr-site').value.trim();
      payload.move_target_days = parseInt($('pr-mdays').value, 10) || 0;
      var le = {};
      if (payload.destination_site.length < 2) { le.destination_site = 'Site tujuan wajib diisi.'; }
      if (payload.move_target_days < 1) { le.move_target_days = 'Isi target lebih dari 0 hari.'; }
      if (Object.keys(le).length) { api.showFieldErrors(form, le); b.disabled = false; return; }
    }
    api.post('/pqa/' + rmFor.id + '/remove', payload).then(function () {
      b.disabled = false; ui.closeSheets(true); ui.toast('Permintaan pencabutan terkirim', 'Menunggu persetujuan admin', 'ok'); reload();
    }).catch(function (err) {
      b.disabled = false;
      if (err.fields && Object.keys(err.fields).length) { api.showFieldErrors(form, err.fields); } else { ui.closeSheets(true); reload(); errToast(err); }
    });
  });

  $('pqa-rm-form').addEventListener('change', function (e) {
    if (e.target && e.target.name === 'destination') { $('pr-site-wrap').hidden = e.target.value !== 'site'; }
  });

  // ---------------------------------------------------------------- daftar ---------------
  var q = '', histLoaded = 0, histTotal = 0, HLIMIT = 10;
  var token = 0;

  function fill(box, items, empty) {
    box.textContent = '';
    if (!items.length) {
      var c = h('div', 'card'), em = h('div', 'empty'); em.appendChild(h('p', '', empty)); c.appendChild(em); box.appendChild(c);
      return;
    }
    items.forEach(function (p) { box.appendChild(renderCard(p)); });
  }
  function skeleton(box) { box.textContent = ''; var s = h('div', 'card skeleton'); s.style.height = '120px'; box.appendChild(s); }
  function qs(group, limit, offset) {
    return '/pqa?group=' + group + '&limit=' + limit + '&offset=' + offset + (q ? '&q=' + encodeURIComponent(q) : '');
  }

  function reload() {
    var t = ++token;
    ['pqa-pending', 'pqa-active', 'pqa-history'].forEach(function (id) { if (!$(id).childElementCount) { skeleton($(id)); } });
    Promise.all([
      api.get(qs('pending', 50, 0)),
      api.get(qs('active', 100, 0)),
      api.get(qs('history', Math.max(HLIMIT, histLoaded), 0))
    ]).then(function (r) {
      if (t !== token) { return; }
      var pend = r[0], act = r[1], his = r[2];
      $('sec-pending').hidden = pend.total === 0;
      $('cnt-pending').textContent = String(pend.total);
      $('cnt-active').textContent = String(act.total) + ' unit';
      $('cnt-history').textContent = String(his.total);
      fill($('pqa-pending'), pend.items, '');
      fill($('pqa-active'), act.items, q ? 'Tidak ada unit yang cocok.' : 'Tidak ada unit PQA yang sedang terpasang di lapangan.');
      fill($('pqa-history'), his.items, q ? 'Tidak ada riwayat yang cocok.' : 'Belum ada riwayat pencabutan PQA.');
      histLoaded = his.items.length; histTotal = his.total;
      $('pqa-more').hidden = histLoaded >= histTotal;
    }).catch(function (e) { if (t === token) { errToast(e, 'Gagal memuat data'); } });
  }

  $('pqa-more').addEventListener('click', function () {
    api.get(qs('history', HLIMIT, histLoaded)).then(function (d) {
      d.items.forEach(function (p) { $('pqa-history').appendChild(renderCard(p)); });
      histLoaded += d.items.length;
      $('pqa-more').hidden = histLoaded >= d.total;
    }).catch(function (e) { errToast(e); });
  });
  var timer = null;
  $('pqa-q').addEventListener('input', function (e) {
    clearTimeout(timer);
    var v = e.target.value.trim();
    timer = setTimeout(function () { q = v; histLoaded = 0; reload(); }, 300);
  });

  reload();
  // Tautan dari notifikasi: /pqa?open=<id> membuka detail pemasangan itu
  var openId = new URLSearchParams(location.search).get('open') || '';
  if (/^[0-9]{1,10}$/.test(openId)) { openDetail(openId); }
  // Realtime: tracker dimuat ulang; detail terbuka & daftar unit di formulir ikut diperbarui tanpa mengosongkan isian
  if (window.hmeLive) {
    window.hmeLive.on(['pqa', 'item'], function (ch, reset) {
      reload();
      if (detailId && !busy && window.hmeLive.isOpen('sheet-pqa') && (reset || (ch.pqa || []).map(String).indexOf(detailId) !== -1)) { openDetail(detailId, true); }
      if (window.hmePqaUnits && window.hmeLive.isOpen('sheet-pqa-new')) { window.hmePqaUnits(true); }
    });
  }
  window.hmePqa = { reload: reload };
})();
