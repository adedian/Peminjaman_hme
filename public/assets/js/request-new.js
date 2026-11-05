/* Form pengajuan peminjaman (staff): pilih beberapa barang yang tersedia, isi detail, ajukan.
   Ketersediaan dicek ulang oleh server saat pengajuan (barang bisa saja diambil orang lain di antara waktu). */
(function () {
  'use strict';
  var api = window.hmeApi, ui = window.hme;
  var cfg = document.getElementById('rn-config');
  if (!api || !ui || !cfg) { return; }
  var $ = function (id) { return document.getElementById(id); };
  var base = (document.querySelector('meta[name="base-path"]') || {}).content || '';
  var NS = 'http://www.w3.org/2000/svg';

  var items = [], selected = {}, q = '';
  var list = $('pick-list'), form = $('rn-form');

  function h(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) { e.className = cls; }
    if (text !== undefined) { e.textContent = text; }
    return e;
  }
  function boxIcon() {
    var s = document.createElementNS(NS, 'svg');
    s.setAttribute('class', 'ico');
    var u = document.createElementNS(NS, 'use');
    u.setAttribute('href', '#i-box');
    s.appendChild(u);
    return s;
  }

  function row(it) {
    var lab = h('label', 'pick-row' + (selected[it.id] ? ' on' : ''));
    var cb = document.createElement('input');
    cb.type = 'checkbox';
    cb.checked = !!selected[it.id];
    cb.setAttribute('aria-label', it.name);
    cb.addEventListener('change', function () {
      if (cb.checked) { selected[it.id] = it; } else { delete selected[it.id]; }
      lab.classList.toggle('on', cb.checked);
      renderCart();
      $('pick-err').textContent = '';
    });
    lab.appendChild(cb);

    var th = h('span', 'thumb sq');
    if (it.thumb_url) {
      var img = document.createElement('img');
      img.src = it.thumb_url; img.alt = ''; img.loading = 'lazy';
      th.appendChild(img);
    } else { th.appendChild(boxIcon()); }
    lab.appendChild(th);

    var m = h('span', 'pick-meta');
    m.appendChild(h('b', '', it.name));
    m.appendChild(h('span', '', it.code + ' · ' + it.category_name));
    lab.appendChild(m);
    if (it.is_pqa) { lab.appendChild(h('span', 'chip info', 'PQA')); }
    return lab;
  }

  function render() {
    list.textContent = '';
    var needle = q.toLowerCase();
    var shown = items.filter(function (it) { return !needle || (it.name + ' ' + it.code).toLowerCase().indexOf(needle) !== -1; });
    shown.forEach(function (it) { list.appendChild(row(it)); });
    $('pick-empty').hidden = shown.length !== 0;
    $('pick-empty').textContent = items.length ? 'Tidak ada barang yang cocok.' : 'Tidak ada barang yang tersedia saat ini.';
  }

  function renderCart() {
    var ids = Object.keys(selected);
    $('cart').hidden = ids.length === 0;
    $('cart-count').textContent = ids.length + ' barang dipilih';
    var box = $('cart-items');
    box.textContent = '';
    ids.forEach(function (id) {
      var c = h('button', 'chip on-dark cart-chip', selected[id].name + ' ✕');
      c.type = 'button';
      c.setAttribute('aria-label', 'Batalkan pilihan ' + selected[id].name);
      c.addEventListener('click', function () { delete selected[id]; render(); renderCart(); });
      box.appendChild(c);
    });
  }

  function load(live) {
    return api.get('/items/available').then(function (d) {
      items = d;
      // Buang pilihan yang barangnya sudah tidak tersedia (mis. baru saja diambil orang lain) dan beri tahu pengguna
      var ids = {}, gone = [];
      d.forEach(function (it) { ids[it.id] = true; });
      Object.keys(selected).forEach(function (id) { if (!ids[id]) { gone.push(selected[id].name); delete selected[id]; } });
      var warn = $('cart-warn');
      if (warn && gone.length) {
        warn.textContent = gone.join(', ') + ' baru saja tidak tersedia (diajukan/dipinjam orang lain) dan dihapus dari pilihan Anda.';
        warn.hidden = false;
      }
      render();
      renderCart();
    }).catch(function (e) { if (!live) { list.textContent = ''; ui.toast('Gagal memuat barang', e.message, 'err'); } });
  }
  // Realtime: daftar barang tersedia diperbarui tanpa menyentuh isian formulir (lokasi, tim, keterangan, pencarian)
  if (window.hmeLive) { window.hmeLive.on(['item', 'request'], function () { load(true); }); }

  $('pick-q').addEventListener('input', function (e) { q = e.target.value.trim(); render(); });


  // Batas pengembalian: bawaan besok pukul 17.00 (hanya kenyamanan, bebas diubah); tidak boleh sebelum sekarang
  (function () {
    var f = $('f-due');
    if (!f) { return; }
    function loc(d) {
      var p = function (n) { return (n < 10 ? '0' : '') + n; };
      return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + 'T' + p(d.getHours()) + ':' + p(d.getMinutes());
    }
    var now = new Date(), t = new Date(now.getFullYear(), now.getMonth(), now.getDate() + 1, 17, 0);
    f.min = loc(now);
    f.value = loc(t);
    // Pintasan hanya mengisi kolom (jam 17.00 pada hari ke-N); pemohon tetap bebas mengubah tanggal & jam
    var chips = $('due-chips');
    if (chips) {
      chips.addEventListener('click', function (e) {
        var b = e.target.closest('button[data-days]');
        if (!b) { return; }
        var n = new Date(), d = new Date(n.getFullYear(), n.getMonth(), n.getDate() + parseInt(b.getAttribute('data-days'), 10), 17, 0);
        f.value = loc(d);
        [].forEach.call(chips.querySelectorAll('button'), function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
        f.dispatchEvent(new Event('input', { bubbles: true }));
      });
      f.addEventListener('input', function (ev) {
        if (ev.isTrusted) { [].forEach.call(chips.querySelectorAll('button'), function (x) { x.setAttribute('aria-pressed', 'false'); }); }
      });
    }
  })();

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var ids = Object.keys(selected).map(Number);
    var body = {
      item_ids: ids,
      borrow_date: $('f-date').value,
      due_at: $('f-due').value,
      location: $('f-loc').value.trim(),
      team: $('f-team').value.trim(),
      notes: $('f-notes').value.trim()
    };
    var local = {};
    if (!ids.length) { $('pick-err').textContent = 'Pilih minimal satu barang.'; $('pick-err').classList.add('err'); }
    if (body.location.length < 2) { local.location = 'Lokasi tujuan wajib diisi.'; }
    if (!body.borrow_date) { local.borrow_date = 'Tanggal pinjam wajib diisi.'; }
    if (!body.due_at) { local.due_at = 'Batas pengembalian wajib diisi.'; }
    else if (new Date(body.due_at).getTime() <= Date.now()) { local.due_at = 'Batas pengembalian harus setelah saat ini.'; }
    api.showFieldErrors(form, local);
    if (!ids.length || Object.keys(local).length) {
      if (!ids.length) { $('pick-q').scrollIntoView({ block: 'center', behavior: 'smooth' }); }
      return;
    }

    var btn = $('rn-submit');
    btn.disabled = true;
    api.post('/requests', body).then(function () {
      ui.flashNext('Pengajuan terkirim', 'Menunggu persetujuan admin', 'ok');
      window.location.href = base + '/requests';
    }).catch(function (err) {
      btn.disabled = false;
      if (err.code === 'has_active_loan') { window.location.reload(); return; }
      if (err.code === 'item_unavailable') {
        $('pick-err').textContent = err.message;
        $('pick-err').classList.add('err');
        ui.toast('Barang tidak tersedia', err.message, 'err');
        load();
        return;
      }
      if (err.fields && err.fields.item_ids) { $('pick-err').textContent = err.fields.item_ids; $('pick-err').classList.add('err'); }
      if (err.fields && Object.keys(err.fields).length) { api.showFieldErrors(form, err.fields); }
      else { ui.toast('Gagal mengajukan', err.message, 'err'); }
    });
  });

  load();
})();
