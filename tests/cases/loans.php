<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\HttpException;
use App\Services\AvailabilityService;
use App\Services\LoanService;

// ---------------------------------------------------------------- pembantu --------------------

/** @return array{id:int,name:string,role:string,username:string} */
function lStaff(string $username): array
{
    $id = aUser($username, 'Rahasia-Uji-1');
    return ['id' => $id, 'name' => ucfirst($username), 'role' => 'staff', 'username' => $username];
}

/** @return array{id:int,name:string,role:string} */
function lAdmin(): array
{
    return ['id' => aUser('l_admin', 'Admin-Uji-123', 'admin'), 'role' => 'admin', 'name' => 'L_admin'];
}

/** @param array<string,mixed> $o */
function lSubmit(array $user, array $itemIds, array $o = []): array
{
    return LoanService::submit($user, $o + ['item_ids' => $itemIds, 'location' => 'Proyek Uji'], ureq());
}

/** Hitungan per status: buang yang nol, urutkan kunci (agar perbandingan tidak bergantung urutan). @param array<string,int> $c */
function lc(array $c): array
{
    $c = array_filter($c);
    ksort($c);
    return $c;
}

function lErr(callable $fn): HttpException
{
    /** @var HttpException $e */
    $e = throws($fn, HttpException::class);
    return $e;
}

function lItem(string $name = 'Alat Uji'): int
{
    return iMake(['name' => $name])['id'];
}

/** Hapus data peminjaman milik user uji agar BR-02 tidak terpengaruh tes lain. */
function lReset(array ...$users): void
{
    foreach ($users as $u) {
        Database::run("UPDATE loans SET status = 'returned', returned_at = NOW() WHERE user_id = ? AND status IN ('pending','approved')", [$u['id']]);
    }
}

function lStatus(int $loanId): string
{
    return (string) Database::value('SELECT status FROM loans WHERE id = ?', [$loanId]);
}

function lMaster(int $itemId): string
{
    return (string) Database::value('SELECT master_status FROM items WHERE id = ?', [$itemId]);
}

/** @return array<int,int> */
function lAvailIds(): array
{
    return array_column(LoanService::availableItems(), 'id');
}

// ---------------------------------------------------------------------------
section('Peminjaman — pengajuan (BR-02..BR-06)');

t('Ajukan 3 barang: 1 pengajuan, 3 loan pending, nomor urut berurutan, kode HME-<ms>, snapshot nama, barang masih Tersedia', function () {
    $u = lStaff('l_staff1'); lReset($u);
    [$a, $b, $c] = [lItem('Drone Uji'), lItem('Meteran Uji'), lItem('Helm Uji')];
    Database::run('DELETE FROM change_events');
    $r = lSubmit($u, [$a, $b, $c], ['team' => 'Tio, Sandi', 'notes' => 'Survey', 'borrow_date' => date('Y-m-d')]);

    eq('pending', $r['status']);
    eq(3, count($r['loans']));
    ok((bool) preg_match('/^HME-\d{13}$/', $r['code']), $r['code']);
    eq('L_staff1', $r['user']['name']);
    eq(['Tio, Sandi', 'Proyek Uji', 'Survey'], [$r['team'], $r['location'], $r['notes']]);
    $seqs = array_column($r['loans'], 'seq_no');
    eq([$seqs[0], $seqs[0] + 1, $seqs[0] + 2], $seqs, 'nomor urut berurutan');
    foreach ($r['loans'] as $i => $l) {
        eq('pending', $l['status']);
        eq($r['code'] . '-' . $i, $l['code']);
        eq(['Drone Uji', 'Meteran Uji', 'Helm Uji'][$i], $l['item']['name']);
    }
    eq('L_staff1', Database::value('SELECT carrier_name_snapshot FROM loans WHERE id = ?', [$r['loans'][0]['id']]));
    ok(str_starts_with((string) Database::value('SELECT borrowed_at FROM loans WHERE id = ?', [$r['loans'][0]['id']]), date('Y-m-d ')), 'tanggal = hari ini, jam dari server');
    foreach ([$a, $b, $c] as $id) { eq('Tersedia', lMaster($id), 'pending tidak mengubah master_status'); }
    $avail = lAvailIds();
    foreach ([$a, $b, $c] as $id) { ok(!in_array($id, $avail, true), 'barang yang tertahan tidak muncul di daftar tersedia'); }
    ok(Database::value("SELECT COUNT(*) FROM change_events WHERE entity_type = 'request' AND action = 'submitted'") >= 1);
    ok(Database::value("SELECT COUNT(*) FROM activity_logs WHERE action = 'request.submitted' AND entity_id = ?", [(string) $r['id']]) >= 1);
});

