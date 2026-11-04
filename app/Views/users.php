<?php
/**
 * @var array<string,mixed> $user
 * @var array<int,array<string,mixed>> $users
 */
use App\Core\View;

View::share('scripts', ['users.js']);
View::share('sheets', View::render('partials/users_sheets', [], null));
$active = array_filter($users, static fn($u) => $u['is_active']);
?>
<div class="section-head">
  <h2><?= count($active) ?> akun aktif · <?= count($users) - count($active) ?> nonaktif</h2>
  <button class="btn primary" type="button" id="btn-add-user"><?= icon('plus') ?>Tambah</button>
</div>

<label class="search"><?= icon('search') ?><span class="sr-only">Cari pengguna</span><input type="search" id="user-filter" placeholder="Cari nama atau username" autocomplete="off"></label>

<?php if ($users === []): ?>
  <div class="card"><div class="empty"><span class="e-ico"><?= icon('users') ?></span><strong>Belum ada pengguna</strong><p>Tambahkan staff pertama dengan tombol Tambah.</p></div></div>
<?php endif; ?>

<div class="list user-list" id="user-list" data-self="<?= (int) $user['id'] ?>">
<?php foreach ($users as $u): ?>
  <button class="row user-row<?= $u['is_active'] ? '' : ' is-off' ?>" type="button"
          data-user='<?= e(json_encode([
              'id' => $u['id'], 'username' => $u['username'], 'name' => $u['name'], 'role' => $u['role'],
              'active' => $u['is_active'], 'open' => $u['open_loans'] ?? 0,
          ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'
          data-search="<?= e(mb_strtolower($u['name'] . ' ' . $u['username'])) ?>">
    <span class="avatar"><?= e($u['initials']) ?></span>
    <span class="meta">
      <span class="title" style="display:block"><?= e($u['name']) ?><?= (int) $u['id'] === (int) $user['id'] ? ' (Anda)' : '' ?></span>
      <span class="sub" style="display:block">@<?= e($u['username']) ?> · <?= $u['role'] === 'admin' ? 'Admin' : 'Staff' ?><?= ($u['open_loans'] ?? 0) > 0 ? ' · ' . (int) $u['open_loans'] . ' pinjaman' : '' ?></span>
    </span>
    <?php if (!$u['is_active']): ?><span class="chip cancelled">Nonaktif</span>
    <?php elseif ($u['must_change_password']): ?><span class="chip pending">Password sementara</span>
    <?php else: ?><span class="chip done">Aktif</span><?php endif; ?>
  </button>
<?php endforeach; ?>
</div>
