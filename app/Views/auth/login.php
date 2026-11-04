<?php
/**
 * @var string|null $error
 * @var array{username:string,role:string} $old
 */
$role = ($old['role'] ?? 'staff') === 'admin' ? 'admin' : 'staff';
?>
<?= icon_sprite() ?>
<main class="auth-wrap">
  <div class="auth-card">
    <img class="auth-logo" src="<?= e(asset('img/logo-hme.png')) ?>" alt="Logo HME">
    <h1>Sistem Peminjaman</h1>
    <p class="auth-sub">PT Hexa Multi Energi</p>

    <form method="post" action="<?= e(url('/login')) ?>" class="auth-form" autocomplete="on" novalidate>
      <?= csrf_field() ?>

      <fieldset class="role-switch">
        <legend class="sr-only">Masuk sebagai</legend>
        <input type="radio" name="role" id="role-staff" value="staff"<?= $role === 'staff' ? ' checked' : '' ?>>
        <label for="role-staff">Staff</label>
        <input type="radio" name="role" id="role-admin" value="admin"<?= $role === 'admin' ? ' checked' : '' ?>>
        <label for="role-admin">Admin</label>
      </fieldset>

      <?php if (!empty($error)): ?>
        <div class="form-error" role="alert"><?= icon('shield') ?><span><?= e($error) ?></span></div>
      <?php endif; ?>

      <div class="field">
        <label for="username">Username</label>
        <input class="input" id="username" name="username" type="text" inputmode="text" autocomplete="username"
               autocapitalize="none" autocorrect="off" spellcheck="false" maxlength="50" required
               value="<?= e($old['username'] ?? '') ?>"<?= empty($old['username']) ? ' autofocus' : '' ?>>
      </div>

      <div class="field">
        <label for="password">Password</label>
        <div class="pw-wrap">
          <input class="input" id="password" name="password" type="password" autocomplete="current-password"
                 maxlength="1024" required<?= !empty($old['username']) ? ' autofocus' : '' ?>>
          <button class="pw-toggle" type="button" data-pw-toggle="password" aria-label="Tampilkan password" aria-pressed="false"><?= icon('eye') ?></button>
        </div>
      </div>

      <button class="btn primary block" type="submit">Masuk ke Sistem</button>
    </form>

    <p class="auth-hint">Hubungi Admin jika belum memiliki akses.</p>
    <p class="auth-credit">© PT. HEXA MULTI ENERGI. All rights reserved.<br>Designed by Ade Dian Sukmana</p>
  </div>
</main>
<script src="<?= e(asset('js/login.js')) ?>" defer></script>
