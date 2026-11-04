<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Middleware\AuthMiddleware;
use App\Services\AuthService;
use App\Services\UserService;

function ureq(): Request
{
    return new Request('POST', '/api/v1/users', [], [], ['User-Agent' => 'tes'], ['REMOTE_ADDR' => '10.8.0.1']);
}

/** Admin pelaku (satu-satunya admin aktif dibuat ulang tiap tes agar aturan "admin terakhir" dapat diuji). */
function uSetup(): array
{
    clearAttempts();
    Database::run('DELETE FROM change_events');
    Database::run("UPDATE users SET is_active = 0 WHERE role = 'admin'");   // admin dari tes lain tidak ikut dihitung
    $adminId = aUser('u_admin', 'Admin-Uji-123', 'admin', 1, 0);
    return ['id' => $adminId, 'role' => 'admin', 'username' => 'u_admin'];
}

function uLoan(int $userId, string $status): int
{
    static $n = 0;
    $n++;
    $cat = (int) Database::value('SELECT id FROM categories LIMIT 1');
    $item = Database::insert('items', ['code' => 'U-ITEM-' . $userId . '-' . $n . '-' . $status, 'name' => 'Brg', 'category_id' => $cat]);
    $batch = Database::insert('loan_batches', ['user_id' => $userId]);
    return Database::insert('loans', [
        'seq_no' => 900000 + $n + $userId * 100, 'batch_id' => $batch, 'user_id' => $userId, 'item_id' => $item,
        'item_name_snapshot' => 'X', 'carrier_name_snapshot' => 'Y', 'borrowed_at' => '2026-11-04 08:00:00', 'location' => 'L', 'status' => $status,
    ]);
}

function uErr(callable $fn): HttpException
{
    /** @var HttpException $e */
    $e = throws($fn, HttpException::class);
    return $e;
}

// ---------------------------------------------------------------------------
section('Kelola pengguna — tambah');

t('Tambah staff: inisial otomatis, wajib ganti password, password sementara dibuat sistem & berfungsi', function () {
    $admin = uSetup();
    Database::run("DELETE FROM users WHERE username = 'budi.s'");
    $r = UserService::create(['username' => 'budi.s', 'name' => 'Budi Santoso', 'role' => 'staff'], $admin, ureq());
    eq('BS', $r['user']['initials']);
    eq('staff', $r['user']['role']);
    eq(true, $r['user']['must_change_password']);
    eq(true, $r['user']['is_active']);
    $tmp = (string) $r['temporary_password'];
    eq(12, strlen($tmp));
    ok(!array_key_exists('password_hash', $r['user']));
    $u = AuthService::attempt('budi.s', $tmp, 'staff', areq('10.8.0.9'));
    eq(true, $u['must_change_password']);
    eq(1, (int) Database::value("SELECT COUNT(*) FROM change_events WHERE entity_type = 'user' AND action = 'created'"));
});

t('Tambah: password yang diketik admin tidak dikirim balik, dan tunduk pada kebijakan', function () {
    $admin = uSetup();
    Database::run("DELETE FROM users WHERE username = 'siti.n'");
    $r = UserService::create(['username' => 'siti.n', 'name' => 'Siti Nur', 'role' => 'staff', 'password' => 'Awal-Siti-77'], $admin, ureq());
    eq(null, $r['temporary_password']);
    ok(password_verify('Awal-Siti-77', (string) Database::value("SELECT password_hash FROM users WHERE username = 'siti.n'")));
    contains('mudah ditebak', uErr(fn() => UserService::create(['username' => 'x.lemah', 'name' => 'Lemah', 'role' => 'staff', 'password' => '12345678'], $admin, ureq()))->fields()['password']);
    contains('minimal 8', uErr(fn() => UserService::create(['username' => 'x.pendek', 'name' => 'Pendek', 'role' => 'staff', 'password' => 'Ab1'], $admin, ureq()))->fields()['password']);
});

