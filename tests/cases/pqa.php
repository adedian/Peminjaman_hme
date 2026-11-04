<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\HttpException;
use App\Services\ItemService;
use App\Services\LoanService;
use App\Services\PqaService;

function qUnit(string $name = 'PQA Uji'): int
{
    return iMake(['name' => $name, 'is_pqa' => '1'])['id'];
}

/** @param array<string,mixed> $o */
function qSubmit(array $staff, int $item, array $o = []): array
{
    return PqaService::submit($staff, $o + ['item_id' => $item, 'ppic' => 'Naufal', 'location' => 'PT Uji Coba', 'install_team' => 'Rian, Vega', 'target_days' => 7], ureq());
}

/** Ajukan lalu setujui → unit Terpasang. */
function qInstalled(array $staff, array $admin, int $item, array $o = []): array
{
    $p = qSubmit($staff, $item, $o);
    return PqaService::decideInstall($p['id'], true, $admin, ureq());
}

function qErr(callable $fn): HttpException
{
    /** @var HttpException $e */
    $e = throws($fn, HttpException::class);
    return $e;
}

function qAgo(int $id, string $interval): void
{
    Database::run("UPDATE pqa_installations SET installed_at = NOW() - INTERVAL {$interval} WHERE id = ?", [$id]);
}

function qRow(int $id): array
{
    return Database::one('SELECT * FROM pqa_installations WHERE id = ?', [$id]) ?? [];
}

/** @return array<int,array<string,mixed>> */
function qHist(int $id): array
{
    return Database::all('SELECT type, days, team, status FROM pqa_requests WHERE installation_id = ? ORDER BY id', [$id]);
}

// ---------------------------------------------------------------------------
section('PQA — pengajuan pemasangan (BR-18..22, 29)');

t('Ajukan: status Pending, unit langsung Dipinjam & hilang dari daftar unit/peminjaman; izin aksi sesuai peran', function () {
    $s = lStaff('l_staff1'); $adm = lAdmin(); lReset($s);
    $u = qUnit('PQA Pengajuan');
    ok(in_array($u, array_column(PqaService::availableUnits(), 'id'), true));
    Database::run('DELETE FROM change_events');
    $p = qSubmit($s, $u, ['ppic' => '  Naufal  ', 'target_days' => '14']);

    eq(['Pending', 14, 'Naufal', 'PT Uji Coba', 'Rian, Vega'], [$p['status'], $p['target_days'], $p['ppic'], $p['location'], $p['install_team']]);
    eq(null, $p['actual_days']); eq(null, $p['installed_at']);
    ok($p['submitted_at'] !== null);
    eq('L_staff1', $p['requested_by']['name']);
    eq('Dipinjam', lMaster($u));
    ok(!in_array($u, array_column(PqaService::availableUnits(), 'id'), true), 'tidak muncul lagi di daftar unit PQA');
    ok(!in_array($u, lAvailIds(), true), 'tidak muncul di daftar barang yang bisa dipinjam');
    $asAdmin = PqaService::find($p['id'], $adm);
    eq([true, true, false, false], [$asAdmin['can']['approve'], $asAdmin['can']['reject'], $asAdmin['can']['extend'], $asAdmin['can']['remove']]);
    $asStaff = PqaService::find($p['id'], $s);
    eq([false, false, false, false], [$asStaff['can']['approve'], $asStaff['can']['reject'], $asStaff['can']['extend'], $asStaff['can']['remove']]);
    ok(Database::value("SELECT COUNT(*) FROM change_events WHERE entity_type = 'pqa' AND action = 'submitted'") >= 1);
    ok(Database::value("SELECT COUNT(*) FROM activity_logs WHERE action = 'pqa.submitted' AND entity_id = ?", [(string) $p['id']]) >= 1);
});

