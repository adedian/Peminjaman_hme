<?php
declare(strict_types=1);

/**
 * Revisi V2b — notifikasi push (PWA). Transport HTTP diganti penampung: tes TIDAK pernah menembak layanan push sungguhan.
 * Pengiriman nyata ke FCM/Mozilla/Apple baru bisa dibuktikan di perangkat sungguhan (HTTPS) — lihat docs/16.
 */

use App\Core\Config;
use App\Core\Database;
use App\Models\Setting;
use App\Services\LoanService;
use App\Services\NotificationService;
use App\Services\PqaService;
use App\Services\PushService;
use App\Services\WebPush;

/** Dekripsi aes128gcm (RFC 8188/8291) sebagai "browser": memakai kunci privat penerima. Dipakai untuk membuktikan isi pesan terbaca. */
function pushDecrypt(string $body, string $uaPrivD, string $uaPub, string $auth): string
{
    $salt = substr($body, 0, 16);
    $idlen = ord($body[20]);
    $asPub = substr($body, 21, $idlen);
    $cipher = substr($body, 21 + $idlen, -16);
    $tag = substr($body, -16);
    $pem = (static function () use ($uaPrivD, $uaPub) {
        $ref = new ReflectionMethod(WebPush::class, 'privateKey');
        $ref->setAccessible(true);
        return $ref->invoke(null, $uaPrivD, $uaPub);
    })();
    $pubRef = new ReflectionMethod(WebPush::class, 'publicKey');
    $pubRef->setAccessible(true);
    $shared = openssl_pkey_derive($pubRef->invoke(null, $asPub), $pem, 32);
    $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPub . $asPub, $auth);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $plain = openssl_decrypt($cipher, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($plain === false) {
        throw new RuntimeException('Dekripsi gagal');
    }
    return rtrim($plain, "\0") === '' ? '' : substr(rtrim($plain, "\0"), 0, -1);   // buang pembatas 0x02
}

/** Pasangan kunci "browser": [d(32), pub(65)] mentah. */
function pushBrowserKeys(): array
{
    $k = WebPush::generateVapidKeys();
    return [WebPush::b64uDecode($k['private']), WebPush::b64uDecode($k['public'])];
}

function pushReset(): void
{
    Database::run('DELETE FROM push_outbox');
    Database::run('DELETE FROM push_subscriptions');
    Database::run("DELETE FROM settings WHERE key_name = 'push_enabled'");
    Config::override('push.vapid_public', (string) getenv('VAPID_PUBLIC_KEY'));
    Config::override('push.vapid_private', (string) getenv('VAPID_PRIVATE_KEY'));
    Config::override('push.subject', 'mailto:tes@hme.test');
    WebPush::setTransport(null);
}

/** Daftarkan satu "perangkat" untuk $user dan kembalikan kunci privat browser-nya untuk dekripsi. */
function pushDevice(array $user, string $host = 'fcm.googleapis.com', ?string $id = null): array
{
    [$d, $pub] = pushBrowserKeys();
    $auth = random_bytes(16);
    $endpoint = 'https://' . $host . '/fcm/send/' . ($id ?? bin2hex(random_bytes(6)));
    $r = PushService::subscribe($user, ['endpoint' => $endpoint, 'keys' => ['p256dh' => WebPush::b64uEncode($pub), 'auth' => WebPush::b64uEncode($auth)]], 'Mozilla/5.0 (Linux; Android 14) Chrome/120.0 Mobile Safari/537.36', ureq());
    return ['id' => $r['id'], 'endpoint' => $endpoint, 'd' => $d, 'pub' => $pub, 'auth' => $auth];
}

/** Penampung transport: antrean respons [status, body, headers]; default 201. */
function pushFake(array $responses = []): object
{
    $o = new class {
        public array $calls = [];
        public array $queue = [];
    };
    $o->queue = $responses;
    WebPush::setTransport(function (string $url, array $headers, string $body, int $timeout) use ($o): array {
        $o->calls[] = ['url' => $url, 'headers' => $headers, 'body' => $body];
        return array_shift($o->queue) ?? [201, '', []];
    });
    return $o;
}

