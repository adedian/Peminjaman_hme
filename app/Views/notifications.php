<?php
/** @var array<string,mixed> $user */
use App\Core\View;

View::share('scripts', ['notifications.js']);
?>
<div class="nt-bar">
  <div class="seg" role="group" aria-label="Filter notifikasi" id="nt-filter">
    <button type="button" data-unread="0" aria-pressed="true">Semua</button>
    <button type="button" data-unread="1" aria-pressed="false">Belum dibaca</button>
  </div>
  <button class="btn ghost" type="button" id="nt-read-all"><?= icon('check') ?>Tandai semua dibaca</button>
</div>

<div class="list" id="nt-list" aria-live="polite"><div class="skeleton" style="height:72px"></div><div class="skeleton" style="height:72px"></div></div>
<button class="btn ghost block" type="button" id="nt-more" hidden>Muat lebih banyak</button>
