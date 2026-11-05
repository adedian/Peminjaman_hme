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


  // ================================================================== Notifikasi push ==========
  var pushForm = $('push-form');
  var logState = { status: '', offset: 0 };
  var EV_STATUS = { pending: ['Menunggu', 'pending'], sending: ['Mengirim', 'pending'], sent: ['Terkirim', 'done'], failed: ['Gagal', 'rejected'], gone: ['Berakhir', 'cancelled'], cancelled: ['Dibatalkan', 'cancelled'] };

  function renderPush(s) {
    var p = s.push;
    $('push-enabled').checked = s.push_enabled;
    $('push-loan-hours').value = s.loan_reminder_hours;
    $('push-pqa-hours').value = s.pqa_reminder_hours;
    $('push-max-days').value = s.loan_max_days;
    ['push-enabled', 'push-loan-hours', 'push-pqa-hours', 'push-max-days', 'push-save'].forEach(function (id) { $(id).disabled = false; });
    var box = $('push-status'); box.textContent = '';
    box.appendChild(fact('Server push (VAPID)', p.configured ? 'Siap' : 'BELUM dikonfigurasi'));
    box.appendChild(fact('Perangkat berlangganan', p.devices + ' perangkat · ' + p.users + ' pengguna'));
    box.appendChild(fact('Terkirim', String(p.sent)));
    box.appendChild(fact('Menunggu / gagal', p.pending + ' / ' + p.failed));
    box.appendChild(fact('Berakhir (perangkat hilang)', String(p.gone)));
    box.appendChild(fact('Terakhir berhasil', p.last_success_at ? p.last_success_at.slice(0, 16) : '—'));
    if (!p.configured) {
      var n = h('div', 'pqa-note warn', 'Kunci VAPID belum diisi di server, jadi notifikasi hanya tampil di lonceng aplikasi. Jalankan: php tools/generate-vapid.php --write');
      n.style.gridColumn = '1 / -1'; box.appendChild(n);
    }
  }

  function loadPush() {
    api.get('/settings').then(renderPush, function (err) { ui.toast('Pengaturan notifikasi gagal dimuat', err.message, 'err'); });
  }

  pushForm.addEventListener('submit', function (e) {
    e.preventDefault();
    var b = $('push-save'); b.disabled = true;
    api.showFieldErrors(pushForm, {});
    api.put('/settings', {
      push_enabled: $('push-enabled').checked,
      loan_reminder_hours: $('push-loan-hours').value, pqa_reminder_hours: $('push-pqa-hours').value, loan_max_days: $('push-max-days').value
    }).then(function (s) { renderPush(s); ui.toast('Pengaturan disimpan', '', 'ok'); }, function (err) {
      b.disabled = false;
      if (err.status === 422) { api.showFieldErrors(pushForm, err.fields); } else { ui.toast('Tidak dapat disimpan', err.message, 'err'); }
    });
  });

  // ---- log ---------------------------------------------------------------------------------
  function fmtT(s) { return s ? s.slice(0, 16).replace('T', ' ') : '—'; }
  function logRow(r) {
    var row = h('div', 'row'); row.style.cursor = 'default';
    var meta = h('span', 'meta');
    meta.appendChild(h('span', 'title', r.title + ' → ' + r.user));
    var sub = h('span', 'sub', fmtT(r.created_at) + ' · ' + (r.device || 'perangkat') + ' · percobaan ' + r.attempts + (r.http_status ? ' · HTTP ' + r.http_status : '') + (r.error ? ' · ' + r.error : ''));
    sub.style.display = 'block'; sub.style.whiteSpace = 'normal'; meta.appendChild(sub);
    row.appendChild(meta);
    var s = EV_STATUS[r.status] || [r.status, '']; row.appendChild(h('span', 'chip ' + s[1], s[0]));
    if (r.status === 'failed') {
      var b = h('button', 'mini-btn', 'Kirim ulang'); b.type = 'button';
      b.addEventListener('click', function () {
        b.disabled = true;
        api.post('/push/log/' + r.id + '/retry').then(function () { ui.toast('Dikirim ulang', '', 'ok'); loadLog(true); loadPush(); }, function (err) { b.disabled = false; ui.toast('Tidak dapat dikirim ulang', err.message, 'err'); });
      });
      row.appendChild(b);
    }
    return row;
  }
  function loadLog(reset) {
    if (reset) { logState.offset = 0; }
    api.get('/push/log?limit=20&offset=' + logState.offset + (logState.status ? '&status=' + logState.status : '')).then(function (d) {
      var box = $('push-log'); if (reset) { box.textContent = ''; }
      if (reset && !d.items.length) { box.appendChild(h('p', 'recap-note', 'Belum ada pengiriman push.')); }
      d.items.forEach(function (r) { box.appendChild(logRow(r)); });
      logState.offset += d.items.length;
      $('push-more').hidden = logState.offset >= d.total;
    }, function (err) { ui.toast('Log gagal dimuat', err.message, 'err'); });
  }
  $('push-more').addEventListener('click', function () { loadLog(false); });
  $('push-filter').addEventListener('click', function (e) {
    var b = e.target.closest('button[data-status]'); if (!b) { return; }
    logState.status = b.getAttribute('data-status');
    [].forEach.call($('push-filter').querySelectorAll('button'), function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
    loadLog(true);
  });

  loadPush();
  loadLog(true);

  load();
})();
