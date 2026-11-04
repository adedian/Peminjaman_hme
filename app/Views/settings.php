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
