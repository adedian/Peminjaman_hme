/* HME — klien API (fetch). Menyertakan token CSRF, menangani sesi berakhir, dan memetakan error validasi ke field. */
(function () {
  'use strict';

  var meta = function (n) { var m = document.querySelector('meta[name="' + n + '"]'); return m ? m.getAttribute('content') || '' : ''; };
  var base = meta('base-path');
  var token = meta('csrf-token');

  function ApiError(status, code, message, fields) {
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.message = message;
    this.fields = fields || {};
  }
  ApiError.prototype = Object.create(Error.prototype);

  function request(method, path, body) {
    var opts = {
      method: method,
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-CSRF-Token': token },
    };
    if (body instanceof FormData) {
      opts.body = body;          // multipart: biarkan browser menyusun Content-Type + boundary
    } else if (body !== undefined) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    return fetch(base + '/api/v1' + path, opts).then(function (res) {
      return res.text().then(function (txt) {
        var j = null;
        try { j = txt ? JSON.parse(txt) : null; } catch (e) { /* bukan JSON */ }
        if (res.ok && j && j.ok) {
          if (j.data && j.data.csrf_token) { token = j.data.csrf_token; }
          return j.data;
        }
        var err = (j && j.error) || {};
        // Sesi habis: kembali ke halaman masuk
        if (res.status === 401 && path !== '/auth/login') {
          window.location.href = base + '/login';
        }
        throw new ApiError(res.status, err.code || 'error', err.message || 'Terjadi kesalahan. Silakan coba lagi.', err.fields);
      });
    }, function () {
      throw new ApiError(0, 'network', 'Tidak dapat terhubung ke server. Periksa koneksi Anda.');
    });
  }

  /** Tampilkan error per-field pada formulir: elemen [data-error-for=nama] dan kelas .invalid pada .field. */
  function showFieldErrors(form, fields) {
    form.querySelectorAll('.field').forEach(function (f) { f.classList.remove('invalid'); });
    form.querySelectorAll('[data-error-for]').forEach(function (h) {
      if (!h.hasAttribute('data-default')) { h.setAttribute('data-default', h.textContent); }
      h.textContent = h.getAttribute('data-default');
      h.classList.remove('err');
    });
    var first = null;
    Object.keys(fields || {}).forEach(function (name) {
      var hint = form.querySelector('[data-error-for="' + name + '"]');
      var input = form.querySelector('[name="' + name + '"]');
      if (hint) { hint.textContent = fields[name]; hint.classList.add('err'); }
      if (input) {
        var wrap = input.closest('.field');
        if (wrap) { wrap.classList.add('invalid'); }
        input.setAttribute('aria-invalid', 'true');
        first = first || input;
      }
    });
    if (first) { first.focus(); }
  }

  window.hmeApi = {
    get: function (p) { return request('GET', p); },
    post: function (p, b) { return request('POST', p, b === undefined ? {} : b); },
    put: function (p, b) { return request('PUT', p, b); },
    postForm: function (p, formData) { return request('POST', p, formData); },
    showFieldErrors: showFieldErrors,
    ApiError: ApiError,
  };
})();
