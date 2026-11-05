<?php /** @var array<string,mixed> $user Menu tambahan admin (ponsel). */ ?>
<div class="list">
  <a class="row" href="<?= e(url('/recap')) ?>">
    <span class="thumb sq"><?= icon('chart') ?></span>
    <span class="meta"><span class="title" style="display:block">Rekap &amp; laporan Excel</span><span class="sub" style="display:block">Statistik per bulan/tahun, alat terpopuler, unduh laporan stok</span></span>
    <?= icon('arrow') ?>
  </a>
  <a class="row" href="<?= e(url('/users')) ?>">
    <span class="thumb sq"><?= icon('users') ?></span>
    <span class="meta"><span class="title" style="display:block">Pengguna</span><span class="sub" style="display:block">Tambah staff/admin, reset password, nonaktifkan akun</span></span>
    <?= icon('arrow') ?>
  </a>
  <a class="row" href="<?= e(url('/archive')) ?>">
    <span class="thumb sq"><?= icon('history') ?></span>
    <span class="meta"><span class="title" style="display:block">Arsip data lama</span><span class="sub" style="display:block">Catatan manual 2025–2026 &amp; register inventaris</span></span>
    <?= icon('arrow') ?>
  </a>
  <a class="row" href="<?= e(url('/migration')) ?>">
    <span class="thumb sq"><?= icon('list') ?></span>
    <span class="meta"><span class="title" style="display:block">Laporan migrasi</span><span class="sub" style="display:block">Hasil pemindahan data dari spreadsheet lama</span></span>
    <?= icon('arrow') ?>
  </a>
  <a class="row" href="<?= e(url('/logs')) ?>">
    <span class="thumb sq"><?= icon('shield') ?></span>
    <span class="meta"><span class="title" style="display:block">Log aktivitas</span><span class="sub" style="display:block">Siapa melakukan apa dan kapan</span></span>
    <?= icon('arrow') ?>
  </a>
  <a class="row" href="<?= e(url('/settings')) ?>">
    <span class="thumb sq"><?= icon('gear') ?></span>
    <span class="meta"><span class="title" style="display:block">Pengaturan</span><span class="sub" style="display:block">Notifikasi HP, pengingat, dan email admin</span></span>
    <?= icon('arrow') ?>
  </a>
  <a class="row" href="<?= e(url('/profile')) ?>">
    <span class="thumb sq"><?= icon('user') ?></span>
    <span class="meta"><span class="title" style="display:block">Profil &amp; keamanan</span><span class="sub" style="display:block">Ganti password, tema, keluar</span></span>
    <?= icon('arrow') ?>
  </a>
</div>
