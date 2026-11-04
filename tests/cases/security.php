<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\ForcePasswordChangeMiddleware;
use App\Middleware\RoleMiddleware;

/** @return array<string,int> id user uji (dibuat sekali) */
function testUsers(): array
{
    static $ids = null;
    if ($ids === null) {
        $h = password_hash('uji-password-123', PASSWORD_DEFAULT);
        $mk = fn(string $u, string $role, int $active, int $must) => Database::insert('users', [
            'username' => $u, 'password_hash' => $h, 'name' => strtoupper($u), 'initials' => strtoupper(substr($u, 0, 2)),
            'role' => $role, 'is_active' => $active, 'must_change_password' => $must,
        ]);
        $ids = [
            'admin'    => $mk('t_admin', 'admin', 1, 0),
            'staff'    => $mk('t_staff', 'staff', 1, 0),
            'inactive' => $mk('t_off', 'staff', 0, 0),
            'forced'   => $mk('t_forced', 'staff', 1, 1),
        ];
    }
    return $ids;
}

function restartSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $p = new ReflectionProperty(Session::class, 'started');
    $p->setAccessible(true);
    $p->setValue(null, false);
}

/** @param array<string,mixed> $override */
function withSessionConfig(array $override, callable $fn): void
{
    $cfg = require HME_ROOT . '/app/Config/app.php';
    foreach ($override as $k => $v) {
        $cfg['session'][$k] = $v;
    }
    $f = tempnam(sys_get_temp_dir(), 'cfg');
    file_put_contents($f, '<?php return ' . var_export($cfg, true) . ';');
    Config::load($f);
    try {
        $fn();
    } finally {
        Config::load();
        unlink($f);
    }
}

$pass = static fn(Request $r): Response => Response::ok('lolos');

// ---------------------------------------------------------------------------
section('Sesi');

t('Sesi: login mengganti ID sesi dan token CSRF (anti session-fixation)', function () {
    $u = testUsers();
    Session::start();
    $idBefore = session_id();
    $tokBefore = Csrf::token();
    Session::login($u['staff']);
    ok(session_id() !== $idBefore, 'ID sesi harus berganti saat login');
    ok(Csrf::token() !== $tokBefore, 'token CSRF harus berganti saat login');
    eq($u['staff'], Session::userId());
});

t('Sesi: logout menghapus identitas dan menghancurkan sesi', function () {
    $u = testUsers();
    Session::login($u['staff']);
    $id = session_id();
    Session::logout();
    eq(null, Session::userId());
    ok(session_id() !== $id);
});

t('Sesi: timeout IDLE mengosongkan sesi dan mengganti ID', function () {
    $u = testUsers();
    Session::start();
    Session::login($u['staff']);
    $id = session_id();
    restartSession();
    withSessionConfig(['idle_timeout' => 1], function () use ($id) {
        sleep(2);
        session_id($id);
        Session::start();
        eq(null, Session::userId(), 'sesi idle harus berakhir');
        ok(session_id() !== $id, 'ID baru setelah timeout');
    });
});

t('Sesi: timeout ABSOLUT berlaku walau pengguna aktif', function () {
    $u = testUsers();
    Session::start();
    Session::login($u['staff']);
    $id = session_id();
    restartSession();
    withSessionConfig(['idle_timeout' => 9999, 'absolute_timeout' => 1], function () use ($id) {
        sleep(2);
        session_id($id);
        Session::start();
        eq(null, Session::userId());
    });
});

t('Sesi: polling (touch=false) TIDAK memperpanjang timeout idle', function () {
    $u = testUsers();
    Session::start();
    Session::login($u['staff']);
    $_SESSION['_last'] = time() - 100;
    $id = session_id();
    restartSession();
    session_id($id);
    Session::start(null, false);
    ok($_SESSION['_last'] <= time() - 99, 'polling tidak boleh memperbarui aktivitas terakhir');
    $_SESSION['_last'] = time() - 100;
    restartSession();
    session_id($id);
    Session::start(null, true);
    ok($_SESSION['_last'] >= time() - 2, 'request biasa memperbarui aktivitas terakhir');
});

t('Sesi: cookie HttpOnly + SameSite=Lax + path aplikasi; strict mode; folder sesi khusus', function () {
    Session::logout();
    Session::start(new Request('GET', '/', [], [], [], []));
    $p = session_get_cookie_params();
    eq(true, $p['httponly'], 'HttpOnly');
    eq('Lax', $p['samesite'], 'SameSite');
    eq(false, $p['secure'], 'HTTP biasa → tanpa Secure');
    eq('/Peminjaman_hme', $p['path'], 'path cookie = base path aplikasi');
    eq(0, $p['lifetime'], 'cookie sesi, bukan persisten');
    eq('1', ini_get('session.use_strict_mode'));
    eq('1', ini_get('session.use_only_cookies'));
    eq('0', ini_get('session.use_trans_sid'));
    eq('HME_SESSION', session_name());
    eq(realpath(HME_ROOT . '/storage/sessions'), realpath(session_save_path()));
});

