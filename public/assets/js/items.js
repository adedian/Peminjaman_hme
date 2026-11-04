/* Halaman Master Barang (admin): cari/filter, tambah, ubah (termasuk foto), nonaktifkan/aktifkan.
   Semua aturan ditegakkan ulang oleh server; pengecekan di sini hanya kenyamanan. */
(function () {
  'use strict';
  var api = window.hmeApi, ui = window.hme;
  var grid = document.getElementById('item-grid');
  if (!api || !ui || !grid) { return; }

  var $ = function (id) { return document.getElementById(id); };
  var form = $('item-form'), current = null, mode = 'create', confirmFn = null;
  var chosen = null, previewUrl = null, pqaTouched = false;
  var MAX_SOURCE = 15 * 1024 * 1024;     // foto asli dari kamera boleh besar; diperkecil sebelum dikirim
  var TYPES = ['image/jpeg', 'image/png', 'image/webp'];

  // ---- Cari & filter -----------------------------------------------------------------------
  var f = { q: '', cat: '', status: '' };
  function applyFilters() {
    var shown = 0;
    grid.querySelectorAll('.inv-card').forEach(function (c) {
      var ok = (!f.q || c.getAttribute('data-search').indexOf(f.q) !== -1) &&
               (!f.cat || c.getAttribute('data-cat') === f.cat) &&
               (!f.status || c.getAttribute('data-state') === f.status);
      c.hidden = !ok;
      if (ok) { shown++; }
    });
    $('item-empty').hidden = shown !== 0 || !grid.children.length;
  }
  $('item-filter').addEventListener('input', function (e) { f.q = e.target.value.trim().toLowerCase(); applyFilters(); });
  $('cat-filter').addEventListener('change', function (e) { f.cat = e.target.value; applyFilters(); });
  $('status-filter').addEventListener('click', function (e) {
    var b = e.target.closest('button[data-status]');
    if (!b) { return; }
    $('status-filter').querySelectorAll('button').forEach(function (x) { x.setAttribute('aria-pressed', 'false'); });
    b.setAttribute('aria-pressed', 'true');
    f.status = b.getAttribute('data-status');
    applyFilters();
  });

  // ---- Kartu → menu aksi -------------------------------------------------------------------
  grid.addEventListener('click', function (e) {
    var card = e.target.closest('.inv-card');
    if (!card) { return; }
    try { current = JSON.parse(card.getAttribute('data-item')); } catch (err) { return; }
    $('sa-title').textContent = current.name;
    $('sa-sub').textContent = current.code + (current.active ? '' : ' · Nonaktif');
    var note = $('sa-note'), t = $('act-toggle');
    note.hidden = true;
    t.textContent = current.active ? 'Nonaktifkan barang' : 'Aktifkan kembali';
    t.className = 'btn ' + (current.active ? 'danger' : 'success');
    t.disabled = false;
    if (current.active && current.in_use) {
      t.disabled = true;
      note.hidden = false;
      note.textContent = 'Barang ini sedang dipinjam atau memiliki pengajuan/pemasangan aktif sehingga belum dapat dinonaktifkan.';
    }
    ui.openSheet('sheet-actions');
  });

  // ---- Formulir ----------------------------------------------------------------------------
  function setPreview(src) {
    var box = $('photo-preview');
    box.textContent = '';
    if (src) {
      var img = document.createElement('img');
      img.alt = '';
      img.src = src;
      box.appendChild(img);
    } else {
      var ns = 'http://www.w3.org/2000/svg';
      var svg = document.createElementNS(ns, 'svg');
      svg.setAttribute('class', 'ico');
      var use = document.createElementNS(ns, 'use');
      use.setAttribute('href', '#i-image');
      svg.appendChild(use);
      box.appendChild(svg);
    }
  }
  function releasePreview() { if (previewUrl) { URL.revokeObjectURL(previewUrl); previewUrl = null; } }

  function openForm(m) {
    mode = m;
    form.reset();
    chosen = null;
    pqaTouched = false;
    releasePreview();
    api.showFieldErrors(form, {});
    form.querySelectorAll('[aria-invalid]').forEach(function (i) { i.removeAttribute('aria-invalid'); });
    var editing = m === 'edit';
    $('si-title').textContent = editing ? 'Ubah barang' : 'Tambah barang';
    $('si-sub').textContent = editing ? current.code : 'Barang baru';
    $('photo-label').textContent = 'Pilih foto';
    $('remove-wrap').hidden = !(editing && current.photo);
    $('f-remove').checked = false;
    $('f-code').disabled = false;
    $('code-hint').setAttribute('data-default', 'Unik. Tidak dapat diubah setelah barang punya riwayat.');
    $('code-hint').textContent = $('code-hint').getAttribute('data-default');
    if (editing) {
      $('f-code').value = current.code;
      $('f-name').value = current.name;
      $('f-cat').value = String(current.category_id);
      $('f-ref').value = current.finance_ref || '';
      $('f-pqa').checked = !!current.is_pqa;
      setPreview(current.photo);
      if (current.has_history) {
        $('f-code').disabled = true;
        $('code-hint').textContent = 'ID tidak dapat diubah karena barang ini sudah memiliki riwayat transaksi.';
        $('code-hint').setAttribute('data-default', $('code-hint').textContent);
      }
    } else {
      setPreview(null);
    }
    $('si-submit').disabled = false;
    $('si-submit').textContent = 'Simpan';
    ui.openSheet('sheet-item');
  }
  $('btn-add-item').addEventListener('click', function () { current = null; openForm('create'); });
  // Dari Register Inventaris ("Jadikan Master Barang", Q2): /items?new=1&name=… membuka form tambah dengan nama terisi.
  // ID barang & kategori tetap diisi admin.
  (function () {
    var qs = new URLSearchParams(window.location.search);
    if (qs.get('new') !== '1') { return; }
    current = null; openForm('create');
    $('f-name').value = (qs.get('name') || '').slice(0, 150);
    try { history.replaceState(null, '', window.location.pathname); } catch (e) { /* abaikan */ }
    setTimeout(function () { try { $('f-code').focus(); } catch (e) { /* abaikan */ } }, 150);
  })();
  $('act-edit').addEventListener('click', function () { openForm('edit'); });

  $('f-pqa').addEventListener('change', function () { pqaTouched = true; });
  $('f-name').addEventListener('input', function () {
    if (mode === 'create' && !pqaTouched) { $('f-pqa').checked = /pqa/i.test($('f-name').value); }
  });

  $('f-photo').addEventListener('change', function (e) {
    var file = e.target.files && e.target.files[0];
    var hint = form.querySelector('[data-error-for="photo"]');
    api.showFieldErrors(form, {});
    chosen = null;
    if (!file) { return; }
    if (TYPES.indexOf(file.type) === -1) {
      hint.textContent = 'Format foto harus JPG, PNG, atau WebP.'; hint.classList.add('err'); e.target.value = ''; return;
    }
    if (file.size > MAX_SOURCE) {
      hint.textContent = 'Foto terlalu besar (maksimal 15 MB).'; hint.classList.add('err'); e.target.value = ''; return;
    }
    chosen = file;
    releasePreview();
    previewUrl = URL.createObjectURL(file);
    setPreview(previewUrl);
    $('photo-label').textContent = 'Ganti foto';
    $('f-remove').checked = false;
  });

  /** Perkecil di sisi klien (hemat data seluler & mempercepat unggah); server tetap memvalidasi & mengolah ulang. */
  function downscale(file, maxSide) {
    return new Promise(function (resolve) {
      var done = function (blob) { resolve(blob || file); };
      var img = new Image();
      var url = URL.createObjectURL(file);
      img.onload = function () {
        URL.revokeObjectURL(url);
        var w = img.naturalWidth, h = img.naturalHeight, long = Math.max(w, h);
        if (!w || !h) { return done(null); }
        var s = long > maxSide ? maxSide / long : 1;
        var cw = Math.max(1, Math.round(w * s)), ch = Math.max(1, Math.round(h * s));
        if (s === 1 && file.size <= 1.5 * 1024 * 1024) { return done(null); }   // sudah kecil
        var c = document.createElement('canvas');
        c.width = cw; c.height = ch;
        var ctx = c.getContext('2d');
        if (!ctx) { return done(null); }
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, cw, ch);
        ctx.drawImage(img, 0, 0, cw, ch);
        if (!c.toBlob) { return done(null); }
        c.toBlob(function (b) { done(b && b.size < file.size ? b : null); }, 'image/jpeg', 0.85);
      };
      img.onerror = function () { URL.revokeObjectURL(url); done(null); };
      img.src = url;
    });
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var v = {
      code: mode === 'edit' ? current.code : $('f-code').value.trim(),
      name: $('f-name').value.trim(),
      category_id: $('f-cat').value,
      finance_ref: $('f-ref').value.trim(),
    };
    var local = {};
    if (!/^[A-Za-z0-9][A-Za-z0-9._-]{1,49}$/.test(v.code)) { local.code = 'ID 2–50 karakter: huruf, angka, titik, strip, garis bawah.'; }
    if (mode === 'edit' && !current.has_history) { v.code = $('f-code').value.trim(); if (!/^[A-Za-z0-9][A-Za-z0-9._-]{1,49}$/.test(v.code)) { local.code = 'ID 2–50 karakter: huruf, angka, titik, strip, garis bawah.'; } }
    if (v.name.length < 2) { local.name = 'Nama alat minimal 2 karakter.'; }
    if (Object.keys(local).length) { api.showFieldErrors(form, local); return; }

    var btn = $('si-submit');
    btn.disabled = true;
    btn.textContent = 'Menyimpan…';

    var prep = chosen ? downscale(chosen, 1600) : Promise.resolve(null);
    prep.then(function (photo) {
      var fd = new FormData();
      fd.append('code', v.code);
      fd.append('name', v.name);
      fd.append('category_id', v.category_id);
      if (v.finance_ref) { fd.append('finance_ref', v.finance_ref); }
      fd.append('is_pqa', $('f-pqa').checked ? '1' : '0');
      if (mode === 'edit' && $('f-remove').checked && !chosen) { fd.append('remove_photo', '1'); }
      if (chosen) { fd.append('photo', photo || chosen, 'foto.' + (photo && photo.type === 'image/jpeg' ? 'jpg' : (chosen.name.split('.').pop() || 'jpg'))); }
      return mode === 'create' ? api.postForm('/items', fd) : api.postForm('/items/' + current.id, fd);
    }).then(function () {
      ui.flashNext(mode === 'create' ? 'Barang ditambahkan' : 'Perubahan disimpan', v.name, 'ok');
      window.location.reload();
    }).catch(function (err) {
      btn.disabled = false;
      btn.textContent = 'Simpan';
      if (err.fields && Object.keys(err.fields).length) { api.showFieldErrors(form, err.fields); }
      else { ui.toast('Gagal menyimpan', err.message, 'err'); }
    });
  });

  // ---- Nonaktifkan / aktifkan ---------------------------------------------------------------
  function ask(title, body, okLabel, fn) {
    $('sc-title').textContent = title;
    $('sc-body').textContent = body;
    $('sc-ok').textContent = okLabel;
    $('sc-ok').disabled = false;
    confirmFn = fn;
    ui.openSheet('sheet-confirm');
  }
  $('sc-ok').addEventListener('click', function () {
    if (!confirmFn) { return; }
    this.disabled = true;
    var fn = confirmFn;
    confirmFn = null;
    fn();
  });
  function fail(err) { ui.closeSheets(true); ui.toast('Tidak dapat diproses', err.message, 'err'); }

  $('act-toggle').addEventListener('click', function () {
    var it = current;
    if (it.active) {
      ask('Nonaktifkan ' + it.name + '?', 'Barang tidak lagi muncul di form peminjaman. Riwayat transaksi tetap tersimpan dan barang dapat diaktifkan kembali kapan saja.', 'Ya, nonaktifkan', function () {
        api.post('/items/' + it.id + '/deactivate').then(function () {
          ui.flashNext('Barang dinonaktifkan', it.name, 'ok'); window.location.reload();
        }).catch(fail);
      });
    } else {
      ask('Aktifkan kembali ' + it.name + '?', 'Barang akan muncul lagi di form peminjaman.', 'Ya, aktifkan', function () {
        api.post('/items/' + it.id + '/activate').then(function () {
          ui.flashNext('Barang diaktifkan', it.name, 'ok'); window.location.reload();
        }).catch(fail);
      });
    }
  });

  // Realtime: status/ketersediaan barang berubah (dipinjam, dikembalikan, diubah admin lain) → kartu diganti versi terbaru
  // dari server; filter yang sedang dipakai diterapkan ulang. Formulir di sheet tidak disentuh.
  if (window.hmeLive) { window.hmeLive.on(['item'], function () { window.hmeLive.swap('item-grid', applyFilters); }); }
})();
