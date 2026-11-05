<?php
declare(strict_types=1);

use App\Core\Database;
use App\Services\AuthService;

/** Siapkan akun uji dan bersihkan pembatas percobaan. */
function e2eReset(): void
{
    clearAttempts();
    Database::run("UPDATE users SET is_active = 0 WHERE role = 'admin'");
    aUser('e_admin', 'Admin-Uji-123', 'admin');
    aUser('e_staff', 'Rahasia-Uji-1');
    aUser('e_staff2', 'Rahasia-Uji-1');
}

/** Login lewat API dan kembalikan klien yang sudah masuk. */
function e2eLogin(string $user, string $role = 'staff', string $pw = 'Rahasia-Uji-1'): Client
{
    $c = new Client();
    $r = $c->loginApi($user, $pw, $role);
    eq(200, $r['status'], "login {$user}: " . $r['body']);
    return $c;
}

function e2eAdmin(): Client { return e2eLogin('e_admin', 'admin', 'Admin-Uji-123'); }

function loc(array $r): string { return $r['headers']['location'][0] ?? ''; }

// ---------------------------------------------------------------------------
section('E2E — halaman masuk & sesi');

t('GET /login: formulir dengan token CSRF, cookie sesi aman, tanpa skrip inline, tidak di-cache', function () {
    e2eReset();
    $c = new Client();
    $r = $c->get('/login');
    eq(200, $r['status']);
    contains('name="_csrf"', $r['body']);
    contains('name="role"', $r['body']);
    contains('autocomplete="current-password"', $r['body']);
    ok(!preg_match('/<script(?![^>]*\bsrc=)/i', $r['body']), 'tidak boleh ada skrip inline');
    eq('no-store', $r['headers']['cache-control'][0]);
    $cookie = strtolower(implode(';', $r['headers']['set-cookie'] ?? []));
    contains('hme_session=', $cookie);
    contains('httponly', $cookie);
    contains('samesite=lax', $cookie);
    lacks('secure', $cookie, 'HTTP lokal tidak boleh Secure');
    contains("default-src 'self'", $r['headers']['content-security-policy'][0]);
});

t('Login via formulir: 303 ke beranda; ID sesi berganti (anti-fixation); beranda memuat nama & menu sesuai peran', function () {
    e2eReset();
    $c = new Client();
    $c->bootstrapGuest();
    $guest = $c->cookie();
    $r = $c->request('POST', '/login', null, ['_csrf' => $c->csrf, 'username' => 'e_staff', 'password' => 'Rahasia-Uji-1', 'role' => 'staff']);
    eq(303, $r['status']);
    eq('/', loc($r));
    ok($c->cookie() !== null && $c->cookie() !== $guest, 'cookie sesi harus diganti setelah login');
    $home = $c->get('/');
    eq(200, $home['status']);
    contains('E_staff', $home['body']);
    contains('name="csrf-token"', $home['body']);
    lacks('href="/users"', $home['body'], 'staff tidak melihat menu Pengguna');
    // Sesi tamu lama tidak lagi berguna
    $old = new Client();
    $old->cookies['HME_SESSION'] = $guest;
    eq(302, $old->get('/')['status']);
});

t('Login gagal: pesan umum, username terisi kembali, pesan hanya tampil sekali, tanpa membocorkan akun', function () {
    e2eReset();
    $c = new Client();
    $c->bootstrapGuest();
    $r = $c->request('POST', '/login', null, ['_csrf' => $c->csrf, 'username' => 'e_staff', 'password' => 'SALAH-SALAH', 'role' => 'staff']);
    eq(303, $r['status']);
    eq('/login', loc($r));
    $page = $c->get('/login');
    contains('Username atau password salah.', $page['body']);
    contains('value="e_staff"', $page['body']);
    lacks('SALAH-SALAH', $page['body']);
    lacks('Username atau password salah.', $c->get('/login')['body'], 'flash hanya sekali');

    // Akun tidak ada → pesan sama persis
    $c->request('POST', '/login', null, ['_csrf' => $c->csrf, 'username' => 'tidak_ada', 'password' => 'x', 'role' => 'staff']);
    contains('Username atau password salah.', $c->get('/login')['body']);
});