t('Validasi pengajuan: tanpa barang, duplikat, terlalu banyak, tipe salah, lokasi, tanggal — tidak ada loan terbentuk', function () {
    $u = lStaff('l_staff1'); lReset($u);
    $a = lItem();
    $before = (int) Database::value('SELECT COUNT(*) FROM loans');
    $f = fn(array $in) => lErr(fn() => LoanService::submit($u, $in, ureq()))->fields();
    $ok = ['item_ids' => [$a], 'location' => 'Lokasi Ok'];
    ok(isset($f(['item_ids' => []] + $ok)['item_ids']), 'kosong');
    ok(isset($f(['item_ids' => 'x'] + $ok)['item_ids']), 'bukan array');
    ok(isset($f(['item_ids' => [$a, $a]] + $ok)['item_ids']), 'duplikat');
    ok(isset($f(['item_ids' => [$a, (string) $a]] + $ok)['item_ids']), 'duplikat beda tipe');
    ok(isset($f(['item_ids' => range(1, LoanService::MAX_ITEMS + 1)] + $ok)['item_ids']), 'terlalu banyak');
    foreach ([[0], [-1], ['abc'], [1.5], [[1]], [null], ['1; DROP TABLE loans'], [99999999999]] as $bad) {
        ok(isset($f(['item_ids' => $bad] + $ok)['item_ids']), 'item_ids ' . json_encode($bad));
    }
    ok(isset($f(['location' => ''] + $ok)['location']), 'lokasi kosong');
    ok(isset($f(['location' => 'x'] + $ok)['location']), 'lokasi 1 huruf');
    ok(isset($f(['location' => ['x']] + $ok)['location']), 'lokasi array');
    ok(isset($f(['location' => str_repeat('l', 256)] + $ok)['location']), 'lokasi panjang');
    ok(isset($f(['team' => str_repeat('t', 256)] + $ok)['team']), 'tim panjang');
    ok(isset($f(['notes' => str_repeat('n', 2001)] + $ok)['notes']), 'catatan panjang');
    foreach (['31/12/2026', '2026-02-30', 'kemarin', '2019-12-31', date('Y-m-d', strtotime('+2 years')), ['2026-01-01']] as $d) {
        ok(isset($f(['borrow_date' => $d] + $ok)['borrow_date']), 'tanggal ' . json_encode($d));
    }
    eq($before, (int) Database::value('SELECT COUNT(*) FROM loans'));
});

t('Tanggal pinjam: kosong → hari ini; tanggal lampau yang wajar boleh; jam tetap dari server', function () {
    $u = lStaff('l_staff1'); lReset($u);
    $r = lSubmit($u, [lItem()], ['borrow_date' => '2026-10-01']);
    ok(str_starts_with($r['borrowed_at'], '2026-10-01 '));
    lReset($u);
    $r = lSubmit($u, [lItem()], ['borrow_date' => '']);
    ok(str_starts_with($r['borrowed_at'], date('Y-m-d ')));
});

t('BR-02: punya loan APPROVED → ajukan baru ditolak (409); pending/returned tidak memblokir', function () {
    $u = lStaff('l_staff1'); $adm = lAdmin(); lReset($u);
    $r1 = lSubmit($u, [lItem()]);
    lSubmit($u, [lItem()]);                                    // pending tidak memblokir
    LoanService::approveRequest($r1['id'], $adm, ureq());
    $e = lErr(fn() => lSubmit($u, [lItem()]));
    eq(409, $e->status()); eq('has_active_loan', $e->errorCode());
    LoanService::returnRequest($r1['id'], $u, ureq());
    ok(lSubmit($u, [lItem()])['id'] > 0, 'setelah dikembalikan boleh lagi');
    lReset($u);
});

