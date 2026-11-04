<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\Env;
use App\Core\ErrorHandler;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Validator;
use App\Core\View;
use App\Middleware\MiddlewareInterface;

/** Middleware tiruan yang mencatat urutan eksekusi. */
final class TraceAuth implements MiddlewareInterface
{
    public static array $log = [];
    public function handle(Request $r, callable $next): Response { self::$log[] = 'auth'; return $next($r); }
}
final class TraceCsrf implements MiddlewareInterface
{
    public function handle(Request $r, callable $next): Response { TraceAuth::$log[] = 'csrf'; return $next($r); }
}
final class TraceRole implements MiddlewareInterface
{
    private string $role;
    public function __construct(string $role = '') { $this->role = $role; }
    public function handle(Request $r, callable $next): Response { TraceAuth::$log[] = 'role:' . $this->role; return $next($r); }
}

function mkRouter(): Router
{
    return new Router(['auth' => TraceAuth::class, 'csrf' => TraceCsrf::class, 'role' => TraceRole::class]);
}

// ---------------------------------------------------------------------------
section('Env & Config');

t('Env: kutip, komentar, nilai kosong, dan env proses menang atas file', function () {
    $f = tempnam(sys_get_temp_dir(), 'env');
    file_put_contents($f, "T_PLAIN=abc\nT_DQ=\"a b # bukan komentar\"\nT_SQ='x y'\nT_INLINE=nilai # komentar\n# komentar penuh\nT_EMPTY=\nT_PROC=dari_file\nbad key=1\n");
    putenv('T_PROC=dari_proses');
    Env::reset();
    Env::load($f);
    eq('abc', Env::get('T_PLAIN'));
    eq('a b # bukan komentar', Env::get('T_DQ'));
    eq('x y', Env::get('T_SQ'));
    eq('nilai', Env::get('T_INLINE'));
    eq('', Env::get('T_EMPTY'));
    eq('dari_proses', Env::get('T_PROC'), 'env proses harus menang');
    eq(null, Env::get('T_TIDAK_ADA'));
    eq('def', Env::get('T_TIDAK_ADA', 'def'));
    putenv('T_PROC');
    Env::reset();
    unlink($f);
    // pulihkan isi .env yang ikut terhapus oleh reset() agar tes berikutnya konsisten
    Env::load(HME_ROOT . '/.env');
    Config::load();
});

t('Env: bool & int', function () {
    putenv('T_B1=true'); putenv('T_B2=0'); putenv('T_I=42'); putenv('T_I2=abc');
    ok(Env::bool('T_B1') === true);
    ok(Env::bool('T_B2', true) === false);
    ok(Env::bool('T_NONE', true) === true);
    eq(42, Env::int('T_I'));
    eq(7, Env::int('T_I2', 7));
    foreach (['T_B1', 'T_B2', 'T_I', 'T_I2'] as $k) { putenv($k); }
});

t('Config: akses bertitik & mode (testing bukan production)', function () {
    eq('hme_peminjaman_test', Config::get('db.name'));
    eq('Asia/Jakarta', Config::get('app.timezone'));
    eq('x', Config::get('tidak.ada', 'x'));
    ok(!Config::isProduction());
    eq('Asia/Jakarta', date_default_timezone_get());
});

t('Config: default aman = production, debug diabaikan di production', function () {
    putenv('APP_ENV=production'); putenv('APP_DEBUG=true');
    Config::load();
    ok(Config::isProduction());
    ok(!Config::isDebug(), 'APP_DEBUG tidak boleh aktif di production');
    putenv('APP_ENV=testing'); putenv('APP_DEBUG=false');
    Config::load();
});

// ---------------------------------------------------------------------------
section('Request');

t('Request: base path dibuang, query dibaca, // dinormalisasi', function () {
    $_SERVER['REQUEST_URI'] = '/Peminjaman_hme//api//v1/me?x=1&y=2';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ['x' => '1', 'y' => '2'];
    $r = Request::fromGlobals();
    eq('/api/v1/me', $r->path());
    eq('1', $r->query('x'));
    ok($r->isApi());
    ok($r->isSafeMethod());
});

t('Request: path %-encoded didekode sekali; traversal tidak lolos router', function () {
    $_SERVER['REQUEST_URI'] = '/Peminjaman_hme/api/v1/%2e%2e/%2e%2e/etc/passwd';
    $r = Request::fromGlobals();
    $router = mkRouter();
    $router->get('/api/v1/health', fn() => Response::ok());
    $e = throws(fn() => $router->dispatch($r), HttpException::class);
    eq(404, $e->status());
});

