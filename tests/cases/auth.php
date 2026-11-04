<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Middleware\AuthMiddleware;
use App\Services\AuthService;
use App\Services\PasswordPolicy;

function areq(string $ip = '10.9.0.1'): Request
{
    return new Request('POST', '/login', [], [], ['User-Agent' => 'tes'], ['REMOTE_ADDR' => $ip]);
}

function aUser(string $username, string $password = 'Rahasia-Uji-1', string $role = 'staff', int $active = 1, int $must = 0): int
{
    $id = Database::value('SELECT id FROM users WHERE username = ?', [$username]);
    if ($id !== null) {
        Database::update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'role' => $role, 'is_active' => $active, 'must_change_password' => $must], ['id' => (int) $id]);
        return (int) $id;
    }
    return Database::insert('users', [
        'username' => $username, 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'name' => ucfirst($username),
        'initials' => strtoupper(substr($username, 0, 1)), 'role' => $role, 'is_active' => $active, 'must_change_password' => $must,
    ]);
}

function clearAttempts(): void
{
    Database::run('DELETE FROM login_attempts');
}

/** @return HttpException */
function loginFails(string $u, string $p, string $role = 'staff', string $ip = '10.9.0.1'): HttpException
{
    /** @var HttpException $e */
    $e = throws(fn() => AuthService::attempt($u, $p, $role, areq($ip)), HttpException::class);
    return $e;
}

// ---------------------------------------------------------------------------
section('Kebijakan password');

t('PasswordPolicy: minimal 8, maksimal 72 byte, tolak yang lemah/sama dengan username', function () {
    ok(PasswordPolicy::check('Abcdef1x') === null);
    contains('minimal 8', (string) PasswordPolicy::check('Abc1'));
    contains('terlalu panjang', (string) PasswordPolicy::check(str_repeat('a1', 37)));       // 74 byte
    ok(PasswordPolicy::check(str_repeat('ab', 36)) === null, '72 byte masih diterima');
    contains('mudah ditebak', (string) PasswordPolicy::check('12345678'));
    contains('mudah ditebak', (string) PasswordPolicy::check('PASSWORD'));
    contains('mudah ditebak', (string) PasswordPolicy::check('aaaaaaaaaa'));
    contains('sama dengan username', (string) PasswordPolicy::check('naufal2026', 'Naufal2026'));
    ok(PasswordPolicy::check('é' . str_repeat('x', 7)) === null, 'dihitung per karakter, bukan byte, untuk batas bawah');
});

t('PasswordPolicy::generate: 12 karakter, campuran, tanpa karakter ambigu, acak', function () {
    $seen = [];
    for ($i = 0; $i < 60; $i++) {
        $p = PasswordPolicy::generate();
        eq(12, strlen($p));
        ok((bool) preg_match('/[a-z]/', $p) && (bool) preg_match('/[A-Z]/', $p) && (bool) preg_match('/\d/', $p), "campuran: {$p}");
        ok(!preg_match('/[0O1lI]/', $p), "tanpa karakter ambigu: {$p}");
        $seen[$p] = true;
    }
    eq(60, count($seen), 'tidak boleh ada duplikat');
    ok(PasswordPolicy::check(PasswordPolicy::generate()) === null, 'password buatan sistem lolos kebijakan sendiri');
});

// ---------------------------------------------------------------------------
section('Login');

t('Login sukses: data pengguna, last_login_at terisi, catatan gagal dihapus', function () {
    clearAttempts();
    $id = aUser('a_staff');
    Database::insert('login_attempts', ['username' => 'a_staff', 'ip' => '10.9.0.1', 'success' => 0]);
    $u = AuthService::attempt('a_staff', 'Rahasia-Uji-1', 'staff', areq());
    eq($id, $u['id']);
    eq('staff', $u['role']);
    eq(false, $u['must_change_password']);
    ok(!array_key_exists('password_hash', $u));
    ok(Database::value('SELECT last_login_at FROM users WHERE id = ?', [$id]) !== null);
    eq(0, (int) Database::value("SELECT COUNT(*) FROM login_attempts WHERE username = 'a_staff' AND success = 0"));
});