t('Validasi: bukan unit PQA / tidak ada / nonaktif, kolom wajib, target hari — tidak ada pemasangan terbentuk', function () {
    $s = lStaff('l_staff1');
    $unit = qUnit('PQA Validasi');
    $plain = iMake(['name' => 'Bukan PQA'])['id'];
    $off = qUnit('PQA Nonaktif'); ItemService::setActive($off, false, iActor(), ureq());
    $before = (int) Database::value('SELECT COUNT(*) FROM pqa_installations');
    $f = fn(array $o) => qErr(fn() => qSubmit($s, $unit, $o))->fields();

    foreach ([$plain, 99999999, 0, -1, 'abc', [1], null] as $bad) {
        $e = qErr(fn() => PqaService::submit($s, ['item_id' => $bad, 'ppic' => 'Naufal', 'location' => 'PT X', 'install_team' => 'Tim', 'target_days' => 7], ureq()));
        eq(422, $e->status(), 'item_id ' . json_encode($bad));
        ok(isset($e->fields()['item_id']), 'item_id ' . json_encode($bad));
    }
    eq(409, qErr(fn() => qSubmit($s, $off))->status(), 'unit nonaktif tidak bisa dipasang');
    ok(isset($f(['ppic' => ''])['ppic']) && isset($f(['ppic' => 'x'])['ppic']) && isset($f(['ppic' => str_repeat('p', 101)])['ppic']));
    ok(isset($f(['location' => ''])['location']) && isset($f(['location' => str_repeat('l', 256)])['location']));
    ok(isset($f(['install_team' => ''])['install_team']) && isset($f(['install_team' => ['x']])['install_team']));
    foreach ([0, -5, 3651, 'abc', 1.5, ['7'], '7; DROP TABLE x', ''] as $d) {
        ok(isset($f(['target_days' => $d])['target_days']), 'target_days ' . json_encode($d));
    }
    eq($before, (int) Database::value('SELECT COUNT(*) FROM pqa_installations'));
});

t('BR-19/BR-29: unit dengan PQA aktif, loan pending/approved, atau status Dipinjam ditolak (409)', function () {
    $s = lStaff('l_staff1'); $v = lStaff('l_staff2'); $adm = lAdmin(); lReset($s, $v);
    // PQA aktif (3 status) — ajukan ulang unit yang sama
    foreach (['Pending', 'Terpasang', 'Req Cabut'] as $st) {
        $u = qUnit("PQA {$st}");
        $p = qSubmit($s, $u);
        Database::run('UPDATE pqa_installations SET status = ? WHERE id = ?', [$st, $p['id']]);
        $e = qErr(fn() => qSubmit($v, $u));
        eq(409, $e->status(), $st); eq('item_unavailable', $e->errorCode(), $st);
    }
    // loan pending / approved
    $a = qUnit('PQA Dipesan'); $l = lSubmit($s, [$a]);
    eq('item_unavailable', qErr(fn() => qSubmit($v, $a))->errorCode(), 'loan pending');
    LoanService::approveRequest($l['id'], $adm, ureq());
    eq('item_unavailable', qErr(fn() => qSubmit($v, $a))->errorCode(), 'loan approved');
    LoanService::returnRequest($l['id'], $s, ureq());
    ok(qSubmit($v, $a)['id'] > 0, 'setelah dikembalikan unit boleh dipasang');
    // sebaliknya: unit PQA terpasang tidak bisa dipinjam
    $b = qUnit('PQA Terpasang Pinjam'); qInstalled($s, $adm, $b);
    eq('item_unavailable', qErr(fn() => lSubmit($v, [$b]))->errorCode(), 'PQA aktif tidak bisa dipinjam biasa');
    lReset($s, $v);
});

t('Hanya staff yang dapat mengajukan (admin → 403 di level service)', function () {
    eq(403, qErr(fn() => qSubmit(lAdmin(), qUnit()))->status());
});

// ---------------------------------------------------------------------------
section('PQA — keputusan pemasangan');

