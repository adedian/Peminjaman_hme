<?php
declare(strict_types=1);

/**
 * Revisi V2 — uji end-to-end lewat HTTP asli: berkas PWA (manifes, service worker, offline, ikon), logo "Peminjaman HME",
 * API notifikasi push (otorisasi, langganan, pengaturan), dan halaman terkait.
 * Server uji berjalan dengan kunci VAPID khusus tes dan TANPA perangkat berlangganan saat aksi yang memicu pengiriman,
 * sehingga tidak ada permintaan keluar ke layanan push sungguhan.
 */

use App\Core\Database;

section('V2 E2E — PWA & logo');

t('Manifes: publik, tipe benar, nama "Peminjaman HME", standalone, ikon (termasuk maskable) semua dapat diunduh dengan ukuran benar', function () {
    $c = new Client();
    $r = $c->get('/manifest.webmanifest');
    eq(200, $r['status']);
    contains('application/manifest+json', $r['headers']['content-type'][0] ?? '');
    $m = json_decode($r['body'], true);
    eq(['Peminjaman HME', 'Peminjaman HME', 'standalone', 'id'], [$m['name'], $m['short_name'], $m['display'], $m['lang']]);
    eq('/', $m['scope']);
    contains('/', $m['start_url']);
    $sizes = [];
    foreach ($m['icons'] as $ic) {
        $img = $c->get($ic['src']);
        eq(200, $img['status'], $ic['src']);
        $info = getimagesizefromstring($img['body']);
        eq('image/png', $info['mime']);
        eq($ic['sizes'], $info[0] . 'x' . $info[1], $ic['src']);
        $sizes[$ic['purpose']][] = $ic['sizes'];
    }
    ok(in_array('192x192', $sizes['any'], true) && in_array('512x512', $sizes['any'], true) && in_array('512x512', $sizes['maskable'], true), json_encode($sizes));
    ok(count($m['shortcuts']) >= 2);
    eq(null, $c->cookie(), 'manifes tidak membuat sesi');
});

t('Service worker: tipe JS, tidak di-cache, cakupan root, versi terisi, memuat penangan push/klik/fetch, aset prapenyimpanan semuanya ada', function () {
    $c = new Client();
    $r = $c->get('/sw.js');
    eq(200, $r['status']);
    contains('javascript', $r['headers']['content-type'][0] ?? '');
    contains('no-cache', $r['headers']['cache-control'][0] ?? '');
    eq('/', $r['headers']['service-worker-allowed'][0] ?? '');
    lacks('__VERSION__', $r['body']); lacks('__PRECACHE__', $r['body']); lacks('__OFFLINE__', $r['body']);
    foreach (["addEventListener('push'", "addEventListener('notificationclick'", "addEventListener('fetch'", "addEventListener('pushsubscriptionchange'"] as $h) {
        contains($h, $r['body']);
    }
    ok(preg_match('/var PRECACHE = (\[.*?\]);/s', $r['body'], $m) === 1);
    foreach (json_decode($m[1], true) as $u) {
        eq(200, $c->get($u)['status'], 'prapenyimpanan: ' . $u);
    }
    // data pengguna tidak boleh ikut tersimpan: SW tidak menyentuh /api, /media, dan HTML aplikasi
    lacks("'/api/", $r['body']);
    eq(null, $c->cookie());
});

t('Halaman offline: publik, tanpa skrip/gaya inline, memakai CSS & logo yang di-cache', function () {
    $r = (new Client())->get('/offline');
    eq(200, $r['status']);
    contains('Tidak ada koneksi', $r['body']);
    contains('Peminjaman HME', $r['body']);
    lacks('<script>', $r['body']);
    lacks('<style', $r['body']);
    contains('logo-mark.png', $r['body']);
});

t('Semua halaman memuat tag PWA (manifes, theme-color, apple-touch-icon), pwa.js eksternal, dan logo "Peminjaman HME" (bukan logo lama)', function () {
    e2eReset();
    $pages = ['/login' => new Client(), '/' => e2eAdmin(), '/pqa' => e2eLogin('e_staff')];
    foreach ($pages as $path => $c) {
        $b = $c->get($path)['body'];
        contains('rel="manifest"', $b, $path);
        contains('manifest.webmanifest', $b, $path);
        contains('name="theme-color"', $b, $path);
        contains('apple-touch-icon', $b, $path);
        contains('js/pwa.js', $b, $path);
        lacks('logo-hme.png', $b, $path);
        lacks('<script>', $b, $path);
    }
    $login = (new Client())->get('/login')['body'];
    contains('<h1>Peminjaman HME</h1>', $login);
    contains('alt="Logo Peminjaman HME"', $login);
    $home = e2eAdmin()->get('/')['body'];
    contains('class="brandmark', $home);
    contains('<b>HME</b>', $home);
    contains('<small>Peminjaman</small>', $home);
});