t('Sesi: request HTTPS → cookie bertanda Secure', function () {
    Session::logout();
    Session::start(new Request('GET', '/', [], [], [], ['HTTPS' => 'on']));
    eq(true, session_get_cookie_params()['secure']);
    Session::logout();
});

t('Sesi: pengunjung anonim tidak membuat sesi (Auth & CSRF)', function () use ($pass) {
    Session::logout();
    unset($_COOKIE['HME_SESSION']);
    $before = count(glob(HME_ROOT . '/storage/sessions/sess_*') ?: []);
    throws(fn() => (new AuthMiddleware())->handle(new Request('GET', '/api/v1/me'), $pass), HttpException::class);
    $e = throws(fn() => (new CsrfMiddleware())->handle(new Request('POST', '/x'), $pass), HttpException::class);
    eq('csrf_invalid', $e->errorCode());
    eq($before, count(glob(HME_ROOT . '/storage/sessions/sess_*') ?: []), 'tidak boleh ada file sesi baru');
    ok(!Session::isStarted());
});

// ---------------------------------------------------------------------------
section('CSRF');

t('Csrf: token stabil dalam sesi, 64 hex, verify menolak kosong/salah', function () {
    Session::logout();
    Session::start();
    $t = Csrf::token();
    eq($t, Csrf::token());
    ok((bool) preg_match('/^[a-f0-9]{64}$/', $t));
    ok(Csrf::verify($t));
    ok(!Csrf::verify(null));
    ok(!Csrf::verify(''));
    ok(!Csrf::verify($t . 'x'));
    ok(!Csrf::verify(strrev($t)));
    Csrf::regenerate();
    ok(!Csrf::verify($t), 'token lama harus tidak berlaku setelah regenerate');
});

t('CsrfMiddleware: GET lolos tanpa token', function () use ($pass) {
    $res = (new CsrfMiddleware())->handle(new Request('GET', '/x'), $pass);
    eq(200, $res->status());
});

t('CsrfMiddleware: POST/PUT/DELETE tanpa token → 403 csrf_invalid', function () use ($pass) {
    foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $m) {
        $e = throws(fn() => (new CsrfMiddleware())->handle(new Request($m, '/x'), $pass), HttpException::class);
        eq(403, $e->status(), $m);
        eq('csrf_invalid', $e->errorCode(), $m);
    }
});

t('CsrfMiddleware: token salah → 403; token benar di header ATAU body → lolos', function () use ($pass) {
    $tok = Csrf::token();
    $e = throws(fn() => (new CsrfMiddleware())->handle(new Request('POST', '/x', [], [], ['X-CSRF-Token' => 'salah']), $pass), HttpException::class);
    eq('csrf_invalid', $e->errorCode());
    eq(200, (new CsrfMiddleware())->handle(new Request('POST', '/x', [], [], ['X-CSRF-Token' => $tok]), $pass)->status());
    eq(200, (new CsrfMiddleware())->handle(new Request('POST', '/x', [], ['_csrf' => $tok]), $pass)->status());
    $e = throws(fn() => (new CsrfMiddleware())->handle(new Request('POST', '/x', [], ['_csrf' => ['bukan', 'string']]), $pass), HttpException::class);
    eq('csrf_invalid', $e->errorCode(), 'token non-string tidak boleh menyebabkan error/bypass');
});

t('CsrfMiddleware: Origin asing ditolak walau token benar; Origin sama lolos', function () use ($pass) {
    $tok = Csrf::token();
    $h = ['X-CSRF-Token' => $tok, 'Host' => 'localhost'];
    $e = throws(fn() => (new CsrfMiddleware())->handle(new Request('POST', '/x', [], [], $h + ['Origin' => 'https://evil.example']), $pass), HttpException::class);
    eq('csrf_origin', $e->errorCode());
    eq(200, (new CsrfMiddleware())->handle(new Request('POST', '/x', [], [], $h + ['Origin' => 'http://localhost']), $pass)->status());
    $h2 = ['X-CSRF-Token' => $tok, 'Host' => 'localhost:8080'];
    eq(200, (new CsrfMiddleware())->handle(new Request('POST', '/x', [], [], $h2 + ['Origin' => 'http://localhost:8080']), $pass)->status());
    $e = throws(fn() => (new CsrfMiddleware())->handle(new Request('POST', '/x', [], [], $h2 + ['Origin' => 'http://localhost:9999']), $pass), HttpException::class);
    eq('csrf_origin', $e->errorCode(), 'port berbeda = origin berbeda');
});

// ---------------------------------------------------------------------------
section('Autentikasi & otorisasi');

