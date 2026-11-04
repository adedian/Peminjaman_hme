/* Halaman Pengguna (admin): tambah, ubah, reset password, nonaktifkan/aktifkan. Semua aksi lewat API dan
   diotorisasi ulang oleh server; tombol yang disembunyikan di sini hanya kenyamanan. */
(function () {
  'use strict';
  var api = window.hmeApi, ui = window.hme;
  var list = document.getElementById('user-list');
  if (!api || !ui || !list) { return; }
  var self = parseInt(list.getAttribute('data-self'), 10);

  var $ = function (id) { return document.getElementById(id); };
  var form = $('user-form'), current = null, mode = 'create', confirmFn = null;

  // ---- Pencarian ---------------------------------------------------------------------------
  function applyFilter() {
    var q = $('user-filter').value.trim().toLowerCase();
    list.querySelectorAll('.user-row').forEach(function (r) {
      r.hidden = q !== '' && r.getAttribute('data-search').indexOf(q) === -1;
    });
  }
  $('user-filter').addEventListener('input', applyFilter);
  // Realtime: akun ditambah/diubah admin lain → daftar diganti versi terbaru, pencarian diterapkan ulang
  if (window.hmeLive) { window.hmeLive.on(['user'], function () { window.hmeLive.swap('user-list', applyFilter); }); }

  // ---- Baris → menu aksi -------------------------------------------------------------------
  list.addEventListener('click', function (e) {
    var row = e.target.closest('.user-row');
    if (!row) { return; }
    try { current = JSON.parse(row.getAttribute('data-user')); } catch (err) { return; }
    var isSelf = current.id === self;
    $('sa-title').textContent = current.name;
    $('sa-sub').textContent = '@' + current.username + ' · ' + (current.role === 'admin' ? 'Admin' : 'Staff') + (current.active ? '' : ' · Nonaktif');
    var note = $('sa-note');
    note.hidden = true;
    if (isSelf) {
      note.hidden = false;
      note.textContent = 'Ini akun Anda. Untuk mengganti password gunakan menu Profil; peran dan status akun sendiri tidak dapat diubah.';
    } else if (current.open > 0 && current.active) {
      note.hidden = false;
      note.textContent = 'Pengguna ini masih memiliki ' + current.open + ' pengajuan/pinjaman aktif sehingga belum dapat dinonaktifkan.';
    }
    $('act-reset').hidden = isSelf;
    var t = $('act-toggle');
    t.hidden = isSelf;
    t.textContent = current.active ? 'Nonaktifkan akun' : 'Aktifkan kembali';
    t.className = 'btn ' + (current.active ? 'danger' : 'success');
    t.disabled = false;
    ui.openSheet('sheet-actions');
  });

  // ---- Formulir tambah / ubah --------------------------------------------------------------
  function openForm(m) {
    mode = m;
    form.reset();
    api.showFieldErrors(form, {});
    form.querySelectorAll('[aria-invalid]').forEach(function (i) { i.removeAttribute('aria-invalid'); });
    var editing = m === 'edit';
    $('su-title').textContent = editing ? 'Ubah pengguna' : 'Tambah pengguna';
    $('su-sub').textContent = editing ? '@' + current.username : 'Pengguna baru';
    $('f-username').disabled = editing;
    $('f-username-wrap').hidden = editing;
    $('f-password-wrap').hidden = editing;
    if (editing) {
      $('f-name').value = current.name;
      $('f-role').value = current.role;
      $('f-role').disabled = current.id === self;
    } else {
      $('f-role').disabled = false;
    }
    $('su-submit').disabled = false;
    $('su-submit').textContent = 'Simpan';
    ui.openSheet('sheet-user');
  }
  $('btn-add-user').addEventListener('click', function () { current = null; openForm('create'); });
  $('act-edit').addEventListener('click', function () { openForm('edit'); });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var body = { name: $('f-name').value.trim(), role: $('f-role').value };
    var local = {};
    if (body.name.length < 2) { local.name = 'Nama lengkap minimal 2 karakter.'; }
    if (mode === 'create') {
      body.username = $('f-username').value.trim().toLowerCase();
      var pw = $('f-password').value;
      if (pw !== '') { body.password = pw; }
      if (!/^[a-z0-9._-]{3,50}$/.test(body.username)) { local.username = 'Username 3–50 karakter: huruf, angka, titik, strip, garis bawah.'; }
    }
    if (Object.keys(local).length) { api.showFieldErrors(form, local); return; }

    var btn = $('su-submit');
    btn.disabled = true;
    btn.textContent = 'Menyimpan…';
    var req = mode === 'create' ? api.post('/users', body) : api.put('/users/' + current.id, body);
    req.then(function (d) {
      if (mode === 'create' && d.temporary_password) {
        showSecret('Pengguna ditambahkan', '@' + d.user.username, d.temporary_password);
      } else {
        ui.flashNext(mode === 'create' ? 'Pengguna ditambahkan' : 'Perubahan disimpan', '', 'ok');
        window.location.reload();
      }
    }).catch(function (err) {
      btn.disabled = false;
      btn.textContent = 'Simpan';
      if (err.fields && Object.keys(err.fields).length) { api.showFieldErrors(form, err.fields); }
      else { ui.toast('Gagal menyimpan', err.message, 'err'); }
    });
  });

  // ---- Konfirmasi (reset / nonaktifkan / aktifkan) ----------------------------------------
  function ask(title, body, okLabel, fn) {
    $('sc-title').textContent = title;
    $('sc-body').textContent = body;
    var ok = $('sc-ok');
    ok.textContent = okLabel;
    ok.disabled = false;
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

  function fail(err) {
    ui.closeSheets(true);
    ui.toast('Tidak dapat diproses', err.message, 'err');
  }

  $('act-toggle').addEventListener('click', function () {
    var u = current;
    if (u.active) {
      ask('Nonaktifkan ' + u.name + '?', 'Akun tidak dapat masuk lagi dan sesi yang sedang aktif langsung berakhir. Riwayat transaksi tetap tersimpan, dan akun dapat diaktifkan kembali kapan saja.', 'Ya, nonaktifkan', function () {
        api.post('/users/' + u.id + '/deactivate').then(function () {
          ui.flashNext('Akun dinonaktifkan', u.name, 'ok'); window.location.reload();
        }).catch(fail);
      });
    } else {
      ask('Aktifkan kembali ' + u.name + '?', 'Pengguna dapat masuk lagi dengan password yang dimilikinya.', 'Ya, aktifkan', function () {
        api.post('/users/' + u.id + '/activate').then(function () {
          ui.flashNext('Akun diaktifkan', u.name, 'ok'); window.location.reload();
        }).catch(fail);
      });
    }
  });

  $('act-reset').addEventListener('click', function () {
    var u = current;
    ask('Reset password ' + u.name + '?', 'Password lama tidak berlaku lagi, sesi aktif pengguna berakhir, dan pengguna wajib membuat password baru saat masuk. Password sementara akan ditampilkan sekali.', 'Ya, reset password', function () {
      api.post('/users/' + u.id + '/reset-password').then(function (d) {
        showSecret('Password direset', '@' + u.username, d.temporary_password);
      }).catch(fail);
    });
  });

  // ---- Password sementara (sekali tampil) --------------------------------------------------
  function showSecret(title, sub, pass) {
    $('ss-title').textContent = title;
    $('ss-sub').textContent = sub;
    $('ss-pass').textContent = pass;
    ui.openSheet('sheet-secret');
  }
  $('ss-copy').addEventListener('click', function () {
    var text = $('ss-pass').textContent;
    var done = function () { ui.toast('Password disalin', '', 'ok'); };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done, function () { selectText($('ss-pass')); });
    } else {
      selectText($('ss-pass'));
    }
  });
  function selectText(node) {
    var r = document.createRange();
    r.selectNodeContents(node);
    var s = window.getSelection();
    s.removeAllRanges();
    s.addRange(r);
    ui.toast('Tekan Salin pada perangkat', 'Teks sudah dipilih', '');
  }
  function finishSecret() {
    $('ss-pass').textContent = '';
    window.location.reload();
  }
  $('ss-done').addEventListener('click', finishSecret);
  $('sheet-secret').addEventListener('sheet:close', finishSecret);
})();
