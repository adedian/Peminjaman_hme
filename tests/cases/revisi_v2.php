<?php
declare(strict_types=1);

/**
 * Revisi V2 — R1 (login tanpa paksa ganti password, kompatibilitas format lama) dan R2 (nomor WhatsApp pengguna).
 */

use App\Core\Database;
use App\Core\HttpException;
use App\Services\AuthService;
use App\Services\LoanService;
use App\Services\UserService;

// ---------------------------------------------------------------------------
section('V2-R1 — login tanpa paksa ganti password');

t('Akun baru (admin buat, dengan atau tanpa password awal) TIDAK ditandai wajib ganti password', function () {
    $adminId = aUser('v2_admin', 'Admin-Uji-123', 'admin');
    $actor = ['id' => $adminId, 'role' => 'admin', 'username' => 'v2_admin'];
    $r = UserService::create(['username' => 'v2.staff1', 'name' => 'Staff Satu', 'role' => 'staff', 'password' => 'Awal-Uji-12345'], $actor, ureq());
    eq(false, $r['user']['must_change_password']);
    $login = AuthService::attempt('v2.staff1', 'Awal-Uji-12345', 'staff', areq('10.20.0.1'));
    eq(false, $login['must_change_password'], 'login pertama tidak dipaksa ganti');

    $r2 = UserService::create(['username' => 'v2.staff2', 'name' => 'Staff Dua', 'role' => 'staff'], $actor, ureq());
    eq(false, $r2['user']['must_change_password']);
    eq(false, AuthService::attempt('v2.staff2', (string) $r2['temporary_password'], 'staff', areq('10.20.0.2'))['must_change_password']);
});

t('Reset password oleh admin tidak memaksa ganti password, password lama berhenti berlaku', function () {
    $adminId = aUser('v2_admin', 'Admin-Uji-123', 'admin');
    $actor = ['id' => $adminId, 'role' => 'admin', 'username' => 'v2_admin'];
    $id = aUser('v2.reset', 'Lama-Uji-12345', 'staff');
    $r = UserService::resetPassword($id, $actor, ureq());
    eq(false, $r['user']['must_change_password']);
    eq(false, AuthService::attempt('v2.reset', (string) $r['temporary_password'], 'staff', areq('10.20.0.3'))['must_change_password']);
    loginFails('v2.reset', 'Lama-Uji-12345', 'staff', '10.20.0.3');
});

t('Password format lama (teks biasa) tetap bisa login lalu di-hash transparan tanpa meminta ganti', function () {
    $id = aUser('v2.legacy', 'dummy-awal-1', 'staff');
    Database::run('UPDATE users SET password_hash = ? WHERE id = ?', ['sandi-lama-123', $id]);   // simulasi nilai asli tanpa hash
    clearAttempts();
    loginFails('v2.legacy', 'salah-sekali', 'staff', '10.20.0.4');
    $u = AuthService::attempt('v2.legacy', 'sandi-lama-123', 'staff', areq('10.20.0.4'));
    eq(false, $u['must_change_password']);
    $row = Database::one('SELECT password_hash, auth_version FROM users WHERE id = ?', [$id]);
    ok($row['password_hash'] !== 'sandi-lama-123' && password_verify('sandi-lama-123', (string) $row['password_hash']), 'sudah di-hash');
    // login berikutnya lewat jalur hash normal, password tetap sama (tidak diubah pengguna)
    eq($id, AuthService::attempt('v2.legacy', 'sandi-lama-123', 'staff', areq('10.20.0.4'))['id']);
});

t('Password format lama: perbedaan huruf besar/kecil dan string kosong ditolak', function () {
    $id = aUser('v2.legacy2', 'dummy-awal-1', 'staff');
    Database::run('UPDATE users SET password_hash = ? WHERE id = ?', ['SandiLama', $id]);
    clearAttempts();
    loginFails('v2.legacy2', 'sandilama', 'staff', '10.20.0.5');
    loginFails('v2.legacy2', '', 'staff', '10.20.0.5');
    eq($id, AuthService::attempt('v2.legacy2', 'SandiLama', 'staff', areq('10.20.0.5'))['id']);
});

t('Migrasi 0011: semua akun yang tadinya wajib ganti password sudah dilepas; default kolom 0', function () {
    eq(0, (int) Database::value('SELECT COUNT(*) FROM users WHERE must_change_password = 1 AND username LIKE \'v2.%\''));
    $def = Database::value("SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'must_change_password'");
    eq('0', (string) $def);
});

// ---------------------------------------------------------------------------
section('V2-R3 — batas pengembalian & status Terlambat');