t('Setujui: Terpasang, tanggal pasang = saat DISETUJUI (bukan saat diajukan), unit tetap Dipinjam; tak bisa diproses dua kali', function () {
    $s = lStaff('l_staff1'); $adm = lAdmin();
    $u = qUnit('PQA Setuju');
    $p = qSubmit($s, $u);
    Database::run("UPDATE pqa_installations SET submitted_at = NOW() - INTERVAL 3 DAY WHERE id = ?", [$p['id']]);
    $res = PqaService::decideInstall($p['id'], true, $adm, ureq());
    eq('Terpasang', $res['status']);
    ok(abs(strtotime((string) $res['installed_at']) - time()) < 120, 'installed_at ≈ sekarang');
    ok(strtotime((string) $res['installed_at']) > strtotime((string) $res['submitted_at']) + 86400, 'bukan waktu pengajuan');
    eq($adm['id'], (int) qRow($p['id'])['decided_by']);
    eq(0, $res['actual_days']);
    eq('Dipinjam', lMaster($u));
    eq('invalid_state', qErr(fn() => PqaService::decideInstall($p['id'], true, $adm, ureq()))->errorCode());
    eq('invalid_state', qErr(fn() => PqaService::decideInstall($p['id'], false, $adm, ureq()))->errorCode());
});

t('Tolak: Ditolak, unit tersedia lagi untuk dipasang/dipinjam; staff tidak boleh memutuskan; 404', function () {
    $s = lStaff('l_staff1'); $adm = lAdmin();
    $u = qUnit('PQA Tolak');
    $p = qSubmit($s, $u);
    eq(403, qErr(fn() => PqaService::decideInstall($p['id'], true, $s, ureq()))->status());
    eq('Pending', qRow($p['id'])['status']);
    $res = PqaService::decideInstall($p['id'], false, $adm, ureq());
    eq('Ditolak', $res['status']);
    eq('Tersedia', lMaster($u));
    ok(in_array($u, array_column(PqaService::availableUnits(), 'id'), true));
    ok(qSubmit($s, $u)['id'] > 0, 'dapat diajukan lagi setelah ditolak');
    eq(404, qErr(fn() => PqaService::decideInstall(99999999, true, $adm, ureq()))->status());
});

// ---------------------------------------------------------------------------
section('PQA — tambah waktu (BR-23)');

t('Ajukan tambah waktu hanya saat Terpasang; satu permintaan menunggu sekali; validasi hari', function () {
    $s = lStaff('l_staff1'); $adm = lAdmin();
    $u = qUnit('PQA Tambah');
    $pend = qSubmit($s, $u);
    eq('invalid_state', qErr(fn() => PqaService::requestExtension($pend['id'], 3, $s, ureq()))->errorCode(), 'belum terpasang');
    PqaService::decideInstall($pend['id'], true, $adm, ureq());

    foreach ([0, -1, 'abc', 3651, 1.5, [3], null, ''] as $bad) {
        eq(422, qErr(fn() => PqaService::requestExtension($pend['id'], $bad, $s, ureq()))->status(), json_encode($bad));
    }
    $res = PqaService::requestExtension($pend['id'], '5', $s, ureq());
    eq(5, $res['extension_pending_days']);
    eq([false, false, true, true], [$res['can']['extend'], $res['can']['approve_extension'], $res['can']['remove'], true], 'staff: tak bisa ajukan lagi');
    $asAdmin = PqaService::find($pend['id'], $adm);
    ok($asAdmin['can']['approve_extension'] && $asAdmin['can']['reject_extension']);
    eq('extension_pending', qErr(fn() => PqaService::requestExtension($pend['id'], 2, $s, ureq()))->errorCode());
    eq(403, qErr(fn() => PqaService::requestExtension($pend['id'], 2, $adm, ureq()))->status(), 'admin tidak mengajukan');
    eq([['type' => 'extend', 'days' => 5, 'team' => null, 'status' => 'pending']], array_map(fn($h) => array_map(fn($v) => is_numeric($v) ? (int) $v : $v, $h), qHist($pend['id'])));
});

