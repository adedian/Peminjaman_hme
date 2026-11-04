<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\HttpException;
use App\Services\ImageService;
use App\Services\ItemService;

// ---------------------------------------------------------------- pembantu gambar -----------

/** Bangun gambar uji dengan GD. @return string byte gambar */
function imgBytes(string $type, int $w, int $h, bool $alpha = false): string
{
    $im = imagecreatetruecolor($w, $h);
    if ($alpha) {
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocatealpha($im, 255, 0, 0, 64));
    } else {
        for ($i = 0; $i < 12; $i++) {
            imagefilledrectangle($im, intdiv($w * $i, 12), 0, intdiv($w * ($i + 1), 12), $h, imagecolorallocate($im, ($i * 21) % 255, ($i * 53) % 255, ($i * 97) % 255));
        }
    }
    ob_start();
    $type === 'png' ? imagepng($im) : ($type === 'webp' ? imagewebp($im, null, 80) : imagejpeg($im, null, 90));
    $out = (string) ob_get_clean();
    imagedestroy($im);
    return $out;
}

/** @return array{name:string,type:string,tmp_name:string,error:int,size:int} */
function upl(string $bytes, string $name = 'foto.jpg', string $type = 'image/jpeg', int $error = UPLOAD_ERR_OK): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'hmeup');
    file_put_contents($tmp, $bytes);
    return ['name' => $name, 'type' => $type, 'tmp_name' => $tmp, 'error' => $error, 'size' => strlen($bytes)];
}

function fakePng(int $w, int $h): string
{
    $chunk = static fn(string $t, string $d): string => pack('N', strlen($d)) . $t . $d . pack('N', crc32($t . $d));
    return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0)) . $chunk('IEND', '');
}

function uploadFiles(): array
{
    return array_map('basename', glob(ImageService::dir() . '/*') ?: []);
}

function photoErr(callable $fn): string
{
    /** @var HttpException $e */
    $e = throws($fn, HttpException::class);
    eq(422, $e->status());
    ok(isset($e->fields()['photo']), 'harus ada fields[photo]');
    return $e->fields()['photo'];
}

function iActor(): array
{
    return ['id' => aUser('i_admin', 'Admin-Uji-123', 'admin'), 'role' => 'admin'];
}

function iCode(string $p = 'IT'): string
{
    static $n = 0;
    return $p . '-' . ++$n . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
}

/** @param array<string,mixed> $o */
function iMake(array $o = [], ?array $file = null): array
{
    return ItemService::create($o + ['code' => iCode(), 'name' => 'Barang Uji', 'category_id' => (int) Database::value('SELECT id FROM categories ORDER BY id LIMIT 1')], $file, iActor(), ureq());
}

function iErr(callable $fn): HttpException
{
    /** @var HttpException $e */
    $e = throws($fn, HttpException::class);
    return $e;
}

// ---------------------------------------------------------------------------
section('Foto barang — penyimpanan aman');

t('JPEG besar: diperkecil (utama ≤1200px, thumbnail ≤360px), nama acak, dua berkas valid', function () {
    ImageService::allowPlainFilesForTests(true);
    $name = ImageService::store(upl(imgBytes('jpeg', 3000, 2000), 'IMG_20260101_NAMA-ASLI.jpg'));
    ok(ImageService::isValidName($name));
    lacks('NAMA-ASLI', $name);
    $thumb = ImageService::thumbName($name);
    [$w, $h, $t] = getimagesize(ImageService::dir() . '/' . $name);
    eq(IMAGETYPE_JPEG, $t);
    eq(1200, $w); eq(800, $h);
    [$tw, $th] = getimagesize(ImageService::dir() . '/' . $thumb);
    eq(360, $tw); eq(240, $th);
    ImageService::delete($name);
    eq([], array_values(array_filter(uploadFiles(), fn($f) => strpos($f, substr($name, 0, 32)) === 0)), 'delete menghapus utama + thumbnail');
});

