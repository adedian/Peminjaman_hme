<?php
/**
 * @var array<string,mixed> $user
 * @var array{pending:int,approved:int,returned:int,rejected:int,cancelled:int,requests_pending:int} $summary
 */
use App\Core\View;

$isAdmin = $user['role'] === 'admin';
View::share('scripts', ['requests.js']);
View::share('sheets', View::render('partials/requests_sheets', [], null));
?>
<div id="req-config" hidden data-role="<?= e($user['role']) ?>" data-self="<?= (int) $user['id'] ?>"></div>

<?php if (!$isAdmin): ?>
  <section class="quick" aria-label="Aksi cepat">
    <a class="quick-card primary" href="<?= e(url('/requests/new')) ?>"><strong>Pinjam Barang</strong><?= icon('box', 'q-ico') ?></a>
    <a class="quick-card" href="<?= e(url('/pqa')) ?>"><strong>Tracker PQA</strong><?= icon('bolt', 'q-ico') ?></a>
  </section>
<?php endif; ?>

<section class="stats" aria-label="Ringkasan">
  <?php if ($isAdmin): ?>
    <a class="stat accent" href="<?= e(url('/requests?status=pending')) ?>"><small>Perlu review</small><strong class="num" data-stat="requests_pending"><?= (int) $summary['requests_pending'] ?></strong></a>
    <a class="stat" href="<?= e(url('/requests?status=approved')) ?>"><small>Aktif</small><strong class="num" data-stat="approved"><?= (int) $summary['approved'] ?></strong></a>
    <a class="stat" href="<?= e(url('/requests?status=returned')) ?>"><small>Selesai</small><strong class="num" data-stat="returned"><?= (int) $summary['returned'] ?></strong></a>
  <?php else: ?>
    <a class="stat accent" href="<?= e(url('/requests?status=approved')) ?>"><small>Aktif</small><strong class="num" data-stat="approved"><?= (int) $summary['approved'] ?></strong></a>
    <a class="stat" href="<?= e(url('/requests?status=pending')) ?>"><small>Menunggu</small><strong class="num" data-stat="pending"><?= (int) $summary['pending'] ?></strong></a>
    <a class="stat" href="<?= e(url('/requests?status=returned')) ?>"><small>Selesai</small><strong class="num" data-stat="returned"><?= (int) $summary['returned'] ?></strong></a>
  <?php endif; ?>
</section>

<?php if ($isAdmin): ?>
  <section aria-labelledby="h-pend">
    <div class="section-head"><h2 id="h-pend">Permohonan menunggu</h2><a class="link" href="<?= e(url('/requests?status=pending')) ?>">Lihat semua</a></div>
    <div class="list loan-list" id="feed-pending" data-feed="pending" data-limit="5" data-empty="Tidak ada permohonan yang menunggu." style="margin-top:var(--s-3)"></div>
  </section>
<?php else: ?>
  <section aria-labelledby="h-act">
    <div class="section-head"><h2 id="h-act">Pinjaman aktif</h2><a class="link" href="<?= e(url('/requests?status=approved')) ?>">Lihat semua</a></div>
    <div class="list loan-list" id="feed-active" data-feed="approved" data-limit="5" data-empty="Anda tidak sedang meminjam barang." style="margin-top:var(--s-3)"></div>
  </section>
  <?php if ($summary['pending'] > 0): ?>
  <section aria-labelledby="h-wait">
    <div class="section-head"><h2 id="h-wait">Menunggu persetujuan</h2></div>
    <div class="list loan-list" id="feed-waiting" data-feed="pending" data-limit="5" data-empty="" style="margin-top:var(--s-3)"></div>
  </section>
  <?php endif; ?>
<?php endif; ?>

<section aria-labelledby="h-recent">
  <div class="section-head"><h2 id="h-recent"><?= $isAdmin ? 'Aktivitas terbaru' : 'Riwayat terbaru' ?></h2><a class="link" href="<?= e(url('/requests')) ?>">Lihat semua</a></div>
  <div class="list loan-list" id="feed-recent" data-feed="" data-limit="4" data-empty="Belum ada riwayat peminjaman." style="margin-top:var(--s-3)"></div>
</section>
