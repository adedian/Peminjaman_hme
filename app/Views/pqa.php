<?php
/** @var array<string,mixed> $user */
use App\Core\View;

View::share('scripts', ['pqa.js']);
View::share('sheets', View::render('partials/pqa_sheets', ['user' => $user], null));
$isAdmin = $user['role'] === 'admin';
?>
<div id="pqa-config" hidden data-role="<?= e($user['role']) ?>" data-name="<?= e($user['name']) ?>"></div>

<?php if (!$isAdmin): ?>
  <button class="btn primary block" type="button" id="btn-new-pqa"><?= icon('plus') ?>Pasang PQA Baru</button>
<?php endif; ?>

<label class="search"><?= icon('search') ?><span class="sr-only">Cari unit PQA</span><input type="search" id="pqa-q" placeholder="Cari unit, lokasi, atau PPIC" autocomplete="off" maxlength="100"></label>

<section aria-labelledby="h-pq-pend" id="sec-pending" hidden>
  <div class="section-head"><h2 id="h-pq-pend">Menunggu persetujuan pemasangan</h2><span class="chip pending" id="cnt-pending"></span></div>
  <div class="list loan-list" id="pqa-pending" style="margin-top:var(--s-3)"></div>
</section>

<section aria-labelledby="h-pq-act">
  <div class="section-head"><h2 id="h-pq-act">Aktif di lapangan</h2><span class="chip info" id="cnt-active"></span></div>
  <div class="list loan-list" id="pqa-active" style="margin-top:var(--s-3)"></div>
</section>

<section aria-labelledby="h-pq-his">
  <div class="section-head"><h2 id="h-pq-his">Riwayat</h2><span class="chip cancelled" id="cnt-history"></span></div>
  <div class="list loan-list" id="pqa-history" style="margin-top:var(--s-3)"></div>
  <button class="btn ghost block" type="button" id="pqa-more" hidden style="margin-top:var(--s-3)">Muat lebih banyak</button>
</section>