t('Login: tab peran harus cocok dengan peran akun (staff memilih Admin → ditolak)', function () {
    e2eReset();
    $c = new Client();
    $c->bootstrapGuest();
    $r = $c->request('POST', '/login', null, ['_csrf' => $c->csrf, 'username' => 'e_staff', 'password' => 'Rahasia-Uji-1', 'role' => 'admin']);
    eq('/login', loc($r));
    eq(302, $c->get('/')['status'], 'tidak ter-login');
    $c->request('POST', '/login', null, ['_csrf' => $c->csrf, 'username' => 'e_admin', 'password' => 'Admin-Uji-123', 'role' => 'staff']);
    eq(302, $c->get('/')['status'], 'admin lewat tab staff pun ditolak');
});

t('Login tanpa token CSRF / token palsu → 403 halaman (bukan 500), tidak ter-login', function () {
    e2eReset();
    $c = new Client();
    $c->bootstrapGuest();
    $r = $c->request('POST', '/login', null, ['username' => 'e_staff', 'password' => 'Rahasia-Uji-1', 'role' => 'staff']);
    eq(403, $r['status']);
    contains('text/html', $r['headers']['content-type'][0]);
    $r = $c->request('POST', '/login', null, ['_csrf' => str_repeat('a', 64), 'username' => 'e_staff', 'password' => 'Rahasia-Uji-1', 'role' => 'staff']);
    eq(403, $r['status']);
    eq(302, $c->get('/')['status']);
});

t('Pembatasan login via HTTP: 5 gagal → percobaan benar tetap ditolak dengan pesan tunggu', function () {
    e2eReset();
    $c = new Client();
    $c->bootstrapGuest();
    $post = fn(string $pw) => $c->request('POST', '/login', null, ['_csrf' => $c->csrf, 'username' => 'e_staff', 'password' => $pw, 'role' => 'staff']);
    for ($i = 0; $i < 5; $i++) { $post('salah' . $i); }
    $r = $post('Rahasia-Uji-1');
    eq(303, $r['status']);
    eq('/login', loc($r));
    ok(isset($r['headers']['retry-after']), 'harus ada Retry-After');
    contains('Terlalu banyak percobaan', $c->get('/login')['body']);
    eq(302, $c->get('/')['status']);
    clearAttempts();
});

t('Redirect terbuka tidak mungkin: parameter next/redirect diabaikan', function () {
    e2eReset();
    $c = new Client();
    $c->bootstrapGuest();
    $r = $c->request('POST', '/login?next=https://evil.example&redirect=//evil.example', null,
        ['_csrf' => $c->csrf, 'username' => 'e_staff', 'password' => 'Rahasia-Uji-1', 'role' => 'staff', 'next' => 'https://evil.example']);
    eq('/', loc($r));
});

t('Sudah masuk → GET /login mengarahkan ke beranda', function () {
    e2eReset();
    $c = e2eLogin('e_staff');
    $r = $c->get('/login');
    eq(302, $r['status']);
    eq('/', loc($r));
});

// ---------------------------------------------------------------------------
section('E2E — API autentikasi & CSRF');

t('API login: token CSRF dirotasi, data pengguna tanpa rahasia, /me konsisten', function () {
    e2eReset();
    $c = new Client();
    $guestToken = $c->bootstrapGuest();
    $r = $c->api('POST', '/auth/login', ['username' => 'E_Staff', 'password' => 'Rahasia-Uji-1', 'role' => 'staff']);
    eq(200, $r['status']);
    $d = $r['json']['data'];
    eq('e_staff', $d['user']['username']);
    eq('/', $d['redirect']);
    ok($d['csrf_token'] !== $guestToken, 'token harus berganti setelah login');
    lacks('password_hash', $r['body']);
    lacks('auth_version', $r['body']);
    eq(['id', 'username', 'name', 'initials', 'role', 'must_change_password'], array_keys($d['user']), 'hanya field publik');
    $me = $c->api('GET', '/me');
    eq(200, $me['status']);
    eq('e_staff', $me['json']['data']['user']['username']);
    eq($c->csrf, $me['json']['data']['csrf_token']);
});

