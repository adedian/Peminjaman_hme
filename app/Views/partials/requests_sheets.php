<?php /** Sheet (dialog gelap) untuk detail pengajuan; isinya dibangun oleh requests.js. */ ?>
<section class="sheet" id="sheet-request" role="dialog" aria-modal="true" aria-labelledby="rq-title" aria-hidden="true">
  <div class="grab"></div>
  <div class="sheet-inner" id="rq-body"></div>
</section>

<!-- Ganti unit -->
<section class="sheet" id="sheet-swap" role="dialog" aria-modal="true" aria-labelledby="sw-title" aria-hidden="true">
  <div class="grab"></div>
  <div class="sheet-inner">
    <div class="head"><div><small id="sw-sub"></small><div class="big" id="sw-title">Ganti unit alat</div></div>
      <button class="round-btn" type="button" data-close-sheet aria-label="Tutup"><?= icon('x') ?></button></div>
    <div class="field">
      <label for="sw-select">Pilih unit pengganti</label>
      <select class="select" id="sw-select"></select>
      <span class="hint" id="sw-hint"></span>
    </div>
    <div class="btn-row">
      <button class="btn dark-btn" type="button" data-close-sheet>Batal</button>
      <button class="btn primary" type="button" id="sw-ok">Simpan</button>
    </div>
  </div>
</section>

<!-- Konfirmasi -->
<section class="sheet" id="sheet-confirm" role="alertdialog" aria-modal="true" aria-labelledby="sc-title" aria-hidden="true">
  <div class="grab"></div>
  <div class="sheet-inner">
    <div><div class="big" id="sc-title"></div><p class="sheet-note" id="sc-body"></p></div>
    <div class="btn-row">
      <button class="btn dark-btn" type="button" data-close-sheet>Batal</button>
      <button class="btn primary" type="button" id="sc-ok">Ya, lanjutkan</button>
    </div>
  </div>
</section>