t('Login: username tidak peka huruf besar & spasi di tepi', function () {
    aUser('a_staff');
    eq('a_staff', AuthService::attempt('  A_Staff  ', 'Rahasia-Uji-1', 'staff', areq())['username']);
});

t('Login gagal: semua penyebab menghasilkan error IDENTIK (tidak membocorkan akun)', function () {
    clearAttempts();
    aUser('a_staff'); aUser('a_off', 'Rahasia-Uji-1', 'staff', 0);
    $cases = [
        'password salah'   => loginFails('a_staff', 'salah-salah'),
        'user tidak ada'   => loginFails('tidak_ada_x', 'Rahasia-Uji-1'),
        'peran salah'      => loginFails('a_staff', 'Rahasia-Uji-1', 'admin'),
        'akun nonaktif'    => loginFails('a_off', 'Rahasia-Uji-1'),
        'password kosong'  => loginFails('a_staff', ''),
        'username kosong'  => loginFails('', 'Rahasia-Uji-1'),
        'username kepanjangan' => loginFails(str_repeat('a', 300), 'x'),
        'payload SQLi'     => loginFails("a_staff' OR '1'='1", 'x'),
    ];
    foreach ($cases as $label => $e) {
        eq(401, $e->status(), $label);
        eq('invalid_credentials', $e->errorCode(), $label);
        eq('Username atau password salah.', $e->getMessage(), $label);
        eq([], $e->fields(), $label);
    }
});

t('Login gagal dicatat di audit tanpa menyimpan password', function () {
    clearAttempts();
    $from = (int) Database::value('SELECT COALESCE(MAX(id), 0) FROM activity_logs');   // log lama tidak dihapus (dipakai tes cakupan audit)
    aUser('a_staff');
    loginFails('a_staff', 'RAHASIA-PERCOBAAN-9');
    $row = Database::one("SELECT * FROM activity_logs WHERE action = 'auth.login_failed' AND id > ?", [$from]);
    ok($row !== null);
    lacks('RAHASIA-PERCOBAAN-9', (string) json_encode($row));
    contains('a_staff', (string) $row['after_json']);
});

t('Login sukses dengan sesi: ID sesi berganti, versi kredensial tersimpan, audit tercatat', function () {
    clearAttempts();
    $id = aUser('a_staff');
    Session::logout();
    Session::start();
    $before = session_id();
    $u = AuthService::login('a_staff', 'Rahasia-Uji-1', 'staff', areq());
    ok(session_id() !== $before, 'anti session-fixation');
    eq($id, Session::userId());
    eq($u['auth_version'], Session::authVersion());
    ok(Database::value("SELECT COUNT(*) FROM activity_logs WHERE action = 'auth.login' AND user_id = ?", [$id]) >= 1);
    AuthService::logout($id, areq());
    eq(null, Session::userId());
    ok(Database::value("SELECT COUNT(*) FROM activity_logs WHERE action = 'auth.logout' AND user_id = ?", [$id]) >= 1);
});

t('Login: hash lama (cost rendah) otomatis di-upgrade tanpa mengubah password', function () {
    clearAttempts();
    $id = aUser('a_staff');
    $weak = password_hash('Rahasia-Uji-1', PASSWORD_BCRYPT, ['cost' => 4]);
    Database::update('users', ['password_hash' => $weak], ['id' => $id]);
    AuthService::attempt('a_staff', 'Rahasia-Uji-1', 'staff', areq());
    $new = (string) Database::value('SELECT password_hash FROM users WHERE id = ?', [$id]);
    ok($new !== $weak, 'hash harus diperbarui');
    ok(password_verify('Rahasia-Uji-1', $new));
    ok(!password_needs_rehash($new, PASSWORD_DEFAULT));
});

// ---------------------------------------------------------------------------
section('Pembatasan percobaan login');