t('API login: validasi (field kosong, peran tidak dikenal) → 422 JSON dengan fields', function () {
    e2eReset();
    $c = new Client();
    $c->bootstrapGuest();
    $r = $c->api('POST', '/auth/login', ['username' => '', 'password' => '', 'role' => 'root']);
    eq(422, $r['status']);
    foreach (['username', 'password', 'role'] as $f) { ok(isset($r['json']['error']['fields'][$f]), $f); }
    $r = $c->api('POST', '/auth/login', ['username' => 'e_staff', 'password' => 'salah', 'role' => 'staff']);
    eq(401, $r['status']);
    eq('invalid_credentials', $r['json']['error']['code']);
});

t('CSRF: POST terautentikasi tanpa/salah/token-tamu → 403; Origin asing → 403; token benar → lolos', function () {
    e2eReset();
    $c = new Client();
    $guest = $c->bootstrapGuest();
    $c->loginApi('e_staff', 'Rahasia-Uji-1');
    $good = $c->csrf;
    eq('csrf_invalid', $c->api('POST', '/auth/logout', [], '')['json']['error']['code']);
    eq('csrf_invalid', $c->api('POST', '/auth/logout', [], 'x')['json']['error']['code']);
    eq('csrf_invalid', $c->api('POST', '/auth/logout', [], $guest)['json']['error']['code'], 'token sebelum login tidak berlaku');
    $r = $c->api('POST', '/auth/logout', [], $good, ['Origin' => 'https://evil.example']);
    eq(403, $r['status']);
    eq('csrf_origin', $r['json']['error']['code']);
    eq(200, $c->api('GET', '/me')['status'], 'masih login setelah upaya-upaya itu');
    eq(200, $c->api('POST', '/auth/logout', [], $good)['status']);
});

t('Logout: sesi dihancurkan di server — cookie lama tidak bisa dipakai ulang', function () {
    e2eReset();
    $c = e2eLogin('e_staff');
    $stolen = $c->cookie();
    eq(200, $c->api('POST', '/auth/logout', [])['status']);
    eq(401, $c->api('GET', '/me')['status']);
    $thief = new Client();
    $thief->cookies['HME_SESSION'] = (string) $stolen;
    eq(401, $thief->api('GET', '/me')['status'], 'replay cookie lama harus gagal');
});

t('Logout via formulir web: tanpa token 403, dengan token 303 ke /login', function () {
    e2eReset();
    $c = e2eLogin('e_staff');
    eq(403, $c->request('POST', '/logout', null, [])['status']);
    $r = $c->request('POST', '/logout', null, ['_csrf' => $c->csrf]);
    eq(303, $r['status']);
    eq('/login', loc($r));
    eq(302, $c->get('/')['status']);
});

t('JSON rusak / tipe konten aneh pada API → 400 JSON (bukan 500)', function () {
    e2eReset();
    $c = new Client();
    $c->bootstrapGuest();
    $r = $c->request('POST', '/api/v1/auth/login', null, null, ['Content-Type' => 'application/json', 'X-CSRF-Token' => $c->csrf, 'Accept' => 'application/json']);
    ok(in_array($r['status'], [400, 422], true), 'status ' . $r['status']);
    $ch = curl_init(TestServer::$base . '/api/v1/auth/login');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => '{rusak', CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $c->csrf, 'Cookie: HME_SESSION=' . $c->cookie()]]);
    $body = (string) curl_exec($ch);
    eq(400, curl_getinfo($ch, CURLINFO_RESPONSE_CODE));
    curl_close($ch);
    eq('invalid_json', json_decode($body, true)['error']['code']);
});

