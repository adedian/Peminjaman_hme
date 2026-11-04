<?php
declare(strict_types=1);

use App\Core\Database;
use App\Services\ImageService;

/** Tulis gambar uji ke berkas sementara. @return string path */
function e2eImg(string $type = 'jpeg', int $w = 900, int $h = 600): string
{
    $p = tempnam(sys_get_temp_dir(), 'e2eimg');
    file_put_contents($p, imgBytes($type, $w, $h));
    return $p;
}

function e2eBytes(string $bytes): string
{
    $p = tempnam(sys_get_temp_dir(), 'e2eb');
    file_put_contents($p, $bytes);
    return $p;
}

/** Buat barang lewat API sebagai admin; kembalikan data barang. */
function e2eNewItem(Client $a, array $fields = [], ?string $photo = null): array
{
    $fields += ['code' => iCode('E2E'), 'name' => 'Barang E2E', 'category_id' => (string) Database::value('SELECT id FROM categories ORDER BY id LIMIT 1')];
    $r = $a->multipart('/items', $fields, $photo ? ['photo' => [$photo, 'image/jpeg', 'foto.jpg']] : []);
    eq(201, $r['status'], $r['body']);
    return $r['json']['data'];
}

// ---------------------------------------------------------------------------
section('E2E Barang — otorisasi');

t('Anonim: semua endpoint barang 401; halaman & media → redirect /login', function () {
    e2eReset();
    $c = new Client();
    foreach ([['GET', '/items'], ['GET', '/items/1'], ['POST', '/items'], ['POST', '/items/1'], ['POST', '/items/1/activate'],
              ['POST', '/items/1/deactivate'], ['GET', '/categories']] as [$m, $p]) {
        $r = $c->api($m, $p, $m === 'GET' ? null : []);
        eq(401, $r['status'], "{$m} {$p}");
    }
    foreach (['/items', '/media/items/' . str_repeat('a', 32) . '.jpg'] as $p) {
        $r = $c->get($p);
        eq(302, $r['status'], $p);
        eq('/login', loc($r), $p);
    }
});

t('Staff + CSRF benar + foto valid ke SEMUA endpoint barang → 403; tidak ada barang/berkas yang tercipta', function () {
    e2eReset();
    $admin = e2eAdmin();
    $item = e2eNewItem($admin, ['name' => 'Milik Admin']);
    $s = e2eLogin('e_staff');
    $files = uploadFiles();
    $count = (int) Database::value('SELECT COUNT(*) FROM items');
    $img = e2eImg();
    $resp = [
        $s->api('GET', '/items'), $s->api('GET', '/categories'), $s->api('GET', "/items/{$item['id']}"),
        $s->multipart('/items', ['code' => iCode('HAK'), 'name' => 'Peretas', 'category_id' => '1'], ['photo' => [$img, 'image/jpeg', 'x.jpg']]),
        $s->multipart("/items/{$item['id']}", ['code' => 'DIRETAS', 'name' => 'Diretas', 'category_id' => '1'], ['photo' => [$img, 'image/jpeg', 'x.jpg']]),
        $s->api('POST', "/items/{$item['id']}/deactivate"), $s->api('POST', "/items/{$item['id']}/activate"),
    ];
    foreach ($resp as $i => $r) {
        eq(403, $r['status'], "endpoint #{$i}");
        eq('forbidden', $r['json']['error']['code'], "endpoint #{$i}");
    }
    eq(403, $s->get('/items')['status'], 'halaman /items');
    eq($count, (int) Database::value('SELECT COUNT(*) FROM items'));
    eq($files, uploadFiles(), 'tidak ada berkas baru');
    $after = ItemServiceFind($item['id']);
    eq(['Milik Admin', true], [$after['name'], $after['is_active']], 'barang tidak berubah');
});