t('Setujui tambah waktu: target += N, permintaan tertutup, bisa ajukan lagi; tolak: target tetap', function () {
    $s = lStaff('l_staff1'); $adm = lAdmin();
    $p = qInstalled($s, $adm, qUnit('PQA Tambah 2'), ['target_days' => 7]);
    PqaService::requestExtension($p['id'], 5, $s, ureq());
    eq(403, qErr(fn() => PqaService::decideExtension($p['id'], true, $s, ureq()))->status());
    $res = PqaService::decideExtension($p['id'], true, $adm, ureq());
    eq([12, 0], [$res['target_days'], $res['extension_pending_days']]);
    eq('invalid_state', qErr(fn() => PqaService::decideExtension($p['id'], true, $adm, ureq()))->errorCode(), 'tidak ada yang menunggu');

    PqaService::requestExtension($p['id'], 3, $s, ureq());
    $res = PqaService::decideExtension($p['id'], false, $adm, ureq());
    eq([12, 0], [$res['target_days'], $res['extension_pending_days']], 'ditolak → target tidak berubah');
    eq(['approved', 'rejected'], array_column(qHist($p['id']), 'status'));
    ok(PqaService::requestExtension($p['id'], 1, $s, ureq())['can']['extend'] === false);
});

// ---------------------------------------------------------------------------
section('PQA — pencabutan (BR-24/25)');

t('Request cabut: hanya saat Terpasang, tim wajib; status Req Cabut; unit tetap Dipinjam; tolak → kembali Terpasang', function () {
    $s = lStaff('l_staff1'); $adm = lAdmin();
    $u = qUnit('PQA Cabut');
    $pend = qSubmit($s, $u);
    eq('invalid_state', qErr(fn() => PqaService::requestRemoval($pend['id'], 'Tim', $s, ureq()))->errorCode());
    PqaService::decideInstall($pend['id'], true, $adm, ureq());
    foreach (['', ' ', 'x', ['a'], null, str_repeat('t', 256)] as $bad) {
        eq(422, qErr(fn() => PqaService::requestRemoval($pend['id'], $bad, $s, ureq()))->status(), json_encode($bad));
    }
    eq(403, qErr(fn() => PqaService::requestRemoval($pend['id'], 'Tim', $adm, ureq()))->status());
    $res = PqaService::requestRemoval($pend['id'], '  Niko, Adil ', $s, ureq());
    eq(['Req Cabut', 'Niko, Adil'], [$res['status'], $res['removal_team']]);
    eq('Dipinjam', lMaster($u));
    eq('invalid_state', qErr(fn() => PqaService::requestRemoval($pend['id'], 'Lagi', $s, ureq()))->errorCode(), 'tak bisa dua kali');
    eq('invalid_state', qErr(fn() => PqaService::requestExtension($pend['id'], 2, $s, ureq()))->errorCode(), 'tak bisa tambah waktu saat Req Cabut');

    $res = PqaService::decideRemoval($pend['id'], false, $adm, ureq());
    eq(['Terpasang', null], [$res['status'], $res['removal_team']]);
    eq('rejected', qHist($pend['id'])[0]['status']);
    eq('invalid_state', qErr(fn() => PqaService::decideRemoval($pend['id'], true, $adm, ureq()))->errorCode(), 'tak ada permintaan');
});

t('Setujui cabut: Dicabut, total hari = floor(hari terpasang) (contoh data lama 80 hari), dibekukan, unit tersedia kembali', function () {
    $s = lStaff('l_staff1'); $adm = lAdmin();
    $u = qUnit('PQA Selesai');
    $p = qInstalled($s, $adm, $u, ['target_days' => 14]);
    qAgo($p['id'], '80 DAY - INTERVAL 5 HOUR');                         // terpasang 80 hari 5 jam → 80 (contoh data lama)
    PqaService::requestRemoval($p['id'], 'Niko', $s, ureq());
    eq(403, qErr(fn() => PqaService::decideRemoval($p['id'], true, $s, ureq()))->status());
    $res = PqaService::decideRemoval($p['id'], true, $adm, ureq());
    eq('Dicabut', $res['status']);
    eq(80, $res['actual_days']);
    eq(80, (int) qRow($p['id'])['total_days']);
    ok($res['removed_at'] !== null);
    eq(false, $res['over_target'], 'sudah dicabut: bukan lagi "melewati target aktif"');
    eq('Tersedia', lMaster($u));
    ok(in_array($u, array_column(PqaService::availableUnits(), 'id'), true));
    eq(['approved'], array_column(qHist($p['id']), 'status'));
});