// ---------------------------------------------------------------------------
section('E2E — otorisasi (setiap endpoint menjaga dirinya sendiri)');

t('Anonim: API → 401, halaman → redirect /login (termasuk POST tanpa cookie)', function () {
    e2eReset();
    $c = new Client();
    foreach ([['GET', '/users'], ['POST', '/users'], ['PUT', '/users/1'], ['POST', '/users/1/deactivate'], ['POST', '/users/1/activate'],
              ['POST', '/users/1/reset-password'], ['GET', '/me'], ['POST', '/auth/logout'], ['POST', '/me/password']] as [$m, $p]) {
        $r = $c->api($m, $p, $m === 'GET' ? null : []);
        eq(401, $r['status'], "{$m} {$p}");
        eq('unauthenticated', $r['json']['error']['code'], "{$m} {$p}");
    }
    foreach (['/', '/users', '/profile', '/profile/password'] as $p) {
        $r = $c->get($p);
        eq(302, $r['status'], $p);
        eq('/login', loc($r), $p);
    }
});

t('Staff terautentikasi + CSRF benar: SEMUA endpoint admin → 403 forbidden (bukan hanya tombol yang disembunyikan)', function () {
    e2eReset();
    $victim = aUser('e_victim', 'Rahasia-Uji-1');
    $c = e2eLogin('e_staff');
    foreach ([['GET', '/users', null], ['POST', '/users', ['username' => 'peretas', 'name' => 'Peretas', 'role' => 'admin']],
              ['PUT', "/users/{$victim}", ['name' => 'X', 'role' => 'admin']], ['POST', "/users/{$victim}/deactivate", []],
              ['POST', "/users/{$victim}/activate", []], ['POST', "/users/{$victim}/reset-password", []]] as [$m, $p, $b]) {
        $r = $c->api($m, $p, $b);
        eq(403, $r['status'], "{$m} {$p}");
        eq('forbidden', $r['json']['error']['code'], "{$m} {$p}");
    }
    eq(403, $c->get('/users')['status'], 'halaman /users');
    eq(0, (int) Database::value("SELECT COUNT(*) FROM users WHERE username = 'peretas'"), 'tidak ada akun yang terbentuk');
    eq(1, (int) Database::value('SELECT is_active FROM users WHERE id = ?', [$victim]), 'korban tidak berubah');
    ok(password_verify('Rahasia-Uji-1', (string) Database::value('SELECT password_hash FROM users WHERE id = ?', [$victim])), 'password korban utuh');
});

t('Eskalasi peran lewat parameter tidak mungkin: /me/password mengabaikan id/role yang disusupkan', function () {
    e2eReset();
    $other = aUser('e_other', 'Rahasia-Uji-1');
    $before = (string) Database::value('SELECT password_hash FROM users WHERE id = ?', [$other]);
    $c = e2eLogin('e_staff');
    $r = $c->api('POST', '/me/password', ['current' => 'Rahasia-Uji-1', 'new' => 'Baru-Pass-4567', 'confirm' => 'Baru-Pass-4567', 'id' => $other, 'user_id' => $other, 'role' => 'admin']);
    eq(200, $r['status']);
    eq($before, (string) Database::value('SELECT password_hash FROM users WHERE id = ?', [$other]), 'akun lain tidak tersentuh');
    eq('staff', Database::value("SELECT role FROM users WHERE username = 'e_staff'"));
    ok(password_verify('Baru-Pass-4567', (string) Database::value("SELECT password_hash FROM users WHERE username = 'e_staff'")));
});

// ---------------------------------------------------------------------------
section('E2E — alur admin mengelola pengguna');

