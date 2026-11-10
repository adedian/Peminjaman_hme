<?php
/** @var array<string,mixed> $user */
use App\Core\View;

View::share('scripts', ['settings.js']);
?>
<form class="card stack" id="set-form" novalidate>
  <h2 class="card-title"><?= icon('bell') ?>Email notifikasi admin</h2>
  <p class="recap-note">Email dikirim saat ada <b>permohonan baru</b> dan saat <b>barang dikembalikan</b>. Notifikasi lain cukup tampil di lonceng aplikasi.</p>
  <div class="field">
    <label for="set-email">Alamat penerima <span class="req">*</span></label>
    <textarea class="textarea" id="set-email" name="admin_notification_email" rows="2" maxlength="500" placeholder="admin@contoh.com, gudang@contoh.com" disabled></textarea>
    <span class="hint" data-error-for="admin_notification_email">Bisa lebih dari satu (maks. 10), dipisah koma.</span>
  </div>
  <div class="btn-row">
    <button class="btn primary" type="submit" id="set-save" disabled><?= icon('check') ?>Simpan</button>
    <button class="btn ghost" type="button" id="set-test" disabled><?= icon('bell') ?>Kirim email uji</button>
  </div>
</form>

<section class="card stack" aria-labelledby="h-smtp">
  <h2 class="card-title" id="h-smtp"><?= icon('shield') ?>Status pengiriman</h2>
  <div class="facts-grid" id="set-status"><div class="skeleton" style="height:64px"></div></div>
  <p class="recap-note">Akun SMTP diatur di berkas <code>.env</code> server (MAIL_HOST, MAIL_USERNAME, MAIL_PASSWORD, …), tidak dapat diubah dari aplikasi demi keamanan. Bila belum diisi, email dilewati tanpa mengganggu transaksi.</p>
</section>

<form class="card stack" id="push-form" novalidate>
  <h2 class="card-title"><?= icon('bell') ?>Notifikasi HP &amp; pengingat</h2>
  <p class="recap-note">Notifikasi muncul di layar HP/PC lewat <b>aplikasi terpasang (PWA)</b>, walau aplikasi sedang ditutup. Setiap pengguna mengaktifkannya sendiri di menu <b>Profil</b> pada perangkatnya. Pengingat dijalankan oleh cron server.</p>
  <div class="facts-grid" id="push-status"><div class="skeleton" style="height:64px"></div></div>
  <label class="switch-row" for="push-enabled"><span class="sw-text"><strong>Kirim notifikasi ke HP</strong><small>Matikan untuk menghentikan semua push. Notifikasi di lonceng aplikasi tetap tampil.</small></span><input type="checkbox" role="switch" id="push-enabled" disabled></label>
  <div class="field">
    <label for="push-loan-hours">Pengingat jatuh tempo peminjaman (jam sebelumnya)</label>
    <input class="input" id="push-loan-hours" name="loan_reminder_hours" type="number" inputmode="numeric" min="1" max="168" disabled>
    <span class="hint" data-error-for="loan_reminder_hours"></span>
  </div>
  <div class="field">
    <label for="push-pqa-hours">Pengingat pencabutan PQA (jam sebelumnya)</label>
    <input class="input" id="push-pqa-hours" name="pqa_reminder_hours" type="number" inputmode="numeric" min="1" max="168" disabled>
    <span class="hint" data-error-for="pqa_reminder_hours">Bawaan 24 jam = pengingat H-1.</span>
  </div>
  <div class="field">
    <label for="push-max-days">Batas maksimum lama peminjaman (hari)</label>
    <input class="input" id="push-max-days" name="loan_max_days" type="number" inputmode="numeric" min="1" max="365" disabled>
    <span class="hint" data-error-for="loan_max_days"></span>
  </div>
  <div class="btn-row">
    <button class="btn primary" type="submit" id="push-save" disabled><?= icon('check') ?>Simpan</button>
  </div>
</form>

<section class="card stack" aria-labelledby="h-pushlog">
  <h2 class="card-title" id="h-pushlog"><?= icon('list') ?>Log pengiriman push</h2>
  <div class="seg" role="group" aria-label="Filter status" id="push-filter">
    <button type="button" data-status="" aria-pressed="true">Semua</button>
    <button type="button" data-status="pending" aria-pressed="false">Menunggu</button>
    <button type="button" data-status="sent" aria-pressed="false">Terkirim</button>
    <button type="button" data-status="failed" aria-pressed="false">Gagal</button>
    <button type="button" data-status="gone" aria-pressed="false">Berakhir</button>
  </div>
  <div class="list" id="push-log"><div class="skeleton" style="height:64px"></div></div>
  <button class="btn ghost block" type="button" id="push-more" hidden>Muat lebih banyak</button>
</section>