t('CSRF pada unggahan multipart: tanpa/ salah/ token tamu → 403 dan berkas tidak diproses', function () {
    e2eReset();
    $c = new Client();
    $guest = $c->bootstrapGuest();
    $c->loginApi('e_admin', 'Admin-Uji-123', 'admin');
    $good = $c->csrf;
    $files = uploadFiles();
    $img = e2eImg();
    $f = ['code' => iCode('CSRF'), 'name' => 'Tanpa Token', 'category_id' => '1'];
    foreach (['' => 'kosong', 'salah' => 'salah', $guest => 'token tamu'] as $tok => $label) {
        $r = $c->multipart('/items', $f, ['photo' => [$img, 'image/jpeg', 'x.jpg']], $tok === '' ? '' : $tok);
        eq(403, $r['status'], $label);
        eq('csrf_invalid', $r['json']['error']['code'], $label);
    }
    eq($files, uploadFiles());
    eq(0, (int) Database::value('SELECT COUNT(*) FROM items WHERE name = ?', ['Tanpa Token']));
    eq(201, $c->multipart('/items', $f, [], $good)['status'], 'token benar → lolos');
});

// ---------------------------------------------------------------------------
section('E2E Barang — foto');

t('Tambah dengan foto: 201, foto & thumbnail disajikan lewat login, header aman, ETag → 304', function () {
    e2eReset();
    $a = e2eAdmin();
    $it = e2eNewItem($a, ['name' => 'Berfoto', 'finance_ref' => 'REF-9', 'is_pqa' => '1'], e2eImg('jpeg', 2400, 1600));
    eq(true, $it['is_pqa']);
    ok($it['photo_url'] !== null && $it['thumb_url'] !== null);
    lacks('storage', json_encode($it));

    $r = $a->get($it['thumb_url']);
    eq(200, $r['status']);
    eq('image/jpeg', $r['headers']['content-type'][0]);
    eq('nosniff', $r['headers']['x-content-type-options'][0]);
    contains('private', $r['headers']['cache-control'][0]);
    $im = imagecreatefromstring($r['body']);
    ok($im !== false, 'thumbnail adalah gambar valid');
    ok(imagesx($im) <= 360 && imagesy($im) <= 360, 'thumbnail ≤ 360px');
    $full = imagecreatefromstring($a->get($it['photo_url'])['body']);
    ok(max(imagesx($full), imagesy($full)) === 1200, 'foto utama 1200px');
    ok(strlen($r['body']) < 60000, 'thumbnail ringan');

    $etag = $r['headers']['etag'][0];
    $again = $a->get($it['thumb_url'], ['If-None-Match' => $etag]);
    eq(304, $again['status']);
    eq('', $again['body']);

    // staff yang login boleh melihat foto (untuk form peminjaman), tamu tidak
    $s = e2eLogin('e_staff');
    eq(200, $s->get($it['thumb_url'])['status']);
    eq(302, (new Client())->get($it['thumb_url'])['status']);
});

t('Unggahan berbahaya via HTTP: skrip PHP/SVG/poliglot/HTML/sampah → 422 fields.photo; tak ada barang & berkas', function () {
    e2eReset();
    $a = e2eAdmin();
    $files = uploadFiles();
    $count = (int) Database::value('SELECT COUNT(*) FROM items');
    $bad = [
        'shell.php.jpg'  => ["<?php system(\$_GET['c']); ?>", 'image/jpeg'],
        'x.svg'          => ['<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(1)</script></svg>', 'image/svg+xml'],
        'poliglot.jpg'   => ["GIF89a<?php echo 1; ?>", 'image/gif'],
        'halaman.jpg'    => ['<html><script>alert(1)</script></html>', 'image/jpeg'],
        'rusak.png'      => ["\x89PNG\r\n\x1a\n" . random_bytes(200), 'image/png'],
        '../../../x.jpg' => ["\xFF\xD8\xFF\xE0" . random_bytes(100), 'image/jpeg'],
        'raksasa.png'    => [fakePng(9000, 9000), 'image/png'],
    ];
    foreach ($bad as $name => [$bytes, $mime]) {
        $r = $a->multipartBytes('/items', ['code' => iCode('BAD'), 'name' => 'Foto Berbahaya', 'category_id' => '1'], ['photo' => [$bytes, $mime, $name]]);
        eq(422, $r['status'], "{$name}: " . $r['body']);
        ok(isset($r['json']['error']['fields']['photo']), $name);
    }
    eq($count, (int) Database::value('SELECT COUNT(*) FROM items'), 'tidak ada barang tercipta');
    eq($files, uploadFiles(), 'tidak ada berkas tersimpan');
});