t('Admin: tambah (201 + password sementara), daftar, duplikat 422, ubah, nonaktif/aktif', function () {
    e2eReset();
    Database::run("DELETE FROM users WHERE username = 'e_baru'");
    $a = e2eAdmin();
    $r = $a->api('POST', '/users', ['username' => 'E_Baru', 'name' => 'Pengguna Baru', 'role' => 'staff']);
    eq(201, $r['status']);
    $d = $r['json']['data'];
    eq('e_baru', $d['user']['username']);
    eq('PB', $d['user']['initials']);
    eq(12, strlen($d['temporary_password']));
    $id = $d['user']['id'];

    $list = $a->api('GET', '/users');
    eq(200, $list['status']);
    ok(in_array('e_baru', array_column($list['json']['data'], 'username'), true));
    lacks('password_hash', $list['body']);

    $dup = $a->api('POST', '/users', ['username' => 'e_baru', 'name' => 'Lagi', 'role' => 'staff']);
    eq(422, $dup['status']);
    eq('Username sudah digunakan.', $dup['json']['error']['fields']['username']);

    $up = $a->api('PUT', "/users/{$id}", ['name' => 'Baru Banget', 'role' => 'staff']);
    eq(200, $up['status']);
    eq('BB', $up['json']['data']['initials']);

    eq(false, $a->api('POST', "/users/{$id}/deactivate")['json']['data']['is_active']);
    eq(true, $a->api('POST', "/users/{$id}/activate")['json']['data']['is_active']);
});

t('Admin: aturan bisnis ditegakkan server — diri sendiri, admin terakhir, pinjaman terbuka (409)', function () {
    e2eReset();
    $a = e2eAdmin();
    $me = (int) Database::value("SELECT id FROM users WHERE username = 'e_admin'");
    $r = $a->api('POST', "/users/{$me}/deactivate");
    eq(409, $r['status']); eq('self_action', $r['json']['error']['code']);
    eq('self_action', $a->api('POST', "/users/{$me}/reset-password")['json']['error']['code']);
    eq('self_action', $a->api('PUT', "/users/{$me}", ['name' => 'E_admin', 'role' => 'staff'])['json']['error']['code']);

    $staff = (int) Database::value("SELECT id FROM users WHERE username = 'e_staff'");
    Database::run('DELETE FROM loans WHERE user_id = ?', [$staff]);
    uLoan($staff, 'approved');
    $r = $a->api('POST', "/users/{$staff}/deactivate");
    eq(409, $r['status']); eq('open_loans', $r['json']['error']['code']);
    eq(404, $a->api('POST', '/users/99999999/deactivate')['status']);
});

t('Tambah pengguna: vektor serangan pada input → ditolak/di-escape, tidak ada 500', function () {
    e2eReset();
    $a = e2eAdmin();
    foreach ([
        ['username' => "x'; DROP TABLE users; --", 'name' => 'A B', 'role' => 'staff'],
        ['username' => '<script>', 'name' => 'A B', 'role' => 'staff'],
        ['username' => ['array'], 'name' => 'A B', 'role' => 'staff'],
        ['username' => 'ok.name', 'name' => ['x'], 'role' => 'staff'],
        ['username' => 'ok.name', 'name' => 'A B', 'role' => ['admin']],
        ['username' => 'ok.name', 'name' => 'A B', 'role' => 'staff', 'is_active' => 0, 'must_change_password' => 0, 'id' => 1],
    ] as $i => $body) {
        $r = $a->api('POST', '/users', $body);
        ok($r['status'] < 500, "#{$i} status {$r['status']}");
    }
    Database::run("UPDATE users SET must_change_password = 0 WHERE username = 'ok.name'");
    eq(1, (int) Database::value("SELECT is_active FROM users WHERE username = 'ok.name'"), 'mass-assignment: is_active tidak bisa disetel dari input');
    eq(0, (int) Database::value("SELECT must_change_password FROM users WHERE username = 'ok.name'"));
    eq(1, (int) Database::value("SELECT is_active FROM users WHERE username = 'ok.name'"));
    ok(Database::value('SELECT COUNT(*) FROM users') > 3, 'tabel users utuh');
});