t('Hanya staff yang dapat mengajukan (admin → 403 di level service)', function () {
    eq(403, lErr(fn() => lSubmit(lAdmin(), [lItem()]))->status());
});

t('BR-03/BR-29: barang tidak tersedia ditolak — pending orang lain, nonaktif, Dipinjam, PQA aktif; semua-atau-tidak-sama-sekali', function () {
    $u = lStaff('l_staff1'); $v = lStaff('l_staff2'); lReset($u, $v);
    $held = lItem('Tertahan'); lSubmit($v, [$held]);
    $off = lItem('Nonaktif'); \App\Services\ItemService::setActive($off, false, iActor(), ureq());
    $busy = lItem('Dipinjam'); Database::run("UPDATE items SET master_status = 'Dipinjam' WHERE id = ?", [$busy]);
    $pqa = iMake(['is_pqa' => '1', 'name' => 'PQA Terpasang'])['id']; mkPqa($pqa, 'Terpasang');
    $free = lItem('Bebas');

    foreach ([$held => 'Tertahan', $off => 'Nonaktif', $busy => 'Dipinjam', $pqa => 'PQA Terpasang'] as $id => $name) {
        $e = lErr(fn() => lSubmit($u, [$free, $id]));
        eq(409, $e->status(), $name); eq('item_unavailable', $e->errorCode(), $name);
        contains($name, $e->getMessage(), $name);
        eq(0, (int) Database::value("SELECT COUNT(*) FROM loans WHERE user_id = ? AND item_id = ?", [$u['id'], $free]), 'barang bebas TIDAK ikut terajukan');
    }
    eq(422, lErr(fn() => lSubmit($u, [99999999]))->status(), 'barang tidak ada');
});

t('Penghitung nomor urut: tidak pernah ganda, melompati data yang lebih tinggi (mis. hasil impor)', function () {
    $u = lStaff('l_staff1'); lReset($u);
    $max = (int) Database::value('SELECT COALESCE(MAX(seq_no),0) FROM loans');
    $r = lSubmit($u, [lItem()]); lReset($u);
    $s1 = $r['loans'][0]['seq_no'];
    ok($s1 > $max);
    // simulasi impor data lama dengan nomor jauh lebih tinggi
    $it = lItem();
    Database::insert('loans', ['seq_no' => $s1 + 5000, 'batch_id' => (int) Database::value('SELECT id FROM loan_batches LIMIT 1'), 'user_id' => $u['id'], 'item_id' => $it,
        'item_name_snapshot' => 'X', 'carrier_name_snapshot' => 'Y', 'borrowed_at' => '2026-01-01 08:00:00', 'location' => 'L', 'status' => 'returned']);
    $r2 = lSubmit($u, [lItem()]); lReset($u);
    eq($s1 + 5001, $r2['loans'][0]['seq_no']);
    eq((int) $r2['loans'][0]['seq_no'], (int) Database::value("SELECT value FROM settings WHERE key_name = 'loan_seq'"));
});

// ---------------------------------------------------------------------------
section('Peminjaman — keputusan admin (BR-08/09)');

t('Setujui pengajuan: semua barang → approved, master Dipinjam, pencatat keputusan; tidak bisa diproses dua kali', function () {
    $u = lStaff('l_staff1'); $adm = lAdmin(); lReset($u);
    [$a, $b] = [lItem(), lItem()];
    $r = lSubmit($u, [$a, $b]);
    $res = LoanService::approveRequest($r['id'], $adm, ureq());
    eq('approved', $res['status']);
    foreach ([$a, $b] as $id) { eq('Dipinjam', lMaster($id)); }
    foreach ($res['loans'] as $l) {
        eq('approved', $l['status']);
        eq($adm['id'], (int) Database::value('SELECT decided_by FROM loans WHERE id = ?', [$l['id']]));
        ok(Database::value('SELECT decided_at FROM loans WHERE id = ?', [$l['id']]) !== null);
    }
    eq('invalid_state', lErr(fn() => LoanService::approveRequest($r['id'], $adm, ureq()))->errorCode());
    eq('invalid_state', lErr(fn() => LoanService::rejectRequest($r['id'], $adm, ureq()))->errorCode());
    ok(!in_array($a, lAvailIds(), true));
    lReset($u);
});

