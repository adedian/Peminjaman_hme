<?php
declare(strict_types=1);

use App\Core\Database;

// ---------------------------------------------------------------------------
section('E2E Rekap — otorisasi');

t('Anonim: API rekap & ekspor 401; halaman /recap → redirect /login', function () {
    e2eReset();
    $c = new Client();
    foreach (['/reports/summary?month=4&year=2031', '/reports/export?month=4&year=2031'] as $p) {
        eq(401, $c->api('GET', $p)['status'], $p);
    }
    $r = $c->get('/recap');
    eq([302, '/login'], [$r['status'], loc($r)]);
});

t('Staff → rekap, ekspor, halaman /recap semua 403 (tanpa berkas terunduh); akun wajib-ganti-password terkunci', function () {
    e2eReset();
    $staff = e2eLogin('e_staff');
    foreach (['/reports/summary?month=4&year=2031', '/reports/export?month=4&year=2031'] as $p) {
        $r = $staff->api('GET', $p);
        eq([403, 'forbidden'], [$r['status'], $r['json']['error']['code'] ?? null], $p);
        ok(strpos($r['headers']['content-type'][0] ?? '', 'spreadsheetml') === false, 'bukan berkas Excel');
    }
    eq(403, $staff->get('/recap')['status']);
    lacks('href="/recap"', $staff->get('/')['body'], 'menu Rekap tidak tampil untuk staff');

    aUser('e_forced_admin', 'Admin-Uji-123', 'admin', 1, 1);
    $f = e2eLogin('e_forced_admin', 'admin', 'Admin-Uji-123');
    foreach (['/reports/summary', '/reports/export'] as $p) {
        eq([403, 'password_change_required'], [$f->api('GET', $p)['status'], $f->api('GET', $p)['json']['error']['code'] ?? null], $p);
    }
    Database::run("UPDATE users SET is_active = 0 WHERE username = 'e_forced_admin'");
});

// ---------------------------------------------------------------------------
section('E2E Rekap — API & unduhan');

t('Admin: rekap JSON sesuai service; filter tidak valid 422 per field; parameter array 422 (bukan 500)', function () {
    e2eReset();
    rData();
    $a = e2eAdmin();
    $r = $a->api('GET', '/reports/summary?month=4&year=2031');
    eq(200, $r['status'], $r['body']);
    $d = $r['json']['data'];
    eq(\App\Services\ReportService::summary(\App\Services\ReportService::period('4', '2031')), $d, 'JSON = hasil service');
    ok($d['total'] >= 6 && $d['period']['label'] === 'April 2031');
    eq('Drone DJI', $d['top_items'][0]['name']);
    eq(['Kunci'], array_column($d['active'], 'item_name'));
    eq(\App\Services\ReportService::summary(\App\Services\ReportService::period('all', '2031-2032'))['total'],
       $a->api('GET', '/reports/summary?month=all&year=2031%20-%202032')['json']['data']['total'], 'rentang dengan spasi (URL-encoded)');
    ok($a->api('GET', '/reports/summary')['json']['data']['total'] >= 10, 'tanpa parameter = semua data');

    $bad = $a->api('GET', '/reports/summary?month=13&year=2026-2025');
    eq([422, 'validation_failed'], [$bad['status'], $bad['json']['error']['code']]);
    ok(isset($bad['json']['error']['fields']['month'], $bad['json']['error']['fields']['year']));
    foreach (['/reports/summary?month[]=4&year=2031', '/reports/summary?month=4&year[]=2031', "/reports/summary?month=4&year=2031'%20OR%201=1--"] as $p) {
        eq(422, $a->api('GET', $p)['status'], $p);
    }
    eq('no-store', $r['headers']['cache-control'][0] ?? '');
});

t('Admin: unduh XLSX (tipe, nama berkas, ZIP valid, no-store, header keamanan); filter tidak valid → 422 JSON tanpa berkas', function () {
    e2eReset();
    rData();
    $a = e2eAdmin();
    $r = $a->get('/api/v1/reports/export?month=4&year=2031');
    eq(200, $r['status'], substr($r['body'], 0, 300));
    eq('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $r['headers']['content-type'][0] ?? '');
    eq('attachment; filename="Laporan_Stok_HME_4_2031.xlsx"', $r['headers']['content-disposition'][0] ?? '');
    eq("PK\x03\x04", substr($r['body'], 0, 4));
    eq((string) strlen($r['body']), $r['headers']['content-length'][0] ?? '');
    eq('no-store', $r['headers']['cache-control'][0] ?? '');
    eq('nosniff', $r['headers']['x-content-type-options'][0] ?? '');

    $tmp = tempnam(sys_get_temp_dir(), 'hmexe') . '.xlsx';
    file_put_contents($tmp, $r['body']);
    try {
        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp);
        eq(['Laporan Stok', 'Transaksi Periode'], $book->getSheetNames());
        ok($book->getSheet(1)->getHighestDataRow() >= 7, 'transaksi April 2031 ada di sheet 2');
        $book->disconnectWorksheets();
    } finally {
        @unlink($tmp);
    }

    $all = $a->get('/api/v1/reports/export?month=all&year=semua');
    eq('attachment; filename="Laporan_Stok_HME_all_semua.xlsx"', $all['headers']['content-disposition'][0] ?? '');

    $bad = $a->get('/api/v1/reports/export?month=4&year=abc');
    eq(422, $bad['status']);
    ok(isset($bad['json']['error']['fields']['year']));
    lacks('spreadsheetml', $bad['headers']['content-type'][0] ?? '');
});

// ---------------------------------------------------------------------------
section('E2E Rekap — halaman & navigasi');

t('Halaman /recap (admin): form filter, skrip eksternal tanpa inline, menu Rekap di sidebar & Lainnya, tab Lainnya aktif', function () {
    e2eReset();
    $a = e2eAdmin();
    $r = $a->get('/recap');
    eq(200, $r['status']);
    foreach (['id="recap-form"', 'id="r-mode"', 'id="r-month"', 'id="r-year"', 'id="r-export"', 'id="recap-result"', 'recap.js', 'value="' . date('Y') . '"'] as $n) {
        contains($n, $r['body'], $n);
    }
    ok((bool) preg_match('#<option value="' . (int) date('n') . '" selected>#', $r['body']), 'bulan berjalan terpilih');
    ok(!preg_match('/<script(?![^>]*\bsrc=)/i', $r['body']), 'tanpa skrip inline');
    ok((bool) preg_match('#sidebar.*?href="/recap"[^>]*aria-current="page"#s', $r['body']), 'menu Rekap aktif di sidebar');
    ok((bool) preg_match('#bottom-nav.*?aria-current="page"[^>]*>.{0,300}Lainnya#s', $r['body']), 'tab Lainnya aktif di ponsel');
    contains('href="/recap"', $a->get('/more')['body']);
    $js = $a->get('/assets/js/recap.js');
    eq(200, $js['status']);
    lacks('innerHTML', $js['body'], 'render tanpa innerHTML');
});