t('Gambar kecil tidak diperbesar; PNG mempertahankan transparansi; WebP diterima', function () {
    ImageService::allowPlainFilesForTests(true);
    $s = ImageService::store(upl(imgBytes('jpeg', 200, 100)));
    eq([200, 100], array_slice(getimagesize(ImageService::dir() . '/' . $s), 0, 2));
    $p = ImageService::store(upl(imgBytes('png', 100, 100, true), 'a.png', 'image/png'));
    ok(substr($p, -4) === '.png');
    $im = imagecreatefrompng(ImageService::dir() . '/' . $p);
    $alpha = (imagecolorat($im, 10, 10) >> 24) & 0x7F;
    ok($alpha > 0, "alpha harus dipertahankan (alpha={$alpha})");
    $w = ImageService::store(upl(imgBytes('webp', 300, 200), 'a.webp', 'image/webp'));
    ok(substr($w, -5) === '.webp');
    foreach ([$s, $p, $w] as $n) { ImageService::delete($n); }
});

t('Metadata & muatan tersembunyi dibuang: komentar JPEG berisi kode PHP/rahasia tidak ikut tersimpan', function () {
    ImageService::allowPlainFilesForTests(true);
    $jpeg = imgBytes('jpeg', 300, 200);
    $com = "SECRET-GPS-LAT -7.2575 <?php system(\$_GET['c']); ?>";
    $dirty = substr($jpeg, 0, 2) . "\xFF\xFE" . pack('n', strlen($com) + 2) . $com . substr($jpeg, 2);
    contains('SECRET-GPS', $dirty, 'prasyarat: sumber memang berisi muatan');
    $name = ImageService::store(upl($dirty));
    foreach ([$name, ImageService::thumbName($name)] as $n) {
        $out = (string) file_get_contents(ImageService::dir() . '/' . $n);
        lacks('SECRET-GPS', $out);
        lacks('<?php', $out);
        lacks('system(', $out);
    }
    ImageService::delete($name);
});

t('Orientasi EXIF diterapkan (foto HP yang diputar tampil tegak) lalu EXIF dibuang', function () {
    ImageService::allowPlainFilesForTests(true);
    if (!function_exists('exif_read_data')) { return; }
    $jpeg = imgBytes('jpeg', 400, 200);
    $tiff = "II*\0" . pack('V', 8) . pack('v', 1) . pack('vvVvv', 0x0112, 3, 1, 6, 0) . pack('V', 0);
    $payload = "Exif\0\0" . $tiff;
    $withExif = substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload . substr($jpeg, 2);
    $name = ImageService::store(upl($withExif));
    [$w, $h] = getimagesize(ImageService::dir() . '/' . $name);
    eq([200, 400], [$w, $h], 'landscape + orientasi 6 → potret');
    lacks('Exif', (string) file_get_contents(ImageService::dir() . '/' . $name));
    ImageService::delete($name);
});

