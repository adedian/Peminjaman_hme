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
<?php
$catName = [];
foreach ($categories as $c) { $catName[(int) $c['id']] = $c['name']; }
$hiddenN = count($items) - $active;
?>
<div class="inv-top">
  <div>
    <h2><?= $active ?> barang aktif<?= $hiddenN > 0 ? ' · ' . $hiddenN . ' tersembunyi' : '' ?></h2>
    <span class="sub" id="item-count" aria-live="polite"></span>
  </div>
  <button class="btn primary" type="button" id="btn-add-item"><?= icon('plus') ?>Tambah</button>
</div>

<div class="inv-tools">
  <label class="search"><?= icon('search') ?><span class="sr-only">Cari barang</span><input type="search" id="item-filter" placeholder="Cari nama, ID, ref" autocomplete="off"></label>
  <select class="select" id="cat-filter" aria-label="Filter kategori">
    <option value="">Semua kategori</option>
    <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
  </select>
  <div class="seg" role="group" aria-label="Filter status" id="status-filter">
    <button type="button" data-status="" aria-pressed="true">Aktif</button>
    <button type="button" data-status="Tersedia" aria-pressed="false">Tersedia</button>
    <button type="button" data-status="Dipinjam" aria-pressed="false">Dipinjam</button>
    <button type="button" data-status="off" aria-pressed="false">Tersembunyi</button>
  </div>
</div>

<?php if ($items === []): ?>
  <div class="card"><div class="empty"><span class="e-ico"><?= icon('box') ?></span><strong>Belum ada barang</strong><p>Tambahkan barang pertama dengan tombol Tambah.</p></div></div>
<?php endif; ?>
<div class="inv-table"<?= $items === [] ? ' hidden' : '' ?>>
  <div class="inv-head" aria-hidden="true"><span></span><span>Barang</span><span>Kategori</span><span>Kode ref</span><span>Status</span></div>
<div class="inv-grid" id="item-grid">
<?php foreach ($items as $i): ?>
  <button class="inv-card<?= $i['is_active'] ? '' : ' is-off' ?>" type="button"<?= $i['is_active'] ? '' : ' hidden' ?>
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
        <img src="<?= e($i['thumb_url']) ?>" alt="" loading="lazy" decoding="async" width="72" height="72">
      <?php else: ?>
        <?= icon('image') ?>
      <?php endif; ?>
    </span>
    <span class="inv-main">
      <span class="inv-name"><?= e($i['name']) ?></span>
      <span class="inv-code"><?= e($i['code']) ?><?php if (isset($catName[(int) $i['category_id']])): ?><span class="inv-codecat"><span class="sep">·</span><?= e($catName[(int) $i['category_id']]) ?></span><?php endif; ?></span>
    </span>
    <span class="inv-cat"><?= e($catName[(int) $i['category_id']] ?? '—') ?></span>
    <span class="inv-ref"><?= $i['finance_ref'] !== null && $i['finance_ref'] !== '' ? e($i['finance_ref']) : '—' ?></span>
    <span class="inv-chips">
      <?php if (!$i['is_active']): ?><span class="chip cancelled">Tersembunyi</span>
      <?php elseif ($i['master_status'] === 'Dipinjam'): ?><span class="chip active">Dipinjam</span>
      <?php else: ?><span class="chip done">Tersedia</span><?php endif; ?>
      <?php if ($i['is_pqa']): ?><span class="chip info">PQA</span><?php endif; ?>
    </span>
  </button>
<?php endforeach; ?>
</div>
</div>
<p class="empty-filter" id="item-empty" hidden>Tidak ada barang yang cocok dengan pencarian.</p>