t('Berkas logo & ikon: ukuran tepat, PNG valid, logo-mark tidak punya tepi kosong berlebih', function () {
    $dir = dirname(__DIR__, 2) . '/public/assets/img/';
    foreach (['icon-192.png' => [192, 192], 'icon-512.png' => [512, 512], 'icon-maskable-512.png' => [512, 512], 'apple-touch-icon.png' => [180, 180], 'favicon-32.png' => [32, 32], 'badge-96.png' => [96, 96]] as $f => $wh) {
        $i = getimagesize($dir . $f);
        eq($wh, [$i[0], $i[1]], $f);
        eq('image/png', $i['mime']);
    }
    $m = imagecreatefrompng($dir . 'logo-mark.png');
    $w = imagesx($m); $h = imagesy($m);
    $edge = 0;
    foreach ([[0, intdiv($h, 2)], [$w - 1, intdiv($h, 2)], [intdiv($w, 2), 0], [intdiv($w, 2), $h - 1]] as [$x, $y]) {
        $edge += (((imagecolorat($m, $x, $y) >> 24) & 0x7F) < 100) ? 1 : 0;   // buram di tengah tiap sisi = heksagon menyentuh tepi (pangkasan rapat)
    }
    ok($edge >= 3, 'heksagon memenuhi bingkai (pangkasan rapat): ' . $edge);
});

section('V2 E2E — push: API & halaman');

t('Otorisasi: anonim 401; semua peran boleh config/devices; status/log/retry khusus admin; penulisan wajib CSRF', function () {
    e2eReset();
    $anon = new Client(); $staff = e2eLogin('e_staff'); $admin = e2eAdmin();
    foreach ([['GET', '/push/config'], ['GET', '/push/devices'], ['POST', '/push/subscribe'], ['POST', '/push/unsubscribe'], ['POST', '/push/test'], ['GET', '/push/status'], ['GET', '/push/log'], ['POST', '/push/log/1/retry']] as [$m, $p]) {
        eq(401, $anon->api($m, $p, $m === 'GET' ? null : [])['status'], "anonim {$m} {$p}");
    }
    foreach ([$staff, $admin] as $c) {
        eq(200, $c->api('GET', '/push/config')['status']);
        eq(200, $c->api('GET', '/push/devices')['status']);
    }
    foreach ([['GET', '/push/status'], ['GET', '/push/log'], ['POST', '/push/log/1/retry']] as [$m, $p]) {
        eq(403, $staff->api($m, $p, $m === 'GET' ? null : [])['status'], "staff {$m} {$p}");
        ok($admin->api($m, $p, $m === 'GET' ? null : [])['status'] < 500);
    }
    foreach ([['POST', '/push/subscribe'], ['POST', '/push/unsubscribe'], ['POST', '/push/test'], ['POST', '/push/devices/1/remove']] as [$m, $p]) {
        eq(403, $staff->api($m, $p, [], 'salah')['status'], "CSRF {$m} {$p}");
    }
    $cfg = $staff->api('GET', '/push/config')['json']['data'];
    eq([true, getenv('VAPID_PUBLIC_KEY')], [$cfg['configured'], $cfg['public_key']]);
    lacks((string) getenv('VAPID_PRIVATE_KEY'), (string) json_encode([$cfg, $admin->api('GET', '/settings')['json'], $admin->api('GET', '/push/status')['json']]), 'kunci privat tidak pernah keluar lewat API');
});