t('Unggahan melebihi batas aplikasi (3 MB) → 422 dengan pesan ukuran; array berkas (photo[]) tidak menyebabkan 500', function () {
    e2eReset();
    $a = e2eAdmin();
    $big = e2eBytes(imgBytes('jpeg', 100, 100) . str_repeat("\0", 3 * 1024 * 1024 + 100));
    $r = $a->multipart('/items', ['code' => iCode('BIG'), 'name' => 'Terlalu Besar', 'category_id' => '1'], ['photo' => [$big, 'image/jpeg', 'b.jpg']]);
    eq(422, $r['status']);
    contains('terlalu besar', $r['json']['error']['fields']['photo']);

    $code = iCode('ARR');
    $r = $a->multipart('/items', ['code' => $code, 'name' => 'Array Berkas', 'category_id' => '1'],
        ['photo[0]' => [e2eImg(), 'image/jpeg', 'a.jpg'], 'photo[1]' => [e2eImg(), 'image/jpeg', 'b.jpg']]);
    ok($r['status'] < 500, 'status ' . $r['status']);
    if ($r['status'] === 201) { eq(null, $r['json']['data']['photo_url'], 'berkas bertingkat diabaikan'); }
});

t('Media: traversal & nama tidak sah → 404 (tidak membocorkan berkas apa pun)', function () {
    e2eReset();
    $a = e2eAdmin();
    foreach ([
        '/media/items/..%2f..%2f.env', '/media/items/..%2f..%2fapp%2fbootstrap.php', '/media/items/%2e%2e%2f%2e%2e%2f.env',
        '/media/items/' . str_repeat('a', 32) . '.php', '/media/items/' . str_repeat('a', 32) . '.jpg',          // pola sah, tidak ada
        '/media/items/' . str_repeat('a', 32) . '.jpg%00.php', '/media/items/' . str_repeat('A', 32) . '.JPG',
        '/media/items/x', '/media/items/' . str_repeat('a', 5000) . '.jpg', '/media/items/',
    ] as $p) {
        $r = $a->get($p);
        eq(404, $r['status'], $p);
        lacks('DB_PASS', $r['body'], $p);
        lacks('<?php', $r['body'], $p);
    }
});

t('Ubah via API: ganti foto (lama terhapus), hapus foto, ID terkunci bila ada riwayat (409 JSON)', function () {
    e2eReset();
    $a = e2eAdmin();
    $it = e2eNewItem($a, ['name' => 'Akan Diubah'], e2eImg());
    $old = basename($it['photo_url']);
    $base = ['code' => $it['code'], 'name' => 'Sudah Diubah', 'category_id' => (string) $it['category_id']];

    $r = $a->multipart("/items/{$it['id']}", $base, ['photo' => [e2eImg('png', 500, 500), 'image/png', 'baru.png']]);
    eq(200, $r['status'], $r['body']);
    $new = basename($r['json']['data']['photo_url']);
    ok($new !== $old);
    eq(404, $a->get('/media/items/' . $old)['status'], 'foto lama tidak lagi tersaji');
    eq(200, $a->get('/media/items/' . $new)['status']);

    $r = $a->multipart("/items/{$it['id']}", $base + ['remove_photo' => '1']);
    eq(null, $r['json']['data']['photo_url']);
    eq(404, $a->get('/media/items/' . $new)['status']);

    mkLoan($it['id'], 'returned');
    $r = $a->multipart("/items/{$it['id']}", ['code' => iCode('LOCK')] + $base);
    eq(409, $r['status']);
    eq('code_locked', $r['json']['error']['code']);
    eq(200, $a->multipart("/items/{$it['id']}", $base)['status'], 'kode sama tetap boleh');
});

// ---------------------------------------------------------------------------
section('E2E Barang — aturan & tampilan');

t('Nonaktifkan lewat API: 409 item_in_use bila sedang dipakai; sukses bila tidak; aktifkan lagi; 404', function () {
    e2eReset();
    $a = e2eAdmin();
    $busy = e2eNewItem($a);
    mkLoan($busy['id'], 'approved');
    $r = $a->api('POST', "/items/{$busy['id']}/deactivate");
    eq(409, $r['status']); eq('item_in_use', $r['json']['error']['code']);
    $free = e2eNewItem($a);
    eq(false, $a->api('POST', "/items/{$free['id']}/deactivate")['json']['data']['is_active']);
    eq(true, $a->api('POST', "/items/{$free['id']}/activate")['json']['data']['is_active']);
    eq(404, $a->api('POST', '/items/99999999/deactivate')['status']);
    eq(404, $a->api('GET', '/items/99999999')['status']);
});