function pushHeader(array $call, string $name): ?string
{
    foreach ($call['headers'] as $h) {
        if (stripos($h, $name . ':') === 0) {
            return trim(substr($h, strlen($name) + 1));
        }
    }
    return null;
}

section('V2b-Push — enkripsi & VAPID');

t('RFC 8291 Lampiran A: keluaran enkripsi identik dengan vektor uji resmi', function () {
    $b = fn(string $s) => WebPush::b64uDecode($s);
    $got = WebPush::b64uEncode(WebPush::encrypt(
        'When I grow up, I want to be a watermelon',
        $b('BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4'), $b('BTBZMqHH6r4Tts7J_aSIgg'),
        $b('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw'), $b('BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8'), $b('DGv6ra1nlYgDCS1FRnbzlw')
    ));
    eq('DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN', $got);
});

t('Enkripsi acak: penerima dapat mendekripsi (30 putaran, isi berbagai panjang & Unicode); salt/kunci berbeda tiap kali', function () {
    $seen = [];
    for ($i = 0; $i < 30; $i++) {
        [$d, $pub] = pushBrowserKeys();
        $auth = random_bytes(16);
        $msg = $i === 0 ? '' : ($i === 1 ? str_repeat('Ω🙂', 600) : json_encode(['title' => 'Judul ' . $i, 'body' => str_repeat('x', $i * 37), 'ü' => "😀\n"]));
        $enc = WebPush::encrypt((string) $msg, $pub, $auth);
        eq((string) $msg, pushDecrypt($enc, $d, $pub, $auth), "putaran {$i}");
        $seen[substr($enc, 0, 16)] = true;
    }
    eq(30, count($seen), 'salt unik');
    [$d, $pub] = pushBrowserKeys();
    $enc = WebPush::encrypt('rahasia', $pub, random_bytes(16));
    $e = throws(fn() => pushDecrypt($enc, $d, $pub, random_bytes(16)), RuntimeException::class);
    ok($e instanceof RuntimeException, 'auth secret salah → tidak terbaca');
    $e2 = throws(fn() => WebPush::encrypt(str_repeat('a', 5000), $pub, random_bytes(16)));
    ok($e2 instanceof RuntimeException, 'isi terlalu besar ditolak');
});

t('VAPID JWT: klaim benar (aud=asal endpoint, exp<24 jam, sub), tanda tangan valid (60 putaran uji konversi DER), rusak ditolak', function () {
    $k = WebPush::generateVapidKeys();
    for ($i = 0; $i < 60; $i++) {
        $jwt = WebPush::vapidJwt('https://fcm.googleapis.com', 'mailto:a@b.co', $k['private'], $k['public']);
        ok(WebPush::verifyJwt($jwt, $k['public']), "putaran {$i}");
    }
    [$h, $c, $s] = explode('.', WebPush::vapidJwt('https://updates.push.services.mozilla.com', 'mailto:a@b.co', $k['private'], $k['public']));
    eq(['typ' => 'JWT', 'alg' => 'ES256'], json_decode(WebPush::b64uDecode($h), true));
    $claims = json_decode(WebPush::b64uDecode($c), true);
    eq(['https://updates.push.services.mozilla.com', 'mailto:a@b.co'], [$claims['aud'], $claims['sub']]);
    ok($claims['exp'] > time() && $claims['exp'] <= time() + 86400);
    eq(64, strlen(WebPush::b64uDecode($s)), 'tanda tangan mentah R||S 64 byte');
    $other = WebPush::generateVapidKeys();
    $jwt = WebPush::vapidJwt('https://fcm.googleapis.com', 'mailto:a@b.co', $k['private'], $k['public']);
    eq(false, WebPush::verifyJwt($jwt, $other['public']), 'kunci publik lain menolak');
    [$h2, $c2, $s2] = explode('.', $jwt);
    eq(false, WebPush::verifyJwt($h2 . '.' . WebPush::b64uEncode('{"aud":"https://evil.example","exp":9999999999,"sub":"x"}') . '.' . $s2, $k['public']), 'klaim diubah → tanda tangan batal');
});