t('Tolak: status rejected, master TIDAK berubah, barang kembali tersedia untuk orang lain', function () {
    $u = lStaff('l_staff1'); $v = lStaff('l_staff2'); $adm = lAdmin(); lReset($u, $v);
    $a = lItem();
    $r = lSubmit($u, [$a]);
    ok(!in_array($a, lAvailIds(), true));
    $res = LoanService::rejectRequest($r['id'], $adm, ureq());
    eq('rejected', $res['status']);
    eq('Tersedia', lMaster($a));
    ok(in_array($a, lAvailIds(), true), 'tersedia lagi');
    ok(lSubmit($v, [$a])['id'] > 0, 'orang lain dapat mengajukan');
    lReset($v);
});

t('Keputusan per barang: setujui satu, tolak satu; status ringkas & izin aksi mengikuti', function () {
    $u = lStaff('l_staff1'); $adm = lAdmin(); lReset($u);
    [$a, $b] = [lItem(), lItem()];
    $r = lSubmit($u, [$a, $b]);
    [$l1, $l2] = array_column($r['loans'], 'id');
    $res = LoanService::approveLoan($l1, $adm, ureq());
    eq('pending', $res['status'], 'masih ada yang menunggu');
    eq(['approved' => 1, 'pending' => 1], lc($res['counts']));
    ok($res['can']['approve'] && !$res['can']['return'], 'admin: bisa setujui sisa; bukan pemilik: tidak bisa return');
    $res = LoanService::rejectLoan($l2, $adm, ureq());
    eq('approved', $res['status']);
    eq(['approved' => 1, 'rejected' => 1], lc($res['counts']));
    eq(['Dipinjam', 'Tersedia'], [lMaster($a), lMaster($b)]);
    lReset($u);
});

t('Setujui semua-atau-tidak-sama-sekali: bila satu barang tak lagi tersedia, tidak ada yang berubah (409)', function () {
    $u = lStaff('l_staff1'); $adm = lAdmin(); lReset($u);
    [$a, $b] = [lItem('Aman'), lItem('Bermasalah')];
    $r = lSubmit($u, [$a, $b]);
    Database::run("UPDATE items SET is_active = 0 WHERE id = ?", [$b]);          // dinonaktifkan paksa setelah diajukan
    $e = lErr(fn() => LoanService::approveRequest($r['id'], $adm, ureq()));
    eq(409, $e->status()); eq('item_unavailable', $e->errorCode());
    contains('Bermasalah', $e->getMessage());
    foreach ($r['loans'] as $l) { eq('pending', lStatus($l['id'])); }
    eq('Tersedia', lMaster($a), 'master barang lain tidak ikut berubah');
    // sebagian tetap bisa disetujui per barang
    LoanService::approveLoan($r['loans'][0]['id'], $adm, ureq());
    eq('Dipinjam', lMaster($a));
    lReset($u);
});

t('Staff tidak dapat menyetujui/menolak (403 di level service); loan tidak ada → 404', function () {
    $u = lStaff('l_staff1'); lReset($u);
    $r = lSubmit($u, [lItem()]);
    eq(403, lErr(fn() => LoanService::approveRequest($r['id'], $u, ureq()))->status());
    eq(403, lErr(fn() => LoanService::rejectLoan($r['loans'][0]['id'], $u, ureq()))->status());
    eq('pending', lStatus($r['loans'][0]['id']));
    eq(404, lErr(fn() => LoanService::approveLoan(99999999, lAdmin(), ureq()))->status());
    eq(404, lErr(fn() => LoanService::rejectRequest(99999999, lAdmin(), ureq()))->status());
    lReset($u);
});

// ---------------------------------------------------------------------------
section('Peminjaman — pengembalian, pembatalan, ganti unit (BR-07/10, Q5)');

