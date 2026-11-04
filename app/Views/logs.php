<?php
/**
 * @var array<string,mixed> $user
 * @var array<int,array<string,mixed>> $users
 */
use App\Core\View;

View::share('scripts', ['logs.js']);
?>
<form class="card stack" id="log-form" novalidate>
  <h2 class="card-title"><?= icon('shield') ?>Filter log</h2>
  <label class="search" style="box-shadow:none;background:var(--surface-2)"><?= icon('search') ?><span class="sr-only">Cari</span><input type="search" name="q" id="log-q" placeholder="Cari aksi, ID, pengguna, atau IP" autocomplete="off" maxlength="100"></label>
  <div class="log-filters">
    <div class="field">
      <label for="log-group">Jenis aksi</label>
      <select class="select" id="log-group" name="group">
        <option value="">Semua</option>
        <option value="loan">Peminjaman</option>
        <option value="pqa">PQA</option>
        <option value="item">Barang</option>
        <option value="user">Pengguna</option>
        <option value="auth">Login &amp; password</option>
        <option value="report">Laporan</option>
        <option value="settings">Pengaturan</option>
      </select>
    </div>
    <div class="field">
      <label for="log-user">Pelaku</label>
      <select class="select" id="log-user" name="user_id">
        <option value="">Semua pengguna</option>
        <?php foreach ($users as $u): ?>
          <option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?> (<?= e($u['username']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="log-from">Dari tanggal</label>
      <input class="input" type="date" id="log-from" name="from">
      <span class="hint" data-error-for="from"></span>
    </div>
    <div class="field">
      <label for="log-to">Sampai tanggal</label>
      <input class="input" type="date" id="log-to" name="to">
      <span class="hint" data-error-for="to"></span>
    </div>
  </div>
  <button class="btn primary" type="submit"><?= icon('search') ?>Terapkan</button>
</form>

<div class="list" id="log-list" aria-live="polite"></div>
<button class="btn ghost block" type="button" id="log-more" hidden>Muat lebih banyak</button>
