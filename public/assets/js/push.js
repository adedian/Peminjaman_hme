/* Notifikasi push di Profil: aktifkan/matikan di perangkat ini, notifikasi uji, daftar perangkat, dan tombol "Pasang aplikasi".
   Semua izin diminta HANYA setelah pengguna menekan tombol (syarat browser). DOM + textContent saja (CSP, tanpa innerHTML). */
(function () {
  'use strict';
  var api = window.hmeApi, ui = window.hme, pwa = window.hmePwa;
  var card = document.getElementById('push-card');
  if (!api || !ui || !card) { return; }
  var $ = function (id) { return document.getElementById(id); };
  var publicKey = null;

  var supported = ('serviceWorker' in navigator) && ('PushManager' in window) && ('Notification' in window) && window.isSecureContext;

  function h(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) { e.className = cls; }
    if (text !== undefined && text !== null) { e.textContent = text; }
    return e;
  }
  function u8(b64u) {
    var pad = '='.repeat((4 - b64u.length % 4) % 4);
    var raw = atob((b64u + pad).replace(/-/g, '+').replace(/_/g, '/'));
    var out = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) { out[i] = raw.charCodeAt(i); }
    return out;
  }
  function sameKey(sub) {
    try {
      var k = sub.options && sub.options.applicationServerKey;
      if (!k) { return true; }
      var a = new Uint8Array(k), b = u8(publicKey);
      if (a.length !== b.length) { return false; }
      for (var i = 0; i < a.length; i++) { if (a[i] !== b[i]) { return false; } }
      return true;
    } catch (e) { return true; }
  }

  function state(kind, text) {
    var box = $('push-state');
    box.textContent = '';
    var chip = h('span', 'chip ' + kind, text);
    box.appendChild(chip);
  }
  function help(text) { $('push-help').textContent = text || ''; }
  function show(id, on) { $(id).hidden = !on; }

  function currentSub() {
    return navigator.serviceWorker.ready.then(function (reg) { return reg.pushManager.getSubscription().then(function (s) { return { reg: reg, sub: s }; }); });
  }

  function loadDevices() {
    api.get('/push/devices').then(function (list) {
      var ul = $('push-devices'); ul.textContent = '';
      list.forEach(function (d) {
        var row = h('div', 'row'); row.style.cursor = 'default';
        var meta = h('span', 'meta');
        meta.appendChild(h('span', 'title', d.label));
        meta.appendChild(h('span', 'sub', (d.active ? 'Aktif' : 'Tidak aktif') + (d.last_success_at ? ' · terakhir menerima ' + d.last_success_at.slice(0, 16) : ' · belum pernah menerima')));
        row.appendChild(meta);
        var b = h('button', 'mini-btn', 'Hapus'); b.type = 'button';
        b.addEventListener('click', function () {
          b.disabled = true;
          api.post('/push/devices/' + d.id + '/remove').then(function () { refresh(); }, function (e) { b.disabled = false; ui.toast('Tidak dapat menghapus', e.message, 'err'); });
        });
        row.appendChild(b);
        ul.appendChild(row);
      });
      show('push-devices-wrap', list.length > 0);
    }, function () { /* biarkan */ });
  }

  /** Muat ulang status tombol sesuai kondisi perangkat. */
  function refresh() {
    show('push-on', false); show('push-off', false); show('push-test', false);
    help('');
    if (!supported) {
      var ios = pwa && pwa.isIos && pwa.isIos();
      state('cancelled', 'Tidak didukung');
      help(ios && !(pwa && pwa.isStandalone())
        ? 'Di iPhone/iPad: ketuk Bagikan → "Tambah ke Layar Utama", lalu buka aplikasi dari ikon di layar utama dan aktifkan notifikasi di sini.'
        : 'Peramban ini belum mendukung notifikasi push, atau situs belum dibuka lewat HTTPS. Pakai Chrome/Edge/Firefox terbaru atau pasang aplikasinya.');
      return;
    }
    api.get('/push/config').then(function (cfg) {
      if (!cfg.configured) {
        state('cancelled', 'Belum aktif di server');
        help('Admin belum mengaktifkan notifikasi HP di server. Notifikasi tetap tampil di lonceng aplikasi.');
        return;
      }
      publicKey = cfg.public_key;
      if (Notification.permission === 'denied') {
        state('rejected', 'Diblokir');
        help('Notifikasi diblokir untuk situs ini. Buka pengaturan situs di peramban (ikon gembok) lalu izinkan Notifikasi.');
        return;
      }
      currentSub().then(function (r) {
        if (r.sub && Notification.permission === 'granted' && sameKey(r.sub)) {
          // pastikan server tahu perangkat ini milik pengguna yang sedang masuk (mis. setelah ganti akun di perangkat yang sama)
          api.post('/push/subscribe', r.sub.toJSON()).catch(function () { /* abaikan */ });
          state('done', 'Aktif di perangkat ini');
          show('push-off', true); show('push-test', true);
        } else {
          state('pending', 'Belum aktif di perangkat ini');
          show('push-on', true);
          if (pwa && pwa.isIos && pwa.isIos() && !pwa.isStandalone()) {
            help('Di iPhone/iPad notifikasi hanya bisa diaktifkan dari aplikasi yang sudah dipasang di Layar Utama.');
          }
        }
        loadDevices();
      }, function () { state('cancelled', 'Service worker belum siap'); help('Muat ulang halaman, lalu coba lagi.'); });
    }, function (e) { state('cancelled', 'Gagal memuat'); help(e.message); });
  }

  function enable() {
    var b = $('push-on'); b.disabled = true;
    Notification.requestPermission().then(function (perm) {
      if (perm !== 'granted') { throw new Error(perm === 'denied' ? 'Izin notifikasi ditolak.' : 'Izin notifikasi belum diberikan.'); }
      return currentSub();
    }).then(function (r) {
      var make = function () { return r.reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: u8(publicKey) }); };
      if (r.sub && !sameKey(r.sub)) { return r.sub.unsubscribe().then(make); }   // kunci server berganti
      return r.sub || make();
    }).then(function (sub) {
      return api.post('/push/subscribe', sub.toJSON());
    }).then(function () {
      ui.toast('Notifikasi aktif', 'Perangkat ini akan menerima pengingat.', 'ok');
      b.disabled = false; refresh();
    }).catch(function (e) {
      b.disabled = false;
      ui.toast('Belum bisa mengaktifkan', e && e.message ? e.message : 'Coba lagi.', 'err');
      refresh();
    });
  }

  function disable() {
    var b = $('push-off'); b.disabled = true;
    currentSub().then(function (r) {
      var ep = r.sub ? r.sub.endpoint : null;
      return (r.sub ? r.sub.unsubscribe() : Promise.resolve()).then(function () { return ep ? api.post('/push/unsubscribe', { endpoint: ep }) : null; });
    }).then(function () { ui.toast('Notifikasi dimatikan di perangkat ini', '', 'ok'); }, function (e) { ui.toast('Tidak dapat mematikan', e.message, 'err'); })
      .then(function () { b.disabled = false; refresh(); });
  }

  function test() {
    var b = $('push-test'); b.disabled = true;
    api.post('/push/test').then(function (r) {
      if (r.sent > 0) { ui.toast('Notifikasi uji dikirim', 'Cek layar perangkat Anda.', 'ok'); }
      else { ui.toast('Belum terkirim', 'Layanan push belum menerima; akan dicoba ulang otomatis.', 'err'); }
    }, function (e) { ui.toast('Gagal mengirim notifikasi uji', e.message, 'err'); }).then(function () { b.disabled = false; });
  }

  // ---- Pasang aplikasi -------------------------------------------------------------------
  function installUi() {
    var box = $('install-card');
    if (!box || !pwa) { return; }
    if (pwa.isStandalone()) { box.hidden = true; return; }
    var btn = $('pwa-install'), tip = $('pwa-install-help');
    if (pwa.canInstall()) { box.hidden = false; btn.hidden = false; tip.textContent = ''; }
    else if (pwa.isIos()) { box.hidden = false; btn.hidden = true; tip.textContent = 'Ketuk tombol Bagikan di Safari, lalu pilih "Tambah ke Layar Utama".'; }
    else { box.hidden = false; btn.hidden = true; tip.textContent = 'Buka menu peramban (⋮) lalu pilih "Pasang aplikasi" / "Tambahkan ke layar utama".'; }
  }
  if ($('pwa-install')) {
    $('pwa-install').addEventListener('click', function () {
      pwa.install().then(function (o) { if (o === 'accepted') { ui.toast('Aplikasi dipasang', '', 'ok'); } installUi(); }, function () { installUi(); });
    });
  }
  document.addEventListener('hme:installable', installUi);
  document.addEventListener('hme:installed', installUi);

  $('push-on').addEventListener('click', enable);
  $('push-off').addEventListener('click', disable);
  $('push-test').addEventListener('click', test);
  if (supported) {
    navigator.serviceWorker.addEventListener('message', function (e) {
      if (e.data && e.data.type === 'resubscribe') { refresh(); }
    });
  }
  installUi();
  refresh();
})();