t('Request: X-Forwarded-For diabaikan kecuali TRUST_PROXY aktif', function () {
    $srv = ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9'];
    $r = new Request('GET', '/', [], [], [], $srv);
    eq('10.0.0.5', $r->ip());
    putenv('TRUST_PROXY=true'); Config::load();
    eq('9.9.9.9', $r->ip());
    $bad = new Request('GET', '/', [], [], [], ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => 'bukan-ip']);
    eq('10.0.0.5', $bad->ip(), 'nilai XFF tidak valid harus diabaikan');
    putenv('TRUST_PROXY=false'); Config::load();
});

t('Request: isSecure dari HTTPS / X-Forwarded-Proto (hanya bila proxy dipercaya)', function () {
    ok((new Request('GET', '/', [], [], [], ['HTTPS' => 'on']))->isSecure());
    ok(!(new Request('GET', '/', [], [], [], ['HTTPS' => 'off']))->isSecure());
    ok(!(new Request('GET', '/', [], [], ['X-Forwarded-Proto' => 'https'], []))->isSecure());
});

// ---------------------------------------------------------------------------
section('Router');

t('Router: parameter {id:\d+} cocok, non-angka → 404, trailing slash ditoleransi', function () {
    $r = mkRouter();
    $r->get('/loans/{id:\d+}/approve', fn(Request $q, array $p) => Response::ok($p['id']));
    $res = $r->dispatch(new Request('GET', '/loans/12/approve'));
    eq('{"ok":true,"data":"12"}', $res->body());
    $res = $r->dispatch(new Request('GET', '/loans/12/approve/'));
    eq(200, $res->status());
    $e = throws(fn() => $r->dispatch(new Request('GET', '/loans/abc/approve')), HttpException::class);
    eq(404, $e->status());
});

t('Router: method salah → 405 + header Allow; HEAD memakai route GET', function () {
    $r = mkRouter();
    $r->get('/x', fn() => Response::ok());
    $e = throws(fn() => $r->dispatch(new Request('DELETE', '/x')), HttpException::class);
    eq(405, $e->status());
    eq('GET', $e->headers()['Allow']);
    eq(200, $r->dispatch(new Request('HEAD', '/x'))->status());
});

t('Router: grup (prefix + middleware) bersarang', function () {
    TraceAuth::$log = [];
    $r = mkRouter();
    $r->group(['prefix' => '/api/v1', 'middleware' => ['auth']], function (Router $r) {
        $r->group(['prefix' => '/admin', 'middleware' => ['role:admin']], function (Router $r) {
            $r->get('/users', fn() => Response::ok('u'));
        });
    });
    $res = $r->dispatch(new Request('GET', '/api/v1/admin/users'));
    eq(200, $res->status());
    eq(['auth', 'role:admin'], TraceAuth::$log, 'urutan middleware');
});

t('Router: CSRF otomatis untuk POST/PUT/DELETE, tidak untuk GET', function () {
    $r = mkRouter();
    $r->post('/a', fn() => Response::ok());
    $r->put('/b', fn() => Response::ok());
    $r->delete('/c', fn() => Response::ok());
    $r->get('/d', fn() => Response::ok());
    foreach ([['POST', '/a'], ['PUT', '/b'], ['DELETE', '/c']] as [$m, $p]) {
        TraceAuth::$log = [];
        $r->dispatch(new Request($m, $p));
        eq(['csrf'], TraceAuth::$log, "{$m} {$p}");
    }
    TraceAuth::$log = [];
    $r->dispatch(new Request('GET', '/d'));
    eq([], TraceAuth::$log);
});

t('Router: CSRF dipasang SETELAH auth (sesi habis → 401, bukan 403)', function () {
    TraceAuth::$log = [];
    $r = mkRouter();
    $r->group(['middleware' => ['auth', 'role:admin']], function (Router $r) {
        $r->post('/x', fn() => Response::ok());
    });
    $r->dispatch(new Request('POST', '/x'));
    eq(['auth', 'csrf', 'role:admin'], TraceAuth::$log);
});

t('Router: middleware tak terdaftar → LogicException (tidak diam-diam dilewati)', function () {
    $r = mkRouter();
    $r->get('/x', fn() => Response::ok(), ['tidak-ada']);
    throws(fn() => $r->dispatch(new Request('GET', '/x')), LogicException::class);
});

t('Router: handler wajib mengembalikan Response', function () {
    $r = mkRouter();
    $r->get('/x', fn() => 'teks');
    throws(fn() => $r->dispatch(new Request('GET', '/x')), LogicException::class);
});

