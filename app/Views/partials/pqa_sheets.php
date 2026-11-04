<?php /** @var array<string,mixed> $user */ ?>
<!-- Pasang PQA baru (staff) -->
<section class="sheet" id="sheet-pqa-new" role="dialog" aria-modal="true" aria-labelledby="pn-title" aria-hidden="true">
  <div class="grab"></div>
  <form class="sheet-inner" id="pqa-form" novalidate>
    <div class="head"><div><small>Tracker PQA</small><div class="big" id="pn-title">Pasang PQA baru</div></div>
      <button class="round-btn" type="button" data-close-sheet aria-label="Tutup"><?= icon('x') ?></button></div>
    <div class="field">
      <label for="pn-unit">Unit PQA</label>
      <select class="select" id="pn-unit" name="item_id"></select>
      <span class="hint" data-error-for="item_id" id="pn-unit-hint"></span>
    </div>
    <div class="field">
      <label for="pn-ppic">PPIC / penanggung jawab</label>
      <input class="input" id="pn-ppic" name="ppic" autocomplete="off" maxlength="100">
      <span class="hint" data-error-for="ppic"></span>
    </div>
    <div class="field">
      <label for="pn-loc">Lokasi pemasangan (proyek / klien)</label>
      <input class="input" id="pn-loc" name="location" autocomplete="off" maxlength="255" placeholder="mis. PT Wahana">
      <span class="hint" data-error-for="location"></span>
    </div>
    <div class="field">
      <label for="pn-team">Tim pemasang</label>
      <input class="input" id="pn-team" name="install_team" autocomplete="off" maxlength="255" placeholder="Nama anggota tim pemasang">
      <span class="hint" data-error-for="install_team"></span>
    </div>
    <div class="field">
      <label for="pn-days">Target waktu pasang (hari)</label>
      <input class="input" id="pn-days" name="target_days" type="number" inputmode="numeric" min="1" max="3650" placeholder="mis. 7">
      <span class="hint" data-error-for="target_days"></span>
    </div>
    <button class="btn primary block" type="submit" id="pn-submit">Ajukan Pemasangan</button>
  </form>
</section>

<!-- Tambah waktu (staff) -->
<section class="sheet" id="sheet-pqa-ext" role="dialog" aria-modal="true" aria-labelledby="pe-title" aria-hidden="true">
  <div class="grab"></div>
  <form class="sheet-inner" id="pqa-ext-form" novalidate>
    <div class="head"><div><small id="pe-sub"></small><div class="big" id="pe-title">Ajukan tambah waktu</div></div>
      <button class="round-btn" type="button" data-close-sheet aria-label="Tutup"><?= icon('x') ?></button></div>
    <div class="field">
      <label for="pe-days">Tambahan waktu (hari)</label>
      <input class="input" id="pe-days" name="days" type="number" inputmode="numeric" min="1" max="3650" placeholder="mis. 7">
      <span class="hint" data-error-for="days">Target akan bertambah bila admin menyetujui.</span>
    </div>
    <button class="btn primary block" type="submit" id="pe-submit">Kirim Permintaan</button>
  </form>
</section>

<!-- Request pencabutan (staff) -->
<section class="sheet" id="sheet-pqa-rm" role="dialog" aria-modal="true" aria-labelledby="pr-title" aria-hidden="true">
  <div class="grab"></div>
  <form class="sheet-inner" id="pqa-rm-form" novalidate>
    <div class="head"><div><small id="pr-sub"></small><div class="big" id="pr-title">Request pencabutan PQA</div></div>
      <button class="round-btn" type="button" data-close-sheet aria-label="Tutup"><?= icon('x') ?></button></div>
    <div class="field">
      <label for="pr-team">Tim pencabut</label>
      <input class="input" id="pr-team" name="team" autocomplete="off" maxlength="255" placeholder="Nama anggota tim pencabut">
      <span class="hint" data-error-for="team"></span>
    </div>
    <button class="btn danger block" type="submit" id="pr-submit">Kirim Permintaan Pencabutan</button>
  </form>
</section>

<!-- Detail unit -->
<section class="sheet" id="sheet-pqa" role="dialog" aria-modal="true" aria-labelledby="pq-title" aria-hidden="true">
  <div class="grab"></div>
  <div class="sheet-inner" id="pq-body"></div>
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