t('Berkas berbahaya/bukan gambar ditolak (isi diperiksa, nama & Content-Type kiriman diabaikan)', function () {
    ImageService::allowPlainFilesForTests(true);
    $before = uploadFiles();
    $cases = [
        'skrip PHP bernama .jpg'      => upl("<?php system(\$_GET['x']); ?>", 'shell.php.jpg', 'image/jpeg'),
        'PHP dengan Content-Type gambar' => upl("<?php echo 1;", 'a.png', 'image/png'),
        'SVG berisi skrip'            => upl('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'x.svg', 'image/svg+xml'),
        'HTML'                        => upl('<html><script>alert(1)</script></html>', 'x.jpg', 'image/jpeg'),
        'GIF'                         => upl(base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'), 'x.gif', 'image/gif'),
        'poliglot GIF+PHP'            => upl("GIF89a<?php system('id'); ?>", 'x.jpg', 'image/jpeg'),
        'PDF'                         => upl("%PDF-1.4\n1 0 obj<<>>endobj", 'x.pdf', 'application/pdf'),
        'ZIP'                         => upl("PK\x03\x04" . str_repeat("\0", 64), 'x.zip', 'application/zip'),
        'sampah berkepala JPEG'       => upl("\xFF\xD8\xFF\xE0" . random_bytes(300), 'x.jpg', 'image/jpeg'),
        'berkas kosong'               => upl('', 'x.jpg', 'image/jpeg'),
    ];
    foreach ($cases as $label => $file) {
        $msg = photoErr(fn() => ImageService::store($file));
        ok($msg !== '', $label);
    }
    eq($before, uploadFiles(), 'tidak ada berkas yang tersimpan dari percobaan di atas');
});

t('Batas: resolusi > 24 MP ditolak SEBELUM didekode; ukuran berkas melebihi batas ditolak', function () {
    ImageService::allowPlainFilesForTests(true);
    contains('megapiksel', photoErr(fn() => ImageService::store(upl(fakePng(8000, 8000), 'b.png', 'image/png'))));
    contains('megapiksel', photoErr(fn() => ImageService::store(upl(fakePng(65000, 65000), 'b.png', 'image/png'))), 'dimensi ekstrem');
    $big = upl(imgBytes('jpeg', 100, 100) . str_repeat("\0", 3 * 1024 * 1024 + 10));
    contains('terlalu besar', photoErr(fn() => ImageService::store($big)));
});

t('Kode galat unggahan PHP dipetakan ke pesan yang jelas; tmp_name palsu (bukan unggahan HTTP) ditolak', function () {
    ImageService::allowPlainFilesForTests(true);
    contains('terlalu besar', photoErr(fn() => ImageService::store(upl('x', 'a.jpg', 'image/jpeg', UPLOAD_ERR_INI_SIZE))));
    contains('terlalu besar', photoErr(fn() => ImageService::store(upl('x', 'a.jpg', 'image/jpeg', UPLOAD_ERR_FORM_SIZE))));
    contains('terputus', photoErr(fn() => ImageService::store(upl('x', 'a.jpg', 'image/jpeg', UPLOAD_ERR_PARTIAL))));
    contains('gagal diunggah', photoErr(fn() => ImageService::store(upl('x', 'a.jpg', 'image/jpeg', UPLOAD_ERR_NO_TMP_DIR))));
    // Tanpa pintu uji: path apa pun yang bukan hasil unggahan HTTP ditolak (mencegah baca file sistem lewat tmp_name)
    ImageService::allowPlainFilesForTests(false);
    $real = tempnam(sys_get_temp_dir(), 'x');
    file_put_contents($real, imgBytes('jpeg', 50, 50));
    contains('gagal diunggah', photoErr(fn() => ImageService::store(['name' => 'a.jpg', 'type' => 'image/jpeg', 'tmp_name' => $real, 'error' => 0, 'size' => 100])));
    contains('gagal diunggah', photoErr(fn() => ImageService::store(['name' => 'a.jpg', 'type' => 'image/jpeg', 'tmp_name' => HME_ROOT . '/.env', 'error' => 0, 'size' => 100])));
    ImageService::allowPlainFilesForTests(true);
});

t('path()/delete(): hanya nama berpola acak yang diterima — traversal & ekstensi lain ditolak', function () {
    $ok = str_repeat('a', 32);
    foreach (['../x.jpg', "{$ok}.php", "{$ok}.jpg/..", "..\\{$ok}.jpg", "{$ok}.JPG", 'abc.jpg', "{$ok}.jpg\0.php", "{$ok}_t_t.jpg", ''] as $bad) {
        ok(!ImageService::isValidName($bad), "harus ditolak: " . json_encode($bad));
        throws(fn() => ImageService::path($bad), InvalidArgumentException::class);
    }
    ok(ImageService::isValidName("{$ok}.jpg") && ImageService::isValidName("{$ok}_t.webp"));
    // delete() dengan nama jahat / nama thumbnail tidak menyentuh apa pun
    $victim = sys_get_temp_dir() . '/hme_victim.jpg';
    file_put_contents($victim, 'x');
    ImageService::delete('../../../../' . ltrim($victim, '/'));
    ImageService::delete("{$ok}_t.jpg");
    ok(is_file($victim));
    unlink($victim);
});

// ---------------------------------------------------------------------------
section('Barang — tambah');

t('Tambah minimal: bawaan Tersedia & aktif & bukan PQA; audit dan change feed tercatat', function () {
    Database::run('DELETE FROM change_events');
    $code = iCode();
    $it = iMake(['code' => $code, 'name' => '  Multitester  ']);
    eq($code, $it['code']);
    eq('Multitester', $it['name'], 'nama di-trim');
    eq('Tersedia', $it['master_status']);
    eq(true, $it['is_active']);
    eq(false, $it['is_pqa']);
    eq(null, $it['photo_url']);
    eq(null, $it['finance_ref']);
    eq(false, $it['has_history']); eq(false, $it['in_use']);
    eq(1, (int) Database::value("SELECT COUNT(*) FROM change_events WHERE entity_type = 'item' AND action = 'created'"));
    ok(Database::value("SELECT COUNT(*) FROM activity_logs WHERE action = 'item.created' AND entity_id = ?", [(string) $it['id']]) >= 1);
});

t('Tambah lengkap: finance_ref, is_pqa, foto (utama+thumbnail tersimpan, URL lewat controller media)', function () {
    ImageService::allowPlainFilesForTests(true);
    $it = iMake(['name' => 'PQA Baru Uji', 'finance_ref' => 'BK 05/20', 'is_pqa' => '1'], upl(imgBytes('jpeg', 800, 600)));
    eq('BK 05/20', $it['finance_ref']);
    eq(true, $it['is_pqa']);
    $mp = preg_quote(url('/media/items/'), '#');
    ok((bool) preg_match('#^' . $mp . '[a-f0-9]{32}\.jpg$#', (string) $it['photo_url']), (string) $it['photo_url']);
    ok((bool) preg_match('#^' . $mp . '[a-f0-9]{32}_t\.jpg$#', (string) $it['thumb_url']));
    $stored = basename((string) $it['photo_url']);
    ok(is_file(ImageService::path($stored)) && is_file(ImageService::path(ImageService::thumbName($stored))));
    lacks('photo_path', (string) json_encode($it), 'path internal tidak bocor');
    lacks(HME_ROOT, (string) json_encode($it));
});

t('Validasi tambah: ID, nama, kategori, kode ref — pesan per field', function () {
    $act = fn(array $in) => iErr(fn() => ItemService::create($in, null, iActor(), ureq()))->fields();
    $cat = (int) Database::value('SELECT id FROM categories LIMIT 1');
    $ok = ['code' => iCode(), 'name' => 'Nama Ok', 'category_id' => $cat];
    foreach ([
        'code'        => ['', 'a', str_repeat('x', 51), 'HME 01', '../etc', '-awal', "x'; DROP TABLE items;--", 'a<b>c', 'é-01', ['array']],
        'name'        => ['', 'a', str_repeat('n', 151), ['x']],
        'category_id' => ['', '0', 'abc', '99999999', ['1']],
        'finance_ref' => [str_repeat('r', 101), ['x']],
        'is_pqa'      => ['mungkin', ['1']],
    ] as $field => $values) {
        foreach ($values as $v) {
            ok(isset($act([$field => $v] + $ok)[$field]), "{$field} = " . json_encode($v) . ' harus ditolak');
        }
    }
    eq(0, (int) Database::value("SELECT COUNT(*) FROM items WHERE name = 'Nama Ok' AND code NOT LIKE 'IT-%'"));
});

t('ID duplikat (tak peka huruf) ditolak 422; foto yang sudah diproses dibersihkan (tidak ada berkas yatim)', function () {
    ImageService::allowPlainFilesForTests(true);
    $code = iCode('DUP');
    iMake(['code' => $code]);
    $before = uploadFiles();
    $e = iErr(fn() => iMake(['code' => strtolower($code)], upl(imgBytes('jpeg', 300, 200))));
    eq(422, $e->status());
    eq('ID barang sudah digunakan.', $e->fields()['code']);
    eq($before, uploadFiles(), 'foto dari percobaan gagal harus dihapus');
});

t('Foto ditolak → barang TIDAK dibuat; mass-assignment (master_status, is_active, id) diabaikan', function () {
    ImageService::allowPlainFilesForTests(true);
    $code = iCode('NOP');
    iErr(fn() => iMake(['code' => $code], upl('bukan gambar')));
    eq(0, (int) Database::value('SELECT COUNT(*) FROM items WHERE code = ?', [$code]));
    $it = iMake(['master_status' => 'Dipinjam', 'is_active' => 0, 'id' => 1, 'photo_path' => '../../x']);
    eq('Tersedia', $it['master_status']);
    eq(true, $it['is_active']);
    ok($it['id'] > 1 || $it['id'] !== 1);
    eq(null, $it['photo_url']);
});

// ---------------------------------------------------------------------------
section('Barang — ubah');

t('Ubah data biasa; kode boleh diganti selama belum punya riwayat', function () {
    $it = iMake(['name' => 'Lama']);
    $new = iCode('REN');
    $u = ItemService::update($it['id'], ['code' => $new, 'name' => 'Baru', 'category_id' => $it['category_id'], 'finance_ref' => 'REF-1', 'is_pqa' => '1'], null, iActor(), ureq());
    eq([$new, 'Baru', 'REF-1', true], [$u['code'], $u['name'], $u['finance_ref'], $u['is_pqa']]);
    $u = ItemService::update($it['id'], ['code' => $new, 'name' => 'Baru', 'category_id' => $it['category_id']], null, iActor(), ureq());
    eq(null, $u['finance_ref'], 'kosong = hapus kode ref');
    eq(false, $u['is_pqa'], 'tidak dicentang = bukan PQA');
});

t('BR: ID barang yang sudah punya riwayat TERKUNCI (409), tetapi nilai yang sama tetap boleh disimpan', function () {
    $it = iMake();
    mkLoan($it['id'], 'returned');
    $data = ['code' => $it['code'], 'name' => 'Nama Diubah', 'category_id' => $it['category_id']];
    eq('Nama Diubah', ItemService::update($it['id'], $data, null, iActor(), ureq())['name']);
    $e = iErr(fn() => ItemService::update($it['id'], ['code' => iCode('CHG')] + $data, null, iActor(), ureq()));
    eq(409, $e->status()); eq('code_locked', $e->errorCode());
    eq($it['code'], ItemService::find($it['id'])['code']);
    eq(true, ItemService::find($it['id'])['has_history']);
});

t('Riwayat PQA juga mengunci ID; flag is_pqa tidak boleh berubah saat ada pemasangan aktif', function () {
    $it = iMake(['is_pqa' => '1']);
    mkPqa($it['id'], 'Terpasang');
    $base = ['code' => $it['code'], 'name' => $it['name'], 'category_id' => $it['category_id']];
    eq('pqa_active', iErr(fn() => ItemService::update($it['id'], $base, null, iActor(), ureq()))->errorCode(), 'melepas flag saat terpasang');
    eq(true, ItemService::update($it['id'], $base + ['is_pqa' => '1', 'name' => 'Tetap PQA'], null, iActor(), ureq())['is_pqa']);
    eq('code_locked', iErr(fn() => ItemService::update($it['id'], ['code' => iCode('X')] + $base + ['is_pqa' => '1'], null, iActor(), ureq()))->errorCode());
    Database::run("UPDATE pqa_installations SET status = 'Dicabut' WHERE item_id = ?", [$it['id']]);
    eq(false, ItemService::update($it['id'], $base, null, iActor(), ureq())['is_pqa'], 'setelah dicabut boleh');
});

t('Ganti foto: berkas lama dihapus setelah sukses; hapus foto; foto buruk TIDAK merusak foto lama', function () {
    ImageService::allowPlainFilesForTests(true);
    $it = iMake([], upl(imgBytes('jpeg', 600, 400)));
    $old = basename($it['photo_url']);
    $base = ['code' => $it['code'], 'name' => $it['name'], 'category_id' => $it['category_id']];

    $bad = iErr(fn() => ItemService::update($it['id'], $base, upl('<?php evil'), iActor(), ureq()));
    eq(422, $bad->status());
    ok(is_file(ImageService::path($old)), 'foto lama utuh setelah unggahan gagal');
    eq(url('/media/items/' . $old), ItemService::find($it['id'])['photo_url']);

    $u = ItemService::update($it['id'], $base, upl(imgBytes('png', 300, 300), 'n.png', 'image/png'), iActor(), ureq());
    $new = basename($u['photo_url']);
    ok($new !== $old && substr($new, -4) === '.png');
    ok(!is_file(ImageService::dir() . '/' . $old) && !is_file(ImageService::dir() . '/' . ImageService::thumbName($old)), 'berkas lama (utama+thumbnail) terhapus');
    ok(is_file(ImageService::path($new)));

    $u = ItemService::update($it['id'], $base + ['remove_photo' => '1'], null, iActor(), ureq());
    eq(null, $u['photo_url']);
    ok(!is_file(ImageService::dir() . '/' . $new), 'berkas terhapus saat foto dihapus');
});

t('Ubah: ID duplikat 422 + foto baru dibersihkan; tidak ditemukan 404', function () {
    ImageService::allowPlainFilesForTests(true);
    $a = iMake(); $b = iMake();
    $before = uploadFiles();
    $e = iErr(fn() => ItemService::update($b['id'], ['code' => strtoupper($a['code']), 'name' => 'X Y', 'category_id' => $b['category_id']], upl(imgBytes('jpeg', 100, 100)), iActor(), ureq()));
    eq('ID barang sudah digunakan.', $e->fields()['code']);
    eq($before, uploadFiles());
    eq(404, iErr(fn() => ItemService::update(99999999, ['code' => iCode(), 'name' => 'Nama', 'category_id' => $a['category_id']], null, iActor(), ureq()))->status());
});

// ---------------------------------------------------------------------------
section('Barang — nonaktifkan / aktifkan (Q3)');

t('Ditolak (409) bila ada pengajuan pending / pinjaman approved / PQA aktif / berstatus Dipinjam', function () {
    foreach (['pending', 'approved'] as $st) {
        $it = iMake();
        mkLoan($it['id'], $st);
        $e = iErr(fn() => ItemService::setActive($it['id'], false, iActor(), ureq()));
        eq(409, $e->status()); eq('item_in_use', $e->errorCode(), $st);
        eq(true, ItemService::find($it['id'])['in_use']);
    }
    foreach (['Pending', 'Terpasang', 'Req Cabut'] as $st) {
        $it = iMake(['is_pqa' => '1']);
        mkPqa($it['id'], $st);
        eq('item_in_use', iErr(fn() => ItemService::setActive($it['id'], false, iActor(), ureq()))->errorCode(), $st);
    }
    $it = iMake();
    Database::run("UPDATE items SET master_status = 'Dipinjam' WHERE id = ?", [$it['id']]);
    eq('item_in_use', iErr(fn() => ItemService::setActive($it['id'], false, iActor(), ureq()))->errorCode());
});

t('Boleh bila hanya riwayat (returned/rejected/cancelled/Dicabut/Ditolak); aktifkan kembali; idempoten', function () {
    $it = iMake(['is_pqa' => '1']);
    foreach (['returned', 'rejected', 'cancelled'] as $st) { mkLoan($it['id'], $st); }
    foreach (['Dicabut', 'Ditolak'] as $st) { mkPqa($it['id'], $st); }
    eq(false, ItemService::setActive($it['id'], false, iActor(), ureq())['is_active']);
    eq(false, ItemService::setActive($it['id'], false, iActor(), ureq())['is_active'], 'idempoten');
    eq(true, ItemService::setActive($it['id'], true, iActor(), ureq())['is_active']);
    eq(404, iErr(fn() => ItemService::setActive(99999999, true, iActor(), ureq()))->status());
    ok(Database::value("SELECT COUNT(*) FROM activity_logs WHERE action IN ('item.deactivated','item.activated') AND entity_id = ?", [(string) $it['id']]) >= 2);
});

// ---------------------------------------------------------------------------
section('Barang — hapus permanen');

t('Hapus: barang tanpa riwayat terhapus (data + foto + audit + change feed); berriwayat/dipakai ditolak 409 dan tetap utuh; 404', function () {
    ImageService::allowPlainFilesForTests(true);
    $c0 = (int) Database::value('SELECT COALESCE(MAX(id),0) FROM change_events');
    $free = iMake(['name' => 'Salah Input'], upl(imgBytes('jpeg', 400, 300), 'f.jpg'));
    $photo = (string) Database::value('SELECT photo_path FROM items WHERE id = ?', [$free['id']]);
    ok($photo !== '' && is_file(ImageService::path($photo)) && is_file(ImageService::path(ImageService::thumbName($photo))));
    ItemService::delete($free['id'], iActor(), ureq());
    eq(0, (int) Database::value('SELECT COUNT(*) FROM items WHERE id = ?', [$free['id']]));
    ok(!is_file(ImageService::path($photo)) && !is_file(ImageService::path(ImageService::thumbName($photo))), 'foto & thumbnail ikut terhapus');
    eq(null, ItemService::find($free['id']));
    eq(1, (int) Database::value("SELECT COUNT(*) FROM activity_logs WHERE action = 'item.deleted' AND entity_id = ?", [(string) $free['id']]));
    contains('Salah Input', (string) Database::value("SELECT before_json FROM activity_logs WHERE action = 'item.deleted' AND entity_id = ?", [(string) $free['id']]));
    eq(1, (int) Database::value("SELECT COUNT(*) FROM change_events WHERE id > ? AND entity_type = 'item' AND entity_id = ? AND action = 'deleted'", [$c0, (string) $free['id']]));
    eq(404, iErr(fn() => ItemService::delete($free['id'], iActor(), ureq()))->status(), 'sudah terhapus → 404');
    eq(404, iErr(fn() => ItemService::delete(99999999, iActor(), ureq()))->status());

    // Berriwayat (apa pun statusnya) → ditolak, data utuh
    foreach (['pending', 'approved', 'returned', 'rejected', 'cancelled'] as $st) {
        $it = iMake();
        mkLoan($it['id'], $st);
        eq('has_history', iErr(fn() => ItemService::delete($it['id'], iActor(), ureq()))->errorCode(), "loan {$st}");
        eq(409, iErr(fn() => ItemService::delete($it['id'], iActor(), ureq()))->status());
        ok(ItemService::find($it['id']) !== null, "barang utuh ({$st})");
    }
    foreach (['Pending', 'Terpasang', 'Dicabut', 'Ditolak'] as $st) {
        $it = iMake(['is_pqa' => '1']);
        mkPqa($it['id'], $st);
        eq('has_history', iErr(fn() => ItemService::delete($it['id'], iActor(), ureq()))->errorCode(), "pqa {$st}");
        ok(ItemService::find($it['id']) !== null);
    }
    // Nonaktif tanpa riwayat tetap boleh dihapus
    $off = iMake();
    ItemService::setActive($off['id'], false, iActor(), ureq());
    ItemService::delete($off['id'], iActor(), ureq());
    eq(null, ItemService::find($off['id']));
    // Kode bekas barang terhapus dapat dipakai lagi
    $again = iMake(['code' => $free['code']]);
    eq($free['code'], $again['code']);
    ItemService::delete($again['id'], iActor(), ureq());
});

// ---------------------------------------------------------------------------
section('Barang — daftar, pencarian, filter');

t('Daftar: filter teks (nama/ID/ref), kategori, status, aktif; wildcard % dan _ dianggap huruf biasa', function () {
    $tag = 'zq' . bin2hex(random_bytes(3));
    $cats = array_column(ItemService::categories(), 'id');
    $a = iMake(['name' => "{$tag} Alat A", 'category_id' => $cats[0], 'finance_ref' => 'BK-77']);
    $b = iMake(['name' => "{$tag} Alat B", 'category_id' => $cats[1]]);
    $c = iMake(['name' => "{$tag} 50%_diskon", 'category_id' => $cats[0]]);
    Database::run("UPDATE items SET master_status = 'Dipinjam' WHERE id = ?", [$b['id']]);
    ItemService::setActive($c['id'], false, iActor(), ureq());

    $ids = fn(array $f) => array_column(ItemService::list($f)['items'], 'id');
    eq([$a['id'], $b['id'], $c['id']], array_values(array_intersect([$a['id'], $b['id'], $c['id']], $ids(['q' => $tag]))), 'q nama');
    eq([$a['id']], $ids(['q' => 'BK-77']), 'q kode ref');
    eq([$a['id']], $ids(['q' => $a['code']]), 'q ID');
    eq([$c['id']], $ids(['q' => '50%_']), '% dan _ literal');
    eq([], $ids(['q' => "{$tag} %"]) === [] ? [] : array_diff($ids(['q' => "{$tag} %"]), [$a['id'], $b['id'], $c['id']]), 'wildcard tidak melebar');
    eq([$a['id'], $c['id']], array_values(array_intersect($ids(['q' => $tag, 'category_id' => (string) $cats[0]]), [$a['id'], $b['id'], $c['id']])));
    eq([$b['id']], $ids(['q' => $tag, 'status' => 'Dipinjam']));
    eq([$c['id']], $ids(['q' => $tag, 'active' => '0']));
    eq([$a['id'], $b['id']], $ids(['q' => $tag, 'active' => '1']));
    eq(3, ItemService::list(['q' => $tag])['total']);
});

t('Daftar: paginasi (limit/offset/total) & urutan aktif dulu; filter tidak valid → 422; injeksi aman', function () {
    $tag = 'pg' . bin2hex(random_bytes(3));
    for ($i = 1; $i <= 5; $i++) { iMake(['name' => "{$tag} item {$i}"]); }
    $p1 = ItemService::list(['q' => $tag], 2, 0);
    $p2 = ItemService::list(['q' => $tag], 2, 2);
    $p3 = ItemService::list(['q' => $tag], 2, 4);
    eq([5, 2, 2, 1], [$p1['total'], count($p1['items']), count($p2['items']), count($p3['items'])]);
    eq([], array_intersect(array_column($p1['items'], 'id'), array_column($p2['items'], 'id')), 'halaman tidak tumpang tindih');
    eq(200, ItemService::list(['q' => $tag], 9999)['limit'], 'limit dibatasi 200');
    foreach ([['status' => 'Hilang'], ['active' => '2'], ['category_id' => 'abc'], ['q' => ['x']], ['category_id' => '0']] as $bad) {
        eq(422, iErr(fn() => ItemService::list($bad))->status(), json_encode($bad));
    }
    eq(0, ItemService::list(['q' => "x' OR '1'='1' --"])['total']);
    eq(0, ItemService::list(['q' => "'; DROP TABLE items; --"])['total']);
    ok(Database::value('SELECT COUNT(*) FROM items') > 0, 'tabel items utuh');
});

t('Kategori: 6 kategori awal terurut', function () {
    $c = array_column(ItemService::categories(), 'name');
    $six = ['Alat Ukur', 'Alat Berat', 'Drone', 'Toolkit', 'Solar Panel', 'Lain-lain'];
    eq($six, array_values(array_intersect($c, $six)), 'urutan sesuai sort_order (tes lain boleh menambah kategori)');
});
