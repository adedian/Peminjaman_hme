<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\Database;
use App\Core\Router;
use App\Services\ItemService;
use App\Services\LoanService;
use App\Services\PqaService;
use App\Services\UserService;

/**
 * Tahap 7 — sapuan menyeluruh atas SEMUA rute yang terdaftar (dibangkitkan dari router, bukan daftar manual),
 * sehingga rute baru otomatis ikut diuji dan kebijakan akses yang terlupa langsung ketahuan.
 */

/** @return array<int,array{method:string,path:string,middleware:array<int,string>}> */
function swRoutes(): array
{
    static $routes = null;
    if ($routes === null) {
        $router = new Router(['auth' => 'x', 'role' => 'x', 'csrf' => 'x', 'pwd' => 'x']);
        require HME_ROOT . '/routes/api.php';
        require HME_ROOT . '/routes/web.php';
        $routes = $router->routes();
    }
    return $routes;
}

/** Path konkret dari pola rute ({id} → 1, {file} → nama berkas valid). */
function swPath(string $pattern, string $id = '1'): string
{
    $p = preg_replace('/\{id:[^}]+\}/', $id, $pattern) ?? $pattern;
    return preg_replace('/\{file\}/', str_repeat('a', 32) . '.jpg', $p) ?? $p;
}

function swIsApi(string $path): bool { return strncmp($path, '/api/', 5) === 0; }
function swRole(array $r): ?string
{
    foreach ($r['middleware'] as $m) {
        if (strncmp($m, 'role:', 5) === 0) {
            return substr($m, 5);
        }
    }
    return null;
}
function swKey(array $r): string { return $r['method'] . ' ' . $r['path']; }

/** Isi log aplikasi yang ditambahkan sejak $offset. */
function swLogSince(int $offset): string
{
    $f = rtrim((string) Config::get('paths.logs'), '/\\') . '/app-' . date('Y-m-d') . '.log';
    if (!is_file($f)) {
        return '';
    }
    clearstatcache(true, $f);
    return (string) file_get_contents($f, false, null, $offset);
}
function swLogSize(): int
{
    $f = rtrim((string) Config::get('paths.logs'), '/\\') . '/app-' . date('Y-m-d') . '.log';
    clearstatcache(true, $f);
    return is_file($f) ? (int) filesize($f) : 0;
}

// ---------------------------------------------------------------------------
section('Tahap 7 — kebijakan akses seluruh rute (statis)');

t('Setiap rute: publik hanya daftar putih; sisanya auth; semua penulisan ber-CSRF; hanya rute bersama yang tanpa peran', function () {
    $public = ['GET /api/v1/health', 'POST /api/v1/auth/login', 'GET /login', 'POST /login', 'POST /api/v1/_csrf-probe'];
    // Rute yang sengaja bisa diakses kedua peran (pemisahan data ditegakkan di service, diuji per modul)
    $shared = [
        'GET /', 'GET /requests', 'GET /pqa', 'GET /notifications', 'GET /profile', 'GET /profile/password', 'POST /profile/password', 'POST /logout',
        'GET /media/items/{file}', 'GET /api/v1/me', 'POST /api/v1/auth/logout', 'POST /api/v1/me/password', 'GET /api/v1/items/available',
        'GET /api/v1/loans/summary', 'GET /api/v1/requests', 'GET /api/v1/requests/{id:\d+}', 'GET /api/v1/pqa/units', 'GET /api/v1/pqa',
        'GET /api/v1/pqa/{id:\d+}', 'GET /api/v1/sync', 'GET /api/v1/notifications', 'GET /api/v1/notifications/count',
        'POST /api/v1/notifications/read-all', 'POST /api/v1/notifications/{id:\d+}/read',
    ];
    // Boleh sebelum mengganti password sementara
    $noPwd = ['GET /api/v1/me', 'POST /api/v1/auth/logout', 'POST /api/v1/me/password', 'GET /profile', 'GET /profile/password', 'POST /profile/password', 'POST /logout'];
    $problems = [];
    $keys = [];
    foreach (swRoutes() as $r) {
        $k = swKey($r);
        $keys[] = $k;
        $mw = $r['middleware'];
        if ($r['method'] !== 'GET' && !in_array('csrf', $mw, true)) { $problems[] = "{$k}: penulisan tanpa CSRF"; }
        if (in_array($k, $public, true)) { continue; }
        if (!in_array('auth', $mw, true)) { $problems[] = "{$k}: tanpa auth"; }
        if (!in_array($k, $noPwd, true) && !in_array('pwd', $mw, true)) { $problems[] = "{$k}: tanpa pwd"; }
        if (swRole($r) === null && !in_array($k, $shared, true)) { $problems[] = "{$k}: tanpa pembatasan peran dan tidak ada di daftar rute bersama"; }
    }
    eq([], $problems);
    eq([], array_values(array_diff(array_merge($public, $shared), $keys)), 'daftar putih tidak memuat rute yang sudah dihapus');
    ok(count($keys) >= 80, 'jumlah rute: ' . count($keys));
});