// ---------------------------------------------------------------------------
section('Validator');

t('Validator: required / string / min / max', function () {
    $v = Validator::make(['n' => ' ab '], ['n' => 'required|string|min:3|max:5']);
    ok(!$v->passes());
    contains('minimal 3', $v->errors()['n']);
    $v = Validator::make(['n' => '  abc  '], ['n' => 'required|string|min:3|max:5']);
    ok($v->passes());
    eq('abc', $v->validated()['n'], 'nilai di-trim');
    $v = Validator::make([], ['n' => 'required']);
    contains('wajib', $v->errors()['n']);
    $v = Validator::make(['n' => '   '], ['n' => 'required']);
    ok(!$v->passes(), 'spasi saja dianggap kosong');
});

t('Validator: int & numeric (cast, batas nilai, tolak non-angka)', function () {
    $v = Validator::make(['d' => '7'], ['d' => 'required|int|min:1|max:30']);
    ok($v->passes());
    eq(7, $v->validated()['d']);
    ok(!Validator::make(['d' => '0'], ['d' => 'required|int|min:1'])->passes());
    ok(!Validator::make(['d' => '1.5'], ['d' => 'required|int'])->passes());
    ok(!Validator::make(['d' => 'abc'], ['d' => 'required|int'])->passes());
    ok(!Validator::make(['d' => '99999999999999999999'], ['d' => 'required|int'])->passes(), 'overflow ditolak');
    ok(Validator::make(['d' => '2.5'], ['d' => 'numeric'])->passes());
});

t('Validator: in / regex / alpha_dash / email / date / same', function () {
    ok(Validator::make(['r' => 'admin'], ['r' => 'in:admin,staff'])->passes());
    ok(!Validator::make(['r' => 'root'], ['r' => 'in:admin,staff'])->passes());
    ok(Validator::make(['u' => 'naufal.r-1_x'], ['u' => 'alpha_dash'])->passes());
    ok(!Validator::make(['u' => 'a b'], ['u' => 'alpha_dash'])->passes());
    ok(!Validator::make(['u' => "a'; DROP"], ['u' => 'alpha_dash'])->passes());
    ok(Validator::make(['e' => 'a@b.co'], ['e' => 'email'])->passes());
    ok(!Validator::make(['e' => 'a@'], ['e' => 'email'])->passes());
    ok(Validator::make(['d' => '2026-02-28'], ['d' => 'date'])->passes());
    ok(!Validator::make(['d' => '2026-02-30'], ['d' => 'date'])->passes());
    ok(!Validator::make(['d' => '04/11/2026'], ['d' => 'date'])->passes());
    // Tahap 7: byte NUL dulu membuat createFromFormat melempar ValueError (500)
    ok(!Validator::make(['d' => "2026-01-01\0x"], ['d' => 'date'])->passes());
    ok(Validator::make(['p' => 'abc', 'c' => 'abc'], ['c' => 'same:p'])->passes());
    ok(!Validator::make(['p' => 'abc', 'c' => 'abd'], ['c' => 'same:p'])->passes());
    ok(Validator::make(['x' => 'HME-01'], ['x' => 'regex:/^HME-\d+$/'])->passes());
});

t('Validator: nullable/opsional kosong → null; validate() melempar 422 dengan fields', function () {
    $v = Validator::make(['a' => '', 'b' => 'x'], ['a' => 'nullable|string|max:5', 'b' => 'required|string']);
    ok($v->passes());
    eq(null, $v->validated()['a']);
    $e = throws(fn() => Validator::make([], ['x' => 'required'], ['x' => 'Lokasi'])->validate(), HttpException::class);
    eq(422, $e->status());
    eq('Lokasi wajib diisi.', $e->fields()['x']);
});

t('Validator: panjang dihitung per karakter (UTF-8), aturan tak dikenal → error', function () {
    ok(Validator::make(['n' => 'ééé'], ['n' => 'string|max:3'])->passes());
    throws(fn() => Validator::make(['n' => 'x'], ['n' => 'ajaib']), LogicException::class);
});

// ---------------------------------------------------------------------------
section('Response, View & Error handling');

