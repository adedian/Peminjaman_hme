<?php /** @var array<int,array{id:int,name:string}> $categories */ ?>
<!-- Tambah / ubah barang -->
<section class="sheet" id="sheet-item" role="dialog" aria-modal="true" aria-labelledby="si-title" aria-hidden="true">
  <div class="grab"></div>
  <form class="sheet-inner" id="item-form" novalidate>
    <div class="head"><div><small id="si-sub">Barang baru</small><div class="big" id="si-title">Tambah barang</div></div>
      <button class="round-btn" type="button" data-close-sheet aria-label="Tutup"><?= icon('x') ?></button></div>

    <div class="photo-pick">
      <div class="photo-preview" id="photo-preview"><?= icon('image') ?></div>
      <div class="photo-actions">
        <label class="btn dark-btn" for="f-photo"><?= icon('camera') ?><span id="photo-label">Pilih foto</span></label>
        <input class="sr-only" type="file" id="f-photo" name="photo_file" accept="image/jpeg,image/png,image/webp">
        <label class="check" id="remove-wrap" hidden><input type="checkbox" id="f-remove"> Hapus foto</label>
        <span class="hint" data-error-for="photo">JPG, PNG, atau WebP. Foto diperkecil otomatis.</span>
      </div>
    </div>

    <div class="field">
      <label for="f-code">ID barang</label>
      <input class="input" id="f-code" name="code" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="50" placeholder="mis. HME-TOOL-01">
      <span class="hint" data-error-for="code" id="code-hint">Unik. Tidak dapat diubah setelah barang punya riwayat.</span>
    </div>
    <div class="field">
      <label for="f-name">Nama alat</label>
      <input class="input" id="f-name" name="name" autocomplete="off" maxlength="150" placeholder="mis. Multitester">
      <span class="hint" data-error-for="name"></span>
    </div>
    <div class="field">
      <label for="f-cat">Kategori</label>
      <select class="select" id="f-cat" name="category_id">
        <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
      </select>
      <span class="hint" data-error-for="category_id"></span>
    </div>
    <div class="field">
      <label for="f-ref">Kode ref / nota finance <span class="opt">(opsional)</span></label>
      <input class="input" id="f-ref" name="finance_ref" autocomplete="off" maxlength="100" placeholder="mis. BK 05/20">
      <span class="hint" data-error-for="finance_ref"></span>
    </div>
    <label class="switch-row" for="f-pqa"><span class="sw-text"><strong>Unit PQA</strong><small>Muncul di Tracker PQA</small></span><input type="checkbox" role="switch" id="f-pqa" name="is_pqa"></label>
    <span class="hint" data-error-for="is_pqa"></span>

    <button class="btn primary block" type="submit" id="si-submit">Simpan</button>
  </form>
</section>

<!-- Menu aksi untuk satu barang -->
<section class="sheet" id="sheet-actions" role="dialog" aria-modal="true" aria-labelledby="sa-title" aria-hidden="true">
  <div class="grab"></div>
  <div class="sheet-inner">
    <div class="head"><div><small id="sa-sub"></small><div class="big" id="sa-title"></div></div>
      <button class="round-btn" type="button" data-close-sheet aria-label="Tutup"><?= icon('x') ?></button></div>
    <p class="sheet-note" id="sa-note" hidden></p>
    <div class="sheet-actions">
      <button class="btn dark-btn" type="button" id="act-edit"><?= icon('edit') ?>Ubah data barang</button>
      <button class="btn danger" type="button" id="act-toggle"></button>
      <button class="btn ghost" type="button" id="act-delete" hidden><?= icon('x') ?>Hapus permanen (salah input)</button>
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