t('Kembalikan: returned, jam server, returned_by, master Tersedia lagi; hanya pemilik; hanya yang approved', function () {
    $u = lStaff('l_staff1'); $v = lStaff('l_staff2'); $adm = lAdmin(); lReset($u, $v);
    [$a, $b] = [lItem(), lItem()];
    $r = lSubmit($u, [$a, $b]);
    eq('invalid_state', lErr(fn() => LoanService::returnRequest($r['id'], $u, ureq()))->errorCode(), 'belum disetujui');
    LoanService::approveRequest($r['id'], $adm, ureq());
    eq(404, lErr(fn() => LoanService::returnRequest($r['id'], $v, ureq()))->status(), 'bukan pemilik → 404');
    eq(404, lErr(fn() => LoanService::returnLoan($r['loans'][0]['id'], $v, ureq()))->status());
    eq(403, lErr(fn() => LoanService::submit($adm, ['item_ids' => [1], 'location' => 'x'], ureq()))->status());

    $res = LoanService::returnLoan($r['loans'][0]['id'], $u, ureq());          // kembalikan satu dulu
    eq(['approved' => 1, 'returned' => 1], lc($res['counts']));
    eq(['Tersedia', 'Dipinjam'], [lMaster($a), lMaster($b)]);
    $res = LoanService::returnRequest($r['id'], $u, ureq());                   // sisanya
    eq('returned', $res['status']);
    eq('Tersedia', lMaster($b));
    $row = Database::one('SELECT returned_at, returned_by FROM loans WHERE id = ?', [$r['loans'][0]['id']]);
    ok($row['returned_at'] !== null && abs(strtotime((string) $row['returned_at']) - time()) < 120);
    eq($u['id'], (int) $row['returned_by']);
    eq('invalid_state', lErr(fn() => LoanService::returnLoan($r['loans'][0]['id'], $u, ureq()))->errorCode(), 'tidak dua kali');
    ok(in_array($a, lAvailIds(), true));
});

t('BR-29 saat kembali: barang yang juga terpakai PQA aktif tetap Dipinjam (status dihitung ulang, bukan dipaksa)', function () {
    $u = lStaff('l_staff1'); $adm = lAdmin(); lReset($u);
    $a = lItem();
    $r = lSubmit($u, [$a]);
    LoanService::approveRequest($r['id'], $adm, ureq());
    mkPqa($a, 'Terpasang');                                                    // kondisi tak lazim, disimulasikan
    LoanService::returnRequest($r['id'], $u, ureq());
    eq('Dipinjam', lMaster($a));
    Database::run("UPDATE pqa_installations SET status = 'Dicabut' WHERE item_id = ?", [$a]);
    eq('Tersedia', AvailabilityService::sync($a));
});

t('Batalkan (Q5): pending → cancelled, barang bebas lagi; yang approved tak bisa dibatalkan; bukan pemilik 404', function () {
    $u = lStaff('l_staff1'); $v = lStaff('l_staff2'); $adm = lAdmin(); lReset($u, $v);
    [$a, $b] = [lItem(), lItem()];
    $r = lSubmit($u, [$a, $b]);
    eq(404, lErr(fn() => LoanService::cancelRequest($r['id'], $v, ureq()))->status());
    eq(404, lErr(fn() => LoanService::cancelLoan($r['loans'][0]['id'], $v, ureq()))->status());
    LoanService::approveLoan($r['loans'][0]['id'], $adm, ureq());
    eq('invalid_state', lErr(fn() => LoanService::cancelLoan($r['loans'][0]['id'], $u, ureq()))->errorCode(), 'approved tak bisa dibatalkan');
    $res = LoanService::cancelRequest($r['id'], $u, ureq());                   // hanya yang pending ikut batal
    eq(['approved' => 1, 'cancelled' => 1], lc($res['counts']));
    ok(in_array($b, lAvailIds(), true), 'barang yang dibatalkan tersedia lagi');
    eq('invalid_state', lErr(fn() => LoanService::cancelRequest($r['id'], $u, ureq()))->errorCode(), 'tak ada lagi yang pending');
    $r2 = lSubmit($v, [$b]);
    ok($r2['id'] > 0, 'orang lain dapat memakai barang itu');
    lReset($u, $v);
});

