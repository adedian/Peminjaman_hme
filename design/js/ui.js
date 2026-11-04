/* HME design — sprite ikon + interaksi mockup (sheet, toast, tema). Tanpa dependensi. */
(function () {
  var P = {
    home: '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h5v-6h4v6h5V10"/>',
    history: '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l3 2"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    bolt: '<path d="M13 2L4 14h7l-1 8 9-12h-7z"/>',
    user: '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>',
    users: '<circle cx="9" cy="8" r="3.5"/><path d="M2 20c0-3.5 3.5-5.5 7-5.5s7 2 7 5.5"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14.8c2.4.6 4 2.2 4 5.2"/>',
    bell: '<path d="M6 17V11a6 6 0 0 1 12 0v6l1.5 2h-15z"/><path d="M10 21h4"/>',
    search: '<circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/>',
    filter: '<path d="M4 6h16M7 12h10M10 18h4"/>',
    check: '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
    x: '<path d="M6 6l12 12M18 6L6 18"/>',
    box: '<path d="M21 8l-9-5-9 5v8l9 5 9-5z"/><path d="M3 8l9 5 9-5M12 13v8"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    pin: '<path d="M12 21s7-6.2 7-11.5A7 7 0 0 0 5 9.5C5 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
    phone: '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/>',
    chat: '<path d="M21 12a8 8 0 0 1-11.7 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/>',
    inbox: '<path d="M3 13l3-8h12l3 8v6H3z"/><path d="M3 13h5l1 3h6l1-3h5"/>',
    wrench: '<path d="M14.7 6.3a4 4 0 0 0 5 5L21 13l-8 8a2.8 2.8 0 0 1-4-4l8-8z" transform="scale(.9) translate(1 0)"/><path d="M8 17h.01"/>',
    chart: '<path d="M4 20V10M10 20V4M16 20v-8M22 20H2"/>',
    list: '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
    arrow: '<path d="M5 12h14M13 6l6 6-6 6"/>',
    chevron: '<path d="M9 6l6 6-6 6"/>',
    sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
    moon: '<path d="M21 13A9 9 0 1 1 11 3a7 7 0 0 0 10 10z"/>',
    scan: '<path d="M4 8V6a2 2 0 0 1 2-2h2M16 4h2a2 2 0 0 1 2 2v2M20 16v2a2 2 0 0 1-2 2h-2M8 20H6a2 2 0 0 1-2-2v-2M7 12h10"/>',
    more: '<circle cx="5" cy="12" r="1.2"/><circle cx="12" cy="12" r="1.2"/><circle cx="19" cy="12" r="1.2"/>',
    truck: '<path d="M2 6h11v10H2zM13 9h4l4 4v3h-8z"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
    drone: '<circle cx="5" cy="5" r="2.5"/><circle cx="19" cy="5" r="2.5"/><circle cx="5" cy="19" r="2.5"/><circle cx="19" cy="19" r="2.5"/><rect x="9" y="9" width="6" height="6" rx="1.5"/><path d="M7 7l2 2M17 7l-2 2M7 17l2-2M17 17l-2-2"/>',
    ruler: '<path d="M3 17L17 3l4 4L7 21z"/><path d="M7 13l2 2M10 10l2 2M13 7l2 2"/>',
    helmet: '<path d="M4 16a8 8 0 0 1 16 0z"/><path d="M12 8v8M3 16h18v2H3z"/>'
  };
  var s = '<svg xmlns="http://www.w3.org/2000/svg" style="display:none">';
  Object.keys(P).forEach(function (k) { s += '<symbol id="i-' + k + '" viewBox="0 0 24 24">' + P[k] + '</symbol>'; });
  s += '</svg>';
  document.body.insertAdjacentHTML('afterbegin', s);

  // <i data-i="home"></i> -> svg
  document.querySelectorAll('i[data-i]').forEach(function (el) {
    var cls = el.className ? ' ' + el.className : '';
    el.outerHTML = '<svg class="ico' + cls + '" aria-hidden="true"><use href="#i-' + el.dataset.i + '"/></svg>';
  });

  // Sheet
  var scrim = document.querySelector('.scrim');
  function openSheet(id) {
    var sh = document.getElementById(id); if (!sh) return;
    sh.classList.add('open'); if (scrim) scrim.classList.add('open');
    sh.setAttribute('aria-hidden', 'false');
  }
  function closeSheets() {
    document.querySelectorAll('.sheet.open').forEach(function (e) { e.classList.remove('open'); e.setAttribute('aria-hidden', 'true'); });
    if (scrim) scrim.classList.remove('open');
  }
  document.addEventListener('click', function (e) {
    var o = e.target.closest('[data-open-sheet]'); if (o) { e.preventDefault(); openSheet(o.dataset.openSheet); return; }
    if (e.target.closest('[data-close-sheet]') || e.target === scrim) closeSheets();
    var t = e.target.closest('[data-toast]'); if (t) { toast(t.dataset.toast, t.dataset.toastSub || '', t.dataset.toastKind); }
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeSheets(); });
  if (location.hash === '#detail') setTimeout(function () { openSheet('sheet-detail'); }, 250);

  // Toast
  function toast(title, sub, kind) {
    var st = document.querySelector('.toast-stack');
    if (!st) { st = document.createElement('div'); st.className = 'toast-stack'; st.setAttribute('role', 'status'); document.body.appendChild(st); }
    var el = document.createElement('div'); el.className = 'toast ' + (kind || '');
    el.innerHTML = '<span class="t-ico"><svg class="ico ico-sm"><use href="#i-' + (kind === 'ok' ? 'check' : 'bell') + '"/></svg></span><div><b></b><span></span></div>';
    el.querySelector('b').textContent = title; el.querySelector('span:not(.t-ico)').textContent = sub;
    st.appendChild(el);
    setTimeout(function () { el.classList.add('out'); setTimeout(function () { el.remove(); }, 240); }, 3600);
  }
  window.hmeToast = toast;

  // Tema
  var root = document.documentElement;
  try { var saved = localStorage.getItem('hme-theme'); if (saved) root.dataset.theme = saved; } catch (e) {}
  document.querySelectorAll('[data-theme-toggle]').forEach(function (b) {
    b.addEventListener('click', function () {
      var dark = root.dataset.theme ? root.dataset.theme === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches;
      root.dataset.theme = dark ? 'light' : 'dark';
      try { localStorage.setItem('hme-theme', root.dataset.theme); } catch (e) {}
    });
  });

  // Chip filter (demo)
  document.querySelectorAll('.seg button').forEach(function (b) {
    b.addEventListener('click', function () {
      b.parentElement.querySelectorAll('button').forEach(function (x) { x.setAttribute('aria-pressed', 'false'); });
      b.setAttribute('aria-pressed', 'true');
    });
  });
})();