// ---------------------------------------------------------------------------
section('Tahap 7 — matriks otorisasi seluruh rute (HTTP)');

t('Anonim → 401/redirect; staff → rute admin 403; admin → rute staff 403; tanpa token CSRF → 403 — untuk SETIAP rute', function () {
    eLoanReset();
    $anon = new Client();
    $staff = e2eLogin('e_staff');
    $admin = e2eAdmin();
    $bad = [];
    $n = 0;
    foreach (swRoutes() as $r) {
        $k = swKey($r);
        $path = swPath($r['path']);
        $api = swIsApi($path);
        $write = $r['method'] !== 'GET';
        if (in_array('auth', $r['middleware'], true)) {
            $res = $api ? $anon->api($r['method'], substr($path, 7), $write ? [] : null) : $anon->request($r['method'], $path, null, $write ? [] : null);
            $ok = $api ? $res['status'] === 401 : ($res['status'] === 302 && loc($res) === '/login');
            if (!$ok) { $bad[] = "anonim {$k} → {$res['status']}"; }
            $n++;
        }
        $role = swRole($r);
        foreach (['admin' => [$staff, 'staff'], 'staff' => [$admin, 'admin']] as $need => [$c, $who]) {
            if ($role !== $need) {
                continue;
            }
            $res = $api ? $c->api($r['method'], substr($path, 7), $write ? [] : null) : $c->request($r['method'], $path, null, $write ? ['_csrf' => $c->csrf] : null, $write ? ['X-CSRF-Token' => $c->csrf] : []);
            if ($res['status'] !== 403) { $bad[] = "{$who} {$k} → {$res['status']}"; }
            $n++;
        }
        if ($write && in_array('auth', $r['middleware'], true) && !in_array($r['path'], ['/logout', '/api/v1/auth/logout'], true)) {
            $res = $api ? $staff->api($r['method'], substr($path, 7), [], 'salah') : $staff->request($r['method'], $path, null, ['_csrf' => 'salah']);
            if ($res['status'] !== 403) { $bad[] = "tanpa CSRF {$k} → {$res['status']}"; }
            $n++;
        }
    }
    eq([], $bad);
    ok($n >= 140, "kombinasi diuji: {$n}");
    eq(200, $staff->api('GET', '/me')['status'], 'sesi staff tetap utuh setelah sapuan');
});

// ---------------------------------------------------------------------------
section('Tahap 7 — fuzzing input seluruh endpoint (HTTP)');