t('Tambah: username dinormalisasi huruf kecil; duplikat (tak peka huruf) ditolak dengan pesan per field', function () {
    $admin = uSetup();
    Database::run("DELETE FROM users WHERE username = 'dupe.u'");
    UserService::create(['username' => ' DUPE.U ', 'name' => 'Dupe Satu', 'role' => 'staff'], $admin, ureq());
    eq('dupe.u', Database::value("SELECT username FROM users WHERE username = 'dupe.u'"));
    $e = uErr(fn() => UserService::create(['username' => 'Dupe.U', 'name' => 'Dupe Dua', 'role' => 'staff'], $admin, ureq()));
    eq(422, $e->status());
    eq('Username sudah digunakan.', $e->fields()['username']);
});

t('Tambah: validasi username, nama, peran', function () {
    $admin = uSetup();
    $f = fn(array $in) => uErr(fn() => UserService::create($in, $admin, ureq()))->fields();
    ok(isset($f(['username' => 'ab', 'name' => 'Nama Ok', 'role' => 'staff'])['username']), 'terlalu pendek');
    ok(isset($f(['username' => 'a b c', 'name' => 'Nama Ok', 'role' => 'staff'])['username']), 'spasi');
    ok(isset($f(['username' => "x'; DROP TABLE users;--", 'name' => 'Nama Ok', 'role' => 'staff'])['username']), 'injeksi');
    ok(isset($f(['username' => str_repeat('a', 51), 'name' => 'Nama Ok', 'role' => 'staff'])['username']), 'terlalu panjang');
    ok(isset($f(['username' => 'valid.u', 'name' => '', 'role' => 'staff'])['name']), 'nama kosong');
    ok(isset($f(['username' => 'valid.u', 'name' => 'A', 'role' => 'staff'])['name']), 'nama 1 huruf');
    ok(isset($f(['username' => 'valid.u', 'name' => 'Nama Ok', 'role' => 'root'])['role']), 'peran tidak dikenal');
    ok(isset($f(['username' => 'valid.u', 'name' => 'Nama Ok'])['role']), 'peran kosong');
    eq(0, (int) Database::value("SELECT COUNT(*) FROM users WHERE username IN ('valid.u','ab')"));
});

t('Tambah: audit tidak memuat password; admin baru dapat dibuat (Q7)', function () {
    $admin = uSetup();
    Database::run("DELETE FROM users WHERE username = 'adm.dua'");
    $from = (int) Database::value('SELECT COALESCE(MAX(id), 0) FROM activity_logs');   // log lama tidak dihapus (dipakai tes cakupan audit)
    $r = UserService::create(['username' => 'adm.dua', 'name' => 'Admin Dua', 'role' => 'admin', 'password' => 'RahasiaAdmin-9'], $admin, ureq());
    eq('admin', $r['user']['role']);
    $log = (string) Database::value("SELECT after_json FROM activity_logs WHERE action = 'user.created' AND id > ?", [$from]);
    contains('adm.dua', $log);
    lacks('RahasiaAdmin-9', $log);
    lacks('password', strtolower($log));
});

t('Inisial: huruf pertama tiap kata (maks. 2), nama satu kata, spasi ganda, Unicode', function () {
    eq('BS', UserService::initialsFor('Budi Santoso'));
    eq('AB', UserService::initialsFor('Adi Budi Cahya'));
    eq('R', UserService::initialsFor('rika'));
    eq('FS', UserService::initialsFor('  fita   sari '));
    eq('ÉV', UserService::initialsFor('élan vital'));
    eq('?', UserService::initialsFor('   '));
});

// ---------------------------------------------------------------------------
section('Kelola pengguna — ubah, nonaktifkan, reset');

t('Ubah nama: inisial dihitung ulang; peran sama tidak menaikkan versi kredensial', function () {
    $admin = uSetup();
    $id = aUser('u_staff1', 'Rahasia-Uji-1');
    $v0 = (int) Database::value('SELECT auth_version FROM users WHERE id = ?', [$id]);
    $r = UserService::update($id, ['name' => 'Rina Maharani', 'role' => 'staff'], $admin, ureq());
    eq('Rina Maharani', $r['name']);
    eq('RM', $r['initials']);
    eq($v0, (int) Database::value('SELECT auth_version FROM users WHERE id = ?', [$id]));
});

