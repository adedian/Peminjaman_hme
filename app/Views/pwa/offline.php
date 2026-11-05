<?php /** Halaman saat tidak ada koneksi (di-cache oleh service worker). Tanpa data pengguna. */ ?>
<main class="auth-wrap">
  <div class="auth-card" style="text-align:center">
    <img class="auth-logo" src="<?= e(asset('img/logo-mark.png')) ?>" alt="Peminjaman HME">
    <h1>Tidak ada koneksi</h1>
    <p class="auth-sub">Peminjaman HME membutuhkan internet untuk menampilkan data terbaru. Periksa koneksi Anda lalu coba lagi.</p>
    <a class="btn primary block" href="<?= e(url('/')) ?>">Coba lagi</a>
  </div>
</main>
