<?php
/**
 * @var array<string,mixed> $user
 * @var string $tab manual|register
 */
use App\Core\View;

View::share('scripts', ['archive.js']);
$isReg = $tab === 'register';
?>
<div class="seg" role="tablist" aria-label="Jenis arsip">
  <a class="seg-link" role="tab" href="<?= e(url('/archive')) ?>" aria-selected="<?= $isReg ? 'false' : 'true' ?>">Catatan manual</a>
  <a class="seg-link" role="tab" href="<?= e(url('/archive?tab=register')) ?>" aria-selected="<?= $isReg ? 'true' : 'false' ?>">Register inventaris</a>
</div>

<p class="recap-note" id="ar-intro">
  <?php if ($isReg): ?>
    Salinan sheet "Nama Barang Elektronik" (hanya baca). Gunakan <b>Jadikan Master Barang</b> untuk menambahkan barang ke daftar yang bisa dipinjam.
  <?php else: ?>
    Catatan peminjaman manual dari spreadsheet lama (hanya baca). Tidak dihitung di Rekap, sama seperti sistem lama.
  <?php endif; ?>
</p>

<form class="card stack" id="ar-form" data-tab="<?= e($tab) ?>" novalidate>
  <label class="search" style="box-shadow:none;background:var(--surface-2)"><?= icon('search') ?><span class="sr-only">Cari</span><input type="search" name="q" id="ar-q" placeholder="<?= $isReg ? 'Cari nama, lokasi, atau keterangan' : 'Cari alat, pembawa, lokasi, atau tim' ?>" autocomplete="off" maxlength="100"></label>
  <div class="field">
    <label for="ar-filter"><?= $isReg ? 'Bagian' : 'Tanda tanggal' ?></label>
    <select class="select" id="ar-filter" name="filter"><option value="">Semua</option></select>
  </div>
</form>

<div class="section-head"><h2 id="ar-count">Memuat…</h2></div>
<div class="list" id="ar-list" aria-live="polite"></div>
<button class="btn ghost block" type="button" id="ar-more" hidden>Muat lebih banyak</button>