t('Ubah peran: staff→admin sah dan menaikkan versi (sesi lama gugur); tidak boleh mengubah peran sendiri', function () {
    $admin = uSetup();
    $id = aUser('u_staff1', 'Rahasia-Uji-1');
    $v0 = (int) Database::value('SELECT auth_version FROM users WHERE id = ?', [$id]);
    UserService::update($id, ['name' => 'U_staff1', 'role' => 'admin'], $admin, ureq());
    eq($v0 + 1, (int) Database::value('SELECT auth_version FROM users WHERE id = ?', [$id]));
    eq('admin', Database::value('SELECT role FROM users WHERE id = ?', [$id]));
    $e = uErr(fn() => UserService::update($admin['id'], ['name' => 'Admin', 'role' => 'staff'], $admin, ureq()));
    eq(409, $e->status()); eq('self_action', $e->errorCode());
});

t('Admin terakhir: tidak bisa diturunkan perannya atau dinonaktifkan', function () {
    $admin = uSetup();                                     // satu-satunya admin aktif
    $other = aUser('u_staff1', 'Rahasia-Uji-1');
    // Dari sudut pandang admin lain yang sama-sama aktif lalu dicopot:
    $a2 = aUser('u_admin2', 'Admin-Uji-123', 'admin', 1, 0);
    UserService::setActive($a2, false, $admin, ureq());    // boleh: u_admin masih ada
    $act2 = ['id' => $a2, 'role' => 'admin'];
    Database::update('users', ['is_active' => 1], ['id' => $a2]);
    UserService::setActive($admin['id'], false, $act2, ureq());   // u_admin dinonaktifkan oleh u_admin2 (sah, u_admin2 tetap)
    // kini u_admin2 adalah satu-satunya admin aktif → tidak boleh dinonaktifkan/diturunkan oleh admin lain
    $third = aUser('u_admin3', 'Admin-Uji-123', 'admin', 0, 0);
    $e = uErr(fn() => UserService::setActive($a2, false, ['id' => $third, 'role' => 'admin'], ureq()));
    eq('last_admin', $e->errorCode());
    $e = uErr(fn() => UserService::update($a2, ['name' => 'Admin2', 'role' => 'staff'], ['id' => $third, 'role' => 'admin'], ureq()));
    eq('last_admin', $e->errorCode());
    eq('admin', Database::value('SELECT role FROM users WHERE id = ?', [$a2]));
    ok($other > 0);
});

t('BR-16: tidak bisa dinonaktifkan bila punya pengajuan pending / pinjaman approved; boleh bila hanya riwayat', function () {
    $admin = uSetup();
    $id = aUser('u_staff1', 'Rahasia-Uji-1');
    Database::run('DELETE FROM loans WHERE user_id = ?', [$id]);
    $pending = uLoan($id, 'pending');
    $e = uErr(fn() => UserService::setActive($id, false, $admin, ureq()));
    eq(409, $e->status()); eq('open_loans', $e->errorCode());
    Database::update('loans', ['status' => 'approved'], ['id' => $pending]);
    eq('open_loans', uErr(fn() => UserService::setActive($id, false, $admin, ureq()))->errorCode());
    Database::update('loans', ['status' => 'returned'], ['id' => $pending]);
    uLoan($id, 'rejected'); uLoan($id, 'cancelled');
    $r = UserService::setActive($id, false, $admin, ureq());
    eq(false, $r['is_active']);
    $list = array_values(array_filter(UserService::listAll(), fn($x) => $x['id'] === $id))[0];
    eq(0, $list['open_loans']);
});

