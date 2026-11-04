<?php
/** @var array<string,mixed> $user */
use App\Core\View;

View::share('scripts', ['migration.js']);
?>
<p class="recap-note">Migrasi dijalankan dari server dengan <code>php tools/migrate-legacy.php --source=…</code> (lihat dokumentasi). Halaman ini menampilkan hasilnya: setiap baris sumber tercatat sebagai berhasil, gagal, duplikat, atau perlu diperiksa.</p>

<section aria-labelledby="h-runs">
  <div class="section-head"><h2 id="h-runs">Riwayat migrasi</h2></div>
  <div class="list" id="mg-runs" style="margin-top:var(--s-3)"><div class="skeleton" style="height:96px"></div></div>
</section>

<section aria-labelledby="h-issues" id="mg-detail" hidden>
  <div class="section-head"><h2 id="h-issues">Rincian per baris</h2><button class="btn ghost" type="button" id="mg-export"><?= icon('list') ?>Unduh XLSX</button></div>
  <div class="seg" role="group" aria-label="Filter kategori" id="mg-filter" style="margin-top:var(--s-3)">
    <button type="button" data-cat="" aria-pressed="true">Semua</button>
    <button type="button" data-cat="failed" aria-pressed="false">Gagal</button>
    <button type="button" data-cat="needs_review" aria-pressed="false">Perlu diperiksa</button>
    <button type="button" data-cat="duplicate" aria-pressed="false">Duplikat</button>
    <button type="button" data-cat="ok" aria-pressed="false">Berhasil</button>
  </div>
  <label class="search" style="margin-top:var(--s-3)"><?= icon('search') ?><span class="sr-only">Cari</span><input type="search" id="mg-q" placeholder="Cari keterangan atau sheet" autocomplete="off" maxlength="100"></label>
  <div class="list" id="mg-issues" style="margin-top:var(--s-3)"></div>
  <button class="btn ghost block" type="button" id="mg-more" hidden>Muat lebih banyak</button>
</section>