t('Ganti unit (BR-07): pending saja, unit baru wajib tersedia, lama bebas, snapshot nama ikut, hanya pemilik', function () {
    $u = lStaff('l_staff1'); $v = lStaff('l_staff2'); $adm = lAdmin(); lReset($u, $v);
    [$old, $new, $taken] = [lItem('Lama'), lItem('Baru'), lItem('Diambil')];
    lSubmit($v, [$taken]);
    $r = lSubmit($u, [$old]);
    $loan = $r['loans'][0]['id'];

    eq(404, lErr(fn() => LoanService::swapItem($loan, $new, $v, ureq()))->status(), 'bukan pemilik');
    eq('item_unavailable', lErr(fn() => LoanService::swapItem($loan, $taken, $u, ureq()))->errorCode(), 'unit tujuan sudah tertahan');
    eq('validation_failed', lErr(fn() => LoanService::swapItem($loan, $old, $u, ureq()))->errorCode(), 'unit sama');
    foreach (['abc', 0, -3, [1], null, '9; DROP'] as $bad) {
        eq(422, lErr(fn() => LoanService::swapItem($loan, $bad, $u, ureq()))->status(), json_encode($bad));
    }
    eq(422, lErr(fn() => LoanService::swapItem($loan, 99999999, $u, ureq()))->status(), 'barang tak ada');

    $res = LoanService::swapItem($loan, $new, $u, ureq());
    eq('Baru', $res['loans'][0]['item']['name']);
    eq('Baru', Database::value('SELECT item_name_snapshot FROM loans WHERE id = ?', [$loan]));
    ok(in_array($old, lAvailIds(), true) && !in_array($new, lAvailIds(), true));

    LoanService::approveRequest($r['id'], $adm, ureq());
    eq('invalid_state', lErr(fn() => LoanService::swapItem($loan, $old, $u, ureq()))->errorCode(), 'setelah disetujui tidak bisa ganti');
    lReset($u, $v);
});

// ---------------------------------------------------------------------------
section('Peminjaman — pembacaan & privasi (IDOR)');

t('Staff hanya melihat pengajuan MILIKNYA (daftar, detail); admin melihat semua; filter user_id diabaikan untuk staff', function () {
    $u = lStaff('l_staff1'); $v = lStaff('l_staff2'); $adm = lAdmin(); lReset($u, $v);
    $ru = lSubmit($u, [lItem('Milik U')]);
    $rv = lSubmit($v, [lItem('Milik V')]);

    $mine = LoanService::listRequests($u);
    $ids = array_column($mine['requests'], 'id');
    ok(in_array($ru['id'], $ids, true) && !in_array($rv['id'], $ids, true));
    foreach ($mine['requests'] as $req) { eq($u['id'], $req['user']['id']); }
    $spy = LoanService::listRequests($u, ['user_id' => (string) $v['id']]);
    ok(!in_array($rv['id'], array_column($spy['requests'], 'id'), true), 'staff tidak bisa mengintip lewat user_id');

    eq(null, LoanService::findRequest($rv['id'], $u), 'detail milik orang lain tidak terlihat');
    ok(LoanService::findRequest($ru['id'], $u) !== null);
    $all = array_column(LoanService::listRequests($adm, [], 100)['requests'], 'id');
    ok(in_array($ru['id'], $all, true) && in_array($rv['id'], $all, true));
    eq([$rv['id']], array_column(LoanService::listRequests($adm, ['user_id' => (string) $v['id'], 'status' => 'pending'])['requests'], 'id'));
    lReset($u, $v);
});

