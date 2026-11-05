<?php
/**
 * @var array<string,mixed> $user
 * @var array<int,array<string,mixed>> $items
 * @var int $total
 * @var array<int,array{id:int,name:string}> $categories
 */
use App\Core\View;

View::share('scripts', ['items.js']);
View::share('sheets', View::render('partials/items_sheets', ['categories' => $categories], null));

$active = count(array_filter($items, static fn($i) => $i['is_active']));
?>
<div class="section-head">
  <h2><?= $active ?> barang aktif<?= $total - $active > 0 ? ' · ' . ($total - $active) . ' tersembunyi dari daftar pinjam' : '' ?></h2>
  <button class="btn primary" type="button" id="btn-add-item"><?= icon('plus') ?>Tambah</button>
</div>

<label class="search"><?= icon('search') ?><span class="sr-only">Cari barang</span><input type="search" id="item-filter" placeholder="Cari nama, ID, atau kode ref" autocomplete="off"></label>

<div class="filters">
  <div class="seg" role="group" aria-label="Filter status" id="status-filter">
    <button type="button" data-status="" aria-pressed="true">Semua</button>
    <button type="button" data-status="Tersedia" aria-pressed="false">Tersedia</button>
    <button type="button" data-status="Dipinjam" aria-pressed="false">Dipinjam</button>
    <button type="button" data-status="off" aria-pressed="false">Tersembunyi</button>
  </div>
  <select class="select" id="cat-filter" aria-label="Filter kategori">
    <option value="">Semua kategori</option>
    <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
  </select>
</div>

<?php if ($items === []): ?>
  <div class="card"><div class="empty"><span class="e-ico"><?= icon('box') ?></span><strong>Belum ada barang</strong><p>Tambahkan barang pertama dengan tombol Tambah.</p></div></div>
<?php endif; ?>

<div class="inv-grid" id="item-grid">
<?php foreach ($items as $i): ?>
  <button class="inv-card<?= $i['is_active'] ? '' : ' is-off' ?>" type="button"
          data-item='<?= e(json_encode([
              'id' => $i['id'], 'code' => $i['code'], 'name' => $i['name'], 'category_id' => $i['category_id'],
              'finance_ref' => $i['finance_ref'], 'is_pqa' => $i['is_pqa'], 'active' => $i['is_active'],
              'in_use' => $i['in_use'], 'has_history' => $i['has_history'], 'photo' => $i['photo_url'],
              'status' => $i['master_status'],
          ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'
          data-search="<?= e(mb_strtolower($i['name'] . ' ' . $i['code'] . ' ' . ($i['finance_ref'] ?? ''))) ?>"
          data-cat="<?= (int) $i['category_id'] ?>"
          data-state="<?= $i['is_active'] ? e($i['master_status']) : 'off' ?>">
    <span class="inv-photo">
      <?php if ($i['thumb_url'] !== null): ?>
        <img src="<?= e($i['thumb_url']) ?>" alt="" loading="lazy" decoding="async" width="360" height="270">
      <?php else: ?>
        <?= icon('image') ?>
      <?php endif; ?>
    </span>
    <span class="inv-name"><?= e($i['name']) ?></span>
    <span class="inv-code"><?= e($i['code']) ?><?= $i['finance_ref'] !== null && $i['finance_ref'] !== '' ? ' · Ref ' . e($i['finance_ref']) : '' ?></span>
    <span class="inv-chips">
      <?php if (!$i['is_active']): ?><span class="chip cancelled">Tersembunyi</span>
      <?php elseif ($i['master_status'] === 'Dipinjam'): ?><span class="chip active">Dipinjam</span>
      <?php else: ?><span class="chip done">Tersedia</span><?php endif; ?>
      <?php if ($i['is_pqa']): ?><span class="chip info">PQA</span><?php endif; ?>
    </span>
  </button>
<?php endforeach; ?>
</div>
<p class="empty-filter" id="item-empty" hidden>Tidak ada barang yang cocok dengan pencarian.</p>
