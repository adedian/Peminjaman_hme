<?php /** Sheet (dialog gelap) untuk halaman Pengguna. */ ?>
<!-- Tambah / ubah pengguna -->
<section class="sheet" id="sheet-user" role="dialog" aria-modal="true" aria-labelledby="su-title" aria-hidden="true">
  <div class="grab"></div>
  <form class="sheet-inner" id="user-form" novalidate>
    <div class="head"><div><small id="su-sub">Pengguna baru</small><div class="big" id="su-title">Tambah pengguna</div></div>
      <button class="round-btn" type="button" data-close-sheet aria-label="Tutup"><?= icon('x') ?></button></div>

    <div class="field" id="f-username-wrap">
      <label for="f-username">Username</label>
      <input class="input" id="f-username" name="username" autocomplete="off" autocapitalize="none" spellcheck="false" maxlength="50" placeholder="mis. budi.s">
      <span class="hint" data-error-for="username">Huruf, angka, titik, strip, garis bawah. Tidak dapat diubah setelah dibuat.</span>
    </div>
    <div class="field">
      <label for="f-name">Nama lengkap</label>
      <input class="input" id="f-name" name="name" autocomplete="off" maxlength="100" placeholder="Nama asli">
      <span class="hint" data-error-for="name"></span>
    </div>
    <div class="field">
      <label for="f-role">Peran</label>
      <select class="select" id="f-role" name="role"><option value="staff">Staff</option><option value="admin">Admin</option></select>
      <span class="hint" data-error-for="role"></span>
    </div>
    <div class="field" id="f-password-wrap">
      <label for="f-password">Password awal <span class="opt">(opsional)</span></label>
      <input class="input" id="f-password" name="password" type="text" autocomplete="off" maxlength="72" placeholder="Kosongkan agar dibuatkan otomatis">
      <span class="hint" data-error-for="password">Kosong = dibuatkan otomatis dan ditampilkan sekali.</span>
    </div>
    <button class="btn primary block" type="submit" id="su-submit">Simpan</button>
  </form>
</section>

<!-- Menu aksi untuk satu pengguna -->
<section class="sheet" id="sheet-actions" role="dialog" aria-modal="true" aria-labelledby="sa-title" aria-hidden="true">
  <div class="grab"></div>
  <div class="sheet-inner">
    <div class="head"><div><small id="sa-sub"></small><div class="big" id="sa-title"></div></div>
      <button class="round-btn" type="button" data-close-sheet aria-label="Tutup"><?= icon('x') ?></button></div>
    <p class="sheet-note" id="sa-note" hidden></p>
    <div class="sheet-actions">
      <button class="btn dark-btn" type="button" id="act-edit"><?= icon('edit') ?>Ubah nama / peran</button>
      <button class="btn dark-btn" type="button" id="act-reset"><?= icon('key') ?>Reset password</button>
      <button class="btn danger" type="button" id="act-toggle"></button>
    </div>
  </div>
</section>

<!-- Konfirmasi -->
<section class="sheet" id="sheet-confirm" role="alertdialog" aria-modal="true" aria-labelledby="sc-title" aria-hidden="true">
  <div class="grab"></div>
  <div class="sheet-inner">
    <div><div class="big" id="sc-title"></div><p class="sheet-note" id="sc-body"></p></div>
    <div class="btn-row">
      <button class="btn dark-btn" type="button" data-close-sheet>Batal</button>
      <button class="btn primary" type="button" id="sc-ok">Ya, lanjutkan</button>
    </div>
  </div>
</section>

<!-- Password sementara (ditampilkan sekali) -->
<section class="sheet" id="sheet-secret" role="dialog" aria-modal="true" aria-labelledby="ss-title" aria-hidden="true">
  <div class="grab"></div>
  <div class="sheet-inner">
    <div><small class="sheet-note" id="ss-sub"></small><div class="big" id="ss-title">Password sementara</div></div>
    <div class="secret-box"><code id="ss-pass" aria-live="polite"></code>
      <button class="round-btn accent" type="button" id="ss-copy" aria-label="Salin password"><?= icon('copy') ?></button></div>
    <p class="sheet-note">Serahkan password ini kepada pengguna. Password <strong>hanya ditampilkan sekali</strong> dan tidak dapat dilihat lagi. Pengguna boleh menggantinya sendiri lewat menu Profil kapan saja.</p>
    <button class="btn primary block" type="button" id="ss-done">Selesai</button>
  </div>
</section>
