/* PWA: mendaftarkan service worker dan menyediakan API kecil untuk tombol "Pasang aplikasi".
   Dimuat di semua halaman (termasuk halaman masuk). Tanpa dependensi, tanpa skrip inline (CSP). */
(function () {
  'use strict';
  var meta = function (n) { var m = document.querySelector('meta[name="' + n + '"]'); return m ? m.getAttribute('content') : ''; };
  var base = meta('base-path') || '';
  var deferred = null;

  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register(base + '/sw.js', { scope: base + '/' }).catch(function () { /* peramban tanpa dukungan / mode privat: abaikan */ });
    });
  }

  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferred = e;
    document.dispatchEvent(new CustomEvent('hme:installable'));
  });
  window.addEventListener('appinstalled', function () {
    deferred = null;
    document.dispatchEvent(new CustomEvent('hme:installed'));
  });

  // Keluar (logout): lepas langganan push perangkat ini lebih dulu agar notifikasi akun ini tidak lagi muncul di HP yang dipakai bergantian.
  // Dibatasi 2,5 detik supaya keluar tidak pernah tertahan oleh jaringan.
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f || !f.getAttribute || !/\/logout$/.test(f.getAttribute('action') || '') || f.__pushDone) { return; }
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) { return; }
    e.preventDefault();
    f.__pushDone = true;
    var finish = function () { f.submit(); };
    var work = navigator.serviceWorker.getRegistration(base + '/').then(function (reg) {
      return reg ? reg.pushManager.getSubscription() : null;
    }).then(function (sub) {
      if (!sub) { return null; }
      var token = meta('csrf-token');
      return fetch(base + '/api/v1/push/unsubscribe', {
        method: 'POST', credentials: 'same-origin', keepalive: true,
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token, 'Accept': 'application/json' },
        body: JSON.stringify({ endpoint: sub.endpoint })
      }).catch(function () { /* abaikan */ }).then(function () { return sub.unsubscribe().catch(function () { /* abaikan */ }); });
    }).catch(function () { /* abaikan */ });
    Promise.race([work, new Promise(function (r) { setTimeout(r, 2500); })]).then(finish, finish);
  }, true);

  window.hmePwa = {
    base: base,
    /** Sudah berjalan sebagai aplikasi terpasang (layar utama)? */
    isStandalone: function () {
      return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone === true;
    },
    isIos: function () { return /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1); },
    canInstall: function () { return !!deferred; },
    install: function () {
      if (!deferred) { return Promise.reject(new Error('Pemasangan tidak tersedia.')); }
      var d = deferred;
      return d.prompt().then(function () { return d.userChoice; }).then(function (c) { deferred = null; return c && c.outcome; });
    },
    ready: function () { return 'serviceWorker' in navigator ? navigator.serviceWorker.ready : Promise.reject(new Error('Service worker tidak didukung.')); }
  };
})();