t('Permintaan HTTP: header VAPID/aes128gcm/TTL/Urgency lengkap, aud = asal endpoint, badan terenkripsi', function () {
    pushReset();
    $fake = pushFake([[201, '', []]]);
    [$d, $pub] = pushBrowserKeys();
    $auth = random_bytes(16);
    $r = WebPush::send(['endpoint' => 'https://updates.push.services.mozilla.com/wpush/v2/abc', 'p256dh' => WebPush::b64uEncode($pub), 'auth' => WebPush::b64uEncode($auth)], '{"title":"Hai"}', 3600, 'high');
    eq(201, $r['status']);
    $c = $fake->calls[0];
    eq('https://updates.push.services.mozilla.com/wpush/v2/abc', $c['url']);
    $a = pushHeader($c, 'Authorization');
    ok(preg_match('/^vapid t=([\w-]+\.[\w-]+\.[\w-]+), k=(\S+)$/', (string) $a, $m) === 1, (string) $a);
    eq(Config::get('push.vapid_public'), $m[2]);
    ok(WebPush::verifyJwt($m[1], $m[2]));
    eq('https://updates.push.services.mozilla.com', json_decode(WebPush::b64uDecode(explode('.', $m[1])[1]), true)['aud']);
    eq(['aes128gcm', 'application/octet-stream', '3600', 'high'], [pushHeader($c, 'Content-Encoding'), pushHeader($c, 'Content-Type'), pushHeader($c, 'TTL'), pushHeader($c, 'Urgency')]);
    eq('{"title":"Hai"}', pushDecrypt($c['body'], $d, $pub, $auth));
    lacks('Hai', $c['body'], 'isi tidak terbaca di badan');
});

section('V2b-Push — langganan & keamanan endpoint');

t('Daftar putih host: layanan push resmi diterima; IP, http, port aneh, userinfo, host tiruan, metadata cloud ditolak', function () {
    foreach (['https://fcm.googleapis.com/fcm/send/abc', 'https://updates.push.services.mozilla.com/wpush/v2/abc', 'https://web.push.apple.com/Qx', 'https://wns2-par02p.notify.windows.com/w/?token=abc', 'https://android.googleapis.com/gcm/send/x'] as $ok) {
        eq(true, PushService::endpointAllowed($ok), $ok);
    }
    foreach (['http://fcm.googleapis.com/x', 'https://127.0.0.1/x', 'https://169.254.169.254/latest/meta-data', 'https://localhost/x', 'https://fcm.googleapis.com:8443/x',
              'https://user:pw@fcm.googleapis.com/x', 'https://fcm.googleapis.com.evil.com/x', 'https://evilfcm.googleapis.com/x', 'https://notfcm.googleapis.com.attacker.io/x',
              'https://[::1]/x', 'ftp://fcm.googleapis.com/x', 'https://fcm.googleapis.com/x y', "https://fcm.googleapis.com/x\r\nHost: evil", '', 'bukan url',
              'https://' . str_repeat('a', 700) . '.googleapis.com/x', 'javascript:alert(1)'] as $bad) {
        eq(false, PushService::endpointAllowed($bad), json_encode($bad));
    }
});

