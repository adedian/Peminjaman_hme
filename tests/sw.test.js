/* Uji logika service worker (app/Views/pwa/sw.js) di Node, dengan lingkungan ServiceWorker tiruan.
 * Dijalankan oleh PHP (tests/cases/revisi_v2_sw.php); mencetak satu baris JSON hasil per kasus: {"name":..., "ok":true|false, "msg":...}.
 * Yang dibuktikan: keputusan menampilkan notifikasi, isi/URL notifikasi, perilaku klik, strategi cache (tidak menyentuh data pengguna).
 * Yang TIDAK dapat dibuktikan di sini: pengiriman push sungguhan oleh browser/layanan push.
 */
'use strict';
const fs = require('fs');
const vm = require('vm');
const assert = require('assert');

const base = process.argv[2];
let src = fs.readFileSync(base, 'utf8');
src = src
  .replace('__VERSION__', 'testver')
  .replace('__PRECACHE__', JSON.stringify(['/offline', '/assets/css/app.css?v=1']))
  .replace('__OFFLINE__', '/offline')
  .replace('__ICON__', '/assets/img/icon-192.png?v=1')
  .replace('__BADGE__', '/assets/img/badge-96.png?v=1')
  .replace('__HOME__', '/');

function makeEnv(opts) {
  opts = opts || {};
  const handlers = {};
  const shown = [];
  const posted = [];
  const opened = [];
  const navigated = [];
  const focused = [];
  const clientList = (opts.clients || []).map((c, i) => ({
    url: c.url || 'https://hme.test/profile', visibilityState: c.visible ? 'visible' : 'hidden', focused: !!c.focused,
    postMessage: (m) => posted.push({ i, m }),
    focus() { focused.push(i); return Promise.resolve(this); },
    navigate(u) { navigated.push(u); return Promise.resolve(this); }
  }));
  const store = {};
  const cache = {
    add: (u) => { store[u] = true; return Promise.resolve(); },
    match: (r) => Promise.resolve(store[typeof r === 'string' ? r : r.url] ? { cached: true } : undefined),
    put: (r, res) => { store[r.url] = res; return Promise.resolve(); }
  };
  const self = {
    location: { origin: 'https://hme.test' },
    addEventListener: (t, fn) => { handlers[t] = fn; },
    skipWaiting: () => Promise.resolve(),
    registration: { showNotification: (title, o) => { shown.push({ title, o }); return Promise.resolve(); } },
    clients: {
      matchAll: () => Promise.resolve(clientList), claim: () => Promise.resolve(),
      openWindow: (u) => { opened.push(u); return Promise.resolve({}); }
    }
  };
  const fetched = [];
  const ctx = {
    self, URL, Promise, JSON, Date, console, Response: { error: () => ({ error: true }) },
    caches: { open: () => Promise.resolve(cache), match: (u) => Promise.resolve(store[u] ? { offline: true } : undefined), keys: () => Promise.resolve([]), delete: () => Promise.resolve(true) },
    fetch: (req) => { fetched.push(req.url || req); return opts.offline ? Promise.reject(new Error('offline')) : Promise.resolve({ ok: true, type: 'basic', clone() { return this; } }); }
  };
  vm.createContext(ctx);
  vm.runInContext(src, ctx);
  const evt = (extra) => { const waits = []; return Object.assign({ waitUntil: (p) => waits.push(p), _waits: waits }, extra); };
  return { handlers, shown, posted, opened, navigated, focused, fetched, store, evt, ctx };
}

const results = [];
async function kase(name, fn) {
  try { await fn(); results.push({ name, ok: true }); } catch (e) { results.push({ name, ok: false, msg: String(e && e.message || e) }); }
}
const pushData = (obj) => ({ json: () => obj, text: () => JSON.stringify(obj) });

