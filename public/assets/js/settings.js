/* Pengaturan (admin): email penerima notifikasi + status SMTP + email uji. Render dengan DOM + textContent saja. */
(function () {
  'use strict';
  var api = window.hmeApi, ui = window.hme;
  var form = document.getElementById('set-form');
  if (!api || !ui || !form) { return; }
  var $ = function (id) { return document.getElementById(id); };

  function h(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) { e.className = cls; }
    if (text !== undefined && text !== null) { e.textContent = text; }
    return e;
  }
  function fact(label, value) {
    var d = h('div'); d.appendChild(h('small', '', label)); d.appendChild(h('strong', '', value)); return d;
  }

  function render(s) {
    $('set-email').value = s.admin_notification_email;
    ['set-email', 'set-save'].forEach(function (id) { $(id).disabled = false; });
    $('set-test').disabled = !s.mail.configured || !s.recipients.length;
    $('set-test').title = s.mail.configured ? '' : 'SMTP belum dikonfigurasi di .env';
    var box = $('set-status'); box.textContent = '';
    var mode = { smtp: 'SMTP aktif', log: 'Mode log (development)', none: 'Belum dikonfigurasi' }[s.mail.driver] || s.mail.driver;
    box.appendChild(fact('Pengiriman', mode + (s.mail.host ? ' · ' + s.mail.host : '')));
    box.appendChild(fact('Pengirim', s.mail.configured ? s.mail.from : '—'));
    box.appendChild(fact('Terkirim', String(s.mail.counts.sent)));
    box.appendChild(fact('Menunggu / gagal', s.mail.counts.pending + ' / ' + s.mail.counts.failed));
    box.appendChild(fact('Dilewati (SMTP kosong)', String(s.mail.counts.skipped)));
    if (s.mail.last_error) {
      var w = h('div', 'pqa-note warn', 'Kesalahan terakhir: ' + s.mail.last_error.message);
      w.style.gridColumn = '1 / -1';
      box.appendChild(w);
    }
  }

  function load() {
    api.get('/settings').then(render, function (err) { ui.toast('Pengaturan gagal dimuat', err.message, 'err'); });
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var b = $('set-save'); b.disabled = true;
    api.showFieldErrors(form, {});
    api.put('/settings', { admin_notification_email: $('set-email').value }).then(function (s) {
      render(s); ui.toast('Pengaturan disimpan', s.recipients.join(', '), 'ok');
    }, function (err) {
      b.disabled = false;
      if (err.status === 422) { api.showFieldErrors(form, err.fields); } else { ui.toast('Tidak dapat disimpan', err.message, 'err'); }
    });
  });

  $('set-test').addEventListener('click', function () {
    var b = this; b.disabled = true;
    api.post('/settings/test-email').then(function (r) {
      ui.toast('Email uji terkirim', r.sent_to.join(', '), 'ok');
    }, function (err) {
      ui.toast('Email uji gagal', err.message, 'err');
    }).then(function () { b.disabled = false; load(); });
  });

  load();
})();