t('5 kegagalan (username+IP) → percobaan ke-6 ditolak 429 walau password BENAR; IP lain tidak terdampak', function () {
    clearAttempts();
    aUser('a_staff');
    for ($i = 0; $i < 5; $i++) {
        eq(401, loginFails('a_staff', 'salah' . $i)->status());
    }
    $e = throws(fn() => AuthService::attempt('a_staff', 'Rahasia-Uji-1', 'staff', areq()), HttpException::class);
    eq(429, $e->status());
    eq('too_many_attempts', $e->errorCode());
    $retry = (int) $e->headers()['Retry-After'];
    ok($retry > 0 && $retry <= AuthService::WINDOW_SECONDS, "Retry-After={$retry}");
    contains('menit', $e->getMessage());
    eq('a_staff', AuthService::attempt('a_staff', 'Rahasia-Uji-1', 'staff', areq('10.9.0.77'))['username'], 'IP lain tetap bisa masuk');
});

t('Jendela geser: kegagalan > 15 menit tidak dihitung; sisa waktu tunggu mengikuti kegagalan tertua', function () {
    clearAttempts();
    aUser('a_staff');
    // 5 kegagalan, tertua 800 detik lalu → boleh coba lagi sekitar 100 detik
    foreach ([800, 600, 400, 200, 10] as $age) {
        Database::run("INSERT INTO login_attempts (username, ip, success, created_at) VALUES ('a_staff', '10.9.0.1', 0, NOW() - INTERVAL {$age} SECOND)");
    }
    $e = throws(fn() => AuthService::attempt('a_staff', 'Rahasia-Uji-1', 'staff', areq()), HttpException::class);
    eq(429, $e->status());
    $retry = (int) $e->headers()['Retry-After'];
    ok($retry >= 95 && $retry <= 105, "Retry-After seharusnya ≈100 detik, didapat {$retry}");
    // semua sudah kedaluwarsa → bisa masuk
    Database::run('UPDATE login_attempts SET created_at = NOW() - INTERVAL 1000 SECOND');
    eq('a_staff', AuthService::attempt('a_staff', 'Rahasia-Uji-1', 'staff', areq())['username']);
});

t('Login sukses mereset hitungan gagal; 4 gagal + sukses + 4 gagal tidak terkunci', function () {
    clearAttempts();
    aUser('a_staff');
    for ($i = 0; $i < 4; $i++) { loginFails('a_staff', 'x' . $i); }
    AuthService::attempt('a_staff', 'Rahasia-Uji-1', 'staff', areq());
    for ($i = 0; $i < 4; $i++) { eq(401, loginFails('a_staff', 'y' . $i)->status()); }
});

t('Batas per IP (20): satu IP menyerang banyak username → semua diblokir; IP lain aman', function () {
    clearAttempts();
    aUser('a_staff');
    for ($i = 0; $i < 20; $i++) {
        Database::insert('login_attempts', ['username' => 'target' . $i, 'ip' => '10.9.0.5', 'success' => 0]);
    }
    $e = throws(fn() => AuthService::attempt('a_staff', 'Rahasia-Uji-1', 'staff', areq('10.9.0.5')), HttpException::class);
    eq(429, $e->status());
    eq('a_staff', AuthService::attempt('a_staff', 'Rahasia-Uji-1', 'staff', areq('10.9.0.6'))['username']);
});

t('Batas per username (30): serangan terdistribusi dari banyak IP memblokir username itu', function () {
    clearAttempts();
    aUser('a_staff');
    for ($i = 0; $i < 30; $i++) {
        Database::insert('login_attempts', ['username' => 'a_staff', 'ip' => '10.20.0.' . $i, 'success' => 0]);
    }
    $e = throws(fn() => AuthService::attempt('a_staff', 'Rahasia-Uji-1', 'staff', areq('10.99.99.99')), HttpException::class);
    eq(429, $e->status());
});

t('Pemblokiran juga berlaku untuk username yang tidak ada (tidak membedakan akun nyata)', function () {
    clearAttempts();
    for ($i = 0; $i < 5; $i++) { eq(401, loginFails('hantu_x', 'abc' . $i)->status()); }
    eq(429, loginFails('hantu_x', 'abc')->status());
});

// ---------------------------------------------------------------------------
section('Ganti password');

