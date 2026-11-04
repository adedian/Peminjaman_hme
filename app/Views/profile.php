<?php
/**
 * @var array<string,mixed> $user
 * @var array<string,string> $errors
 * @var bool $forced
 */
\App\Core\View::share('scripts', ['profile.js']);
$err = static fn(string $k): string => isset($errors[$k]) ? (string) $errors[$k] : '';
?>
<?php if ($forced): ?>
  <div class="notice" role="status"><?= icon('lock') ?><span><strong>Ganti password dulu.</strong> Password Anda adalah password sementara. Buat password baru untuk melanjutkan.</span></div>
<?php endif; ?>

<section class="card profile-head">
  <span class="avatar big"><?= e($user['initials']) ?></span>
  <div>
    <strong><?= e($user['name']) ?></strong>
    <div class="sub">@<?= e($user['username']) ?> · <?= $user['role'] === 'admin' ? 'Admin' : 'Staff' ?></div>
  </div>
</section>

<section class="card" aria-labelledby="h-pw">
  <h2 id="h-pw" class="card-title"><?= icon('key') ?>Ganti password</h2>
  <form id="pw-form" method="post" action="<?= e(url('/profile/password')) ?>" class="stack" novalidate>
    <?= csrf_field() ?>
    <div class="field<?= $err('current') ? ' invalid' : '' ?>">
      <label for="current">Password saat ini</label>
      <input class="input" id="current" name="current" type="password" autocomplete="current-password" required maxlength="1024">
      <span class="hint" data-error-for="current"><?= e($err('current')) ?></span>
    </div>
    <div class="field<?= $err('new') ? ' invalid' : '' ?>">
      <label for="new">Password baru</label>
      <input class="input" id="new" name="new" type="password" autocomplete="new-password" required maxlength="1024" aria-describedby="new-hint">
      <span class="hint" id="new-hint" data-error-for="new"><?= $err('new') !== '' ? e($err('new')) : 'Minimal 8 karakter.' ?></span>
    </div>
    <div class="field<?= $err('confirm') ? ' invalid' : '' ?>">
      <label for="confirm">Ulangi password baru</label>
      <input class="input" id="confirm" name="confirm" type="password" autocomplete="new-password" required maxlength="1024">
      <span class="hint" data-error-for="confirm"><?= e($err('confirm')) ?></span>
    </div>
    <button class="btn primary block" type="submit" id="pw-submit">Simpan password</button>
  </form>
</section>

<?php if ($forced): ?>
<form method="post" action="<?= e(url('/logout')) ?>"><?= csrf_field() ?>
  <button class="btn ghost block" type="submit"><?= icon('logout') ?>Keluar</button>
</form>
<?php else: ?>
<section class="card stack mobile-only-block" aria-label="Akun">
  <button class="btn ghost block" type="button" data-theme-toggle><?= icon('moon') ?>Ganti tema terang/gelap</button>
  <form method="post" action="<?= e(url('/logout')) ?>"><?= csrf_field() ?>
    <button class="btn danger block" type="submit"><?= icon('logout') ?>Keluar</button>
  </form>
</section>
<?php endif; ?>
