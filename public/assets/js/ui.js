/* HME — interaksi UI umum: sheet, toast, tema, toast dari sesi (flash). Tanpa dependensi, tanpa inline-script. */
(function () {
  'use strict';

  var scrim = document.querySelector('.scrim');
  var lastFocus = null;

  function sheetEl(id) { return document.getElementById(id); }

  function openSheet(id) {
    var sh = sheetEl(id);
    if (!sh) { return; }
    closeSheets(true);
    lastFocus = document.activeElement;
    sh.classList.add('open');
    sh.setAttribute('aria-hidden', 'false');
    if (scrim) { scrim.classList.add('open'); }
    var f = sh.querySelector('input:not([disabled]):not([type=hidden]), select, textarea, button.btn');
    if (f) { setTimeout(function () { try { f.focus({ preventScroll: true }); } catch (e) { /* abaikan */ } }, 120); }
    sh.dispatchEvent(new CustomEvent('sheet:open', { bubbles: true }));
  }

  function closeSheets(silent) {
    var open = document.querySelectorAll('.sheet.open');
    open.forEach(function (e) {
      e.classList.remove('open');
      e.setAttribute('aria-hidden', 'true');
      if (!silent) { e.dispatchEvent(new CustomEvent('sheet:close', { bubbles: true })); }
    });
    if (scrim) { scrim.classList.remove('open'); }
    if (!silent && lastFocus && lastFocus.focus) { try { lastFocus.focus({ preventScroll: true }); } catch (e) { /* abaikan */ } }
  }

  function toast(title, sub, kind) {
    var st = document.querySelector('.toast-stack');
    if (!st) {
      st = document.createElement('div');
      st.className = 'toast-stack';
      st.setAttribute('role', 'status');
      st.setAttribute('aria-live', 'polite');
      document.body.appendChild(st);
    }
    var el = document.createElement('div');
    el.className = 'toast ' + (kind || '');
    var ico = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    ico.setAttribute('class', 'ico ico-sm');
    var use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
    use.setAttribute('href', '#i-' + (kind === 'ok' ? 'check' : (kind === 'err' ? 'x' : 'bell')));
    ico.appendChild(use);
    var badge = document.createElement('span');
    badge.className = 't-ico';
    if (kind === 'err') { badge.style.background = 'var(--danger)'; }
    badge.appendChild(ico);
    var box = document.createElement('div');
    var b = document.createElement('b');
    b.textContent = title;
    box.appendChild(b);
    if (sub) { var s = document.createElement('span'); s.textContent = sub; box.appendChild(s); }
    el.appendChild(badge);
    el.appendChild(box);
    st.appendChild(el);
    setTimeout(function () {
      el.classList.add('out');
      setTimeout(function () { el.remove(); }, 240);
    }, kind === 'err' ? 6000 : 3600);
  }

  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!(t instanceof Element)) { return; }
    var o = t.closest('[data-open-sheet]');
    if (o) { e.preventDefault(); openSheet(o.getAttribute('data-open-sheet')); return; }
    if (t.closest('[data-close-sheet]') || t === scrim) { closeSheets(); }
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeSheets(); } });

  // Tema terang/gelap (disimpan per perangkat; aman bila storage diblokir)
  var root = document.documentElement;
  try { var saved = localStorage.getItem('hme-theme'); if (saved === 'dark' || saved === 'light') { root.dataset.theme = saved; } } catch (e) { /* abaikan */ }
  function isDark() { return root.dataset.theme ? root.dataset.theme === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches; }
  function syncThemeSwitches() {
    document.querySelectorAll('[data-theme-toggle][role="switch"]').forEach(function (b) { b.setAttribute('aria-checked', isDark() ? 'true' : 'false'); });
  }
  document.querySelectorAll('[data-theme-toggle]').forEach(function (b) {
    b.addEventListener('click', function () {
      root.dataset.theme = isDark() ? 'light' : 'dark';
      try { localStorage.setItem('hme-theme', root.dataset.theme); } catch (e) { /* abaikan */ }
      syncThemeSwitches();
    });
  });
  syncThemeSwitches();
  if (window.matchMedia) { try { window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', syncThemeSwitches); } catch (e) { /* abaikan */ } }

  // Toast dari server (flash) dan dari halaman sebelumnya (sessionStorage)
  var flash = document.getElementById('flash-toast');
  if (flash) { toast(flash.dataset.title || '', flash.dataset.sub || '', flash.dataset.kind || ''); }
  try {
    var pending = sessionStorage.getItem('hme-flash');
    if (pending) {
      sessionStorage.removeItem('hme-flash');
      var p = JSON.parse(pending);
      toast(String(p.title || ''), String(p.sub || ''), String(p.kind || ''));
    }
  } catch (e) { /* abaikan */ }

  window.hme = window.hme || {};
  window.hme.openSheet = openSheet;
  window.hme.closeSheets = closeSheets;
  window.hme.toast = toast;
  window.hme.flashNext = function (title, sub, kind) {
    try { sessionStorage.setItem('hme-flash', JSON.stringify({ title: title, sub: sub || '', kind: kind || '' })); } catch (e) { /* abaikan */ }
  };
})();
