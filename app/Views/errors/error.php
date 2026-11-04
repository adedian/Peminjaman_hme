<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> — Peminjaman HME</title>
<link rel="stylesheet" href="<?= e(asset('css/tokens.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/components.css')) ?>">
</head>
<body>
<main class="main" style="max-width:480px;margin:0 auto;padding-top:var(--s-10)">
  <div class="card"><div class="empty">
    <span class="e-ico"><svg class="ico" aria-hidden="true" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v6M12 16.5h.01"/></svg></span>
    <strong><?= e($status) ?> · <?= e($title) ?></strong>
    <p><?= e($message) ?></p>
    <a class="btn primary" href="<?= e(url('/')) ?>" style="margin-top:var(--s-3)">Kembali ke beranda</a>
  </div></div>
</main>
</body>
</html>
