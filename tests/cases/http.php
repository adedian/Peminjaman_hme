<?php
declare(strict_types=1);

/**
 * Tes HTTP ke Apache lokal (aplikasi dev). Dilewati otomatis bila server tidak dapat dihubungi.
 * Basis URL: env TEST_BASE_URL, default http://localhost/Peminjaman_hme
 */

/** @param array<string,string> $headers @return array{status:int,headers:array<string,string>,body:string} */
function http(string $method, string $path, array $headers = [], ?string $body = null): array
{
    $base = rtrim((string) (getenv('TEST_BASE_URL') ?: 'http://localhost/Peminjaman_hme'), '/');
    $h = [];
    foreach ($headers as $k => $v) {
        $h[] = "{$k}: {$v}";
    }
    $ctx = stream_context_create(['http' => [
        'method' => $method, 'header' => implode("\r\n", $h), 'content' => $body ?? '',
        'ignore_errors' => true, 'timeout' => 10, 'follow_location' => 0, 'max_redirects' => 0,
    ]]);
    $resBody = @file_get_contents($base . $path, false, $ctx);
    $status = 0;
    $out = [];
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+ (\d{3})#', $line, $m)) {
            $status = (int) $m[1];
        } elseif (strpos($line, ':') !== false) {
            [$k, $v] = explode(':', $line, 2);
            $out[strtolower(trim($k))] = trim($v);
        }
    }
    return ['status' => $status, 'headers' => $out, 'body' => $resBody === false ? '' : $resBody];
}

function httpUp(): bool
{
    static $up = null;
    if ($up === null) {
        $up = http('GET', '/api/v1/health')['status'] > 0;
    }
    return $up;
}

function needHttp(): void
{
    if (!httpUp()) {
        throw new AssertionFailed('server Apache tidak dapat dihubungi (jalankan XAMPP atau pakai --no-http)');
    }
}

// ---------------------------------------------------------------------------
section('HTTP — endpoint & header');

t('GET /api/v1/health → 200 JSON, DB up, tanpa sesi/cookie', function () {
    needHttp();
    $r = http('GET', '/api/v1/health');
    eq(200, $r['status']);
    $j = json_decode($r['body'], true);
    eq(true, $j['ok']);
    eq('ok', $j['data']['status']);
    eq('up', $j['data']['db']);
    contains('application/json', $r['headers']['content-type']);
    ok(!isset($r['headers']['set-cookie']), 'health tidak boleh membuat sesi');
});

t('Header keamanan lengkap pada respons API dan halaman', function () {
    needHttp();
    foreach (['/api/v1/health', '/'] as $p) {
        $r = http('GET', $p);
        eq('nosniff', $r['headers']['x-content-type-options'], $p);
        eq('DENY', $r['headers']['x-frame-options'], $p);
        eq('same-origin', $r['headers']['referrer-policy'], $p);
        contains("default-src 'self'", $r['headers']['content-security-policy'], $p);
        contains("frame-ancestors 'none'", $r['headers']['content-security-policy'], $p);
        ok(isset($r['headers']['x-request-id']), "{$p} harus punya X-Request-Id");
        ok(!isset($r['headers']['x-powered-by']), "{$p} tidak boleh membocorkan X-Powered-By");
    }
});

t('Halaman HTML: tanpa <script> inline, aset memakai ?v=, bahasa id', function () {
    needHttp();
    $r = http('GET', '/login');
    eq(200, $r['status']);
    contains('lang="id"', $r['body']);
    ok(!preg_match('/<script(?![^>]*\bsrc=)/i', $r['body']), 'tidak boleh ada skrip inline (CSP script-src self)');
    contains('tokens.css?v=', $r['body']);
});

t('Route tak dikenal: API → JSON 404; halaman → HTML 404 tanpa jejak stack', function () {
    needHttp();
    $r = http('GET', '/api/v1/tidak-ada');
    eq(404, $r['status']);
    eq('not_found', json_decode($r['body'], true)['error']['code']);
    $r = http('GET', '/halaman-tidak-ada');
    eq(404, $r['status']);
    contains('text/html', $r['headers']['content-type']);
    lacks('Stack trace', $r['body']);
    lacks('C:\\', $r['body']);
});

t('Method salah → 405 + Allow', function () {
    needHttp();
    $r = http('PUT', '/api/v1/health');
    eq(405, $r['status']);
    eq('GET', $r['headers']['allow']);
});

