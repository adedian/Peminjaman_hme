<?php
/**
 * @var array<string,mixed> $user
 * @var int $month
 * @var int $year
 */
use App\Core\View;
use App\Services\ReportService;

View::share('scripts', ['recap.js']);
?>
<form class="card stack" id="recap-form" novalidate>
  <h2 class="card-title"><?= icon('chart') ?>Rekap peminjaman</h2>
  <div class="field">
    <label for="r-mode">Data yang ditampilkan</label>
    <select class="select" id="r-mode" name="mode">
      <option value="filter" selected>Berdasarkan bulan &amp; tahun</option>
      <option value="all">Semua data (tanpa batas waktu)</option>
    </select>
  </div>
  <div class="recap-filter" id="r-filter">
    <div class="field">
      <label for="r-month">Bulan</label>
      <select class="select" id="r-month" name="month">
        <?php foreach (ReportService::MONTHS as $i => $m): ?>
          <option value="<?= $i + 1 ?>"<?= $i + 1 === $month ? ' selected' : '' ?>><?= e($m) ?></option>
        <?php endforeach; ?>
        <option value="all">Semua bulan</option>
      </select>
      <span class="hint" data-error-for="month"></span>
    </div>
    <div class="field">
      <label for="r-year">Tahun</label>
      <input class="input" id="r-year" name="year" value="<?= (int) $year ?>" inputmode="numeric" autocomplete="off" maxlength="11" placeholder="2026 atau 2025-2026">
      <span class="hint" data-error-for="year">Satu tahun (2026) atau rentang (2025-2026)</span>
    </div>
  </div>
  <div class="btn-row">
    <button class="btn primary" type="submit" id="r-show"><?= icon('chart') ?>Tampilkan</button>
    <button class="btn ghost" type="button" id="r-export"><?= icon('list') ?>Unduh Excel</button>
  </div>
  <p class="recap-note">Excel berisi sheet <b>Laporan Stok</b> (kondisi barang saat ini) dan <b>Transaksi Periode</b> (sesuai filter).</p>
</form>

<div id="recap-result" class="stack" aria-live="polite"></div>