t('AuthMiddleware: tanpa sesi → API 401 JSON; halaman web → redirect /login', function () use ($pass) {
    Session::logout();
    $e = throws(fn() => (new AuthMiddleware())->handle(new Request('GET', '/api/v1/me'), $pass), HttpException::class);
    eq(401, $e->status());
    eq('unauthenticated', $e->errorCode());
    $res = (new AuthMiddleware())->handle(new Request('GET', '/dashboard'), $pass);
    eq(302, $res->status());
    contains('/login', (string) $res->header('Location'));
});

t('AuthMiddleware: user valid → request.user terisi (tanpa password_hash)', function () {
    $u = testUsers();
    Session::login($u['admin']);
    $seen = null;
    $res = (new AuthMiddleware())->handle(new Request('GET', '/api/v1/me'), function (Request $r) use (&$seen) {
        $seen = $r->user();
        return Response::ok();
    });
    eq(200, $res->status());
    eq('t_admin', $seen['username']);
    eq('admin', $seen['role']);
    ok(!array_key_exists('password_hash', $seen), 'hash password tidak boleh ikut ke request');
    ok(is_int($seen['id']));
});

t('AuthMiddleware: akun dinonaktifkan → sesi aktif langsung ditolak (401) & sesi dihancurkan', function () use ($pass) {
    $u = testUsers();
    Session::login($u['staff']);
    Database::update('users', ['is_active' => 0], ['id' => $u['staff']]);
    $e = throws(fn() => (new AuthMiddleware())->handle(new Request('GET', '/api/v1/me'), $pass), HttpException::class);
    eq(401, $e->status());
    eq(null, Session::userId(), 'sesi harus hancur');
    Database::update('users', ['is_active' => 1], ['id' => $u['staff']]);
});

t('AuthMiddleware: user dihapus dari DB / id sesi palsu → 401', function () use ($pass) {
    Session::login(987654);
    $e = throws(fn() => (new AuthMiddleware())->handle(new Request('GET', '/api/v1/me'), $pass), HttpException::class);
    eq(401, $e->status());
});

t('AuthMiddleware: perubahan role berlaku seketika (tanpa login ulang)', function () {
    $u = testUsers();
    Session::login($u['staff']);
    $role = null;
    $cap = function (Request $r) use (&$role) { $role = $r->user()['role']; return Response::ok(); };
    (new AuthMiddleware())->handle(new Request('GET', '/x'), $cap);
    eq('staff', $role);
    Database::update('users', ['role' => 'admin'], ['id' => $u['staff']]);
    (new AuthMiddleware())->handle(new Request('GET', '/x'), $cap);
    eq('admin', $role);
    Database::update('users', ['role' => 'staff'], ['id' => $u['staff']]);
});

t('RoleMiddleware: staff ke rute admin → 403; admin lolos; tanpa user → 401', function () use ($pass) {
    $u = testUsers();
    $mkReq = function (string $uname): Request {
        $r = new Request('GET', '/api/v1/admin/x');
        $row = Database::one('SELECT id, username, role FROM users WHERE username = ?', [$uname]);
        $r->setAttribute('user', $row);
        return $r;
    };
    $e = throws(fn() => (new RoleMiddleware('admin'))->handle($mkReq('t_staff'), $pass), HttpException::class);
    eq(403, $e->status());
    eq('forbidden', $e->errorCode());
    eq(200, (new RoleMiddleware('admin'))->handle($mkReq('t_admin'), $pass)->status());
    eq(200, (new RoleMiddleware('admin', 'staff'))->handle($mkReq('t_staff'), $pass)->status());
    $e = throws(fn() => (new RoleMiddleware('admin'))->handle(new Request('GET', '/x'), $pass), HttpException::class);
    eq(401, $e->status());
});

t('ForcePasswordChange: akun wajib ganti password diblokir (API 403, web redirect)', function () use ($pass) {
    $u = testUsers();
    Session::login($u['forced']);
    $r = new Request('GET', '/api/v1/loans');
    $chain = fn(Request $req) => (new AuthMiddleware())->handle($req, fn($q) => (new ForcePasswordChangeMiddleware())->handle($q, $pass));
    $e = throws(fn() => $chain($r), HttpException::class);
    eq(403, $e->status());
    eq('password_change_required', $e->errorCode());
    $res = $chain(new Request('GET', '/dashboard'));
    eq(302, $res->status());
    contains('/profile/password', (string) $res->header('Location'));
    Session::login($u['staff']);
    eq(200, $chain(new Request('GET', '/api/v1/loans'))->status(), 'akun normal tidak diblokir');
});

t('Password: disimpan sebagai hash (bukan plaintext), verify benar/salah', function () {
    $row = Database::one('SELECT password_hash FROM users WHERE username = ?', ['t_admin']);
    ok($row['password_hash'] !== 'uji-password-123');
    ok(strlen($row['password_hash']) >= 60);
    ok(password_verify('uji-password-123', $row['password_hash']));
    ok(!password_verify('salah', $row['password_hash']));
    ok(!password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT));
});