t('Mass-assignment ditolak: master_status/is_active/id/photo_path dari input diabaikan', function () {
    e2eReset();
    $a = e2eAdmin();
    $it = e2eNewItem($a, ['master_status' => 'Dipinjam', 'is_active' => '0', 'id' => '1', 'photo_path' => '../../.env']);
    eq(['Tersedia', true, null], [$it['master_status'], $it['is_active'], $it['photo_url']]);
    ok($it['id'] !== 1);
});

t('Daftar API: filter, paginasi, validasi 422; kategori', function () {
    e2eReset();
    $a = e2eAdmin();
    $tag = 'ap' . bin2hex(random_bytes(3));
    $x = e2eNewItem($a, ['name' => "{$tag} satu"]);
    e2eNewItem($a, ['name' => "{$tag} dua"]);
    $r = $a->api('GET', "/items?q={$tag}&limit=1");
    eq(200, $r['status']);
    eq([2, 1], [$r['json']['data']['total'], count($r['json']['data']['items'])]);
    eq(1, count($a->api('GET', "/items?q={$tag}&limit=1&offset=1")['json']['data']['items']));
    foreach (['status=Hilang', 'active=7', 'category_id=abc', 'q[]=x', 'limit=abc&status[]=x'] as $bad) {
        eq(422, $a->api('GET', "/items?{$bad}")['status'], $bad);
    }
    eq(0, $a->api('GET', '/items?q=' . urlencode("' OR 1=1 --"))['json']['data']['total']);
    eq($x['id'], $a->api('GET', "/items/{$x['id']}")['json']['data']['id']);
    $cats = $a->api('GET', '/categories')['json']['data'];
    ok(count($cats) >= 6 && isset($cats[0]['id'], $cats[0]['name']));
});

t('Halaman /items: grid, filter, sheet; nama berbahaya ter-escape (diverifikasi DOM); tanpa jalur internal', function () {
    e2eReset();
    $a = e2eAdmin();
    $it = e2eNewItem($a, ['name' => '<img src=x onerror=alert(1)>', 'finance_ref' => '"><script>alert(2)</script>'], e2eImg());
    $p = $a->get('/items');
    eq(200, $p['status']);
    foreach (['id="item-grid"', 'id="btn-add-item"', 'id="sheet-item"', 'items.js', 'href="/items"', 'accept="image/jpeg,image/png,image/webp"'] as $needle) {
        contains($needle, $p['body']);
    }
    lacks('<img src=x', $p['body']);
    lacks('<script>alert(2)', $p['body']);
    lacks('photo_path', $p['body']);
    lacks('storage/uploads', $p['body']);
    ok(!preg_match('/<script(?![^>]*\bsrc=)/i', $p['body']), 'tanpa skrip inline');

    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $p['body']);
    $xp = new DOMXPath($dom);
    eq(0, $xp->query('//*[@onerror or @onload or @onclick]')->length);
    eq(0, $xp->query('//img[@src="x"]')->length);
    $card = $xp->query('//button[@data-cat and contains(@data-item, "' . $it['code'] . '")]')->item(0);
    ok($card !== null, 'kartu barang ada');
    $data = json_decode($card->getAttribute('data-item'), true);
    eq('<img src=x onerror=alert(1)>', $data['name'], 'data utuh sebagai teks');
    eq($it['thumb_url'] !== null, $xp->query('.//img', $card)->length === 1, 'thumbnail dipakai di kartu');
    $img = $xp->query('.//img', $card)->item(0);
    eq($it['thumb_url'], $img->getAttribute('src'));
});

t('Admin yang wajib ganti password tidak dapat membuka halaman/API barang', function () {
    e2eReset();
    $aid = aUser('e_admin_forced', 'Admin-Uji-123', 'admin', 1, 1);
    $c = e2eLogin('e_admin_forced', 'admin', 'Admin-Uji-123');
    $r = $c->api('GET', '/items');
    eq(403, $r['status']);
    eq('password_change_required', $r['json']['error']['code']);
    eq('/profile/password', loc($c->get('/items')));
    Database::run('UPDATE users SET is_active = 0 WHERE id = ?', [$aid]);
});

function ItemServiceFind(int $id): array
{
    return \App\Services\ItemService::find($id) ?? [];
}
