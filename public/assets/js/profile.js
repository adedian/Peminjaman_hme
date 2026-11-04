/* Ganti password lewat API (peningkatan progresif; tanpa JS formulir tetap dikirim biasa). */
(function () {
  'use strict';
  var form = document.getElementById('pw-form');
  if (!form || !window.hmeApi) { return; }
  var btn = document.getElementById('pw-submit');

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var fd = new FormData(form);
    var current = String(fd.get('current') || '');
    var next = String(fd.get('new') || '');
    var confirm = String(fd.get('confirm') || '');

    var local = {};
    if (!current) { local.current = 'Password saat ini wajib diisi.'; }
    if (!next) { local['new'] = 'Password baru wajib diisi.'; }
    if (next && next === current) { local['new'] = 'Password baru harus berbeda dari password lama.'; }
    if (next && next !== confirm) { local.confirm = 'Konfirmasi password tidak cocok.'; }
    if (Object.keys(local).length) { window.hmeApi.showFieldErrors(form, local); return; }

    btn.disabled = true;
    btn.textContent = 'Menyimpan…';
    window.hmeApi.post('/me/password', { current: current, 'new': next, confirm: confirm }).then(function (d) {
      window.hme.flashNext('Password diperbarui', 'Gunakan password baru saat masuk berikutnya.', 'ok');
      window.location.href = (d && d.redirect) || '/';
    }).catch(function (err) {
      btn.disabled = false;
      btn.textContent = 'Simpan password';
      if (err.fields && Object.keys(err.fields).length) {
        window.hmeApi.showFieldErrors(form, err.fields);
      } else {
        window.hme.toast('Gagal menyimpan', err.message, 'err');
      }
    });
  });
})();