t('Permintaan tambah waktu & cabut boleh bersamaan (perilaku lama); saat dicabut permintaan tambah waktu yang menggantung ditutup', function () {
    $s = lStaff('l_staff1'); $adm = lAdmin();
    $p = qInstalled($s, $adm, qUnit('PQA Bersamaan'));
    PqaService::requestExtension($p['id'], 4, $s, ureq());
    $res = PqaService::requestRemoval($p['id'], 'Tim Cabut', $s, ureq());
    eq(['Req Cabut', 4], [$res['status'], $res['extension_pending_days']], 'keduanya menunggu');
    $asAdmin = PqaService::find($p['id'], $adm);
    ok($asAdmin['can']['approve_extension'] && $asAdmin['can']['approve_removal']);
    $res = PqaService::decideRemoval($p['id'], true, $adm, ureq());
    eq(['Dicabut', 0], [$res['status'], $res['extension_pending_days']]);
    eq(['rejected', 'approved'], array_column(qHist($p['id']), 'status'), 'tambah waktu ditutup (ditolak), cabut disetujui');
    eq('invalid_state', qErr(fn() => PqaService::decideExtension($p['id'], true, $adm, ureq()))->errorCode());
});

t('Tambah waktu yang menunggu masih dapat disetujui saat Req Cabut', function () {
    $s = lStaff('l_staff1'); $adm = lAdmin();
    $p = qInstalled($s, $adm, qUnit('PQA Req Cabut Tambah'), ['target_days' => 7]);
    PqaService::requestExtension($p['id'], 4, $s, ureq());
    PqaService::requestRemoval($p['id'], 'Tim', $s, ureq());
    $res = PqaService::decideExtension($p['id'], true, $adm, ureq());
    eq([11, 'Req Cabut'], [$res['target_days'], $res['status']]);
});

// ---------------------------------------------------------------------------
section('PQA — durasi, daftar, dan hitungan');

t('Durasi aktif dihitung langsung (hari penuh) dan ditandai melewati target', function () {
    $s = lStaff('l_staff1'); $adm = lAdmin();
    $p = qInstalled($s, $adm, qUnit('PQA Durasi'), ['target_days' => 7]);
    qAgo($p['id'], '9 DAY - INTERVAL 11 HOUR');
    $res = PqaService::find($p['id'], $s);
    eq([9, true], [$res['actual_days'], $res['over_target']]);
    qAgo($p['id'], '6 DAY - INTERVAL 23 HOUR');
    $res = PqaService::find($p['id'], $s);
    eq([6, false], [$res['actual_days'], $res['over_target']], 'floor: 6 hari 23 jam = 6');
    qAgo($p['id'], '7 DAY - INTERVAL 1 HOUR');
    eq(false, PqaService::find($p['id'], $s)['over_target'], 'tepat di target belum melewati');
});

t('Semua staff melihat SEMUA unit (tracker bersama, seperti sistem lama); detail memuat riwayat permintaan', function () {
    $s = lStaff('l_staff1'); $v = lStaff('l_staff2'); $adm = lAdmin();
    $p = qInstalled($s, $adm, qUnit('PQA Milik S1'));
    PqaService::requestExtension($p['id'], 2, $s, ureq());
    $seenByOther = PqaService::find($p['id'], $v, true);
    ok($seenByOther !== null);
    eq('L_staff1', $seenByOther['requested_by']['name']);
    eq([['extend', 2, 'pending']], array_map(fn($h) => [$h['type'], $h['days'], $h['status']], $seenByOther['history']));
    ok($seenByOther['can']['remove'], 'staff lain boleh mengajukan cabut (diputuskan admin) — perilaku lama');
    eq(null, PqaService::find(99999999, $v));
});

