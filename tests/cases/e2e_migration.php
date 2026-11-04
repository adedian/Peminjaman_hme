<?php
declare(strict_types=1);

use App\Core\Database;

// ---------------------------------------------------------------------------
section('E2E Arsip & laporan migrasi');

t('Anonim 401/redirect; staff 403 (API, unduhan, halaman); admin: daftar, rincian, XLSX, halaman tanpa skrip inline', function () {
    eLoanReset();
    $run = (int) Database::value("SELECT id FROM migration_runs ORDER BY id DESC LIMIT 1");
    ok($run > 0, 'ada run migrasi dari tes sebelumnya');
    $paths = ['/archive/manual', '/archive/register', '/migration/runs', "/migration/runs/{$run}/issues", "/migration/runs/{$run}/export"];
    $anon = new Client();
    foreach ($paths as $p) {
        eq(401, $anon->api('GET', $p)['status'], $p);
    }
    foreach (['/archive', '/migration'] as $p) {
        eq([302, '/login'], [$anon->get($p)['status'], loc($anon->get($p))], $p);
    }
    $staff = e2eLogin('e_staff');
    foreach ($paths as $p) {
        $r = $staff->api('GET', $p);
        eq(403, $r['status'], $p);
        lacks('spreadsheetml', $r['headers']['content-type'][0] ?? '', $p);
    }
    eq(403, $staff->get('/archive')['status']);
    eq(403, $staff->get('/migration')['status']);

    $a = e2eAdmin();
    $m = $a->api('GET', '/archive/manual?filter=swapped_dm&limit=5');
    eq(200, $m['status'], $m['body']);
    foreach ($m['json']['data']['items'] as $i) { eq('swapped_dm', $i['parse_flag']); }
    eq(422, $a->api('GET', '/archive/manual?filter=apa')['status']);
    eq(422, $a->api('GET', '/archive/register?q[]=x')['status']);
    ok(count($a->api('GET', '/migration/runs')['json']['data']) >= 1);
    eq(404, $a->api('GET', '/migration/runs/99999999/issues')['status']);
    $x = $a->get("/api/v1/migration/runs/{$run}/export");
    eq([200, "PK\x03\x04"], [$x['status'], substr($x['body'], 0, 4)]);
    contains('attachment; filename="Laporan_Migrasi_HME_run' . $run . '.xlsx"', $x['headers']['content-disposition'][0] ?? '');

    foreach ([['/archive', 'archive.js', 'Catatan manual'], ['/archive?tab=register', 'archive.js', 'data-tab="register"'], ['/migration', 'migration.js', 'id="mg-runs"']] as [$p, $js, $mark]) {
        $r = $a->get($p);
        eq(200, $r['status'], $p);
        contains($js, $r['body'], $p); contains($mark, $r['body'], $p);
        ok(!preg_match('/<script(?![^>]*\bsrc=)/i', $r['body']), "{$p} tanpa skrip inline");
    }
    contains('href="/archive"', $a->get('/more')['body']);
    eq(200, $a->get('/items?new=1&name=' . rawurlencode('Gerinda <b>X</b>'))['status'], 'prefill dari register');
    foreach (['archive.js', 'migration.js'] as $js) {
        lacks('innerHTML', $a->get('/assets/js/' . $js)['body'], $js);
    }
});
