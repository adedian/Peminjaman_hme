<?php
/**
 * @var array<string,mixed> $user
 * @var bool $hasActive
 * @var string $today
 */
use App\Core\View;
?>
<?php if ($hasActive): ?>
  <div class="card"><div class="empty">
    <span class="e-ico"><?= icon('lock') ?></span>
    <strong>Kembalikan barang dulu</strong>
    <p>Anda masih memiliki barang yang sedang dipinjam. Konfirmasi pengembaliannya sebelum mengajukan peminjaman baru.</p>
    <a class="btn primary" href="<?= e(url('/requests?status=approved')) ?>" style="margin-top:var(--s-3)">Lihat pinjaman aktif</a>
  </div></div>
<?php else: ?>
<?php View::share('scripts', ['request-new.js']); ?>
<div id="rn-config" hidden data-today="<?= e($today) ?>"></div>

<section class="card" aria-labelledby="h-pick">
  <h2 id="h-pick" class="card-title"><?= icon('box') ?>Pilih barang</h2>
  <label class="search" style="box-shadow:none;background:var(--surface-2)"><?= icon('search') ?><span class="sr-only">Cari barang</span><input type="search" id="pick-q" placeholder="Cari nama atau ID barang" autocomplete="off" maxlength="100"></label>
  <div class="pick-list" id="pick-list" role="group" aria-label="Barang tersedia"><div class="skeleton" style="height:64px"></div><div class="skeleton" style="height:64px"></div></div>
  <p class="empty-filter" id="pick-empty" hidden>Tidak ada barang yang tersedia.</p>
  <span class="hint" data-error-for="item_ids" id="pick-err" role="alert"></span>
</section>

<form class="card stack" id="rn-form" novalidate>
  <h2 class="card-title"><?= icon('list') ?>Detail peminjaman</h2>
  <p class="pqa-note warn" id="cart-warn" role="alert" hidden></p>
  <div class="cart" id="cart" hidden><strong id="cart-count"></strong><div class="cart-items" id="cart-items"></div></div>
  <div class="field">
    <label for="f-date">Tanggal pinjam</label>
    <input class="input" id="f-date" name="borrow_date" type="date" value="<?= e($today) ?>" required>
    <span class="hint" data-error-for="borrow_date"></span>
  </div>
  <div class="field">
    <label for="f-loc">Lokasi tujuan <span class="req">*</span></label>
    <input class="input" id="f-loc" name="location" autocomplete="off" maxlength="255" placeholder="Nama proyek atau lokasi" required>
    <span class="hint" data-error-for="location"></span>
  </div>
  <div class="field">
    <label for="f-team">Tim yang ikut <span class="opt">(opsional)</span></label>
    <input class="input" id="f-team" name="team" autocomplete="off" maxlength="255" placeholder="mis. Teknisi 1, Teknisi 2">
    <span class="hint" data-error-for="team"></span>
  </div>
  <div class="field">
    <label for="f-notes">Keterangan tambahan <span class="opt">(opsional)</span></label>
    <textarea class="textarea" id="f-notes" name="notes" maxlength="2000" placeholder="Informasi mengenai peruntukan alat"></textarea>
    <span class="hint" data-error-for="notes"></span>
  </div>
  <button class="btn primary block" type="submit" id="rn-submit"><?= icon('check') ?>Ajukan Peminjaman</button>
</form>
<?php endif; ?>
