<?php
declare(strict_types=1);

use App\Core\Database;
use App\Services\IntegrityService;
use App\Services\LoanService;
use App\Services\PqaService;

// ---------------------------------------------------------------------------
section('Tahap 7 — integritas data');

t('Database bersih + alur nyata (pinjam, kembali, batal, PQA) → konsisten; setiap jenis kerusakan terdeteksi; CLI exit 0/1', function () {
    mgWithDatabase('hme_peminjaman_test_int', function () {
        $adm = ['id' => aUser('int_admin', 'Admin-Uji-123', 'admin'), 'role' => 'admin', 'name' => 'Int Admin'];
        $s = ['id' => aUser('int_staff'), 'role' => 'staff', 'name' => 'Int Staff', 'username' => 'int_staff'];
        $s2 = ['id' => aUser('int_staff2'), 'role' => 'staff', 'name' => 'Int Staff 2', 'username' => 'int_staff2'];
        [$a, $b, $c] = [iMake(['name' => 'Int A'])['id'], iMake(['name' => 'Int B'])['id'], iMake(['name' => 'Int C'])['id']];
        $u = iMake(['name' => 'Int PQA', 'is_pqa' => '1'])['id'];
        $r1 = LoanService::submit($s, ['item_ids' => [$a, $b], 'due_at' => dueAt(), 'location' => 'Lokasi Uji'], ureq());
        LoanService::approveRequest($r1['id'], $adm, ureq());
        LoanService::returnLoan((int) $r1['loans'][0]['id'], $s, ureq());
        $r2 = LoanService::submit($s2, ['item_ids' => [$c], 'due_at' => dueAt(), 'location' => 'Lokasi Dua'], ureq());
        LoanService::cancelRequest($r2['id'], $s2, ureq());
        $p = PqaService::submit($s, ['item_id' => $u, 'ppic' => 'Int', 'location' => 'PT Integritas', 'planned_install_at' => dueAt(1), 'install_team' => 'Tim', 'target_days' => 5], ureq());
        PqaService::decideInstall($p['id'], true, $adm, ureq());

        $r = IntegrityService::check();
        eq([], array_values(array_filter($r['checks'], fn($x) => $x['violations'] > 0)));
        ok($r['ok'] && count($r['checks']) >= 13);
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(HME_ROOT . '/tools/check-integrity.php') . ' 2>&1', $okOut, $okCode);
        eq(0, $okCode, implode("\n", $okOut));

        $loaned = (int) $r1['loans'][1]['item']['id'];
        $code = (string) Database::value('SELECT code FROM items WHERE id = ?', [$loaned]);
        $corrupt = [
            ["UPDATE items SET master_status = 'Tersedia' WHERE id = {$loaned}", 'Status barang', $code],
            ["UPDATE loans SET returned_at = NULL WHERE status = 'returned'", 'Pinjaman returned', null],
            ["UPDATE loans SET cancelled_at = NULL WHERE status = 'cancelled'", 'dibatalkan', null],
            ["UPDATE settings SET value = '0' WHERE key_name = 'loan_seq'", 'nomor urut', null],
            ["UPDATE pqa_installations SET installed_at = NULL", 'PQA Terpasang', null],
            ["UPDATE items SET is_active = 0 WHERE id = {$loaned}", 'nonaktif', null],
            ["UPDATE users SET is_active = 0 WHERE role = 'admin'", 'admin aktif', null],
            ["UPDATE items SET photo_path = '" . str_repeat('a', 32) . ".jpg' WHERE id = {$a}", 'Foto', null],
        ];
        foreach ($corrupt as [$sql, $needle, $sample]) {
            Database::run($sql);
            $hit = array_values(array_filter(IntegrityService::check()['checks'], fn($x) => $x['violations'] > 0 && strpos($x['rule'], $needle) !== false));
            ok($hit !== [], "terdeteksi: {$needle}");
            if ($sample !== null) {
                ok(in_array($sample, $hit[0]['samples'], true));
            }
        }
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(HME_ROOT . '/tools/check-integrity.php') . ' 2>&1', $out, $exit);
        eq(1, $exit);
        contains('GAGAL', implode("\n", $out));
    });
});