t('Response: format JSON standar & header keamanan', function () {
    $r = Response::ok(['a' => 1])->withSecurityHeaders();
    eq('{"ok":true,"data":{"a":1}}', $r->body());
    eq('nosniff', $r->header('X-Content-Type-Options'));
    eq('DENY', $r->header('X-Frame-Options'));
    contains("script-src 'self'", $r->header('Content-Security-Policy'));
    contains("frame-ancestors 'none'", $r->header('Content-Security-Policy'));
    $csp = $r->header('Content-Security-Policy');
    ok(!preg_match("/script-src[^;]*unsafe-inline/", $csp), "script-src tidak boleh 'unsafe-inline'");
    ok(!preg_match("/script-src[^;]*unsafe-eval/", $csp));
    eq(null, $r->header('Strict-Transport-Security'), 'HSTS tidak dikirim di HTTP');
    eq('no-store', $r->header('Cache-Control'));
});

t('Response: HSTS hanya pada request HTTPS', function () {
    $https = new Request('GET', '/', [], [], [], ['HTTPS' => 'on']);
    ok(Response::ok()->withSecurityHeaders($https)->header('Strict-Transport-Security') !== null);
});

t('Response: error() memuat code/message/fields', function () {
    $r = Response::error(422, 'validation_failed', 'Salah', ['x' => 'wajib']);
    eq(422, $r->status());
    $b = json_decode($r->body(), true);
    eq(false, $b['ok']);
    eq('validation_failed', $b['error']['code']);
    eq('wajib', $b['error']['fields']['x']);
});

t('View: output di-escape lewat e(), nama view berbahaya ditolak', function () {
    eq('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', e('<script>alert("x")</script>'));
    eq('&#039;', e("'"));
    $html = View::render('auth/login', [
        'title' => '<b>x</b>', 'error' => '<i>galat</i>', 'old' => ['username' => '"><script>alert(1)</script>', 'role' => 'staff'],
    ]);
    contains('&lt;b&gt;x&lt;/b&gt;', $html);
    contains('&lt;i&gt;galat&lt;/i&gt;', $html);
    contains('&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    lacks('<b>x</b>', $html);
    lacks('<i>galat</i>', $html);
    lacks('<script>alert(1)', $html);
    throws(fn() => View::render('../../.env'), InvalidArgumentException::class);
    throws(fn() => View::render('tidak-ada'), RuntimeException::class);
});

t('ErrorHandler: HttpException API → JSON dengan status & header (Allow)', function () {
    $e = new HttpException(405, 'method_not_allowed', 'Salah', [], ['Allow' => 'GET']);
    $res = ErrorHandler::toResponse($e, new Request('POST', '/api/v1/x'));
    eq(405, $res->status());
    eq('GET', $res->header('Allow'));
    eq('method_not_allowed', json_decode($res->body(), true)['error']['code']);
});

t('ErrorHandler: halaman HTML untuk request non-API', function () {
    $res = ErrorHandler::toResponse(new HttpException(404, 'not_found', 'Tidak ada'), new Request('GET', '/xyz'));
    eq(404, $res->status());
    contains('text/html', (string) $res->header('Content-Type'));
    contains('Tidak ada', $res->body());
});

t('ErrorHandler: production TIDAK membocorkan detail exception', function () {
    putenv('APP_ENV=production'); Config::load();
    $res = ErrorHandler::toResponse(new RuntimeException("SQLSTATE rahasia di C:\\xampp\\app\\Secret.php"), new Request('GET', '/api/v1/x'));
    putenv('APP_ENV=testing'); Config::load();
    eq(500, $res->status());
    lacks('rahasia', $res->body());
    lacks('Secret.php', $res->body());
    eq('server_error', json_decode($res->body(), true)['error']['code']);
    ok($res->header('X-Request-Id') !== null);
});

t('ErrorHandler: debug (non-production) menampilkan pesan untuk developer', function () {
    putenv('APP_ENV=development'); putenv('APP_DEBUG=true'); Config::load();
    $res = ErrorHandler::toResponse(new RuntimeException('detail-dev'), new Request('GET', '/api/v1/x'));
    putenv('APP_ENV=testing'); putenv('APP_DEBUG=false'); Config::load();
    contains('detail-dev', $res->body());
});

t('Logger: nilai rahasia disamarkan (password, token, csrf, cookie, nested)', function () {
    $r = Logger::redact([
        'username' => 'naufal', 'password' => 'rahasia1', 'new_password' => 'rahasia2',
        'nested' => ['csrf_token' => 'abc', 'Authorization' => 'Bearer x', 'ok' => 1],
    ]);
    eq('naufal', $r['username']);
    eq('[disamarkan]', $r['password']);
    eq('[disamarkan]', $r['new_password']);
    eq('[disamarkan]', $r['nested']['csrf_token']);
    eq('[disamarkan]', $r['nested']['Authorization']);
    eq(1, $r['nested']['ok']);
});