t('Daftar: grup pending/aktif/riwayat (Ditolak ikut riwayat), pencarian, paginasi, validasi; terbaru dulu', function () {
    $s = lStaff('l_staff1'); $adm = lAdmin();
    $tag = 'zq' . bin2hex(random_bytes(3));
    $a = qUnit("{$tag} A"); $b = qUnit("{$tag} B"); $c = qUnit("{$tag} C"); $d = qUnit("{$tag} D");
    $pa = qSubmit($s, $a, ['location' => "Lok {$tag} 50%_x"]);
    $pb = qInstalled($s, $adm, $b, ['location' => "Lok {$tag}"]);
    $pc = qInstalled($s, $adm, $c, ['location' => "Lok {$tag}"]);
    PqaService::requestRemoval($pc['id'], 'Tim', $s, ureq()); PqaService::decideRemoval($pc['id'], true, $adm, ureq());
    $pd = qSubmit($s, $d, ['location' => "Lok {$tag}"]); PqaService::decideInstall($pd['id'], false, $adm, ureq());

    $ids = fn(array $f, int $lim = 30, int $off = 0) => array_column(PqaService::list($s, $f + ['q' => $tag], $lim, $off)['items'], 'id');
    eq([$pa['id']], $ids(['group' => 'pending']));
    eq([$pb['id']], $ids(['group' => 'active']));
    eq([$pd['id'], $pc['id']], $ids(['group' => 'history']), 'riwayat memuat Dicabut dan Ditolak, terbaru dulu');
    eq(4, count($ids([])));
    eq([$pa['id']], array_column(PqaService::list($s, ['q' => '50%_x'])['items'], 'id'), '% dan _ literal');
    eq([$pd['id']], $ids(['group' => 'history'], 1, 0));
    eq([$pc['id']], $ids(['group' => 'history'], 1, 1));
    eq(2, PqaService::list($s, ['group' => 'history', 'q' => $tag])['total']);
    foreach ([['group' => 'semua'], ['q' => ['x']], ['group' => ['pending']]] as $bad) {
        eq(422, qErr(fn() => PqaService::list($s, $bad))->status(), json_encode($bad));
    }
    eq(0, PqaService::list($s, ['q' => "' OR 1=1 --"])['total']);
});

t('Hitungan perlu tindakan admin: pengajuan + tambah waktu + cabut', function () {
    $s = lStaff('l_staff1'); $adm = lAdmin();
    $before = PqaService::pendingActionCount();
    $p1 = qSubmit($s, qUnit('PQA Hitung 1'));                                         // +1
    $p2 = qInstalled($s, $adm, qUnit('PQA Hitung 2')); PqaService::requestExtension($p2['id'], 3, $s, ureq());   // +1
    $p3 = qInstalled($s, $adm, qUnit('PQA Hitung 3')); PqaService::requestRemoval($p3['id'], 'Tim', $s, ureq()); // +1
    eq($before + 3, PqaService::pendingActionCount());
    PqaService::decideInstall($p1['id'], true, $adm, ureq());
    eq($before + 2, PqaService::pendingActionCount());
});

t('Penjaga barang: ID/flag PQA terkunci saat ada pemasangan aktif; nonaktifkan ditolak', function () {
    $s = lStaff('l_staff1'); $adm = lAdmin();
    $u = qUnit('PQA Dijaga');
    $p = qInstalled($s, $adm, $u);
    $row = ItemService::find($u);
    eq('item_in_use', qErr(fn() => ItemService::setActive($u, false, iActor(), ureq()))->errorCode());
    eq('pqa_active', qErr(fn() => ItemService::update($u, ['code' => $row['code'], 'name' => $row['name'], 'category_id' => $row['category_id']], null, iActor(), ureq()))->errorCode(), 'melepas flag saat terpasang');
    PqaService::requestRemoval($p['id'], 'Tim', $s, ureq()); PqaService::decideRemoval($p['id'], true, $adm, ureq());
    eq(false, ItemService::setActive($u, false, iActor(), ureq())['is_active'], 'setelah dicabut boleh dinonaktifkan');
});