t('Batas pengembalian wajib: kosong/format salah/lampau/sebelum pinjam/lebih dari batas maksimum → 422 di due_at', function () {
    $u = lStaff('l_staff1'); lReset($u);
    $a = lItem();
    $before = (int) Database::value('SELECT COUNT(*) FROM loans');
    $f = fn($due) => lErr(fn() => LoanService::submit($u, ['item_ids' => [$a], 'location' => 'Lok', 'due_at' => $due], ureq()))->fields();
    foreach ([null, '', '   ', 'besok', '2026-13-45T10:00', '2026-02-30T10:00', date('Y-m-d\TH:i', time() - 3600), date('Y-m-d\TH:i'),
              date('Y-m-d\TH:i', time() + 31 * 86400 + 3600), ['x'], 12345, "2030-01-01T10:00\x00"] as $bad) {
        ok(isset($f($bad)['due_at']), 'seharusnya ditolak: ' . json_encode($bad));
    }
    // sebelum waktu pinjam yang dijadwalkan (tanggal pinjam di masa depan)
    $e = lErr(fn() => LoanService::submit($u, ['item_ids' => [$a], 'location' => 'Lok', 'borrow_date' => date('Y-m-d', time() + 5 * 86400), 'due_at' => date('Y-m-d\TH:i', time() + 86400)], ureq()));
    ok(isset($e->fields()['due_at']), 'due sebelum tanggal pinjam');
    eq($before, (int) Database::value('SELECT COUNT(*) FROM loans'));
});

t('Batas pengembalian sah (T atau spasi, detik diabaikan) tersimpan per barang dan tampil di pengajuan', function () {
    $u = lStaff('l_staff1'); lReset($u);
    $due = date('Y-m-d H:i', time() + 3 * 86400);
    $r = LoanService::submit($u, ['item_ids' => [lItem('A1'), lItem('A2')], 'location' => 'Lok', 'due_at' => str_replace(' ', 'T', $due) . ':45'], ureq());
    eq($due . ':00', $r['due_at']);
    eq([$due . ':00', $due . ':00'], array_column($r['loans'], 'due_at'));
    ok($r['loans'][0]['remaining_seconds'] > 3 * 86400 - 120 && $r['loans'][0]['remaining_seconds'] <= 3 * 86400, 'sisa waktu dihitung server');
    eq(false, $r['overdue']);
    eq(2, (int) Database::value('SELECT COUNT(*) FROM loans WHERE batch_id = ? AND due_at IS NOT NULL', [$r['id']]));
});

t('Terlambat otomatis (turunan): approved + lewat batas; returned/pending/tanpa batas tidak pernah terlambat', function () {
    $u = lStaff('l_staff1'); $adm = lAdmin(); lReset($u);
    $r = lSubmit($u, [lItem('T1')]);
    $base = LoanService::summary($adm)['overdue'];
    Database::run('UPDATE loans SET due_at = ? WHERE batch_id = ?', [date('Y-m-d H:i:00', time() - 600), $r['id']]);
    eq(false, LoanService::findRequest($r['id'], $adm)['overdue'], 'pending tidak dihitung terlambat');
    LoanService::approveRequest($r['id'], $adm, ureq());
    $x = LoanService::findRequest($r['id'], $adm);
    eq(true, $x['overdue']);
    eq(true, $x['loans'][0]['overdue']);
    ok($x['remaining_seconds'] < 0 && $x['remaining_seconds'] >= -700, 'sisa waktu negatif = terlambat');
    eq($base + 1, LoanService::summary($adm)['overdue']);
    eq(1, LoanService::summary($u)['overdue']);
    $ids = array_column(LoanService::listRequests($adm, ['status' => 'overdue'])['requests'], 'id');
    ok(in_array($r['id'], $ids, true), 'filter overdue');
    ok(!in_array($r['id'], array_column(LoanService::listRequests($adm, ['status' => 'returned'])['requests'], 'id'), true));
    // staff lain tidak melihat milik orang lain
    $other = lStaff('l_staff2');
    eq(0, LoanService::summary($other)['overdue']);
    // dikembalikan → tidak terlambat lagi, riwayat tersimpan
    LoanService::returnRequest($r['id'], $u, ureq());
    $y = LoanService::findRequest($r['id'], $adm);
    eq(false, $y['overdue']);
    eq('returned', $y['status']);
    ok($y['loans'][0]['returned_at'] !== null);
    eq($base, LoanService::summary($adm)['overdue']);
    // pinjaman lama tanpa batas (NULL) tidak pernah terlambat
    $r2 = lSubmit($u, [lItem('T2')]);
    LoanService::approveRequest($r2['id'], $adm, ureq());
    Database::run('UPDATE loans SET due_at = NULL WHERE batch_id = ?', [$r2['id']]);
    $z = LoanService::findRequest($r2['id'], $adm);
    eq(false, $z['overdue']);
    eq(null, $z['remaining_seconds']);
    LoanService::returnRequest($r2['id'], $u, ureq());
});