t('GET /api/v1/me tanpa login → 401 JSON (setiap endpoint autentikasi sendiri)', function () {
    needHttp();
    $r = http('GET', '/api/v1/me');
    eq(401, $r['status']);
    eq('unauthenticated', json_decode($r['body'], true)['error']['code']);
});

t('Pengunjung anonim tidak diberi cookie sesi (401 / 403 tanpa Set-Cookie)', function () {
    needHttp();
    $r = http('GET', '/api/v1/me');
    ok(!isset($r['headers']['set-cookie']), 'GET /me anonim tidak boleh membuat sesi');
    $r = http('POST', '/api/v1/_csrf-probe', ['Content-Type' => 'application/json'], '{"a":1}');
    ok(!isset($r['headers']['set-cookie']), 'POST anonim tidak boleh membuat sesi');
});

t('POST tanpa token CSRF → 403 csrf_invalid (JSON); JSON rusak → 400 invalid_json (JSON, bukan HTML)', function () {
    needHttp();
    $r = http('POST', '/api/v1/_csrf-probe', ['Content-Type' => 'application/json'], '{"a":1}');
    eq(403, $r['status']);
    eq('csrf_invalid', json_decode($r['body'], true)['error']['code']);
    $r = http('POST', '/api/v1/_csrf-probe', ['Content-Type' => 'application/json'], '{rusak');
    eq(400, $r['status']);
    contains('application/json', $r['headers']['content-type']);
    eq('invalid_json', json_decode($r['body'], true)['error']['code']);
});

t('Cookie sesi palsu tidak diterima: /me tetap 401 dan ID palsu tidak dipakai ulang', function () {
    needHttp();
    $r = http('GET', '/api/v1/me', ['Cookie' => 'HME_SESSION=abcdef1234567890abcdef1234567890']);
    eq(401, $r['status']);
    if (isset($r['headers']['set-cookie'])) {
        lacks('abcdef1234567890abcdef1234567890', $r['headers']['set-cookie'], 'strict mode: ID asing harus diganti');
    }
});

t('POST dengan Origin asing + token palsu → 403 (tidak pernah mencapai handler)', function () {
    needHttp();
    $r = http('POST', '/api/v1/_csrf-probe', ['Origin' => 'https://evil.example', 'X-CSRF-Token' => 'x']);
    eq(403, $r['status']);
});

// ---------------------------------------------------------------------------
section('HTTP — file sensitif tidak bisa diakses');

t('Konfigurasi, kode, database, dokumen, storage, dan tes tidak dilayani web', function () {
    needHttp();
    $paths = [
        '/.env', '/.env.example', '/.gitignore', '/.htaccess', '/app/bootstrap.php', '/app/Core/Database.php', '/app/Config/app.php',
        '/routes/api.php', '/database/migrate.php', '/database/migrations/0001_create_users.sql', '/database/seeds/001_reference_data.sql',
        '/storage/logs/', '/storage/sessions/', '/storage/uploads/', '/tests/run.php', '/tests/', '/tools/check-env.php',
        '/docs/01-audit-dan-perencanaan.md', '/public/.htaccess', '/public/index.php/../../.env',
    ];
    foreach ($paths as $p) {
        $r = http('GET', $p);
        ok(in_array($r['status'], [403, 404], true), "{$p} → {$r['status']} (harus 403/404)");
        lacks('DB_PASS', $r['body'], $p);
        lacks('<?php', $r['body'], $p);
    }
});

t('Percobaan path traversal tidak membocorkan file', function () {
    needHttp();
    foreach (['/assets/../.env', '/assets/%2e%2e/.env', '/assets/..%2f..%2f.env', '/..%2f.env', '/api/v1/../../.env', '/%2e%2e/app/Config/app.php'] as $p) {
        $r = http('GET', $p);
        ok($r['status'] !== 200 || strpos($r['body'], 'DB_') === false, "{$p} membocorkan isi");
        lacks('DB_PASS', $r['body'], $p);
    }
});

t('Listing direktori dimatikan; aset statis dilayani dengan cache & nosniff', function () {
    needHttp();
    $r = http('GET', '/assets/css/');
    ok(in_array($r['status'], [403, 404], true), "listing direktori → {$r['status']}");
    $r = http('GET', '/assets/css/tokens.css');
    eq(200, $r['status']);
    contains('text/css', $r['headers']['content-type']);
    eq('nosniff', $r['headers']['x-content-type-options']);
    contains('max-age', $r['headers']['cache-control']);
});
