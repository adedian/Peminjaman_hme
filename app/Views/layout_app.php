<?php
/**
 * Shell aplikasi (setelah login).
 * @var array<string,mixed> $user   baris user dari AuthMiddleware
 * @var string $nav                 kunci menu aktif
 * @var string $title
 * @var string $content
 * @var array<int,string>|null $scripts
 */
use App\Core\Session;
use App\Core\View;

$scripts = View::shared('scripts', []);
$styles = View::shared('styles', []);
$sheets = View::shared('sheets', '');
$minimal = (bool) ($user['must_change_password'] ?? false);   // wajib ganti password → tanpa navigasi
$isAdmin = ($user['role'] ?? '') === 'admin';
$items = [['home', '/', 'Beranda', 'home']];   // sidebar desktop: semua tujuan
$badge = [];
if ($isAdmin) {
    $items[] = ['requests', '/requests', 'Permohonan', 'inbox'];
    $items[] = ['pqa', '/pqa', 'Tracker PQA', 'bolt'];
    $items[] = ['items', '/items', 'Barang', 'box'];
    $items[] = ['recap', '/recap', 'Rekap', 'chart'];
    $items[] = ['users', '/users', 'Pengguna', 'users'];
    $items[] = ['archive', '/archive', 'Arsip Data Lama', 'history'];
    $items[] = ['logs', '/logs', 'Log Aktivitas', 'shield'];
    $items[] = ['settings', '/settings', 'Pengaturan', 'gear'];
    $badge['requests'] = $minimal ? 0 : \App\Services\LoanService::pendingRequestCount();
    $badge['pqa'] = $minimal ? 0 : \App\Services\PqaService::pendingActionCount();
    $items[] = ['profile', '/profile', 'Profil', 'user'];
    // Bilah bawah ponsel: 5 tujuan utama; sisanya lewat "Lainnya"
    $bottom = [['home', '/', 'Beranda', 'home'], ['requests', '/requests', 'Permohonan', 'inbox'], ['pqa', '/pqa', 'PQA', 'bolt'],
               ['items', '/items', 'Barang', 'box'], ['more', '/more', 'Lainnya', 'more']];
    $moreKeys = ['more', 'recap', 'users', 'archive', 'migration', 'logs', 'settings', 'profile', 'notifications'];
    $bottomActive = in_array($nav, $moreKeys, true) ? 'more' : $nav;
} else {
    $items[] = ['new', '/requests/new', 'Pinjam', 'plus'];
    $items[] = ['requests', '/requests', 'Riwayat', 'history'];
    $items[] = ['pqa', '/pqa', 'PQA', 'bolt'];
    $items[] = ['profile', '/profile', 'Profil', 'user'];
    $bottom = $items;
    $bottomActive = $nav;
}

$unread = $minimal ? 0 : \App\Services\NotificationService::unreadCount((int) ($user['id'] ?? 0));
$toast = Session::pullFlash('toast');
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="base-path" content="<?= e(config('app.base_path', '')) ?>">
<?php if (!$minimal): ?>
<meta name="sync-cursor" content="<?= \App\Services\SyncService::cursor() ?>">
<meta name="sync-interval" content="<?= (int) config('realtime.interval_ms', 1500) ?>">
<?php endif; ?>
<title><?= e($title) ?> — Peminjaman HME</title>
<?php require HME_ROOT . '/app/Views/partials/pwa_head.php'; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="<?= e(asset('css/tokens.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/components.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<?php foreach ($styles as $css): ?>
<link rel="stylesheet" href="<?= e(asset('css/' . $css)) ?>">
<?php endforeach; ?>
</head>
<body>
<?= icon_sprite() ?>
<div class="app<?= $minimal ? ' app-min' : '' ?>">

<?php if (!$minimal): ?>
  <aside class="sidebar only-desktop" aria-label="Navigasi utama">
    <div class="brand"><img src="<?= e(asset('img/logo-mark.png')) ?>" alt=""><div><b>Peminjaman HME</b><span><?= $isAdmin ? 'Admin' : 'Staff' ?></span></div></div>
    <nav>
      <?php foreach ($items as [$key, $path, $label, $ico]): ?>
        <a href="<?= e(url($path)) ?>" data-nav="<?= e($key) ?>"<?= $nav === $key ? ' aria-current="page"' : '' ?>><?= icon($ico) ?><?= e($label) ?><?php if (!empty($badge[$key])): ?><span class="count"><?= (int) $badge[$key] ?></span><?php endif; ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="me">
      <button class="btn block side-btn" type="button" data-theme-toggle><?= icon('moon') ?>Ganti tema</button>
      <form method="post" action="<?= e(url('/logout')) ?>"><?= csrf_field() ?>
        <button class="btn block side-btn" type="submit"><?= icon('logout') ?>Keluar</button>
      </form>
    </div>
  </aside>
<?php endif; ?>

  <main class="main">
    <header class="topbar">
      <a class="brandmark only-mobile" href="<?= e(url('/')) ?>" aria-label="Peminjaman HME — Beranda"><img src="<?= e(asset('img/logo-mark.png')) ?>" alt=""><span class="bm-text"><small>Peminjaman</small><b>HME</b></span></a>
      <div class="who"><small><?= $isAdmin ? 'Admin' : 'Staff' ?> · <?= e($user['name'] ?? '') ?></small><strong><?= e($title) ?></strong></div>
      <?php if (!$minimal): ?>
      <a class="icon-btn bell" id="nav-bell" href="<?= e(url('/notifications')) ?>" aria-label="Notifikasi<?= $unread > 0 ? ' (' . $unread . ' belum dibaca)' : '' ?>"<?= $nav === 'notifications' ? ' aria-current="page"' : '' ?>><?= icon('bell') ?><?php if ($unread > 0): ?><span class="bell-count num" data-unread><?= $unread > 99 ? '99+' : $unread ?></span><?php endif; ?></a>
      <?php endif; ?>
      <span class="avatar" title="<?= e($user['name'] ?? '') ?>"><?= e($user['initials'] ?? '') ?></span>
    </header>

    <?= $content ?>
  </main>

<?php if (!$minimal): ?>
  <nav class="bottom-nav simple" style="--n:<?= count($bottom) ?>" aria-label="Navigasi utama">
    <?php foreach ($bottom as [$key, $path, $label, $ico]): ?>
      <a href="<?= e(url($path)) ?>" data-nav="<?= e($key) ?>"<?= $bottomActive === $key ? ' aria-current="page"' : '' ?>><?= icon($ico) ?><?= e($label) ?><?php if (!empty($badge[$key])): ?><span class="count"><?= (int) $badge[$key] ?></span><?php endif; ?></a>
    <?php endforeach; ?>
  </nav>
<?php endif; ?>
</div>

<div class="scrim" aria-hidden="true"></div>
<?= $sheets ?>

<?php if (is_array($toast)): ?>
  <div id="flash-toast" hidden data-title="<?= e($toast['title'] ?? '') ?>" data-sub="<?= e($toast['sub'] ?? '') ?>" data-kind="<?= e($toast['kind'] ?? '') ?>"></div>
<?php endif; ?>

<script src="<?= e(asset('js/pwa.js')) ?>" defer></script>
<script src="<?= e(asset('js/ui.js')) ?>" defer></script>
<script src="<?= e(asset('js/api.js')) ?>" defer></script>
<?php if (!$minimal): ?>
<script src="<?= e(asset('js/realtime.js')) ?>" defer></script>
<?php endif; ?>
<?php foreach ($scripts as $s): ?>
<script src="<?= e(asset('js/' . $s)) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
