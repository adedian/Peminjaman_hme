<?php
/**
 * @var array<string,mixed> $user
 * @var string $initial status awal dari query string
 */
use App\Core\View;

View::share('scripts', ['requests.js']);
View::share('sheets', View::render('partials/requests_sheets', [], null));
$isAdmin = $user['role'] === 'admin';
?>
<div id="req-config" hidden data-role="<?= e($user['role']) ?>" data-self="<?= (int) $user['id'] ?>" data-initial="<?= e($initial) ?>"></div>

<label class="search"><?= icon('search') ?><span class="sr-only">Cari pengajuan</span><input type="search" id="req-q" placeholder="<?= $isAdmin ? 'Cari peminjam, barang, atau lokasi' : 'Cari barang, lokasi, atau kode' ?>" autocomplete="off" maxlength="100"></label>

<div class="seg" role="group" aria-label="Filter status" id="req-filter">
  <?php if ($isAdmin): ?>
    <button type="button" data-status="pending" aria-pressed="false">Menunggu</button>
    <button type="button" data-status="approved" aria-pressed="false">Aktif</button>
    <button type="button" data-status="overdue" aria-pressed="false">Terlambat</button>
    <button type="button" data-status="returned" aria-pressed="false">Selesai</button>
    <button type="button" data-status="rejected" aria-pressed="false">Ditolak</button>
    <button type="button" data-status="cancelled" aria-pressed="false">Dibatalkan</button>
    <button type="button" data-status="" aria-pressed="false">Semua</button>
  <?php else: ?>
    <button type="button" data-status="" aria-pressed="false">Semua</button>
    <button type="button" data-status="pending" aria-pressed="false">Menunggu</button>
    <button type="button" data-status="approved" aria-pressed="false">Aktif</button>
    <button type="button" data-status="overdue" aria-pressed="false">Terlambat</button>
    <button type="button" data-status="returned" aria-pressed="false">Selesai</button>
    <button type="button" data-status="rejected" aria-pressed="false">Ditolak</button>
    <button type="button" data-status="cancelled" aria-pressed="false">Dibatalkan</button>
  <?php endif; ?>
</div>

<div class="list loan-list" id="req-list" aria-live="polite"></div>
<div class="card" id="req-empty" hidden><div class="empty"><span class="e-ico"><?= icon('inbox') ?></span><strong id="req-empty-title">Tidak ada data</strong><p id="req-empty-sub"></p></div></div>
<button class="btn ghost block" type="button" id="req-more" hidden>Muat lebih banyak</button>