t('Subscribe: validasi (endpoint/kunci), upsert, pengambilalihan perangkat oleh akun lain, unsubscribe & hapus hanya milik sendiri, batas 10 perangkat', function () {
    pushReset();
    $a = lStaff('l_staff1'); $b = lStaff('l_staff2'); lReset($a, $b);
    [$d, $pub] = pushBrowserKeys();
    $good = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/AAA', 'keys' => ['p256dh' => WebPush::b64uEncode($pub), 'auth' => WebPush::b64uEncode(random_bytes(16))]];
    foreach ([
        ['endpoint' => 'https://evil.example/x'] + $good, ['endpoint' => ['x']] + $good, ['keys' => ['p256dh' => 'abc', 'auth' => 'abc']] + $good,
        ['keys' => ['p256dh' => WebPush::b64uEncode($pub), 'auth' => WebPush::b64uEncode('pendek')]] + $good, ['keys' => 'x'] + $good, [],
        ['keys' => ['p256dh' => WebPush::b64uEncode("\x05" . substr($pub, 1)), 'auth' => WebPush::b64uEncode(random_bytes(16))]] + $good,
    ] as $bad) {
        eq(422, lErr(fn() => PushService::subscribe($a, $bad, 'UA', ureq()))->status(), json_encode($bad));
    }
    eq(0, (int) Database::value('SELECT COUNT(*) FROM push_subscriptions'));

    $r1 = PushService::subscribe($a, $good, 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Safari/604.1', ureq());
    eq('Safari · iOS', $r1['label']);
    $r2 = PushService::subscribe($a, $good, 'UA', ureq());                         // upsert: baris sama
    eq($r1['id'], $r2['id']);
    eq(1, (int) Database::value('SELECT COUNT(*) FROM push_subscriptions'));
    PushService::subscribe($b, $good, 'UA', ureq());                                // perangkat berpindah ke akun B
    eq((int) $b['id'], (int) Database::value('SELECT user_id FROM push_subscriptions'));
    eq([], PushService::devices($a));
    $devs = PushService::devices($b);
    eq(1, count($devs));
    lacks('endpoint', (string) json_encode($devs), 'daftar perangkat tidak memuat endpoint/kunci');
    // A tidak bisa menghapus milik B
    eq(404, lErr(fn() => PushService::removeDevice($a, $devs[0]['id'], ureq()))->status());
    eq(0, PushService::unsubscribe($a, $good['endpoint'], ureq())['removed']);
    eq(1, PushService::unsubscribe($b, $good['endpoint'], ureq())['removed']);
    // batas 10 perangkat per pengguna
    for ($i = 0; $i < 13; $i++) { pushDevice($a, 'fcm.googleapis.com', 'dev' . $i); }
    eq(PushService::MAX_DEVICES_PER_USER, (int) Database::value('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?', [$a['id']]));
});

section('V2b-Push — antrean, pengiriman, retry');

t('notify() otomatis mengantre push ke perangkat aktif penerima saja; aktor & orang lain tidak; idempoten via dedupe_key', function () {
    pushReset();
    $a = lStaff('l_staff1'); $b = lStaff('l_staff2'); $adm = lAdmin(); lReset($a, $b);
    $da = pushDevice($a); $da2 = pushDevice($a, 'updates.push.services.mozilla.com'); $db = pushDevice($b);
    Database::run('UPDATE push_subscriptions SET is_active = 0 WHERE id = ?', [$da2['id']]);
    eq(1, NotificationService::notify([$a['id']], 'x.tes', 'Judul', 'Isi', '/requests'));
    $rows = Database::all('SELECT subscription_id FROM push_outbox');
    eq([$da['id']], array_map('intval', array_column($rows, 'subscription_id')), 'hanya perangkat aktif milik A');
    eq(0, NotificationService::notify([$a['id']], 'x.tes', 'Judul', 'Isi', '/requests', $a['id']), 'aktor tidak menerima notifikasi sendiri');

    $opts = ['dedupe_key' => 'x:1', 'entity_type' => 'pqa', 'entity_id' => 1];
    eq(2, NotificationService::notify([$a['id'], $b['id']], 'x.rem', 'Pengingat', 'Isi', '/pqa', null, $opts));
    eq(0, NotificationService::notify([$a['id'], $b['id']], 'x.rem', 'Pengingat', 'Isi', '/pqa', null, $opts), 'kunci sama → tidak ada notifikasi/push baru');
    eq(2, (int) Database::value("SELECT COUNT(*) FROM notifications WHERE dedupe_key = 'x:1'"));
    eq(3, (int) Database::value('SELECT COUNT(*) FROM push_outbox'));
    // tanpa kunci VAPID: notifikasi bell tetap dibuat, push tidak diantrekan
    Config::override('push.vapid_private', '');
    Database::run('DELETE FROM push_outbox');
    eq(1, NotificationService::notify([$a['id']], 'x.tes', 'Judul 2', null, null));
    eq(0, (int) Database::value('SELECT COUNT(*) FROM push_outbox'));
    pushReset();
    Setting::set('push_enabled', '0');
    pushDevice($a);
    NotificationService::notify([$a['id']], 'x.tes', 'Judul 3', null, null);
    eq(0, (int) Database::value('SELECT COUNT(*) FROM push_outbox'), 'saklar push_enabled=0');
    Setting::set('push_enabled', '1');
});

t('Pengiriman: isi terdekripsi benar (judul, isi, url berbasis APP_BASE_PATH, tag), status sent, perangkat ditandai berhasil; tidak terkirim dua kali', function () {
    pushReset();
    $a = lStaff('l_staff1'); lReset($a);
    $dev = pushDevice($a);
    NotificationService::notify([$a['id']], 'request.approved', 'Peminjaman disetujui', 'Bor · Lokasi', '/requests?open=7');
    $fake = pushFake([[201, '', []]]);
    $res = PushService::drain();
    eq(1, $res['sent']);
    $c = $fake->calls[0];
    eq($dev['endpoint'], $c['url']);
    $p = json_decode(pushDecrypt($c['body'], $dev['d'], $dev['pub'], $dev['auth']), true);
    eq(['Peminjaman disetujui', 'Bor · Lokasi'], [$p['title'], $p['body']]);
    eq(url('/requests?open=7'), $p['url']);
    ok(strpos($p['tag'], 'hme-') === 0 && is_int($p['id']));
    $row = Database::one('SELECT * FROM push_outbox');
    eq(['sent', 201, 1], [$row['status'], (int) $row['http_status'], (int) $row['attempts']]);
    ok(Database::value('SELECT last_success_at FROM push_subscriptions') !== null);
    eq(0, PushService::drain()['sent']);
    eq(1, count($fake->calls));
});

t('Status layanan push: 404/410 → perangkat dinonaktifkan ("berakhir"); 5xx/jaringan/429 → ulang bertahap; 400/403 → gagal permanen', function () {
    pushReset();
    $a = lStaff('l_staff1'); lReset($a);
    $d1 = pushDevice($a); $d2 = pushDevice($a); $d3 = pushDevice($a);
    NotificationService::notify([$a['id']], 'x.tes', 'Uji', 'Isi', '/');
    pushFake([[410, '{"reason":"gone"}', []], [500, 'oops', []], [403, 'VAPID mismatch', []]]);
    $res = PushService::drain();
    eq([1, 1, 1], [$res['gone'], $res['retry'], $res['failed']]);
    $byStatus = array_column(Database::all('SELECT status, subscription_id FROM push_outbox'), 'status', 'subscription_id');
    eq(['gone', 'pending', 'failed'], [$byStatus[$d1['id']], $byStatus[$d2['id']], $byStatus[$d3['id']]]);
    eq(0, (int) Database::value('SELECT is_active FROM push_subscriptions WHERE id = ?', [$d1['id']]), 'langganan berakhir dinonaktifkan');
    eq(1, (int) Database::value('SELECT is_active FROM push_subscriptions WHERE id = ?', [$d3['id']]), '403 tidak menonaktifkan perangkat');
    ok(strtotime((string) Database::value('SELECT next_attempt_at FROM push_outbox WHERE subscription_id = ?', [$d2['id']])) > time(), 'jeda sebelum ulang');
    // admin dapat mengirim ulang yang gagal
    $row = (int) Database::value('SELECT id FROM push_outbox WHERE subscription_id = ?', [$d3['id']]);
    pushFake([[201, '', []]]);
    PushService::retry($row, lAdmin(), ureq());
    eq(1, PushService::drain()['sent']);
    eq(409, lErr(fn() => PushService::retry($row, lAdmin(), ureq()))->status(), 'yang sudah terkirim tidak bisa diulang');
});

t('Retry: sukses di percobaan ke-3 (jaringan putus lalu 503); 429 Retry-After dihormati; batas 5 percobaan → gagal', function () {
    pushReset();
    $a = lStaff('l_staff1'); lReset($a);
    pushDevice($a);
    NotificationService::notify([$a['id']], 'x.tes', 'Uji', 'Isi', '/');
    pushFake([[0, 'Jaringan: timeout', []], [503, 'busy', []], [201, '', []]]);
    foreach ([1, 2, 3] as $i) {
        Database::run('UPDATE push_outbox SET next_attempt_at = NOW() - INTERVAL 1 SECOND');
        PushService::drain();
    }
    $row = Database::one('SELECT * FROM push_outbox');
    eq(['sent', 3, null], [$row['status'], (int) $row['attempts'], $row['last_error']]);

    pushReset();
    pushDevice($a);
    NotificationService::notify([$a['id']], 'x.tes', 'Uji 2', 'Isi', '/');
    pushFake([[429, 'slow down', ['retry-after' => '1800']]]);
    PushService::drain();
    $wait = strtotime((string) Database::value('SELECT next_attempt_at FROM push_outbox')) - time();
    ok($wait >= 29 * 60 && $wait <= 31 * 60, 'Retry-After 30 menit dihormati: ' . $wait);

    pushReset();
    pushDevice($a);
    NotificationService::notify([$a['id']], 'x.tes', 'Uji 3', 'Isi', '/');
    pushFake(array_fill(0, 10, [502, 'bad gateway', []]));
    for ($i = 0; $i < PushService::MAX_ATTEMPTS; $i++) {
        Database::run('UPDATE push_outbox SET next_attempt_at = NOW() - INTERVAL 1 SECOND');
        PushService::drain();
    }
    $row = Database::one('SELECT * FROM push_outbox');
    eq(['failed', PushService::MAX_ATTEMPTS], [$row['status'], (int) $row['attempts']]);
});

t('Dua proses kirim bersamaan tidak mengirim ganda (klaim atomik); kunci kedaluwarsa diambil alih sekali; antrean >48 jam kedaluwarsa', function () {
    pushReset();
    $a = lStaff('l_staff1'); lReset($a);
    pushDevice($a);
    NotificationService::notify([$a['id']], 'x.tes', 'Uji', 'Isi', '/');
    $id = (int) Database::value('SELECT id FROM push_outbox');
    Database::run("UPDATE push_outbox SET status = 'sending', locked_until = NOW() + INTERVAL 60 SECOND WHERE id = ?", [$id]);
    $fake = pushFake([[201, '', []]]);
    eq(0, PushService::drain()['sent']);
    eq(0, count($fake->calls));
    Database::run('UPDATE push_outbox SET locked_until = NOW() - INTERVAL 1 SECOND WHERE id = ?', [$id]);
    eq(1, PushService::drain()['sent']);
    eq(0, PushService::drain()['sent']);
    eq(1, count($fake->calls));

    pushReset();
    pushDevice($a);
    NotificationService::notify([$a['id']], 'x.tes', 'Lama', 'Isi', '/');
    Database::run('UPDATE push_outbox SET created_at = NOW() - INTERVAL 49 HOUR');
    $fake = pushFake([]);
    PushService::drain();
    eq(0, count($fake->calls));
    contains('Kedaluwarsa', (string) Database::value('SELECT last_error FROM push_outbox'));
});

t('Tanpa kunci VAPID: pesan menunggu & tidak ada permintaan keluar; perangkat nonaktif / notifikasi hilang → dibatalkan', function () {
    pushReset();
    $a = lStaff('l_staff1'); lReset($a);
    $dev = pushDevice($a);
    NotificationService::notify([$a['id']], 'x.tes', 'Uji', 'Isi', '/');
    Config::override('push.vapid_public', '');
    $fake = pushFake([]);
    $res = PushService::drain();
    eq([false, 0], [$res['configured'], count($fake->calls)]);
    eq('pending', Database::value('SELECT status FROM push_outbox'));
    Config::override('push.vapid_public', (string) getenv('VAPID_PUBLIC_KEY'));
    Database::run('UPDATE push_subscriptions SET is_active = 0 WHERE id = ?', [$dev['id']]);
    eq(1, PushService::drain()['cancelled']);
    eq(0, count($fake->calls));
});

t('Pengingat basi dibatalkan sebelum terkirim (jadwal diperpanjang / sudah dikembalikan)', function () {
    pushReset();
    $s = lStaff('l_staff1'); $adm = lAdmin(); lReset($s);
    pushDevice($s);
    $req = lSubmit($s, [lItem('Basi Push')]);
    LoanService::approveRequest($req['id'], $adm, ureq());
    $due = (string) Database::value('SELECT due_at FROM loans WHERE batch_id = ?', [$req['id']]);
    Database::run('DELETE FROM push_outbox');
    NotificationService::notify([$s['id']], 'loan.due_soon', 'Pengingat', 'Isi', '/requests', null, ['dedupe_key' => 'stale:push:1', 'entity_type' => 'loan_batch', 'entity_id' => $req['id'], 'due_ref' => $due]);
    Database::run('UPDATE loans SET due_at = due_at + INTERVAL 1 DAY WHERE batch_id = ?', [$req['id']]);   // diperpanjang → jadwal acuan basi
    $fake = pushFake([]);
    $res = PushService::drain();
    eq([1, 0], [$res['cancelled'], count($fake->calls)]);
    // jadwal cocok → terkirim
    Database::run('UPDATE loans SET due_at = ? WHERE batch_id = ?', [$due, $req['id']]);
    NotificationService::notify([$s['id']], 'loan.due_soon', 'Pengingat 2', 'Isi', '/requests', null, ['dedupe_key' => 'stale:push:2', 'entity_type' => 'loan_batch', 'entity_id' => $req['id'], 'due_ref' => $due]);
    $fake = pushFake([[201, '', []]]);
    eq(1, PushService::drain()['sent']);
});

t('Notifikasi uji: butuh konfigurasi & perangkat (409), lalu terkirim lewat jalur yang sama dan muncul di lonceng', function () {
    pushReset();
    $a = lStaff('l_staff1'); lReset($a);
    eq(409, lErr(fn() => PushService::sendTest($a, ureq()))->status(), 'belum ada perangkat');
    $dev = pushDevice($a);
    Config::override('push.vapid_public', '');
    eq('push_not_configured', lErr(fn() => PushService::sendTest($a, ureq()))->errorCode());
    Config::override('push.vapid_public', (string) getenv('VAPID_PUBLIC_KEY'));
    $fake = pushFake([[201, '', []]]);
    $r = PushService::sendTest($a, ureq());
    eq([1, 1], [$r['devices'], $r['sent']]);
    $p = json_decode(pushDecrypt($fake->calls[0]['body'], $dev['d'], $dev['pub'], $dev['auth']), true);
    eq('Notifikasi uji', $p['title']);
    ok((int) Database::value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'push.test'", [$a['id']]) >= 1);
});

section('V2b-Push — pemicu peminjaman & PQA (hanya setelah transaksi berhasil)');

t('Peminjaman: baru → admin; disetujui → admin lain ("Barang dipinjam") + peminjam; dikembalikan → admin — masing-masing + push ke perangkat penerima', function () {
    pushReset();
    $s = lStaff('l_staff1'); $adm = lAdmin(); lReset($s);
    $adm2 = ['id' => aUser('push_adm2', 'Admin-Uji-123', 'admin'), 'role' => 'admin', 'username' => 'push_adm2'];
    $dAdm2 = pushDevice($adm2); $dStaff = pushDevice($s);
    $fake = pushFake(array_fill(0, 20, [201, '', []]));
    $before = (int) Database::value('SELECT COALESCE(MAX(id),0) FROM notifications');
    $req = lSubmit($s, [lItem('Bor Push'), lItem('Gerinda Push')]);
    $types = fn() => array_column(Database::all('SELECT n.type, n.user_id FROM notifications n WHERE n.id > ? ORDER BY n.id', [$before]), 'type');
    ok(in_array('request.submitted', $types(), true));
    LoanService::approveRequest($req['id'], $adm, ureq());
    $app = Database::all("SELECT user_id, body FROM notifications WHERE id > ? AND type = 'loan.approved'", [$before]);
    ok(in_array($adm2['id'], array_map('intval', array_column($app, 'user_id')), true), 'admin lain diberi tahu');
    ok(!in_array($adm['id'], array_map('intval', array_column($app, 'user_id')), true), 'admin yang menyetujui tidak');
    contains('Bor Push, Gerinda Push', $app[0]['body']);
    contains('batas kembali', $app[0]['body']);
    ok(in_array('request.approved', $types(), true), 'peminjam diberi tahu');
    LoanService::returnRequest($req['id'], $s, ureq());
    ok(in_array('request.returned', $types(), true));
    $res = PushService::drain(50);
    ok($res['sent'] >= 3, 'push terkirim ke HP admin & peminjam: ' . json_encode($res));
    $titles = [];
    foreach ($fake->calls as $c) {
        foreach ([$dAdm2, $dStaff] as $dev) {
            if ($c['url'] === $dev['endpoint']) {
                $titles[] = json_decode(pushDecrypt($c['body'], $dev['d'], $dev['pub'], $dev['auth']), true)['title'];
            }
        }
    }
    ok(in_array('Barang dipinjam', $titles, true) && in_array('Peminjaman disetujui', $titles, true), json_encode($titles));
});

t('Transaksi gagal/ditolak validasi → tidak ada notifikasi maupun push', function () {
    pushReset();
    $s = lStaff('l_staff1'); lReset($s);
    pushDevice($s);
    $n0 = (int) Database::value('SELECT COUNT(*) FROM notifications');
    lErr(fn() => LoanService::submit($s, ['item_ids' => [], 'location' => 'x', 'due_at' => dueAt()], ureq()));
    lErr(fn() => LoanService::submit($s, ['item_ids' => [lItem()], 'location' => 'Lok', 'due_at' => 'salah'], ureq()));
    eq([$n0, 0], [(int) Database::value('SELECT COUNT(*) FROM notifications'), (int) Database::value('SELECT COUNT(*) FROM push_outbox')]);
});

t('PQA: dikonfirmasi/diperpanjang/dicabut → admin lain + pemohon dengan jadwal baru & tujuan; pindah site memberi tahu admin', function () {
    pushReset();
    $s = lStaff('l_staff1'); $adm = lAdmin(); lReset($s);
    $adm2 = ['id' => aUser('push_adm3', 'Admin-Uji-123', 'admin'), 'role' => 'admin', 'username' => 'push_adm3'];
    $n0 = (int) Database::value('SELECT COALESCE(MAX(id),0) FROM notifications');
    $p = qSubmit($s, qUnit('PQA Push'), ['location' => 'Site Push']);
    PqaService::decideInstall($p['id'], true, $adm, ureq());
    $by = fn(string $type) => Database::all('SELECT user_id, body FROM notifications WHERE id > ? AND type = ?', [$n0, $type]);
    $ap = $by('pqa.approved');
    $to = array_map('intval', array_column($ap, 'user_id'));
    ok(in_array($s['id'], $to, true) && in_array($adm2['id'], $to, true) && !in_array($adm['id'], $to, true), json_encode($to));
    contains('jadwal cabut', $ap[0]['body']);
    PqaService::requestExtension($p['id'], 3, $s, ureq());
    PqaService::decideExtension($p['id'], true, $adm, ureq());
    $ex = $by('pqa.extension_approved');
    ok(in_array($adm2['id'], array_map('intval', array_column($ex, 'user_id')), true));
    contains('jadwal cabut baru', $ex[0]['body']);
    PqaService::requestRemoval($p['id'], 'Tim', $s, ureq(), ['destination' => 'site', 'destination_site' => 'Site Baru', 'move_target_days' => 4]);
    PqaService::decideRemoval($p['id'], true, $adm, ureq());
    $rm = $by('pqa.removal_approved');
    contains('pindah ke Site Baru', $rm[0]['body']);
    ok(count($by('pqa.submitted')) >= 2, 'pemasangan di site tujuan menunggu konfirmasi → admin diberi tahu');
});

t('Pembersihan push', function () {
    pushReset();
    Database::run("UPDATE loans SET status = 'returned', returned_at = NOW() WHERE status IN ('approved','pending') AND borrowed_at BETWEEN (NOW() - INTERVAL 3 DAY) AND (NOW() + INTERVAL 1 DAY)");
    Database::run("UPDATE pqa_installations SET status = 'Dicabut', removed_at = NOW(), total_days = 0 WHERE status IN ('Terpasang','Req Cabut','Pending') AND COALESCE(installed_at, submitted_at) >= (NOW() - INTERVAL 40 DAY)");
    Database::run("UPDATE items i SET i.master_status = IF(EXISTS (SELECT 1 FROM loans l WHERE l.item_id = i.id AND l.status = 'approved') OR EXISTS (SELECT 1 FROM pqa_installations p WHERE p.item_id = i.id AND p.status IN ('Pending','Terpasang','Req Cabut')), 'Dipinjam', 'Tersedia')");
    eq(0, (int) Database::value('SELECT COUNT(*) FROM push_subscriptions'));
});
