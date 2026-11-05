<?php
declare(strict_types=1);

/**
 * Revisi V2 — R7 E2E: otorisasi /api/v1/dashboard, halaman beranda, dan realtime dua akun (perubahan data oleh satu
 * akun muncul di sinyal /sync akun lain dalam hitungan detik, sehingga dashboard dimuat ulang tanpa refresh).
 */

use App\Core\Database;

section('V2 E2E — dashboard & realtime');

t('GET /dashboard: anonim 401; staff & admin 200 dengan bentuk data berbeda sesuai peran; tanpa cache', function () {
    e2eReset();
    $anon = new Client(); $staff = e2eLogin('e_staff'); $admin = e2eAdmin();
    eq(401, $anon->api('GET', '/dashboard')['status']);
    $a = $admin->api('GET', '/dashboard');
    $s = $staff->api('GET', '/dashboard');
    eq([200, 200], [$a['status'], $s['status']]);
    eq(['admin', 'staff'], [$a['json']['data']['role'], $s['json']['data']['role']]);
    eq('no-store', $a['headers']['cache-control'][0] ?? '');
    foreach (['kpi', 'composition', 'trend', 'top_items', 'deadlines', 'pqa_active', 'activity'] as $key) {
        ok(array_key_exists($key, $a['json']['data']), $key);
    }
    ok(in_array($anon->api('POST', '/dashboard', [])['status'], [401, 403, 404, 405], true), 'tulis ke /dashboard ditolak');
});

t('Halaman beranda memuat kontainer dashboard + skrip eksternal (tanpa inline), admin & staff', function () {
    e2eReset();
    foreach ([e2eAdmin(), e2eLogin('e_staff')] as $c) {
        $r = $c->get('/');
        eq(200, $r['status']);
        contains('id="dash"', $r['body']);
        contains('dashboard.js', $r['body']);
        contains('dashboard.css', $r['body']);
        lacks('onclick=', $r['body']);
        lacks('<script>', $r['body']);
    }
});

t('Realtime dua akun: staff mengajukan peminjaman → sinyal /sync admin memuat perubahan "request" dan angka dashboard admin berubah', function () {
    eLoanReset();
    $staff = e2eLogin('e_staff'); $admin = e2eAdmin();
    $cursor = (int) $admin->api('GET', '/sync?since=0')['json']['data']['cursor'];
    $before = $admin->api('GET', '/dashboard')['json']['data']['kpi']['loans_pending'];
    $item = iMake(['name' => 'Alat Realtime V2'])['id'];
    $t0 = microtime(true);
    eq(201, $staff->api('POST', '/requests', ['item_ids' => [$item], 'location' => 'Realtime', 'due_at' => dueAt(2)])['status']);
    $sync = $admin->api('GET', '/sync?since=' . $cursor);
    $lat = microtime(true) - $t0;
    eq(200, $sync['status']);
    ok(isset($sync['json']['data']['changes']['request']), 'sinyal request tiba: ' . json_encode($sync['json']['data']['changes']));
    ok(isset($sync['json']['data']['changes']['item']), 'sinyal item (ketersediaan) tiba');
    ok($lat < 2.0, 'latensi ' . round($lat, 2) . ' dtk');
    eq($before + 1, $admin->api('GET', '/dashboard')['json']['data']['kpi']['loans_pending']);
    // staff lain tidak melihat permohonan orang lain di dashboard-nya
    $other = e2eLogin('e_staff2');
    eq(0, $other->api('GET', '/dashboard')['json']['data']['kpi']['loans_pending']);
});