t('Ganti password: validasi lengkap dengan pesan per field', function () {
    clearAttempts();
    $id = aUser('a_chg_user', 'Lama-Pass-123');
    $try = function (string $cur, string $new, string $conf) use ($id): HttpException {
        return throws(fn() => AuthService::changePassword($id, $cur, $new, $conf, areq()), HttpException::class);
    };
    $e = $try('salah-banget', 'Baru-Pass-456', 'Baru-Pass-456');
    eq(422, $e->status()); eq('Password saat ini tidak tepat.', $e->fields()['current']);
    clearAttempts();
    contains('minimal 8', $try('Lama-Pass-123', 'Ab1', 'Ab1')->fields()['new']);
    contains('mudah ditebak', $try('Lama-Pass-123', '12345678', '12345678')->fields()['new']);
    contains('sama dengan username', $try('Lama-Pass-123', 'A_CHG_USER', 'A_CHG_USER')->fields()['new']);
    contains('terlalu panjang', $try('Lama-Pass-123', str_repeat('x1', 40), str_repeat('x1', 40))->fields()['new']);
    contains('berbeda', $try('Lama-Pass-123', 'Lama-Pass-123', 'Lama-Pass-123')->fields()['new']);
    contains('tidak cocok', $try('Lama-Pass-123', 'Baru-Pass-456', 'Baru-Pass-457')->fields()['confirm']);
    eq(true, password_verify('Lama-Pass-123', (string) Database::value('SELECT password_hash FROM users WHERE id = ?', [$id])), 'password tidak berubah bila gagal');
});

t('Ganti password sukses: hash baru, wajib-ganti dicabut, versi naik, sesi ini tetap masuk, sesi lain gugur', function () {
    clearAttempts();
    $id = aUser('a_chg_user', 'Lama-Pass-123', 'staff', 1, 1);
    $v0 = (int) Database::value('SELECT auth_version FROM users WHERE id = ?', [$id]);
    Session::login($id, $v0);
    AuthService::changePassword($id, 'Lama-Pass-123', 'Baru-Pass-456', 'Baru-Pass-456', areq());

    $row = Database::one('SELECT password_hash, must_change_password, auth_version FROM users WHERE id = ?', [$id]);
    ok(password_verify('Baru-Pass-456', $row['password_hash']));
    ok(!password_verify('Lama-Pass-123', $row['password_hash']));
    eq(0, (int) $row['must_change_password']);
    eq($v0 + 1, (int) $row['auth_version']);
    eq($id, Session::userId(), 'sesi ini tetap masuk');
    eq($v0 + 1, Session::authVersion());

    // Sesi lain (membawa versi lama) tidak berlaku lagi
    Session::login($id, $v0);
    $e = throws(fn() => (new AuthMiddleware())->handle(new Request('GET', '/api/v1/me'), fn() => Response::ok()), HttpException::class);
    eq(401, $e->status());
    ok(Database::value("SELECT COUNT(*) FROM activity_logs WHERE action = 'auth.password_changed' AND user_id = ?", [$id]) >= 1);
});

t('Ganti password: menebak password lama dibatasi (5x → 429)', function () {
    clearAttempts();
    $id = aUser('a_chg_user', 'Lama-Pass-123');
    for ($i = 0; $i < 5; $i++) {
        eq(422, throws(fn() => AuthService::changePassword($id, 'tebak' . $i, 'Baru-Pass-456', 'Baru-Pass-456', areq()), HttpException::class)->status());
    }
    $e = throws(fn() => AuthService::changePassword($id, 'Lama-Pass-123', 'Baru-Pass-456', 'Baru-Pass-456', areq()), HttpException::class);
    eq(429, $e->status());
});

t('Ganti password: akun nonaktif / tidak ada → 401', function () {
    $off = aUser('a_off', 'Lama-Pass-123', 'staff', 0);
    eq(401, throws(fn() => AuthService::changePassword($off, 'Lama-Pass-123', 'Baru-Pass-456', 'Baru-Pass-456', areq()), HttpException::class)->status());
    eq(401, throws(fn() => AuthService::changePassword(99999999, 'x', 'y', 'y', areq()), HttpException::class)->status());
});