t('Query berbahaya pada SETIAP GET yang diizinkan (admin & staff) → tidak pernah 5xx, API tetap JSON', function () {
    eLoanReset();
    $clients = ['admin' => e2eAdmin(), 'staff' => e2eLogin('e_staff')];
    $keys = ['q', 'status', 'filter', 'group', 'month', 'year', 'since', 'limit', 'offset', 'user_id', 'from', 'to', 'unread', 'tab', 'open', 'name', 'new'];
    $values = ["' OR '1'='1' -- ", '"><script>alert(1)</script>', '-1', '99999999999999999999999', str_repeat('A', 3000), '../../.env', "\u{202E}\u{0000}x", '1;DROP TABLE loans', '%', '😀', '0x41'];
    $log0 = swLogSize();
    $bad = [];
    $n = 0;
    foreach (swRoutes() as $r) {
        if ($r['method'] !== 'GET' || !in_array('auth', $r['middleware'], true) || $r['path'] === '/media/items/{file}') {
            continue;
        }
        foreach ($clients as $role => $c) {
            $need = swRole($r);
            if ($need !== null && $need !== $role) {
                continue;
            }
            foreach (array_merge($values, ['__array__']) as $v) {
                $qs = [];
                foreach ($keys as $key) {
                    $qs[] = $v === '__array__' ? rawurlencode($key) . '[]=1' : rawurlencode($key) . '=' . rawurlencode($v);
                }
                $path = swPath($r['path']) . '?' . implode('&', $qs);
                $res = swIsApi($path) ? $c->api('GET', substr($path, 7)) : $c->get($path);
                $n++;
                if ($res['status'] >= 500 || $res['status'] === 0) {
                    $bad[] = "{$role} GET {$r['path']} (" . mb_substr($v, 0, 20) . ") → {$res['status']} " . ($res['error'] ?? '');
                } elseif (swIsApi($path) && $res['status'] !== 204 && strpos($res['headers']['content-type'][0] ?? '', 'spreadsheetml') === false && $res['json'] === null) {
                    $bad[] = "{$role} GET {$r['path']}: bukan JSON";
                }
            }
        }
    }
    eq([], array_slice(array_unique($bad), 0, 20));
    ok($n >= 500, "permintaan: {$n}");
    $log = swLogSince($log0);
    eq(0, preg_match_all('/"lvl":"ERROR"/', $log), 'log server tanpa ERROR: ' . mb_substr($log, 0, 400));
    eq(14, (int) Database::value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('users','items','categories','loans','loan_batches','pqa_installations','pqa_requests','settings','activity_logs','change_events','notifications','login_attempts','mail_outbox','legacy_manual_loans')"), 'tabel utuh');
});

t('Badan permintaan berbahaya pada SETIAP penulisan yang diizinkan (admin & staff) → tidak pernah 5xx', function () {
    eLoanReset();
    $clients = ['admin' => e2eAdmin(), 'staff' => e2eLogin('e_staff')];
    $fields = ['item_ids', 'item_id', 'location', 'team', 'notes', 'borrow_date', 'name', 'username', 'role', 'password', 'code', 'category_id',
               'is_pqa', 'days', 'ppic', 'install_team', 'target_days', 'admin_notification_email', 'current_password', 'new_password',
               'new_password_confirmation', 'new_item_id', 'finance_ref', 'remove_photo', 'status', 'id', 'user_id'];
    $bodies = [
        'kosong'   => [],
        'array'    => array_fill_keys($fields, ['x' => ['y' => 1]]),
        'injeksi'  => array_fill_keys($fields, "' OR 1=1; DROP TABLE users; --"),
        'xss'      => array_fill_keys($fields, '<img src=x onerror=alert(1)>'),
        'besar'    => array_fill_keys($fields, str_repeat('Z', 20000)),
        'angka'    => array_fill_keys($fields, -2147483649),
        'null'     => array_fill_keys($fields, null),
        'bool'     => array_fill_keys($fields, true),
        'nul'      => array_fill_keys($fields, "2026-01-01\0x"),
    ];
    $users = (int) Database::value('SELECT COUNT(*) FROM users');
    $itemsBefore = (int) Database::value('SELECT COUNT(*) FROM items');
    $log0 = swLogSize();
    $bad = [];
    $n = 0;
    foreach (swRoutes() as $r) {
        if ($r['method'] === 'GET' || !in_array('auth', $r['middleware'], true) || !swIsApi($r['path']) || $r['path'] === '/api/v1/auth/logout') {
            continue;
        }
        foreach ($clients as $role => $c) {
            $need = swRole($r);
            if ($need !== null && $need !== $role) {
                continue;
            }
            foreach (['1', '99999999'] as $id) {
                foreach ($bodies as $label => $body) {
                    $res = $c->api($r['method'], substr(swPath($r['path'], $id), 7), $body);
                    $n++;
                    if ($res['status'] >= 500 || $res['status'] === 0 || $res['json'] === null) {
                        $bad[] = "{$role} {$r['method']} {$r['path']} [{$label}, id {$id}] → {$res['status']} " . ($res['error'] ?? '');
                    }
                }
            }
        }
    }
    eq([], array_slice($bad, 0, 20));
    ok($n >= 400, "permintaan: {$n}");
    $log = swLogSince($log0);
    eq(0, preg_match_all('/"lvl":"ERROR"/', $log), 'log server tanpa ERROR: ' . mb_substr($log, 0, 600));
    eq($users, (int) Database::value('SELECT COUNT(*) FROM users'), 'tidak ada akun yang terbentuk dari masukan rusak');
    eq($itemsBefore, (int) Database::value('SELECT COUNT(*) FROM items'), 'tidak ada barang yang terbentuk dari masukan rusak');
});

// ---------------------------------------------------------------------------
section('Tahap 7 — XSS tersimpan di seluruh halaman');

t('Teks HTML di nama pengguna/barang, lokasi, catatan, PQA, notifikasi tampil ter-escape di SEMUA halaman (admin & staff)', function () {
    eLoanReset();
    $p = '"><img src=x onerror=alert(1)><script>alert(2)</script>';
    $admin = ['id' => (int) Database::value("SELECT id FROM users WHERE username = 'e_admin'"), 'role' => 'admin', 'name' => 'E_admin'];
    $staffId = (int) Database::value("SELECT id FROM users WHERE username = 'e_staff'");
    Database::run('UPDATE users SET name = ? WHERE id = ?', ['Staff ' . $p, $staffId]);
    $staff = ['id' => $staffId, 'role' => 'staff', 'name' => 'Staff ' . $p, 'username' => 'e_staff'];
    $item = iMake(['name' => 'Alat ' . $p]);
    $unit = iMake(['name' => 'PQA ' . $p, 'is_pqa' => '1']);
    $req = LoanService::submit($staff, ['item_ids' => [$item['id']], 'location' => 'Lokasi ' . $p, 'team' => 'Tim ' . $p, 'notes' => 'Catatan ' . $p], ureq());
    PqaService::submit($staff, ['item_id' => $unit['id'], 'ppic' => 'PPIC ' . $p, 'location' => 'PQA di ' . $p, 'install_team' => 'Tim ' . $p, 'target_days' => 3], ureq());
    Database::run("INSERT INTO legacy_inventory_register (section, source_row, name, note) VALUES ('Uji', 99999, ?, ?)", ['Reg ' . $p, $p]);
    Database::run("INSERT INTO legacy_manual_loans (source_row, item_text, carrier_text, notes) VALUES (99999, ?, ?, ?)", ['Manual ' . $p, $p, $p]);
    try {
        $clients = ['admin' => e2eAdmin(), 'staff' => e2eLogin('e_staff')];
        $bad = [];
        $pages = 0;
        foreach (swRoutes() as $r) {
            if ($r['method'] !== 'GET' || swIsApi($r['path']) || !in_array('auth', $r['middleware'], true) || $r['path'] === '/media/items/{file}') {
                continue;
            }
            foreach ($clients as $role => $c) {
                $need = swRole($r);
                if ($need !== null && $need !== $role) {
                    continue;
                }
                $paths = [$r['path']];
                if ($r['path'] === '/archive') { $paths[] = '/archive?tab=register'; }
                if ($r['path'] === '/items') { $paths[] = '/items?new=1&name=' . rawurlencode($p); }
                if ($r['path'] === '/requests') { $paths[] = '/requests?status=' . rawurlencode($p) . '&open=' . $req['id']; }
                foreach ($paths as $path) {
                    $res = $c->get($path);
                    $pages++;
                    if ($res['status'] !== 200) { $bad[] = "{$role} {$path} → {$res['status']}"; continue; }
                    foreach (['<img src=x onerror', '<script>alert(2)', 'onerror=alert(1)>'] as $needle) {
                        if (strpos($res['body'], $needle) !== false) { $bad[] = "{$role} {$path}: '{$needle}' tidak ter-escape"; }
                    }
                }
            }
        }
        eq([], $bad);
        ok($pages >= 20, "halaman diperiksa: {$pages}");
        // Data mentah memang dikembalikan API apa adanya (JSON), dan klien merendernya sebagai teks
        eq('Lokasi ' . $p, $clients['admin']->api('GET', '/requests/' . $req['id'])['json']['data']['location']);
        $dangerous = [];
        foreach (glob(HME_ROOT . '/public/assets/js/*.js') ?: [] as $f) {
            $src = (string) file_get_contents($f);
            if (preg_match('/\.innerHTML\s*=|outerHTML|insertAdjacentHTML|document\.write|\beval\s*\(|new Function\s*\(|setTimeout\s*\(\s*[\'"]/', $src, $m)) {
                $dangerous[] = basename($f) . ': ' . $m[0];
            }
        }
        eq([], $dangerous, 'JS tanpa sink HTML/eval');
    } finally {
        Database::run('UPDATE users SET name = ? WHERE id = ?', ['E_staff', $staffId]);
        Database::run('DELETE FROM legacy_inventory_register WHERE source_row = 99999');
        Database::run('DELETE FROM legacy_manual_loans WHERE source_row = 99999');
        eLoanReset();
        Database::run("UPDATE pqa_installations SET status = 'Ditolak' WHERE item_id = ?", [$unit['id']]);
        \App\Services\AvailabilityService::sync($unit['id']);
    }
});

// ---------------------------------------------------------------------------
section('Tahap 7 — penanganan error (production, database mati)');

t('Production + database mati: API 503 JSON, halaman 503 HTML, health "degraded" — tanpa detail teknis/credential; APP_DEBUG diabaikan', function () {
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $name = (string) stream_socket_get_name($sock, false);
    fclose($sock);
    $port = (int) substr((string) strrchr($name, ':'), 1);
    $env = array_merge(getenv(), ['APP_ENV' => 'production', 'APP_DEBUG' => 'true', 'DB_PORT' => '1', 'DB_PASS' => 'Rahasia-DB-777',
                                  'APP_BASE_PATH' => '/', 'LOG_DIR' => sys_get_temp_dir() . '/hme_test_logs_prod']);
    $root = HME_ROOT;
    $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $root . '/public', $root . '/tests/router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/hme_prod.out', 'w'], 2 => ['file', sys_get_temp_dir() . '/hme_prod.err', 'w']], $pipes, $root, $env);
    ok(is_resource($proc));
    try {
        for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port, $e1, $e2, 0.2); $i++) { usleep(100000); }
        $old = TestServer::$base;
        TestServer::$base = "http://127.0.0.1:{$port}";
        try {
            $c = new Client();
            $h = $c->api('GET', '/health');
            eq([503, 'degraded', 'down'], [$h['status'], $h['json']['data']['status'] ?? null, $h['json']['data']['db'] ?? null]);
            ok(!isset($h['json']['data']['env']), 'tanpa info lingkungan di production');
            $c->bootstrapGuest();
            $l = $c->api('POST', '/auth/login', ['username' => 'e_admin', 'password' => 'x', 'role' => 'admin']);
            eq([503, 'db_unavailable'], [$l['status'], $l['json']['error']['code'] ?? null], $l['body']);
            $c->cookies['HME_SESSION'] = str_repeat('a', 26);
            $p = $c->get('/');
            ok(in_array($p['status'], [302, 503], true), 'halaman: ' . $p['status']);
            $nf = $c->get('/tidak-ada');
            eq(404, $nf['status']);
            foreach ([$h, $l, $p, $nf] as $res) {
                foreach (['SQLSTATE', 'PDO', 'Rahasia-DB-777', 'Stack trace', '.php', 'root@', 'Exception'] as $leak) {
                    lacks($leak, $res['body'], "bocor '{$leak}'");
                }
                ok(isset($res['headers']['x-content-type-options']), 'header keamanan tetap ada');
            }
        } finally {
            TestServer::$base = $old;
        }
    } finally {
        proc_terminate($proc);
        proc_close($proc);
    }
});