t('XSS tersimpan: nama berisi HTML tampil ter-escape di halaman Pengguna', function () {
    e2eReset();
    Database::run("DELETE FROM users WHERE username = 'e_xss'");
    $a = e2eAdmin();
    eq(201, $a->api('POST', '/users', ['username' => 'e_xss', 'name' => '<img src=x onerror=alert(1)>', 'role' => 'staff'])['status']);
    $page = $a->get('/users');
    eq(200, $page['status']);
    contains('&lt;img src=x onerror=alert(1)&gt;', $page['body']);
    lacks('<img src=x', $page['body']);
    ok(!preg_match('/<script(?![^>]*\bsrc=)/i', $page['body']), 'tanpa skrip inline');
    // Verifikasi lewat DOM sungguhan: tidak ada elemen/atribut berbahaya hasil injeksi (termasuk lewat atribut data-user/data-search)
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $page['body']);
    $xp = new DOMXPath($dom);
    eq(0, $xp->query('//*[@onerror or @onload or @onclick]')->length, 'tidak boleh ada atribut event handler');
    eq(0, $xp->query('//img[@src="x"]')->length, 'elemen <img> injeksi tidak boleh terbentuk');
    $row = $xp->query('//button[contains(@data-search, "e_xss")]')->item(0);
    ok($row !== null);
    $data = json_decode($row->getAttribute('data-user'), true);
    eq('<img src=x onerror=alert(1)>', $data['name'], 'data tetap utuh sebagai teks, hanya tampilannya yang di-escape');
});

t('Halaman Pengguna (admin): daftar, kontrol, sheet, dan menu Pengguna tampil', function () {
    e2eReset();
    $a = e2eAdmin();
    $p = $a->get('/users');
    eq(200, $p['status']);
    foreach (['id="user-list"', 'id="btn-add-user"', 'id="sheet-user"', 'id="sheet-secret"', 'users.js', 'href="/users"', 'E_staff'] as $needle) {
        contains($needle, $p['body']);
    }
    eq('no-store', $p['headers']['cache-control'][0]);
});

// ---------------------------------------------------------------------------
section('E2E — wajib ganti password & pencabutan sesi');

t('Penjaga paksa-ganti (penanda disetel manual di DB): diarahkan ke ganti password, API terkunci, lalu bebas setelah mengganti', function () {
    e2eReset();
    Database::run("DELETE FROM users WHERE username = 'e_temp'");
    $a = e2eAdmin();
    $tmp = $a->api('POST', '/users', ['username' => 'e_temp', 'name' => 'Pengguna Temp', 'role' => 'staff'])['json']['data']['temporary_password'];
    $own = $a->api('POST', '/users', ['username' => 'e_temp0', 'name' => 'Tanpa Paksa', 'role' => 'staff', 'password' => 'Awal-Uji-4321'])['json']['data'];
    eq(false, $own['user']['must_change_password'], 'V2: akun baru tidak dipaksa ganti password');
    $c0 = new Client();
    $c0->bootstrapGuest();
    $r0 = $c0->request('POST', '/login', null, ['_csrf' => $c0->csrf, 'username' => 'e_temp0', 'password' => 'Awal-Uji-4321', 'role' => 'staff']);
    eq('/', loc($r0), 'login pertama langsung ke beranda');
    eq(200, $c0->get('/')['status']);
    Database::run("UPDATE users SET must_change_password = 1 WHERE username = 'e_temp'");

    $c = new Client();
    $c->bootstrapGuest();
    $r = $c->request('POST', '/login', null, ['_csrf' => $c->csrf, 'username' => 'e_temp', 'password' => $tmp, 'role' => 'staff']);
    eq('/profile/password', loc($r));
    $home = $c->get('/');
    eq(302, $home['status']);
    eq('/profile/password', loc($home));
    $pg = $c->get('/profile/password');
    eq(200, $pg['status']);
    contains('Ganti password dulu', $pg['body']);
    lacks('class="bottom-nav', $pg['body'], 'tanpa navigasi saat wajib ganti password');
    lacks('class="sidebar', $pg['body']);

    $api = $c->api('GET', '/users');
    eq(403, $api['status']);
    eq('password_change_required', $api['json']['error']['code'], 'dikunci sebelum cek peran');

    // Fallback formulir web: salah → kembali dengan pesan
    $bad = $c->request('POST', '/profile/password', null, ['_csrf' => $c->csrf, 'current' => 'salah', 'new' => 'Baru-Temp-9876', 'confirm' => 'Baru-Temp-9876']);
    eq(303, $bad['status']);
    contains('Password saat ini tidak tepat.', $c->get('/profile/password')['body']);

    $r = $c->api('POST', '/me/password', ['current' => $tmp, 'new' => 'Baru-Temp-9876', 'confirm' => 'Baru-Temp-9876']);
    eq(200, $r['status']);
    eq(200, $c->get('/')['status'], 'bebas setelah mengganti');
    eq(200, $c->get('/profile')['status']);
    $fresh = new Client();
    eq(401, $fresh->loginApi('e_temp', $tmp)['status'], 'password sementara tidak berlaku lagi');
    eq(200, (new Client())->loginApi('e_temp', 'Baru-Temp-9876')['status']);
});