(async () => {
  await kase('push tanpa jendela terbuka: notifikasi sistem ditampilkan dengan judul, isi, ikon, lencana, tag, dan url', async () => {
    const e = makeEnv({ clients: [] });
    const ev = e.evt({ data: pushData({ title: 'Peminjaman disetujui', body: 'Bor · Lokasi', url: '/requests?open=7', tag: 'hme-9', id: 9 }) });
    e.handlers.push(ev); await Promise.all(ev._waits);
    assert.strictEqual(e.shown.length, 1);
    const n = e.shown[0];
    assert.strictEqual(n.title, 'Peminjaman disetujui');
    assert.strictEqual(n.o.body, 'Bor · Lokasi');
    assert.strictEqual(n.o.tag, 'hme-9');
    assert.strictEqual(n.o.data.url, '/requests?open=7');
    assert.ok(/icon-192/.test(n.o.icon) && /badge-96/.test(n.o.badge));
  });

  await kase('push saat aplikasi sedang dilihat (fokus): tidak ada notifikasi sistem, halaman diberi tahu lewat postMessage', async () => {
    const e = makeEnv({ clients: [{ visible: true, focused: true }, { visible: false }] });
    const ev = e.evt({ data: pushData({ title: 'X', body: 'Y', url: '/' }) });
    e.handlers.push(ev); await Promise.all(ev._waits);
    assert.strictEqual(e.shown.length, 0);
    assert.strictEqual(e.posted.length, 2);
    assert.strictEqual(e.posted[0].m.type, 'push');
  });

  await kase('push dengan jendela terbuka tetapi tidak fokus/terlihat: notifikasi sistem tetap muncul', async () => {
    const e = makeEnv({ clients: [{ visible: true, focused: false }] });
    const ev = e.evt({ data: pushData({ title: 'Pengingat', body: 'Besok', url: '/pqa?open=3' }) });
    e.handlers.push(ev); await Promise.all(ev._waits);
    assert.strictEqual(e.shown.length, 1);
  });

  await kase('push dengan isi tak valid / kosong / berlebihan: tidak crash, judul bawaan, dipotong', async () => {
    let e = makeEnv();
    let ev = e.evt({ data: { json: () => { throw new Error('bad'); }, text: () => 'teks biasa' } });
    e.handlers.push(ev); await Promise.all(ev._waits);
    assert.strictEqual(e.shown[0].title, 'Peminjaman HME');
    assert.strictEqual(e.shown[0].o.body, 'teks biasa');
    e = makeEnv();
    ev = e.evt({ data: null });
    e.handlers.push(ev); await Promise.all(ev._waits);
    assert.strictEqual(e.shown.length, 1);
    e = makeEnv();
    ev = e.evt({ data: pushData({ title: 'T'.repeat(500), body: 'B'.repeat(900), url: '/' }) });
    e.handlers.push(ev); await Promise.all(ev._waits);
    assert.ok(e.shown[0].title.length <= 120 && e.shown[0].o.body.length <= 300);
  });

  await kase('push dengan url berbahaya (javascript:) tetap diperlakukan sebagai string; klik hanya membuka asal sendiri', async () => {
    const e = makeEnv({ clients: [] });
    const ev = e.evt({ data: pushData({ title: 'X', body: 'Y', url: 'javascript:alert(1)' }) });
    e.handlers.push(ev); await Promise.all(ev._waits);
    const click = e.evt({ notification: { close() {}, data: e.shown[0].o.data } });
    e.handlers.notificationclick(click); await Promise.all(click._waits);
    // new URL('javascript:alert(1)', origin) → skema javascript; harus TIDAK dibuka sebagai halaman aplikasi
    assert.ok(e.opened.length === 0 || !/^javascript:/i.test(e.opened[0]), 'tidak boleh membuka javascript:');
  });

  await kase('klik notifikasi: memfokuskan jendela yang ada dan menavigasikannya ke url tujuan', async () => {
    const e = makeEnv({ clients: [{ url: 'https://hme.test/', visible: false }] });
    const click = e.evt({ notification: { close() {}, data: { url: '/requests?open=7' } } });
    e.handlers.notificationclick(click); await Promise.all(click._waits);
    assert.deepStrictEqual(e.focused, [0]);
    assert.deepStrictEqual(e.navigated, ['https://hme.test/requests?open=7']);
    assert.strictEqual(e.opened.length, 0);
  });

  await kase('klik notifikasi tanpa jendela terbuka: membuka jendela baru ke url tujuan', async () => {
    const e = makeEnv({ clients: [] });
    const click = e.evt({ notification: { close() {}, data: { url: '/pqa?open=3' } } });
    e.handlers.notificationclick(click); await Promise.all(click._waits);
    assert.deepStrictEqual(e.opened, ['https://hme.test/pqa?open=3']);
  });

  await kase('fetch: navigasi online → ke jaringan; offline → halaman /offline; GET aset di-cache; API/media/POST tidak disentuh', async () => {
    let e = makeEnv({ offline: true });
    await Promise.all(e.evt({})._waits);
    // pasang (precache) agar /offline ada di cache
    const inst = e.evt({}); e.handlers.install(inst); await Promise.all(inst._waits);
    assert.ok(e.store['/offline'], 'offline di-prasimpan');
    const nav = { respondWith: (p) => { nav.p = p; }, request: { method: 'GET', mode: 'navigate', url: 'https://hme.test/requests' } };
    e.handlers.fetch(nav);
    const res = await nav.p;
    assert.ok(res && res.offline, 'jatuh ke halaman offline');

    e = makeEnv();
    let called = 0;
    const mk = (method, url, mode) => ({ respondWith: () => { called++; }, request: { method, mode: mode || 'cors', url } });
    e.handlers.fetch(mk('GET', 'https://hme.test/api/v1/dashboard'));
    e.handlers.fetch(mk('GET', 'https://hme.test/media/items/abc.jpg'));
    e.handlers.fetch(mk('GET', 'https://hme.test/api/v1/sync?since=1'));
    e.handlers.fetch(mk('POST', 'https://hme.test/api/v1/requests'));
    e.handlers.fetch(mk('GET', 'https://evil.example/x.js'));
    assert.strictEqual(called, 0, 'data pengguna & lintas-asal tidak pernah ditangani SW');
    const asset = mk('GET', 'https://hme.test/assets/css/app.css?v=1');
    e.handlers.fetch(asset);
    assert.strictEqual(called, 1, 'aset statis ditangani (cache)');
  });

  await kase('pushsubscriptionchange: semua halaman diminta mendaftar ulang', async () => {
    const e = makeEnv({ clients: [{ visible: false }] });
    e.handlers.pushsubscriptionchange({});
    await new Promise((r) => setTimeout(r, 5));
    assert.strictEqual(e.posted.length, 1);
    assert.strictEqual(e.posted[0].m.type, 'resubscribe');
  });

  process.stdout.write(results.map((r) => JSON.stringify(r)).join('\n') + '\n');
})();
