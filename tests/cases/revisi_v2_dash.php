<?php
declare(strict_types=1);

/**
 * Revisi V2 — R7: dashboard (angka dari database, pembatasan per peran) dan E2E (otorisasi, halaman, realtime dua akun).
 */

use App\Core\Database;
use App\Services\DashboardService;
use App\Services\LoanService;
use App\Services\PqaService;

section('V2-R7 — dashboard (service)');

t('Angka KPI = hitungan langsung dari database (admin melihat semua, staff hanya miliknya)', function () {
    [$s, $adm] = scReset();
    $s2 = scUser('dash_s2');
    $s3 = scUser('dash_s3');
    scLoan($s, $adm, 'NOW() + INTERVAL 3 DAY');                  // aktif, belum jatuh tempo
    scLoan($s2, $adm, 'NOW() - INTERVAL 2 HOUR');                // terlambat
    $pend = lSubmit($s3, [lItem('Menunggu')]);                   // pending
    $p = qInstalled($s, $adm, qUnit('PQA Dash'), ['target_days' => 7]);
    qAgo($p['id'], '8 DAY');                                     // PQA melewati jadwal

    $a = DashboardService::data($adm);
    $k = $a['kpi'];
    eq('admin', $a['role']);
    eq((int) Database::value("SELECT COUNT(*) FROM loans WHERE status = 'approved'"), $k['loans_active']);
    eq((int) Database::value("SELECT COUNT(*) FROM loans WHERE status = 'approved' AND due_at < NOW()"), $k['loans_overdue']);
    eq((int) Database::value("SELECT COUNT(DISTINCT batch_id) FROM loans WHERE status = 'pending'"), $k['loans_pending']);
    ok($k['loans_overdue'] >= 1 && $k['loans_pending'] >= 1 && $k['pqa_overdue'] >= 1 && $k['pqa_active'] >= 1);
    eq((int) Database::value("SELECT COUNT(*) FROM items WHERE is_active = 1"), $k['items_total']);
    eq((int) Database::value("SELECT COUNT(*) FROM items WHERE is_active = 1 AND master_status = 'Tersedia'"), $k['items_available']);
    eq($k['items_available'], $a['composition'][0]['value']);
    ok(count($a['trend']['days']) === 14 && $a['trend']['total_submitted'] >= 3);

    // staff: hanya pinjamannya sendiri
    $b = DashboardService::data($s2);
    eq('staff', $b['role']);
    eq(1, $b['kpi']['loans_active']);
    eq(1, $b['kpi']['loans_overdue']);
    eq(0, $b['kpi']['loans_pending'], 's2 tidak punya permohonan menunggu');
    eq(1, DashboardService::data($s3)['kpi']['loans_pending']);
    eq(0, DashboardService::data($s3)['kpi']['loans_active']);
    // inventaris & PQA bersifat bersama
    eq($k['pqa_active'], $b['kpi']['pqa_active']);
    eq($k['items_total'], $b['kpi']['items_total']);
    LoanService::cancelRequest($pend['id'], $s3, ureq());
});

t('Privasi staff: tidak ada nama peminjam lain, tidak ada aktivitas orang lain, daftar jatuh tempo hanya miliknya', function () {
    [$s, $adm] = scReset();
    $s2 = scUser('dash_p2');
    scLoan($s, $adm, 'NOW() + INTERVAL 1 DAY');
    scLoan($s2, $adm, 'NOW() + INTERVAL 2 DAY');
    $adminView = DashboardService::data($adm);
    ok(count($adminView['deadlines']) >= 2 && $adminView['deadlines'][0]['borrower'] !== null);
    $v = DashboardService::data($s);
    eq(1, count($v['deadlines']));
    eq(null, $v['deadlines'][0]['borrower']);
    $json = json_encode($v);
    lacks('Dash_p2', (string) $json, 'nama staff lain tidak bocor');
    $owners = Database::all("SELECT DISTINCT a.user_id FROM activity_logs a WHERE a.action LIKE 'loan.%' OR a.action LIKE 'request.%' OR a.action LIKE 'pqa.%' ORDER BY a.id DESC LIMIT 50");
    ok(count($owners) >= 2, 'ada aktivitas dari lebih dari satu orang di log');
    $mine = array_unique(array_column($v['activity'], 'actor'));
    ok(count($mine) <= 1, 'staff hanya melihat aktivitas sendiri: ' . json_encode($mine));
});

t('Barang paling sering dipinjam: hanya nama barang & jumlah (tanpa identitas peminjam)', function () {
    [$s, $adm] = scReset();
    scLoan($s, $adm, 'NOW() + INTERVAL 1 DAY');
    $top = DashboardService::data($s)['top_items'];
    ok($top !== []);
    eq(['name', 'count'], array_keys($top[0]));
});

t('Dashboard menyusun PQA aktif dengan sisa waktu & progres', function () {
    [$s, $adm] = scReset();
    $p = qInstalled($s, $adm, qUnit('PQA Dash2'), ['target_days' => 7]);
    qAgo($p['id'], '3 DAY');
    $row = null;
    foreach (DashboardService::data($s)['pqa_active'] as $x) {
        if ($x['id'] === $p['id']) { $row = $x; }
    }
    ok($row !== null);
    eq([3, 7, false], [$row['elapsed_days'], $row['target_days'], $row['overdue']]);
    ok($row['remaining_seconds'] > 3 * 86400 && $row['remaining_seconds'] <= 4 * 86400);
    PqaService::requestRemoval($p['id'], 'Tim', $s, ureq());
    PqaService::decideRemoval($p['id'], true, $adm, ureq());
    foreach (DashboardService::data($s)['pqa_active'] as $x) {
        ok($x['id'] !== $p['id'], 'unit yang sudah dicabut hilang dari dashboard');
    }
});

t('Pembersihan dashboard', function () {
    Database::run("UPDATE loans SET status = 'returned', returned_at = NOW() WHERE status IN ('approved','pending') AND borrowed_at BETWEEN (NOW() - INTERVAL 3 DAY) AND (NOW() + INTERVAL 1 DAY)");
    Database::run("UPDATE pqa_installations SET status = 'Dicabut', removed_at = NOW(), total_days = 0 WHERE status IN ('Terpasang','Req Cabut','Pending') AND COALESCE(installed_at, submitted_at) >= (NOW() - INTERVAL 40 DAY)");
    Database::run("UPDATE items i SET i.master_status = IF(EXISTS (SELECT 1 FROM loans l WHERE l.item_id = i.id AND l.status = 'approved') OR EXISTS (SELECT 1 FROM pqa_installations p WHERE p.item_id = i.id AND p.status IN ('Pending','Terpasang','Req Cabut')), 'Dipinjam', 'Tersedia')");
    pushReset();
    eq(0, (int) Database::value('SELECT COUNT(*) FROM push_outbox'));
});