t('Ganti password di satu perangkat → sesi di perangkat lain langsung gugur', function () {
    e2eReset();
    $laptop = e2eLogin('e_staff2');
    $phone = e2eLogin('e_staff2');
    eq(200, $laptop->api('GET', '/me')['status']);
    $before = $phone->cookie();
    eq(200, $phone->api('POST', '/me/password', ['current' => 'Rahasia-Uji-1', 'new' => 'Ganti-Dua-5678', 'confirm' => 'Ganti-Dua-5678'])['status']);
    ok($phone->cookie() !== $before, 'ID sesi perangkat ini diputar');
    eq(200, $phone->api('GET', '/me')['status'], 'perangkat yang mengganti tetap masuk');
    eq(401, $laptop->api('GET', '/me')['status'], 'perangkat lain gugur');
    eq(401, (new Client())->loginApi('e_staff2', 'Rahasia-Uji-1')['status']);
    eq(200, (new Client())->loginApi('e_staff2', 'Ganti-Dua-5678')['status']);
});

t('Admin menonaktifkan staff → sesi aktif staff gugur SEKETIKA dan tidak bisa login; aktif lagi → bisa', function () {
    e2eReset();
    $staff = (int) Database::value("SELECT id FROM users WHERE username = 'e_staff'");
    Database::run('DELETE FROM loans WHERE user_id = ?', [$staff]);
    $a = e2eAdmin();
    $s = e2eLogin('e_staff');
    eq(200, $s->api('GET', '/me')['status']);
    eq(200, $a->api('POST', "/users/{$staff}/deactivate")['status']);
    eq(401, $s->api('GET', '/me')['status'], 'sesi staff langsung ditolak');
    eq(401, (new Client())->loginApi('e_staff', 'Rahasia-Uji-1')['status']);
    eq(200, $a->api('POST', "/users/{$staff}/activate")['status']);
    eq(200, (new Client())->loginApi('e_staff', 'Rahasia-Uji-1')['status']);
});

t('Admin mereset password → sesi staff gugur; password baru langsung berlaku tanpa paksa ganti; lama tidak berlaku', function () {
    e2eReset();
    $staff = (int) Database::value("SELECT id FROM users WHERE username = 'e_staff'");
    $a = e2eAdmin();
    $s = e2eLogin('e_staff');
    $r = $a->api('POST', "/users/{$staff}/reset-password");
    eq(200, $r['status']);
    $tmp = $r['json']['data']['temporary_password'];
    eq(401, $s->api('GET', '/me')['status']);
    eq(401, (new Client())->loginApi('e_staff', 'Rahasia-Uji-1')['status']);
    $n = new Client();
    $lr = $n->loginApi('e_staff', $tmp);
    eq(200, $lr['status']);
    eq(false, $lr['json']['data']['user']['must_change_password']);
    eq('/', $lr['json']['data']['redirect']);
});