t('Daftar: filter status/open, pencarian (nama barang, lokasi, kode, peminjam; wildcard literal), paginasi per pengajuan', function () {
    $u = lStaff('l_staff1'); $adm = lAdmin(); lReset($u);
    $tag = 'zl' . bin2hex(random_bytes(3));
    $r1 = lSubmit($u, [lItem("{$tag} Alpha")], ['location' => "Lokasi {$tag}"]); LoanService::rejectRequest($r1['id'], $adm, ureq());
    $r2 = lSubmit($u, [lItem("{$tag} Beta"), lItem("{$tag} Gamma")], ['location' => '50%_Persen']);
    $ids = fn(array $f, int $lim = 30, int $off = 0) => array_column(LoanService::listRequests($u, $f, $lim, $off)['requests'], 'id');

    eq([$r2['id'], $r1['id']], $ids(['q' => $tag]), 'terbaru dulu, satu entri per pengajuan');
    eq([$r2['id']], $ids(['q' => $tag, 'status' => 'pending']));
    eq([$r1['id']], $ids(['q' => $tag, 'status' => 'rejected']));
    eq([$r2['id']], $ids(['q' => $tag, 'status' => 'open']));
    eq([$r2['id']], $ids(['q' => 'Gamma']), 'cari nama barang pada anggota pengajuan');
    eq([$r2['id']], $ids(['q' => '50%_']), '% dan _ literal');
    eq([$r1['id']], $ids(['q' => $r1['code']]), 'cari kode pengajuan');
    eq(2, count(LoanService::listRequests($u, ['q' => $tag])['requests']));
    eq(2, LoanService::listRequests($u, ['q' => $tag])['total']);
    eq([$r2['id']], $ids(['q' => $tag], 1, 0));
    eq([$r1['id']], $ids(['q' => $tag], 1, 1));
    eq(3, count(LoanService::listRequests($u, ['q' => $tag, 'status' => 'open'])['requests'][0]['loans']) + 1, 'loans lengkap per pengajuan');
    foreach ([['status' => 'hilang'], ['user_id' => 'abc'], ['q' => ['x']]] as $bad) {
        eq(422, lErr(fn() => LoanService::listRequests($u, $bad))->status(), json_encode($bad));
    }
    eq(0, LoanService::listRequests($u, ['q' => "' OR 1=1 --"])['total']);
    lReset($u);
});

t('Ringkasan: staff hitung milik sendiri; admin semua', function () {
    $u = lStaff('l_staff1'); $adm = lAdmin(); lReset($u);
    $before = LoanService::summary($u);
    lSubmit($u, [lItem(), lItem()]);
    $after = LoanService::summary($u);
    eq($before['pending'] + 2, $after['pending']);
    eq($before['requests_pending'] + 1, $after['requests_pending']);
    ok(LoanService::summary($adm)['pending'] >= $after['pending']);
    ok(LoanService::pendingRequestCount() >= 1);
    lReset($u);
});

t('Pengajuan yang tampil memuat izin aksi sesuai peran/pemilik; tanpa data sensitif', function () {
    $u = lStaff('l_staff1'); $adm = lAdmin(); lReset($u);
    $r = lSubmit($u, [lItem()]);
    $asStaff = LoanService::findRequest($r['id'], $u);
    $asAdmin = LoanService::findRequest($r['id'], $adm);
    eq([false, true, true], [$asStaff['can']['approve'], $asStaff['can']['cancel'], $asStaff['loans'][0]['can']['swap']]);
    eq([true, false, false], [$asAdmin['can']['approve'], $asAdmin['can']['cancel'], $asAdmin['loans'][0]['can']['swap']]);
    lacks('password', strtolower((string) json_encode($asAdmin)));
    lacks('photo_path', (string) json_encode($asAdmin));
    lReset($u);
});

// ---------------------------------------------------------------------------
section('Peminjaman — konkurensi (proses PHP paralel sungguhan)');

/**
 * Jalankan beberapa proses anak SECARA BERSAMAAN dan kumpulkan hasilnya.
 *
 * @param array<int,array{0:string,1:array<string,mixed>}> $jobs [aksi, argumen]
 * @return array<int,array<string,mixed>>
 */
function parallel(array $jobs): array
{
    $at = microtime(true) + 2.0;                                 // semua anak menunggu hingga detik yang sama
    $env = array_merge(getenv(), ['APP_ENV' => 'testing', 'DB_NAME' => 'hme_peminjaman_test']);
    $procs = [];
    foreach ($jobs as $i => [$action, $args]) {
        $cmd = [PHP_BINARY, __DIR__ . '/../child_loan.php', $action, (string) $at, json_encode($args)];
        $procs[$i] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), $env);
        $procs[$i] = [$procs[$i], $pipes];
    }
    $out = [];
    foreach ($procs as $i => [$p, $pipes]) {
        $raw = trim((string) stream_get_contents($pipes[1]));
        $err = trim((string) stream_get_contents($pipes[2]));
        proc_close($p);
        $j = json_decode($raw, true);
        $out[$i] = is_array($j) ? $j : ['ok' => false, 'code' => 'no_output', 'raw' => $raw, 'err' => $err];
    }
    return $out;
}

