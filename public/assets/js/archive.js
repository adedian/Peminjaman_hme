/* Arsip data lama (admin): catatan manual & register inventaris. Hanya baca. Render dengan DOM + textContent saja. */
(function () {
  'use strict';
  var api = window.hmeApi, ui = window.hme;
  var form = document.getElementById('ar-form');
  if (!api || !ui || !form) { return; }
  var $ = function (id) { return document.getElementById(id); };
  var NS = 'http://www.w3.org/2000/svg';
  var base = (document.querySelector('meta[name="base-path"]') || {}).content || '';
  var REG = form.getAttribute('data-tab') === 'register';
  var list = $('ar-list');
  var state = { offset: 0, q: '', filter: '', busy: false, filled: false };
  var FLAG = {
    ok:           { label: 'Tanggal terbaca',        cls: 'done' },
    swapped_dm:   { label: 'Tanggal dikoreksi',      cls: 'info' },
    needs_review: { label: 'Perlu diperiksa',        cls: 'pending' },
    unparseable:  { label: 'Tanggal tak terbaca',    cls: 'rejected' }
  };

  function h(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) { e.className = cls; }
    if (text !== undefined && text !== null) { e.textContent = text; }
    return e;
  }
  function icon(name) {
    var s = document.createElementNS(NS, 'svg');
    s.setAttribute('class', 'ico'); s.setAttribute('aria-hidden', 'true');
    var u = document.createElementNS(NS, 'use'); u.setAttribute('href', '#i-' + name);
    s.appendChild(u);
    return s;
  }
  function day(s) {
    var m = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})/.exec(String(s || ''));
    if (!m) { return null; }
    var d = new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]);
    var t = d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
    return (m[4] === '00' && m[5] === '00') ? t : t + ' ' + m[4] + '.' + m[5];
  }
  function fact(label, value) {
    var d = h('div'); d.appendChild(h('small', '', label)); d.appendChild(h('strong', '', value || '—')); return d;
  }

  function manualCard(r) {
    var c = h('article', 'card ar-card');
    var top = h('div', 'ar-top');
    var t = h('div', 'ar-title');
    t.appendChild(h('strong', '', r.item_text || '(tanpa nama alat)'));
    t.appendChild(h('small', '', 'No ' + (r.legacy_no || '—') + ' · baris sheet ' + r.source_row));
    top.appendChild(t);
    var f = FLAG[r.parse_flag] || FLAG.ok;
    if (r.parse_flag !== 'ok') { top.appendChild(h('span', 'chip ' + f.cls, f.label)); }
    c.appendChild(top);
    var g = h('div', 'facts-grid');
    g.appendChild(fact('Pembawa', r.carrier_text));
    g.appendChild(fact('Lokasi', r.location));
    g.appendChild(fact('Dibawa', day(r.borrowed_at) || r.borrowed_raw));
    g.appendChild(fact('Kembali', day(r.returned_at) || r.return_note || r.returned_raw));
    if (r.team) { g.appendChild(fact('Tim', r.team)); }
    c.appendChild(g);
    var notes = [r.notes, r.extra_note].filter(Boolean).join(' · ');
    if (notes) { c.appendChild(h('p', 'ar-note', notes)); }
    if (r.parse_flag === 'swapped_dm' || r.parse_flag === 'needs_review' || r.parse_flag === 'unparseable') {
      c.appendChild(h('p', 'ar-raw', 'Teks asli — dibawa: ' + (r.borrowed_raw || '—') + ' · kembali: ' + (r.returned_raw || '—')));
    }
    return c;
  }

  function registerCard(r) {
    var c = h('article', 'card ar-card');
    var top = h('div', 'ar-top');
    var t = h('div', 'ar-title');
    t.appendChild(h('strong', '', r.name || '(tanpa nama)'));
    t.appendChild(h('small', '', r.section + ' · No ' + (r.no || '—')));
    top.appendChild(t);
    if (r.in_master) { top.appendChild(h('span', 'chip done', 'Sudah di master')); }
    c.appendChild(top);
    var g = h('div', 'facts-grid');
    g.appendChild(fact('Jumlah', [r.qty, r.unit].filter(Boolean).join(' ')));
    g.appendChild(fact('Kondisi', r.condition_text));
    g.appendChild(fact('Lokasi penyimpanan', r.storage_location));
    g.appendChild(fact('Posisi / keterangan', r.current_position));
    c.appendChild(g);
    if (r.note) { c.appendChild(h('p', 'ar-note', r.note)); }
    if (!r.in_master && r.name) {
      var a = h('a', 'btn ghost', null);
      a.href = base + '/items?new=1&name=' + encodeURIComponent(r.name);
      a.appendChild(icon('plus')); a.appendChild(document.createTextNode('Jadikan Master Barang'));
      c.appendChild(a);
    }
    return c;
  }

  function fillFilter(d) {
    if (state.filled) { return; }
    state.filled = true;
    var sel = $('ar-filter');
    if (REG) {
      Object.keys(d.sections || {}).forEach(function (s) {
        var o = document.createElement('option'); o.value = s; o.textContent = s + ' (' + d.sections[s] + ')'; sel.appendChild(o);
      });
    } else {
      Object.keys(FLAG).forEach(function (k) {
        var o = document.createElement('option'); o.value = k; o.textContent = FLAG[k].label + ' (' + (d.flags ? d.flags[k] : 0) + ')'; sel.appendChild(o);
      });
    }
  }

  function load(reset) {
    if (state.busy) { return; }
    state.busy = true;
    if (reset) { state.offset = 0; }
    var q = new URLSearchParams({ limit: '30', offset: String(state.offset) });
    if (state.q) { q.set('q', state.q); }
    if (state.filter) { q.set('filter', state.filter); }
    api.get('/archive/' + (REG ? 'register' : 'manual') + '?' + q.toString()).then(function (d) {
      fillFilter(d);
      if (reset) { list.textContent = ''; }
      d.items.forEach(function (r) { list.appendChild(REG ? registerCard(r) : manualCard(r)); });
      state.offset += d.items.length;
      $('ar-count').textContent = d.total + (REG ? ' barang' : ' catatan');
      $('ar-more').hidden = state.offset >= d.total;
      if (reset && !d.items.length) { list.appendChild(h('p', 'empty-filter', 'Tidak ada data yang cocok.')); }
    }, function (err) { ui.toast('Arsip gagal dimuat', err.message, 'err'); }).then(function () { state.busy = false; });
  }

  var timer = null;
  $('ar-q').addEventListener('input', function (e) {
    clearTimeout(timer);
    var v = e.target.value.trim();
    timer = setTimeout(function () { state.q = v; load(true); }, 300);
  });
  $('ar-filter').addEventListener('change', function (e) { state.filter = e.target.value; load(true); });
  form.addEventListener('submit', function (e) { e.preventDefault(); });
  $('ar-more').addEventListener('click', function () { load(false); });
  load(true);
})();