t('Admin diturunkan perannya → hak admin hilang seketika di sesi yang berjalan', function () {
    e2eReset();
    $a2id = aUser('e_admin2', 'Admin-Uji-123', 'admin');
    $a = e2eAdmin();
    $a2 = e2eLogin('e_admin2', 'admin', 'Admin-Uji-123');
    eq(200, $a2->api('GET', '/users')['status']);
    eq(200, $a->api('PUT', "/users/{$a2id}", ['name' => 'E_admin2', 'role' => 'staff'])['status']);
    eq(401, $a2->api('GET', '/users')['status'], 'sesi lama gugur karena versi kredensial naik');
    eq(403, e2eLogin('e_admin2', 'staff', 'Admin-Uji-123')->api('GET', '/users')['status'], 'login ulang sebagai staff tetap tidak punya akses admin');
});

// ---------------------------------------------------------------------------
section('CLI — tools/create-admin.php');

/** @return array{code:int,out:string,err:string} */
function runCli(array $args, array $env = [], string $stdin = ''): array
{
    $cmd = array_merge([PHP_BINARY, dirname(__DIR__, 2) . '/tools/create-admin.php'], $args);
    $e = array_merge(getenv(), ['APP_ENV' => 'testing', 'DB_NAME' => 'hme_peminjaman_test', 'LOG_DIR' => sys_get_temp_dir() . '/hme_test_logs'], $env);
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), $e);
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    return ['code' => proc_close($p), 'out' => $out, 'err' => $err];
}

t('create-admin --generate: admin dibuat, tanpa paksa ganti password, dapat login; duplikat ditolak', function () {
    clearAttempts();
    Database::run("DELETE FROM users WHERE username = 'cli_admin'");
    $r = runCli(['--username=cli_admin', '--name=CLI Admin', '--generate']);
    eq(0, $r['code'], $r['err']);
    ok((bool) preg_match('/hanya ditampilkan sekali\): (\S{12})/', $r['out'], $m), 'password sementara tampil sekali');
    $u = AuthService::attempt('cli_admin', $m[1], 'admin', areq('10.7.0.1'));
    eq(false, $u['must_change_password']);
    eq('CA', Database::value("SELECT initials FROM users WHERE username = 'cli_admin'"));
    $dup = runCli(['--username=cli_admin', '--name=CLI Admin', '--generate']);
    eq(1, $dup['code']);
    contains('sudah ada', $dup['err']);
});

t('create-admin: password lewat argumen ditolak; password lemah ditolak; prompt interaktif bekerja', function () {
    Database::run("DELETE FROM users WHERE username IN ('cli_a2','cli_a3')");
    $r = runCli(['--username=cli_a2', '--name=Dua', '--password=Rahasia-1234']);
    eq(1, $r['code']);
    contains('Ditolak', $r['err']);
    $r = runCli(['--username=cli_a2', '--name=Dua'], ['HME_ADMIN_PASSWORD' => '12345678']);
    eq(1, $r['code']);
    contains('mudah ditebak', $r['err']);
    $r = runCli(['--username=cli_a2', '--name=Dua'], ['HME_ADMIN_PASSWORD' => 'Env-Pass-4567']);
    eq(0, $r['code'], $r['err']);
    $r = runCli(['--username=cli_a3', '--name=Tiga'], ['HME_ADMIN_PASSWORD' => ''], "Prompt-Pass-8899\nPrompt-Pass-8899\n");
    eq(0, $r['code'], $r['err'] . $r['out']);
    $r = runCli(['--username=cli_a4', '--name=Empat'], ['HME_ADMIN_PASSWORD' => ''], "Prompt-Pass-8899\nBeda-Pass-0000\n");
    eq(1, $r['code']);
    $r = runCli(['--username=ab', '--name=Dua']);
    eq(1, $r['code']);
    eq(0, (int) Database::value("SELECT COUNT(*) FROM users WHERE username IN ('cli_a4','ab')"));
});
