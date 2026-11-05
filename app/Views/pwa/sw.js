/* Service worker Peminjaman HME. Disajikan oleh PwaController (placeholder __X__ diisi server).
   Tugasnya hanya tiga:
   1. PUSH  — menampilkan notifikasi sistem di HP/desktop walau aplikasi sedang ditutup, dan membuka halaman yang tepat saat diketuk.
   2. SHELL — menyimpan aset statis (CSS/JS/ikon) agar aplikasi terbuka cepat; saat TIDAK ADA KONEKSI tampil halaman "offline".
   3. TIDAK PERNAH menyimpan halaman/API yang berisi data pengguna (HTML aplikasi, /api/*, /media/*), sehingga data tidak bocor
      lewat cache perangkat bersama dan tidak pernah basi.
*/
'use strict';

var VERSION = '__VERSION__';
var STATIC_CACHE = 'hme-static-' + VERSION;
var PRECACHE = __PRECACHE__;
var OFFLINE_URL = '__OFFLINE__';
var ICON = '__ICON__';
var BADGE = '__BADGE__';
var HOME = '__HOME__';
var ASSET_PREFIX = new URL(HOME, self.location.origin).pathname.replace(/\/?$/, '/') + 'assets/';

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(STATIC_CACHE).then(function (cache) {
      // addAll gagal bila satu berkas gagal; satu per satu agar offline tetap tersedia walau ada aset yang hilang
      return Promise.all(PRECACHE.map(function (u) { return cache.add(u).catch(function () { /* abaikan */ }); }));
    }).then(function () { return self.skipWaiting(); })
  );
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(keys.filter(function (k) { return k.indexOf('hme-static-') === 0 && k !== STATIC_CACHE; }).map(function (k) { return caches.delete(k); }));
    }).then(function () { return self.clients.claim(); })
  );
});

self.addEventListener('fetch', function (event) {
  var req = event.request;
  if (req.method !== 'GET') { return; }
  var url = new URL(req.url);
  if (url.origin !== self.location.origin) { return; }

  // Navigasi halaman: selalu ke jaringan (data selalu segar & berotorisasi); bila gagal → halaman offline
  if (req.mode === 'navigate') {
    event.respondWith(fetch(req).catch(function () { return caches.match(OFFLINE_URL).then(function (r) { return r || Response.error(); }); }));
    return;
  }
  // Aset statis (CSS/JS/gambar): cache dulu, perbarui di latar (stale-while-revalidate)
  if (url.pathname.indexOf(ASSET_PREFIX) === 0) {
    event.respondWith(
      caches.open(STATIC_CACHE).then(function (cache) {
        return cache.match(req).then(function (hit) {
          var net = fetch(req).then(function (res) {
            if (res && res.ok && res.type === 'basic') { cache.put(req, res.clone()); }
            return res;
          }).catch(function () { return hit; });
          return hit || net;
        });
      })
    );
  }
  // Selain itu (API, /media, /sync, …) tidak disentuh: langsung ke jaringan.
});

// Hanya alamat satu asal (http/https) yang boleh dibuka dari notifikasi; selainnya (mis. javascript:) → beranda.
function safeUrl(u) {
  try {
    if (typeof u !== 'string' || u === '') { return HOME; }
    var x = new URL(u, self.location.origin);
    return (x.origin === self.location.origin && (x.protocol === 'https:' || x.protocol === 'http:')) ? x.pathname + x.search + x.hash : HOME;
  } catch (e) { return HOME; }
}

// ---------------------------------------------------------------- PUSH ----------------------
self.addEventListener('push', function (event) {
  var data = {};
  try { data = event.data ? event.data.json() : {}; } catch (e) { data = { title: 'Peminjaman HME', body: event.data ? event.data.text() : '' }; }
  var title = String(data.title || 'Peminjaman HME').slice(0, 120);
  var options = {
    body: String(data.body || '').slice(0, 300),
    icon: ICON,
    badge: BADGE,
    tag: data.tag ? String(data.tag) : undefined,
    renotify: false,
    timestamp: Date.now(),
    data: { url: safeUrl(data.url), id: data.id || null }
  };
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
      // Aplikasi sedang dilihat pengguna → cukup beri tahu halaman (toast & lonceng sudah diperbarui realtime); tidak perlu notifikasi sistem
      var focused = list.some(function (c) { return c.visibilityState === 'visible' && c.focused; });
      list.forEach(function (c) { c.postMessage({ type: 'push', title: title, body: options.body, url: options.data.url, id: options.data.id }); });
      if (focused) { return undefined; }
      return self.registration.showNotification(title, options);
    })
  );
});

self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  var target = safeUrl(event.notification.data && event.notification.data.url);
  var abs = new URL(target, self.location.origin).href;
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
      for (var i = 0; i < list.length; i++) {
        var c = list[i];
        if (new URL(c.url).origin === self.location.origin && 'focus' in c) {
          return c.focus().then(function (cl) { return 'navigate' in cl ? cl.navigate(abs) : cl; });
        }
      }
      return self.clients.openWindow ? self.clients.openWindow(abs) : undefined;
    })
  );
});

// Langganan diganti/kedaluwarsa oleh browser: beri tahu halaman agar mendaftarkan ulang saat dibuka
self.addEventListener('pushsubscriptionchange', function () {
  self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
    list.forEach(function (c) { c.postMessage({ type: 'resubscribe' }); });
  });
});