// ---------------------------------------------------------------------------
section('PQA — konkurensi (proses PHP paralel sungguhan)');

t('KONKURENSI: 5 staff memasang unit PQA yang sama bersamaan → tepat 1 berhasil', function () {
    $staff = []; foreach (range(1, 5) as $n) { $staff[] = lStaff("l_race{$n}"); }
    $u = qUnit('PQA Rebutan');
    $res = parallel(array_map(fn($s) => ['pqa_submit', ['user_id' => $s['id'], 'item_id' => $u]], $staff));
    eq(1, count(array_filter($res, fn($r) => $r['ok'] === true)), json_encode($res));
    foreach (array_filter($res, fn($r) => $r['ok'] !== true) as $r) { eq('item_unavailable', $r['code'], json_encode($r)); }
    eq(1, (int) Database::value("SELECT COUNT(*) FROM pqa_installations WHERE item_id = ? AND status IN ('Pending','Terpasang','Req Cabut')", [$u]));
    eq('Dipinjam', lMaster($u));
});

t('KONKURENSI LINTAS MODUL: pinjam biasa vs pasang PQA pada unit yang sama → tepat 1 menang, status konsisten (4 putaran)', function () {
    $a = lStaff('l_race1'); $b = lStaff('l_race2');
    foreach (range(1, 4) as $round) {
        lReset($a, $b);
        $u = qUnit("PQA Lintas {$round}");
        $res = parallel([['submit', ['user_id' => $a['id'], 'item_ids' => [$u]]], ['pqa_submit', ['user_id' => $b['id'], 'item_id' => $u]]]);
        eq(1, count(array_filter($res, fn($r) => $r['ok'] === true)), "putaran {$round}: " . json_encode($res));
        $loan = (int) Database::value("SELECT COUNT(*) FROM loans WHERE item_id = ? AND status IN ('pending','approved')", [$u]);
        $pqa = (int) Database::value("SELECT COUNT(*) FROM pqa_installations WHERE item_id = ? AND status IN ('Pending','Terpasang','Req Cabut')", [$u]);
        eq(1, $loan + $pqa, 'tidak boleh dua-duanya aktif');
        eq($pqa === 1 ? 'Dipinjam' : 'Tersedia', lMaster($u), 'master_status sesuai pemenang');
    }
    lReset($a, $b);
});

t('KONKURENSI: admin menyetujui tambah waktu SAAT menyetujui pencabutan → hasil akhir selalu konsisten', function () {
    $s = lStaff('l_staff1'); $adm = lAdmin();
    foreach (range(1, 4) as $round) {
        $p = qInstalled($s, $adm, qUnit("PQA Balap {$round}"), ['target_days' => 7]);
        PqaService::requestExtension($p['id'], 3, $s, ureq());
        PqaService::requestRemoval($p['id'], 'Tim', $s, ureq());
        $res = parallel([['pqa_approve_extension', ['user_id' => $adm['id'], 'id' => $p['id']]], ['pqa_approve_removal', ['user_id' => $adm['id'], 'id' => $p['id']]]]);
        foreach ($res as $r) { ok($r['ok'] === true || in_array($r['code'] ?? '', ['invalid_state'], true), "putaran {$round}: " . json_encode($res)); }
        $row = qRow($p['id']);
        eq('Dicabut', $row['status'], json_encode($res));
        eq(0, (int) $row['pending_extension_days']);
        ok(!in_array('pending', array_column(qHist($p['id']), 'status'), true), 'tidak ada permintaan yang menggantung');
        ok(in_array((int) $row['target_days'], [7, 10], true), 'target 7 (tambah waktu ditutup) atau 10 (disetujui lebih dulu)');
    }
});