t('Nonaktifkan: tidak bisa login, sesi aktif langsung gugur; aktifkan kembali memulihkan; idempoten', function () {
    $admin = uSetup();
    $id = aUser('u_staff1', 'Rahasia-Uji-1');
    Database::run('DELETE FROM loans WHERE user_id = ?', [$id]);
    $v = (int) Database::value('SELECT auth_version FROM users WHERE id = ?', [$id]);
    Session::login($id, $v);
    UserService::setActive($id, false, $admin, ureq());
    eq(401, uErr(fn() => (new AuthMiddleware())->handle(new Request('GET', '/api/v1/me'), fn() => Response::ok()))->status());
    eq(401, loginFails('u_staff1', 'Rahasia-Uji-1')->status(), 'tidak bisa login');
    UserService::setActive($id, false, $admin, ureq());                       // idempoten
    $r = UserService::setActive($id, true, $admin, ureq());
    eq(true, $r['is_active']);
    eq('u_staff1', AuthService::attempt('u_staff1', 'Rahasia-Uji-1', 'staff', areq())['username']);
});

t('Tidak bisa menonaktifkan / mereset akun sendiri', function () {
    $admin = uSetup();
    eq('self_action', uErr(fn() => UserService::setActive($admin['id'], false, $admin, ureq()))->errorCode());
    eq('self_action', uErr(fn() => UserService::resetPassword($admin['id'], $admin, ureq()))->errorCode());
});

t('Reset password: password lama mati, sementara berfungsi & wajib diganti, sesi aktif gugur, throttle dibersihkan', function () {
    $admin = uSetup();
    $id = aUser('u_staff1', 'Rahasia-Uji-1');
    $v = (int) Database::value('SELECT auth_version FROM users WHERE id = ?', [$id]);
    Session::login($id, $v);
    for ($i = 0; $i < 5; $i++) { loginFails('u_staff1', 'tebak' . $i, 'staff', '10.8.0.1'); }
    eq(429, loginFails('u_staff1', 'Rahasia-Uji-1', 'staff', '10.8.0.1')->status(), 'terkunci sebelum reset');

    $r = UserService::resetPassword($id, $admin, ureq());
    $tmp = $r['temporary_password'];
    eq(12, strlen($tmp));
    eq(true, $r['user']['must_change_password']);
    eq(401, loginFails('u_staff1', 'Rahasia-Uji-1')->status(), 'password lama tidak berlaku');
    eq(true, AuthService::attempt('u_staff1', $tmp, 'staff', areq('10.8.0.1'))['must_change_password'], 'kunci dibersihkan & sementara berfungsi');
    eq(401, uErr(fn() => (new AuthMiddleware())->handle(new Request('GET', '/api/v1/me'), fn() => Response::ok()))->status(), 'sesi lama gugur');
    lacks($tmp, (string) json_encode(Database::all('SELECT * FROM activity_logs')), 'password sementara tidak boleh masuk audit');
    ok(Database::value("SELECT COUNT(*) FROM activity_logs WHERE action = 'user.password_reset'") >= 1);
});

t('Pengguna tidak ditemukan → 404 untuk ubah / aktif / reset', function () {
    $admin = uSetup();
    eq(404, uErr(fn() => UserService::update(99999999, ['name' => 'Nama', 'role' => 'staff'], $admin, ureq()))->status());
    eq(404, uErr(fn() => UserService::setActive(99999999, true, $admin, ureq()))->status());
    eq(404, uErr(fn() => UserService::resetPassword(99999999, $admin, ureq()))->status());
});

t('Daftar pengguna: boolean tepat, jumlah pinjaman terbuka, tanpa hash password', function () {
    $admin = uSetup();
    $id = aUser('u_staff1', 'Rahasia-Uji-1');
    Database::run('DELETE FROM loans WHERE user_id = ?', [$id]);
    uLoan($id, 'pending'); uLoan($id, 'approved'); uLoan($id, 'returned');
    $row = array_values(array_filter(UserService::listAll(), fn($x) => $x['id'] === $id))[0];
    eq(2, $row['open_loans']);
    ok(is_bool($row['is_active']) && is_bool($row['must_change_password']) && is_int($row['id']));
    foreach (UserService::listAll() as $r) {
        ok(!array_key_exists('password_hash', $r));
    }
});