t('KONKURENSI: 5 staff berebut SATU barang pada saat yang sama → tepat 1 berhasil, 4 ditolak, tidak ada double-booking', function () {
    $staff = [];
    foreach (range(1, 5) as $n) { $staff[] = lStaff("l_race{$n}"); }
    lReset(...$staff);
    $item = lItem('Rebutan');
    $res = parallel(array_map(fn($s) => ['submit', ['user_id' => $s['id'], 'item_ids' => [$item]]], $staff));
    $okN = count(array_filter($res, fn($r) => $r['ok'] === true));
    $lost = array_filter($res, fn($r) => $r['ok'] !== true);
    eq(1, $okN, json_encode($res));
    foreach ($lost as $r) { eq('item_unavailable', $r['code'], json_encode($r)); eq(409, $r['status']); }
    eq(1, (int) Database::value("SELECT COUNT(*) FROM loans WHERE item_id = ? AND status IN ('pending','approved')", [$item]), 'tepat satu loan aktif untuk barang itu');
    lReset(...$staff);
});

t('KONKURENSI: 5 staff mengajukan barang BERBEDA bersamaan → semua berhasil, nomor urut unik & berurutan', function () {
    $staff = [];
    foreach (range(1, 5) as $n) { $staff[] = lStaff("l_race{$n}"); }
    lReset(...$staff);
    $items = array_map(fn() => lItem('Paralel'), $staff);
    $res = parallel(array_map(fn($s, $it) => ['submit', ['user_id' => $s['id'], 'item_ids' => [$it]]], $staff, $items));
    foreach ($res as $r) { eq(true, $r['ok'], json_encode($r)); }
    $seq = array_map('intval', array_column(Database::all('SELECT seq_no FROM loans WHERE item_id IN (' . implode(',', $items) . ')'), 'seq_no'));
    eq(5, count(array_unique($seq)), 'tidak ada nomor ganda');
    sort($seq);
    eq(range($seq[0], $seq[0] + 4), $seq, 'berurutan tanpa celah');
    lReset(...$staff);
});

t('KONKURENSI: admin menyetujui SAAT staff membatalkan → salah satu menang, status & master_status konsisten', function () {
    $u = lStaff('l_staff1'); $adm = lAdmin();
    foreach (range(1, 4) as $round) {
        lReset($u);
        $a = lItem("Balapan {$round}");
        $r = lSubmit($u, [$a]);
        $res = parallel([['approve', ['user_id' => $adm['id'], 'request_id' => $r['id']]], ['cancel', ['user_id' => $u['id'], 'request_id' => $r['id']]]]);
        $st = lStatus($r['loans'][0]['id']);
        ok(in_array($st, ['approved', 'cancelled'], true), "status {$st}: " . json_encode($res));
        eq(1, count(array_filter($res, fn($x) => $x['ok'] === true)), 'tepat satu aksi menang: ' . json_encode($res));
        eq($st === 'approved' ? 'Dipinjam' : 'Tersedia', lMaster($a), 'master_status sinkron dengan hasil');
    }
    lReset($u);
});

t('KONKURENSI: dua staff mengganti unit ke barang yang sama → tepat 1 berhasil', function () {
    $s1 = lStaff('l_race1'); $s2 = lStaff('l_race2'); lReset($s1, $s2);
    $r1 = lSubmit($s1, [lItem('Asal 1')]);
    $r2 = lSubmit($s2, [lItem('Asal 2')]);
    $target = lItem('Tujuan');
    $res = parallel([
        ['swap', ['user_id' => $s1['id'], 'loan_id' => $r1['loans'][0]['id'], 'item_id' => $target]],
        ['swap', ['user_id' => $s2['id'], 'loan_id' => $r2['loans'][0]['id'], 'item_id' => $target]],
    ]);
    eq(1, count(array_filter($res, fn($x) => $x['ok'] === true)), json_encode($res));
    eq(1, (int) Database::value("SELECT COUNT(*) FROM loans WHERE item_id = ? AND status IN ('pending','approved')", [$target]));
    lReset($s1, $s2);
});
