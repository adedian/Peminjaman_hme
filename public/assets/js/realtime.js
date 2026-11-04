/* HME — realtime (§7 rencana): change-feed + polling adaptif.
   - Satu poller per browser: tab "pemimpin" dipilih lewat Web Locks; hasilnya dibagikan ke tab lain lewat BroadcastChannel.
     Browser tanpa dukungan itu: setiap tab melakukan polling sendiri (tetap benar, hanya lebih banyak request).
   - Interval: aktif 1,5 dtk (REALTIME_INTERVAL_MS) → 5 dtk bila tidak ada interaksi > 1 menit → 20 dtk bila semua tab tersembunyi.
     Sync seketika saat tab kembali terlihat.
   - Server hanya mengirim jenis & ID yang berubah; halaman memuat ulang datanya sendiri (hmeLive.on). Formulir tidak disentuh.
   - Lonceng, lencana navigasi, dan toast notifikasi diperbarui di sini untuk semua halaman. */
(function () {
  'use strict';
  var meta = function (n) { var m = document.querySelector('meta[name="' + n + '"]'); return m ? m.getAttribute('content') || '' : ''; };
  if (!meta('sync-cursor')) { return; }     // halaman tanpa shell (login, wajib ganti password)

  var base = meta('base-path');
  var ACTIVE = Math.max(1000, parseInt(meta('sync-interval'), 10) || 1500);
  var IDLE = 5000, HIDDEN = 20000, IDLE_AFTER = 60000;
  var cursor = parseInt(meta('sync-cursor'), 10) || 0;
  var TAB = Math.random().toString(36).slice(2);
  var bc = ('BroadcastChannel' in window) ? new BroadcastChannel('hme-sync') : null;
  var leader = false, timer = null, inflight = false, failures = 0, stopped = false;
  var lastActivity = Date.now();
  var peers = {};                           // tab lain: { visible, at }
  var subs = [];

  // ------------------------------------------------------------------ status tab ------------
  function anyVisible() {
    if (!document.hidden) { return true; }
    var now = Date.now();
    return Object.keys(peers).some(function (k) { return peers[k].visible && now - peers[k].seen < 45000; });
  }
  function recentActivity() {
    var t = lastActivity;
    Object.keys(peers).forEach(function (k) { if (peers[k].at > t) { t = peers[k].at; } });
    return Date.now() - t < IDLE_AFTER;
  }
  function interval() {
    if (failures > 0) { return Math.min(30000, 2000 * Math.pow(2, failures - 1)); }
    if (!anyVisible()) { return HIDDEN; }
    return recentActivity() ? ACTIVE : IDLE;
  }
  function announce() {
    if (bc) { bc.postMessage({ t: 'state', tab: TAB, visible: !document.hidden, at: lastActivity }); }
  }
  var lastAnnounce = 0;
  function activity() {
    var now = Date.now();
    var wasIdle = now - lastActivity > IDLE_AFTER;
    lastActivity = now;
    if (now - lastAnnounce > 5000) { lastAnnounce = now; announce(); }
    if (wasIdle) { pollSoon(); }
  }
  ['pointerdown', 'keydown', 'wheel', 'touchstart'].forEach(function (ev) { window.addEventListener(ev, activity, { passive: true }); });
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) { lastActivity = Date.now(); }
    announce();
    if (!document.hidden) { pollSoon(); }
  });
  setInterval(function () { if (!document.hidden) { announce(); } }, 15000);

  // ------------------------------------------------------------------ tampilan global -------
  function setCount(el, n, cls) {
    var c = el.querySelector('.' + cls);
    if (n > 0) {
      if (!c) { c = document.createElement('span'); c.className = cls + (cls === 'bell-count' ? ' num' : ''); el.appendChild(c); }
      c.textContent = n > 99 ? '99+' : String(n);
      if (cls === 'bell-count') { c.setAttribute('data-unread', ''); }
    } else if (c) {
      c.remove();
    }
  }
  function applyCounts(counts) {
    if (!counts) { return; }
    var bell = document.getElementById('nav-bell');
    if (bell && typeof counts.notifications === 'number') {
      setCount(bell, counts.notifications, 'bell-count');
      bell.setAttribute('aria-label', counts.notifications > 0 ? 'Notifikasi (' + counts.notifications + ' belum dibaca)' : 'Notifikasi');
    }
    ['requests', 'pqa'].forEach(function (k) {
      if (typeof counts[k] !== 'number') { return; }
      document.querySelectorAll('a[data-nav="' + k + '"]').forEach(function (a) { setCount(a, counts[k], 'count'); });
    });
  }
  function apply(d) {
    applyCounts(d.counts);
    if (d.notifications && d.notifications.length && !document.hidden && window.hme && window.hme.toast) {
      d.notifications.slice(-3).forEach(function (n) { window.hme.toast(n.title, n.body || '', ''); });
    }
    var ch = d.changes || {};
    subs.forEach(function (s) {
      var hit = d.reset ? s.types.slice() : s.types.filter(function (t) { return ch[t] && ch[t].length; });
      if (!hit.length) { return; }
      clearTimeout(s.timer);       // gabungkan perubahan beruntun menjadi satu muat ulang
      s.timer = setTimeout(function () {
        try { s.fn(ch, !!d.reset); } catch (e) { if (window.console) { console.error(e); } }
      }, 150);
    });
  }

  // ------------------------------------------------------------------ polling ---------------
  function schedule(ms) {
    clearTimeout(timer);
    if (leader && !stopped) { timer = setTimeout(poll, ms === undefined ? interval() : ms); }
  }
  function pollSoon() {
    if (leader) { schedule(50); } else if (bc) { bc.postMessage({ t: 'now', tab: TAB }); }
  }
  function poll() {
    if (inflight || stopped) { return; }
    inflight = true;
    fetch(base + '/api/v1/sync?since=' + cursor, { credentials: 'same-origin', headers: { 'Accept': 'application/json' }, cache: 'no-store' })
      .then(function (res) {
        if (res.status === 204) { failures = 0; return res.text().then(function () { return null; }); }   // badan dibaca: tidak tercatat "aborted" di DevTools
        if (res.status === 401) { stopped = true; window.location.href = base + '/login'; return null; }
        return res.json().catch(function () { return null; }).then(function (j) {
          if (res.status === 403 && j && j.error && j.error.code === 'password_change_required') { stopped = true; window.location.reload(); return null; }
          if (!res.ok || !j || !j.ok) { throw new Error('sync ' + res.status); }
          failures = 0;
          var d = j.data;
          cursor = d.cursor;
          apply(d);
          if (bc) { bc.postMessage({ t: 'sync', tab: TAB, d: d }); }
          return null;
        });
      })
      .catch(function () { failures = Math.min(failures + 1, 5); })
      .then(function () { inflight = false; schedule(); });
  }

  if (bc) {
    bc.onmessage = function (e) {
      var m = e.data || {};
      if (m.tab === TAB) { return; }
      if (m.t === 'state') { peers[m.tab] = { visible: m.visible, at: m.at, seen: Date.now() }; if (leader && m.visible) { schedule(); } }
      else if (m.t === 'now') { peers[m.tab] = { visible: true, at: Date.now(), seen: Date.now() }; if (leader) { schedule(50); } }
      else if (m.t === 'sync' && m.d) { if (m.d.cursor > cursor) { cursor = m.d.cursor; } apply(m.d); }
      else if (m.t === 'bye') { delete peers[m.tab]; }
    };
    window.addEventListener('pagehide', function () { bc.postMessage({ t: 'bye', tab: TAB }); });
  }

  function becomeLeader() { leader = true; announce(); schedule(ACTIVE); }
  if (bc && navigator.locks && navigator.locks.request) {
    // Kunci dipegang selama tab hidup; saat tab pemimpin ditutup, tab lain otomatis mengambil alih.
    navigator.locks.request('hme-sync-leader', function () {
      becomeLeader();
      return new Promise(function () { /* tidak pernah selesai */ });
    }).catch(becomeLeader);
    announce();
  } else {
    becomeLeader();
  }

  // ------------------------------------------------------------------ API untuk halaman -----
  /** Ganti isi elemen #id dengan versi terbaru dari halaman yang sama (HTML dirender & di-escape server). */
  function swap(id, done) {
    return fetch(window.location.pathname + window.location.search, { credentials: 'same-origin', headers: { 'Accept': 'text/html' }, cache: 'no-store' })
      .then(function (r) { return r.ok ? r.text() : null; })
      .then(function (html) {
        if (!html) { return; }
        var fresh = new DOMParser().parseFromString(html, 'text/html').getElementById(id);
        var cur = document.getElementById(id);
        if (!fresh || !cur) { return; }
        cur.replaceChildren.apply(cur, Array.prototype.map.call(fresh.childNodes, function (n) { return document.importNode(n, true); }));
        if (done) { done(); }
      }, function () { /* biarkan tampilan lama */ });
  }

  window.hmeLive = {
    /** fn(changes, reset) dipanggil saat salah satu jenis entitas berubah. */
    on: function (types, fn) { subs.push({ types: types, fn: fn, timer: null }); },
    swap: swap,
    pollNow: pollSoon,
    isOpen: function (sheetId) { var s = document.getElementById(sheetId); return !!(s && s.classList.contains('open')); }
  };
})();