t('Langganan via HTTP: endpoint tidak resmi → 422 (anti-SSRF); langganan sah → 201 dan muncul di daftar tanpa endpoint/kunci; hapus milik sendiri; notifikasi uji tanpa perangkat → 409', function () {
    e2eReset();
    Database::run('DELETE FROM push_subscriptions');
    $staff = e2eLogin('e_staff'); $other = e2eLogin('e_staff2');
    $k = App\Services\WebPush::generateVapidKeys();
    $keys = ['p256dh' => $k['public'], 'auth' => App\Services\WebPush::b64uEncode(random_bytes(16))];
    foreach (['https://169.254.169.254/x', 'http://fcm.googleapis.com/x', 'https://evil.example.com/x', 'https://fcm.googleapis.com.evil.com/x'] as $ep) {
        eq(422, $staff->api('POST', '/push/subscribe', ['endpoint' => $ep, 'keys' => $keys])['status'], $ep);
    }
    eq(409, $staff->api('POST', '/push/test', [])['status'], 'belum ada perangkat');
    $ok = $staff->api('POST', '/push/subscribe', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/E2E-AAA', 'keys' => $keys]);
    eq(201, $ok['status']);
    $devs = $staff->api('GET', '/push/devices')['json']['data'];
    eq(1, count($devs));
    lacks('E2E-AAA', (string) json_encode($devs));
    eq(0, count($other->api('GET', '/push/devices')['json']['data']), 'perangkat orang lain tidak terlihat');
    eq(404, $other->api('POST', '/push/devices/' . $devs[0]['id'] . '/remove', [])['status'], 'tidak bisa menghapus milik orang lain');
    eq(200, $staff->api('POST', '/push/devices/' . $devs[0]['id'] . '/remove', [])['status']);
    eq(0, count($staff->api('GET', '/push/devices')['json']['data']));
    // unsubscribe berdasarkan endpoint (dipakai saat keluar)
    $staff->api('POST', '/push/subscribe', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/E2E-BBB', 'keys' => $keys]);
    eq(1, $staff->api('POST', '/push/unsubscribe', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/E2E-BBB'])['json']['data']['removed']);
    eq(0, (int) Database::value('SELECT COUNT(*) FROM push_subscriptions'));
});

t('Pengaturan: push_enabled & jendela pengingat divalidasi dan tercatat; kunci tak dikenal diabaikan; tanpa kunci dikenal tetap 422', function () {
    e2eReset();
    $admin = e2eAdmin();
    $r = $admin->api('PUT', '/settings', ['push_enabled' => false, 'loan_reminder_hours' => 12, 'pqa_reminder_hours' => '24', 'loan_max_days' => 45, 'loan_seq' => '999', 'vapid_private_key' => 'x']);
    eq(200, $r['status']);
    eq([false, 12, 24, 45], [$r['json']['data']['push_enabled'], $r['json']['data']['loan_reminder_hours'], $r['json']['data']['pqa_reminder_hours'], $r['json']['data']['loan_max_days']]);
    foreach ([['push_enabled' => 'mungkin'], ['loan_reminder_hours' => 0], ['loan_reminder_hours' => 9999], ['loan_max_days' => 'abc']] as $bad) {
        eq(422, $admin->api('PUT', '/settings', $bad)['status'], json_encode($bad));
    }
    eq(45, $admin->api('GET', '/settings')['json']['data']['loan_max_days'], 'tidak berubah oleh input salah');
    $admin->api('PUT', '/settings', ['push_enabled' => true, 'loan_reminder_hours' => 24, 'loan_max_days' => 30]);
    eq(422, $admin->api('PUT', '/settings', [])['status']);
});

t('Peminjaman via HTTP: batas wajib; notifikasi admin dibuat; transaksi gagal tidak membuat notifikasi', function () {
    eLoanReset();
    $staff = e2eLogin('e_staff'); $admin = e2eAdmin();
    $item = iMake(['name' => 'Bor E2E V2'])['id'];
    $n0 = (int) Database::value('SELECT COUNT(*) FROM notifications');
    $none = $staff->api('POST', '/requests', ['item_ids' => [$item], 'location' => 'Tanpa batas']);
    eq(422, $none['status']);
    ok(isset($none['json']['error']['fields']['due_at']));
    eq($n0, (int) Database::value('SELECT COUNT(*) FROM notifications'), 'tidak ada notifikasi untuk transaksi gagal');
    $r = $staff->api('POST', '/requests', ['item_ids' => [$item], 'location' => 'Proyek V2', 'due_at' => dueAt(3)]);
    eq(201, $r['status']);
    ok($r['json']['data']['due_at'] !== null && $r['json']['data']['loans'][0]['remaining_seconds'] > 0);
    $adminId = (int) Database::value("SELECT id FROM users WHERE username = 'e_admin'");
    ok((int) Database::value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'request.submitted' AND id > ?", [$adminId, $n0]) >= 1, 'admin diberi tahu');
    eq(200, $admin->api('POST', "/requests/{$r['json']['data']['id']}/approve", [])['status']);
    eq(200, $staff->api('POST', "/requests/{$r['json']['data']['id']}/return", [])['status']);
});

t('Halaman: Profil memuat kartu notifikasi HP & pasang aplikasi + push.js; Pengaturan memuat bagian push; form pinjam & PQA; logout', function () {
    eLoanReset();
    $staff = e2eLogin('e_staff'); $admin = e2eAdmin();
    $p = $staff->get('/profile')['body'];
    foreach (['id="push-card"', 'id="push-on"', 'id="push-test"', 'id="install-card"', 'id="pwa-install"', 'js/push.js'] as $n) { contains($n, $p, $n); }
    $s = $admin->get('/settings')['body'];
    contains('id="push-form"', $s); contains('id="push-log"', $s);
    lacks('wa-form', $s, 'bagian WhatsApp sudah dihapus');
    contains('id="f-due"', $staff->get('/requests/new')['body']);
    contains('data-status="overdue"', $admin->get('/requests')['body']);
    $pq = $staff->get('/pqa')['body'];
    foreach (['id="pn-when"', 'name="destination"', 'id="pr-site"'] as $n) { contains($n, $pq, $n); }
    lacks('f-wa', $admin->get('/users')['body'], 'kolom WhatsApp pengguna dihapus');
    foreach ([$p, $s, $pq] as $b) { lacks('onclick=', $b); }
});
