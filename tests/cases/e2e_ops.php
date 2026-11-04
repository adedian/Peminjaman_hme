<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
section('E2E Tahap 8 — mode pemeliharaan');

t('Mode pemeliharaan: halaman 503 + Retry-After, API 503 JSON, health tetap 200, aset tidak terblokir; "off" memulihkan; CLI on/off/status', function () {
    e2eReset();
    $admin = e2eAdmin();                       // sudah login sebelum mode aktif
    $flag = HME_ROOT . '/storage/maintenance.flag';
    $cli = fn(string $arg) => (function () use ($arg) {
        clearstatcache();
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(HME_ROOT . '/tools/maintenance-mode.php') . ' ' . $arg . ' 2>&1', $o, $c);
        clearstatcache();
        return [$c, implode("\n", $o)];
    })();
    try {
        eq(200, (new Client())->request('GET', '/login')['status']);
        [$c, $o] = $cli('on');
        eq(0, $c, $o);
        ok(is_file($flag), 'flag ada: ' . $flag . ' / ' . $o);
        contains('AKTIF', $cli('status')[1]);

        $page = (new Client())->request('GET', '/login');
        eq(503, $page['status']);
        contains('pemeliharaan', $page['body']);
        eq('120', $page['headers']['retry-after'][0] ?? null);

        $api = $admin->api('GET', '/me');
        eq(503, $api['status']);
        eq('maintenance', $api['json']['error']['code'] ?? null);
        eq(503, $admin->request('GET', '/')['status'], 'sesi yang sudah login pun ditahan');
        $post = (new Client())->request('POST', '/api/v1/auth/login', ['username' => 'e_staff', 'password' => 'Rahasia-Uji-1']);
        eq(503, $post['status']);

        $h = (new Client())->request('GET', '/api/v1/health');
        eq(200, $h['status'], 'health tetap menjawab untuk monitoring');
    } finally {
        $cli('off');
    }
    ok(!is_file($flag), 'flag masih ada setelah off');
    eq(200, (new Client())->request('GET', '/login')['status']);
    eq(200, $admin->api('GET', '/me')['status'], 'setelah off, sesi lama tetap berlaku');
    contains('Nonaktif', $cli('status')[1]);
    eq(2, $cli('bogus')[0]);
});
